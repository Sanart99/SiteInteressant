<?php
$libDir = '../../../lib';
require_once $libDir.'/utils/utils.php';
dotenv();

$debug = (int)$_SERVER['LD_DEBUG'];
header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
var __debug = $debug;
JAVASCRIPT;
?>