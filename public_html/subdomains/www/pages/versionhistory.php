<?php
$libDir = __DIR__.'/../../../lib';
require_once $libDir.'/utils/utils.php';
$root = get_root_link();
if (!isset($_COOKIE['sid'])) { header("Location: $root"); exit; }

ob_start();
$scriptsLib = '../scripts';
require_once $scriptsLib.'/gen/main.js';
ob_end_clean();

header('Content-Type: text/html');
?>

<?= getVersionHistoryElem()['html']; ?>