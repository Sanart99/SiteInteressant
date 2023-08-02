<?php
$libDir = '../../../lib';
require_once $libDir.'/utils/utils.php';
dotenv();

$debug = (int)$_SERVER['LD_DEBUG'];
echo <<<JAVASCRIPT
var __debug = $debug;
JAVASCRIPT;
?>