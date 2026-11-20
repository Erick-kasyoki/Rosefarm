<?php
header('Cache-Control: no-store');
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
$base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($base === '.' || $base === '/' || $base === '') $base = '';
header('Location: ' . ($base === '' ? '/pos/' : $base . '/pos/'), 302);
exit;
