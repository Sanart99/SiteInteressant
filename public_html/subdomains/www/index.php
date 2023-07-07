<?php
$libDir = __DIR__.'/../../lib';
require_once $libDir.'/utils/utils.php';

$root = get_root_link();

$loadPage = '';
$qString = '';
foreach ($_GET as $k => $v) {
    if ($k == 'loadPage') { $loadPage = "$v?"; continue; } 
    $qString .= strlen($qString) == 0 ? "$k=$v" : "&$k=$v";
}
$loadPageURL = $loadPage.$qString;

$scriptsToLoad = ['init.js','quick.js','router.js','load.js'];
?>
<!DOCTYPE html>

<html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Site Interessant</title>

        <?php foreach ($scriptsToLoad as $s) echo "<script src=\"$root/scripts/$s\"></script>" ?>
    </head>

    <body>
        <p>Index.php</p>
    </body>

    <script>
        configRouter(document.querySelector('body'));
        
        let get = "<?php echo $loadPageURL; ?>";
        document.querySelector('body').innerHTML = get;   
        loadPage(get);

        addEventListener("popstate", (event) => {
            let url = history.state.pageUrl;
            loadPage(url);
        });
	</script>
</html>