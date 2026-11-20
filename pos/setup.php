<?php
/**
 * Local installer for XAMPP / a computer you sit at.
 * Open  http://localhost/pos/setup.php  once. It creates the database and tables and your first admin login.
 *
 * It is deliberately DISABLED on a live server (APP_ENV=production, which is the default): use
 *     php bin/install.php
 * there instead (see DEPLOYMENT.md). To use this page locally, create api/config.local.php from
 * api/config.local.php.example (it sets 'env' => 'development' and your PostgreSQL password).
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/api/installer.php';
$cfg   = require __DIR__ . '/api/config.php';
$steps = [];
$fatal = null;
$done  = false;
$admin = null;

// Only from this very computer: loopback address, a localhost Host name, and no proxy headers (a reverse proxy on the
// same machine would otherwise make every visitor look like "localhost").
$ip   = $_SERVER['REMOTE_ADDR'] ?? '';
$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$proxied = isset($_SERVER['HTTP_X_FORWARDED_FOR']) || isset($_SERVER['HTTP_X_FORWARDED_HOST']) || isset($_SERVER['HTTP_FORWARDED']) || isset($_SERVER['HTTP_X_REAL_IP']);

if ($cfg['env'] === 'production') {
    $fatal = 'This page is switched off because the app is in production mode. On a server run "php bin/install.php" instead (see DEPLOYMENT.md). On your own computer (XAMPP), copy api/config.local.php.example to api/config.local.php, set your PostgreSQL password, and reload this page.';
} elseif (!in_array($ip, ['127.0.0.1', '::1'], true) || !in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || $proxied) {
    $fatal = 'For safety, the installer only runs from the server computer itself (http://localhost/pos/setup.php).';
}

if (!$fatal) {
    $r = pos_install($cfg, [
        'admin_user' => env_val('ADMIN_USERNAME'), 'admin_pass' => env_val('ADMIN_PASSWORD'),
        'seed_shop'  => isset($_GET['seed']),
    ]);
    $steps = $r['steps']; $done = $r['ok'];
    if ($r['admin_pass'] !== null) $admin = [$r['admin_user'], $r['admin_pass']];
    if (!$done) $fatal = 'Setup stopped. Fix the problem above, then reload this page.';
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Point of Sale: setup</title>
<style>
:root{--serif:"Palatino Linotype","Book Antiqua",Palatino,Georgia,serif;--sans:"Segoe UI",system-ui,sans-serif}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;font:15px/1.5 var(--sans);color:#1c2521;background:#e3e8dc;
 background-image:repeating-linear-gradient(to bottom,transparent 0 33px,rgba(23,56,47,.08) 33px 34px)}
.box{width:100%;max-width:560px;background:#fbfaf4;border:1px solid #1f4d40;box-shadow:7px 7px 0 rgba(23,56,47,.16)}
.top{background:#17382f;color:#f4efdc;padding:24px;text-align:center;position:relative}
.top::before{content:"";position:absolute;inset:8px;border:1px solid #a98430}
.top h1{margin:0;font:600 24px var(--serif)}.top p{margin:4px 0 0;font:italic 15px var(--serif);color:#dcbd6a}
.body{padding:22px 26px 26px}
ul{list-style:none;margin:0 0 16px;padding:0}
li{padding:9px 0;border-bottom:1px dashed #cdd4c5}
li b{font-family:var(--serif)}li small{display:block;color:#5b685f;margin-top:2px}
.ok b{color:#2e6b3d}.bad b{color:#8e2433}
.err{padding:12px 14px;border-left:4px solid #8e2433;background:#f6e9eb;margin-bottom:14px}
.go{display:block;text-align:center;padding:14px;border:1px solid #7d5f1e;border-radius:4px;color:#2a1f07;text-decoration:none;
 font:700 18px var(--serif);background:linear-gradient(#ead189,#c19a43 55%,#b38b36);box-shadow:0 3px 0 #7d5f1e}
.note{color:#5b685f;font-size:13px;margin-top:14px}
code{background:#e8ecdf;padding:1px 5px;border-radius:3px}
</style></head><body><div class="box">
<div class="top"><h1>Point of Sale</h1><p>Database setup</p></div>
<div class="body">
<ul>
<?php foreach ($steps as [$label, $ok, $detail]): ?>
  <li class="<?= $ok ? 'ok' : 'bad' ?>"><b><?= $ok ? 'Done: ' : 'Problem: ' ?><?= $h($label) ?></b><?php if ($detail): ?><small><?= $h($detail) ?></small><?php endif; ?></li>
<?php endforeach; ?>
</ul>
<?php if ($fatal): ?><div class="err"><?= $h($fatal) ?></div><?php endif; ?>
<?php if ($admin): ?>
  <div class="err" style="border-color:#a98430;background:#fbf3d9"><b>Your first login (shown only once, write it down):</b><br>
  Username: <code><?= $h($admin[0]) ?></code><br>Password: <code><?= $h($admin[1]) ?></code></div>
<?php endif; ?>
<?php if ($done): ?>
  <a class="go" href="./">Open the POS</a>
  <p class="note">Change your password after signing in (user menu, top right). Want the starter milk, eggs and flour products?
  Open <code>setup.php?seed=1</code> once, or press <b>Load shop products</b> on the Products page.
  You can delete <code>setup.php</code> afterwards; the app does not need it.</p>
<?php endif; ?>
</div></div></body></html>
