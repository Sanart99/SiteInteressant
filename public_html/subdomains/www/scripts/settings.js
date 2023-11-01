<?php
header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
async function loadGlobalSettings(fromServer=true) {    
    return sendQuery(`query {
        viewer {
            settings {
                forum_autoMarkPagesAsRead
                forum_followThreadsOnComment
                notificationsEnabled
                notif_newThread
                notif_newCommentOnFollowedThread
            }
        }
    }`).then((json) => {
        if (json?.data?.viewer?.settings == null) { basicQueryResultCheck(); return; }
        const settings = json.data.viewer.settings;
        localSet('settings_forum_autoMarkPagesAsRead',settings.forum_autoMarkPagesAsRead);
        localSet('settings_forum_followThreadsOnComment',settings.forum_followThreadsOnComment);
        localSet('settings_notifications',settings.notificationsEnabled);
        localSet('settings_notif_newThread',settings.notif_newThread);
        localSet('settings_notif_newCommentOnFollowedThread',settings.notif_newCommentOnFollowedThread);
    });
}

async function syncSettingsWithServiceWorker() {
    return getActiveServiceWorker().then((sw) => sw.postMessage(JSON.stringify({
        'setPermissions':{
            'forum_autoMarkPagesAsRead':localGet('settings_forum_autoMarkPagesAsRead') === 'true',
            'notifications':localGet('settings_notifications') === "true",
            'device_notifications':localGet('settings_device_notifications') === "true",
            'notif_newThread':localGet('settings_notif_newThread') === "true",
            'notif_newCommentOnFollowedThread':localGet('settings_notif_newCommentOnFollowedThread') === "true"
        }
    })));
}

JAVASCRIPT;
?>