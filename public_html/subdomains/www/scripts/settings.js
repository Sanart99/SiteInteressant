<?php
header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
async function loadGlobalSettings(fromServer=true) {    
    return sendQuery(`query {
        viewer {
            settings {
                notificationsEnabled
            }
        }
    }`).then((json) => {
        if (json?.data?.viewer?.settings == null) { basicQueryResultCheck(); return; }
        const settings = json.data.viewer.settings;
        localSet('settings_notifications',settings.notificationsEnabled);
    });
}

async function syncSettingsWithServiceWorker() {
    return getActiveServiceWorker().then((sw) => sw.postMessage(JSON.stringify({
        'setPermissions':{
            'notifications':localGet('settings_notifications') === "true",
            'device_notifications':localGet('settings_device_notifications') === "true"
        }
    })));
}

JAVASCRIPT;
?>