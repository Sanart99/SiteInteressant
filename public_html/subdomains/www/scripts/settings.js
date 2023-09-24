<?php
header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
async function initGlobalSettings() {
    return sendQuery(`query {
        viewer {
            settings {
                notificationsEnabled
            }
        }
    }`).then((res) => {
        if (!res.ok) basicQueryResultCheck();
        return res.json();
    }).then((json) => {
        if (json?.data?.viewer?.settings == null) basicQueryResultCheck();
        const settings = json.data.viewer.settings;
        console.log(settings.notificationsEnabled);
        localSet('settings_notifications',settings.notificationsEnabled);
    });
}

async function syncSettingsWithServiceWorker() {
    return (await getActiveServiceWorker())?.postMessage(JSON.stringify({
        'setPermissions':{
            'notifications':localGet('settings_notifications') === "true",
            'device_notifications':localGet('settings_device_notifications') === "true"
        }
    }));
}

JAVASCRIPT;
?>