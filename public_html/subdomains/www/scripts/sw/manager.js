<?php
$libDir = __DIR__.'/../../../../lib';
require_once $libDir.'/utils/utils.php';
$root = get_root_link();

header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
function getActiveServiceWorker() {
    if (!__feat_serviceWorker) return null;
    return navigator.serviceWorker.ready.then(async (reg) => await reg.active);
}

JAVASCRIPT;
?>