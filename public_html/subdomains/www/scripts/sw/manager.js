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

function initPushSubscription() {
    if (!__feat_serviceWorker) return null;
    navigator.serviceWorker.ready.then(async (reg) => {
        let sub = await reg.pushManager.getSubscription();
        if (sub == null) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly:true,
                applicationServerKey:"{$_SERVER['LD_VAPID_PUBLIC_KEY']}"
            });
        }
        const json = sub.toJSON();
        sendQuery(`mutation RegisterPushSubscription(\$endpoint:String!, \$publicKey:String!, \$authToken:String!, \$expirationTime:Float) {
            registerPushSubscription(
                endpoint:\$endpoint,
                publicKey:\$publicKey,
                authToken:\$authToken,
                expirationTime:\$expirationTime,
                userVisibleOnly:true,
            ) {
                __typename
                success
                resultCode
                resultMessage
            }
        }`,{
            endpoint:json.endpoint,
            publicKey:json.keys.p256dh,
            authToken:json.keys.auth,
            expirationTime:json.expirationTime == null ? null : doubleToFloat(json.expirationTime)
        }).then((json) => {
            if (json?.data?.registerPushSubscription?.resultCode == 'DUPLICATE') return;
            basicQueryResultCheck(json?.data?.registerPushSubscription);
        });
    });
}

JAVASCRIPT;
?>