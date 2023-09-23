<?php
$libDir = '../../../lib';
require_once $libDir.'/utils/utils.php';
dotenv();

$debug = (int)$_SERVER['LD_DEBUG'];
header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
var __debug = $debug;
var __feat_serviceWorker = false;
var __feat_notifications = 'body' in Notification?.prototype;

(async () => {
    if ("serviceWorker" in navigator) {
        console.info("Feature: Service worker");
        try {
            const registration = await navigator.serviceWorker.register("/scripts/sw/sw1.js", { scope: "/" });
            if (registration.installing) console.info("Service worker installing");
            else if (registration.waiting) console.info("Service worker installed");
            else if (registration.active) console.info("Service worker active");

            navigator.serviceWorker.ready.then(() => { __feat_serviceWorker = true; });
        } catch (error) {
            console.error(`Registration failed with \${error}`);
        }
    }
})();
JAVASCRIPT;
?>