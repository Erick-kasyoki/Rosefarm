<?php
/**
 * Command-line installer / upgrader. Run on the server (or automatically by the Docker image):
 *
 *     php bin/install.php [--wait] [--seed-shop]
 *
 *   --wait       keep retrying for up to 60 seconds until PostgreSQL accepts connections (used at container start)
 *   --seed-shop  also load the starter shop catalogue (milk, mala, eggs, chicken, cereals, flours, cooking oil)
 *
 * Environment: DB_* or DATABASE_URL (see .env.example), ADMIN_USERNAME, ADMIN_PASSWORD (optional; a random one is printed once if missing).
 * Safe to run again at every deployment.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../api/installer.php';
$cfg = require __DIR__ . '/../api/config.php';

$args = $argv ?? [];
$wait = in_array('--wait', $args, true);
$seed = in_array('--seed-shop', $args, true) || in_array(strtolower((string)env_val('SEED_SHOP', '')), ['1', 'true', 'yes'], true);

$deadline = time() + ($wait ? 60 : 0);
do {
    $r = pos_install($cfg, ['admin_user' => env_val('ADMIN_USERNAME'), 'admin_pass' => env_val('ADMIN_PASSWORD'), 'seed_shop' => $seed]);
    if ($r['ok'] || time() >= $deadline) break;
    fwrite(STDERR, "Waiting for the database...\n");
    sleep(3);
} while (true);

foreach ($r['steps'] as [$label, $ok, $detail]) {
    echo ($ok ? '[ ok ] ' : '[FAIL] ') . $label . ($detail !== '' ? " - $detail" : '') . "\n";
}
if ($r['admin_pass'] !== null) {
    echo "\n==================================================\n";
    echo " First login (shown ONCE, write it down now):\n";
    echo "   username: {$r['admin_user']}\n   password: {$r['admin_pass']}\n";
    echo " Change it after signing in (user menu > Change password).\n";
    echo "==================================================\n";
}
exit($r['ok'] ? 0 : 1);
