<?php
// Database access. Configuration is loaded by bootstrap.php (global $cfg).

class ApiError extends Exception {
    public function __construct(string $msg, int $status = 400) { parent::__construct($msg, $status); }
}

function db(): PDO {
    static $pdo = null;
    global $cfg;
    if ($pdo) return $pdo;
    if (!extension_loaded('pdo_pgsql')) {
        throw new ApiError('The PHP extension pdo_pgsql is not enabled on this server. (XAMPP: uncomment extension=pdo_pgsql and extension=pgsql in php.ini and restart Apache.)', 500);
    }
    $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};connect_timeout=5";
    if (!empty($cfg['sslmode'])) $dsn .= ';sslmode=' . $cfg['sslmode'];
    $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $tz = (string)($cfg['timezone'] ?? 'UTC');
    if (!preg_match('~^[A-Za-z0-9_/+-]+$~', $tz)) $tz = 'UTC';
    $pdo->exec('SET TIME ZONE ' . $pdo->quote($tz));
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
