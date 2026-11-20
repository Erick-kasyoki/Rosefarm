<?php
// App shell. Adds each file's last-changed time to the CSS/JS links, so browsers load the newest version after an update
// (and can safely cache them for a year in between).
require __DIR__ . '/api/bootstrap.php';
header('Cache-Control: no-cache, must-revalidate');
send_security_headers(true);
$v = max(filemtime(__DIR__ . '/assets/app.js'), filemtime(__DIR__ . '/assets/style.css'));
$v = date('Ymd-His', $v);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Point of Sale</title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="stylesheet" href="assets/style.css?v=<?= $v ?>">
</head>
<body data-version="<?= htmlspecialchars($v, ENT_QUOTES) ?>">
<div id="app"></div>
<div id="modal-root"></div>
<div id="toast"></div>
<div id="receipt-print"></div>
<noscript><p style="padding:24px;font-family:sans-serif">This system needs JavaScript. Please enable it in your browser.</p></noscript>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
