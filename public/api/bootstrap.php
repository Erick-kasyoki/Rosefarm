<?php
/**
 * Runtime bootstrap shared by index.php (the app shell) and api/index.php (the JSON API):
 * configuration, PHP settings, timezone, security headers and hardened sessions.
 */
$cfg = require dirname(__DIR__, 2) . '/config/config.php';

function cfg(string $key, $default = null) { global $cfg; return $cfg[$key] ?? $default; }
function is_prod(): bool { return cfg('env') === 'production'; }

function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return cfg('trust_proxy') && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// The address of the visitor. Behind a proxy you control, the proxy appends the real address LAST in X-Forwarded-For.
function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (cfg('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $last = end($parts);
        if ($last !== false && filter_var($last, FILTER_VALIDATE_IP)) $ip = $last;
    }
    return substr($ip, 0, 45);
}

ini_set('display_errors', is_prod() ? '0' : '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);
if (!@date_default_timezone_set((string)cfg('timezone', 'Africa/Nairobi'))) date_default_timezone_set('UTC');

function send_security_headers(bool $html = false): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
    if ($html) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; "
             . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    }
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $hours = (int)cfg('session_hours', 12);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)($hours * 3600));
    session_name('POSSESSID');
    // Cookie is limited to the folder the app lives in, so other apps on the same host cannot read it.
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'))), '/');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => $base === '' ? '/' : $base . '/',
        'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
    // Idle timeout: a till left open overnight does not stay signed in.
    $now = time();
    if (!empty($_SESSION['last']) && $now - (int)$_SESSION['last'] > $hours * 3600) { $_SESSION = []; }
    $_SESSION['last'] = $now;
}
