<?php
ob_start();
$scriptsLib = '../scripts';
require_once $scriptsLib.'/gen/main.js';
ob_end_clean();
?>

<?= getHomeMainDiv()['html']; ?>

<script>

</script>