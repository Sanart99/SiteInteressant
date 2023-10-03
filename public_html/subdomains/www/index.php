<?php
$libDir = __DIR__.'/../../lib';
$scriptsLib = 'scripts';
ob_start();
require_once $libDir.'/utils/utils.php';
require_once $scriptsLib.'/gen/main.js';
require_once $scriptsLib.'/gen/popup.js';
ob_end_clean();

$root = get_root_link();

$loadPage = '';
$qString = '';
foreach ($_GET as $k => $v) {
    if ($k == 'loadPage') { $loadPage = "$v?"; continue; } 
    $qString .= strlen($qString) == 0 ? "$k=$v" : "&$k=$v";
}
$loadPageURL = $loadPage.$qString;

$scriptsToLoad = ['storage.js','sw/manager.js','load.js','init.js','quick.js','router.js','settings.js','gen/popup.js'];
header('Content-Type: text/html');
?>
<!DOCTYPE html>

<html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="stylesheet" href="<?php echo $root ?>/styleReset.css" type="text/css">
		<link rel="stylesheet" href="<?php echo $root ?>/style.css" type="text/css">
        <title>Site Interessant</title>

        <?php foreach ($scriptsToLoad as $s) echo "<script src=\"$root/scripts/$s\"></script>" ?>
    </head>

    <body>
        <?= getIndexElems()['html']; ?>
        <?= getPopupDiv()['html']; ?>
        <div id="bodyDiv" style="min-height: 100vh;">
            <p>Index.php</p>
        </div>
    </body>

    <script>
        initLate();
        
        const elem = document.querySelector('#bodyDiv');
        configRouter(elem);
        
        loadPage("<?php echo $loadPageURL; ?>",StateAction.ReplaceState);
        addEventListener("popstate", (event) => {
            let url = history.state?.pageUrl;
            loadPage(url == null ? window.location : url);
        });

        <?= getPopupDiv()['js']; ?>
        <?php if (!isset($_COOKIE['sid'])): ?>
        popupDiv.insertAdjacentHTML('beforeend',`<?= getConnexionForm()['html']; ?>`);
        popupDiv.openTo('#connexionForm');
        <?= getConnexionForm()['js']; ?>
        connexionForm.openTo('#connexionForm_connect');
        <?php endif; ?>

        <?= getIndexElems()['js']; ?>
	</script>
</html>