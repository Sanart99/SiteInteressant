<?php
ob_start();
$libDir = '../../../lib';
require_once $libDir.'/utils/utils.php';
$scriptsLib = '../scripts';
require_once $scriptsLib.'/gen/main.js';
ob_end_clean();

$root = get_root_link();

header('Content-Type: text/html');
?>

<?= getHomeMainDiv()['html']; ?>

<script>
    location.href = "<?=$root?>/forum";
</script>