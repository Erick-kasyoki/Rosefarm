<?php
/**
 * Application configuration.
 *
 * Precedence (later wins):   defaults below  <  config/config.local.php  <  environment variables
 *
 * For a real deployment set environment variables (see .env.example / DEPLOYMENT.md).
 * On hosting without environment variables (XAMPP, cPanel), copy config.local.php.example
 * to config.local.php and edit it. Never put real passwords in this file.
 *
 * NOTE: PostgreSQL names are case-sensitive: 'pos_db' and 'Pos' are two different databases.
 */
if (!function_exists('env_val')) {
    function env_val(string $key, $default = null) {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}
if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default): bool {
        $v = env_val($key);
        if ($v === null) return $default;
        return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
    }
}

$cfg = [
    'env'                => 'production',        // 'production' (safe default) or 'development' (XAMPP / local)
    'debug'              => false,               // show technical error details to the browser (development only)
    'host'               => 'localhost',
    'port'               => 5432,
    'dbname'             => 'pos_db',
    'user'               => 'postgres',
    'password'           => '',
    'sslmode'            => null,                // e.g. 'require' for managed PostgreSQL (Neon, Supabase, RDS...)
    'timezone'           => 'Africa/Nairobi',
    'trust_proxy'        => false,               // true only when behind ONE reverse proxy you control (Caddy, nginx, Cloudflare Tunnel)
    'session_hours'      => 12,
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $o = require $local;
    if (is_array($o)) $cfg = array_replace($cfg, $o);
}

// DATABASE_URL (postgres://user:pass@host:5432/dbname?sslmode=require) is what most hosting platforms provide.
if ($url = env_val('DATABASE_URL')) {
    $p = parse_url($url);
    if ($p && isset($p['host'])) {
        $cfg['host']   = $p['host'];
        $cfg['port']   = (int)($p['port'] ?? 5432);
        $cfg['user']   = isset($p['user']) ? urldecode($p['user']) : $cfg['user'];
        $cfg['password'] = isset($p['pass']) ? urldecode($p['pass']) : $cfg['password'];
        $cfg['dbname'] = isset($p['path']) ? ltrim(urldecode($p['path']), '/') : $cfg['dbname'];
        if (!empty($p['query'])) { parse_str($p['query'], $qs); if (!empty($qs['sslmode'])) $cfg['sslmode'] = $qs['sslmode']; }
    }
}

$map = ['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_NAME' => 'dbname', 'DB_USER' => 'user', 'DB_PASSWORD' => 'password',
        'DB_SSLMODE' => 'sslmode', 'APP_TIMEZONE' => 'timezone', 'APP_ENV' => 'env'];
foreach ($map as $envKey => $cfgKey) {
    $v = env_val($envKey);
    if ($v !== null) $cfg[$cfgKey] = $cfgKey === 'port' ? (int)$v : $v;
}
$cfg['debug']              = env_bool('APP_DEBUG', (bool)$cfg['debug']);
$cfg['trust_proxy']        = env_bool('TRUST_PROXY', (bool)$cfg['trust_proxy']);
$cfg['session_hours']      = max(1, (int)env_val('SESSION_HOURS', $cfg['session_hours']));
$cfg['port']               = (int)$cfg['port'];

return $cfg;
