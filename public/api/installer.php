<?php
/**
 * Installer logic shared by setup.php (browser, local only) and bin/install.php (command line).
 * Safe to run any number of times: it never overwrites or deletes existing data.
 */
require_once dirname(__DIR__, 2) . '/config/config.php';

function pos_quote_ident(string $n): string { return '"' . str_replace('"', '""', $n) . '"'; }

function pos_connect(array $c, string $dbname): PDO {
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$dbname};connect_timeout=5";
    if (!empty($c['sslmode'])) $dsn .= ';sslmode=' . $c['sslmode'];
    return new PDO($dsn, $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function pos_explain(Throwable $e): string {
    $m = $e->getMessage();
    if (stripos($m, 'password authentication') !== false)
        return 'PostgreSQL rejected the user/password. Check DB_USER / DB_PASSWORD (or config/config.local.php).';
    if (stripos($m, 'could not connect') !== false || stripos($m, 'Connection refused') !== false || stripos($m, 'timeout') !== false || stripos($m, 'could not translate host') !== false)
        return 'Cannot reach PostgreSQL at the host and port in your configuration. Make sure the database server is running and DB_HOST / DB_PORT are correct.';
    return $m;
}

function pos_random_password(int $len = 14): string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';   // no look-alike characters (0/O, 1/l/I)
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}

/**
 * Installs or upgrades the database.
 * $opts: admin_user, admin_pass (optional; a random password is generated when none is given), seed_shop (bool)
 * Returns ['steps' => [[label, ok, detail], ...], 'ok' => bool, 'admin_user' => ?string, 'admin_pass' => ?string (only when just generated/created)]
 */
function pos_install(array $cfg, array $opts = []): array {
    $steps = []; $res = ['steps' => &$steps, 'ok' => false, 'admin_user' => null, 'admin_pass' => null];
    $step = function (string $label, bool $ok = true, string $detail = '') use (&$steps) { $steps[] = [$label, $ok, $detail]; };

    if (!extension_loaded('pdo_pgsql')) {
        $step('PHP extension pdo_pgsql', false, 'Not enabled. Install/enable pdo_pgsql (XAMPP: uncomment extension=pdo_pgsql and extension=pgsql in php.ini, then restart Apache).');
        return $res;
    }
    $root = dirname(__DIR__);
    try {
        // 1) make sure the database exists (managed hosts often pre-create it and forbid CREATE DATABASE: that is fine)
        $pdo = null;
        try {
            $maint = pos_connect($cfg, 'postgres');
            $st = $maint->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $st->execute([$cfg['dbname']]);
            if ($st->fetch()) $step("Database \"{$cfg['dbname']}\" exists");
            else { $maint->exec('CREATE DATABASE ' . pos_quote_ident($cfg['dbname'])); $step("Database \"{$cfg['dbname']}\" created"); }
            $maint = null;
        } catch (PDOException $e) {
            // could not use the maintenance database: fall through and try the target database directly
        }
        $pdo = pos_connect($cfg, $cfg['dbname']);
        $step('Connected to PostgreSQL', true, "{$cfg['host']}:{$cfg['port']} / {$cfg['dbname']} as {$cfg['user']}");

        // 2) tables
        $has = $pdo->query("SELECT to_regclass('public.users') AS t")->fetch()['t'];
        if ($has) {
            $step('Tables already installed', true, 'Nothing was changed.');
        } else {
            $sql = @file_get_contents($root . '/database/schema.sql');
            if ($sql === false || trim($sql) === '') throw new RuntimeException('Could not read database/schema.sql');
            $pdo->exec($sql);
            $step('Tables created');
        }

        // 3) upgrades for older databases, then record the version so the app does not repeat it
        $mig = @file_get_contents($root . '/database/migrate.sql');
        if ($mig === false) throw new RuntimeException('Could not read database/migrate.sql');
        $ver = (string)(require __DIR__ . '/version.php')['schema'];
        $pdo->beginTransaction();
        $pdo->exec('SELECT pg_advisory_xact_lock(727001)');
        $pdo->exec($mig);
        $up = $pdo->prepare("INSERT INTO settings (name, value) VALUES ('schema_version', ?) ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value");
        $up->execute([$ver]);
        $pdo->commit();
        $step('Database structure is up to date', true, "Schema version $ver.");

        // 4) the first admin. Only when the system has no admin yet; an existing admin is never touched.
        $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($admins === 0) {
            $user = trim((string)($opts['admin_user'] ?? '')) ?: 'admin';
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $user)) throw new RuntimeException('ADMIN_USERNAME must be 3-50 characters: letters, numbers, dot, dash or underscore.');
            $pass = (string)($opts['admin_pass'] ?? '');
            $generated = false;
            if ($pass === '') { $pass = pos_random_password(); $generated = true; }
            elseif (strlen($pass) < 8) throw new RuntimeException('ADMIN_PASSWORD must be at least 8 characters.');
            $ins = $pdo->prepare("INSERT INTO users (username, full_name, password_hash, role) VALUES (?, 'Administrator', ?, 'admin') ON CONFLICT (username) DO UPDATE SET role = 'admin', active = TRUE, password_hash = EXCLUDED.password_hash");
            $ins->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
            $res['admin_user'] = $user;
            $res['admin_pass'] = $generated ? $pass : null;
            $step('Admin account created', true, $generated ? "Username: $user (a random password was generated and is shown once)." : "Username: $user (password from ADMIN_PASSWORD).");
        } else {
            $weak = $pdo->query("SELECT username, password_hash FROM users WHERE username IN ('admin','cashier')")->fetchAll();
            $flag = [];
            foreach ($weak as $w) foreach (['admin123', 'cashier123'] as $d) if (password_verify($d, $w['password_hash'])) $flag[] = $w['username'];
            $step('Admin account already exists', true, $flag
                ? 'WARNING: ' . implode(', ', $flag) . ' still use a default password. They will be forced to choose a new one at next login; change them now under Users.'
                : 'Nothing was changed.');
        }

        // 5) optional starter catalogue
        if (!empty($opts['seed_shop'])) {
            $seed = @file_get_contents($root . '/database/shop_seed.sql');
            if ($seed === false) throw new RuntimeException('Could not read database/shop_seed.sql');
            $pdo->exec($seed);
            $step('Starter shop products loaded', true, 'Existing products were not changed.');
        }

        $n = $pdo->query('SELECT (SELECT COUNT(*) FROM users) AS users, (SELECT COUNT(*) FROM products) AS products')->fetch();
        $step('Check', true, "{$n['users']} user(s), {$n['products']} product(s) in \"{$cfg['dbname']}\"");
        $res['ok'] = true;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        $step('Failed', false, pos_explain($e));
    }
    return $res;
}
