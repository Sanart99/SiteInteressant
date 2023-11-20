<?php
$libDir = __DIR__.'/../../lib';
$scriptsLib = 'scripts';
ob_start();
require_once $libDir.'/utils/utils.php';
require_once $scriptsLib.'/gen/main.js';
require_once $scriptsLib.'/gen/popup.js';
ob_end_clean();

$root = get_root_link();
$res = get_root_link('res');

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
        <meta id="meta_viewport" name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
        <link rel="stylesheet" href="<?php echo $root ?>/styleReset.css" type="text/css">
		<link rel="stylesheet" href="<?php echo $root ?>/style.css" type="text/css">
        <link rel="manifest" href="<?php echo $root ?>/manifest.webmanifest" />
        <link rel="icon" href="<?php echo $res ?>/icons/icon_interessant.svg"/>
        <title>Site Intéressant</title>

        <?php foreach ($scriptsToLoad as $s) echo "<script src=\"$root/scripts/$s\"></script>" ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
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

        LinkInterceptor.addPreProcess('/forum/', (url,stateAction) => {
            if (document.querySelector('#mainDiv_forum') != null) return url;
            const m = new RegExp('^<?=$root?>/forum(/\\d+?)?$').exec(url);
            if (m == null) return url;
            return `<?=$root?>/pages/forum.php?urlEnd=${m[1]}`;
        },0);

        if (localGet('settings_minusculeMode') === 'true') document.title = 'site intéressant';

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