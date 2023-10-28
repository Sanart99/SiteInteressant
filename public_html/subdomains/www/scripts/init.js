<?php
$libDir = '../../../lib';
require_once $libDir.'/utils/utils.php';
dotenv();

$debug = (int)$_SERVER['LD_DEBUG'];
$isAuth = isset($_COOKIE['sid']) ? 'true' : 'false';

header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
var __authenticated = $isAuth;
var __debug = $debug;
var __feat_serviceWorker = 'serviceWorker' in navigator;
var __feat_notifications = 'body' in Notification?.prototype;
var __settingsInitialized = false;
var __online = null;

navigator.onLine ? switchToOnline() : switchToOffline();
__authenticated ? switchToAuthenticated() : switchToNotAuthenticated();

(async () => {
    if (__feat_serviceWorker) {
        if (!__online || !__authenticated) return;

        try {
            const registration = await navigator.serviceWorker.register("/scripts/sw/sw1.js", {scope:'/',updateViaCache:'none'});
            if (registration.installing) console.info("Service worker installing");
            else if (registration.waiting) console.info("Service worker installed");
            else if (registration.active) {
                console.info("Service worker active");
                navigator.serviceWorker.addEventListener('message', (s) => {
                    if (s?.data == 'updateServiceWorker') { registration.update(); }
                });

                sendQuery(`query { version:getServiceWorkerName }`).then((res) => {
                    registration.active.postMessage(JSON.stringify({
                        action:{ verifyVersion:res.data.version }
                    }));
                });
            }
        } catch (error) {
            console.error(`Registration failed with \${error}`);
        }
    }
})();

function initLate() {
    if (__authenticated) {
        if (__online) {
            loadGlobalSettings().then(() => {
                if (__feat_serviceWorker) {
                    syncSettingsWithServiceWorker();
                    initPushSubscription();
                }
                __settingsInitialized = true;
            });
        } else {
            __settingsInitialized = true;
        }
    }
}
JAVASCRIPT;
?>