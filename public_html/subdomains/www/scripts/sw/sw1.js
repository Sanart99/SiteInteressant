<?php
$libDir = '../../../../lib';
require_once $libDir.'/utils/utils.php';

$api = get_root_link('api');
$graphql = "$api/graphql.php";

header('Content-Type: text/javascript');

$urlRegex1 = '/^\/(?:(?:index|forum|home|usersettings|versionhistory)(?:\.php)?|style\.css|styleReset\.css)$/';
$urlRegex2 = '/^\/scripts\/(?:init|load|quick|router|storage|settings|(?:sw\/manager))\.js$/';
$urlRegex3 = '/^\/scripts\/gen\/(?:main|popup)\.js$/';
$urlRegex4 = '/^\/pages\/(?:forum|home|usersettings|versionhistory)(?:\.php)?$/';
$trimRegex = '/^(.*)\.php$/';

echo <<<JAVASCRIPT
const swName = 'sw1_v1.0';
const permissions = {};
let settingsSynced = false;
let authenticated = true; //?
let online = false; //?
const __feat_cacheStorage = 'caches' in self;
const __feat_notifications = 'body' in Notification?.prototype;

self.addEventListener('install', (event) => {
    if (!__feat_cacheStorage) return;
    event.waitUntil(replenishCache().then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    if (!__feat_cacheStorage) return;

    const keep = [swName];
    event.waitUntil((async () => {
        const keys = await caches.keys();
        const promises = [];
        for (const k of keys) for (const s of keep) if (k != s) promises.push(caches.delete(k));
        await Promise.all(promises);
        console.info("Name: "+swName);
        await self.clients.matchAll({type: 'window'}).then((tabs) => {
            for (const tab of tabs) tab.navigate(tab.url);
        });
        self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    if (!__feat_cacheStorage) return;

    const url = new URL(event.request.url);
    if (
        $urlRegex1.exec(url.pathname) != null || $urlRegex2.exec(url.pathname) != null ||
        $urlRegex3.exec(url.pathname) != null || $urlRegex4.exec(url.pathname) != null
    ) {
        event.respondWith((async () => {
            const c = await caches.open(swName);
            const vTrim = $trimRegex.exec(url.pathname);
            let res = null;
            if (vTrim != null) res = await c.match(vTrim[1]);
            else res = await c.match(url.pathname);
            return res == null ? fetch(url.href) : res;
        })());
    }
});

self.addEventListener('push', async (event) => {
    if (!settingsSynced) {
        await sendQuery(`query {
            viewer {
                settings {
                    notificationsEnabled
                }
            }
        }`).then((json) => {
            if (json?.data?.viewer?.settings == null) { return; }
            const settings = json.data.viewer.settings;
            permissions.notifications = settings.notificationsEnabled;
            settingsSynced = true;
        });
    }

    let data = null;
    try { data = event.data?.json(); }
    catch (e) { console.error(e); return; }

    if (data?.notifications != null && Array.isArray(data.notifications)) for (const notif of data?.notifications) {
        await self.clients.claim();
        self.clients.matchAll().then(clients => {
            clients.forEach(client => client.postMessage('checkNotifs'));
        });

        if (!__feat_notifications || Notification.permission !== 'granted' || permissions?.notifications !== true) return;
        
        const options = {};
        if (notif?.title == null) continue;
        if (notif?.body != null) options.body = notif.body;
        if (notif?.icon != null) options.icon = notif.icon;

        try { self.registration.showNotification(notif.title, options); }
        catch (e) { console.error(e); continue; }
    }
});

self.addEventListener('message',(event) => {
    let data = null;
    try { data = JSON.parse(event.data); }
    catch (e) { console.error(e); return; }

    if (data?.setPermissions != null) for (const k in data.setPermissions) {
        const v = data.setPermissions[k];
        permissions[k] = v;
        console.info('Permission set: ' + k + '=' + v);
    }
    if (data?.setParam != null) for (const k in data.setParam) switch (k) {
        case 'authenticated':
            authenticated = data.setParam[k];
            break;
        case 'online':
            online = data.setParam[k];
            break;
        default:
            break;
    }
    if (data?.action != null) for (const k in data.action) switch (k) {
        case 'emptyCache': emptyCache(); break;
        case 'replenishCache': replenishCache(); break;
    }
});

async function emptyCache() {
    return;
    const cache = await caches.open(swName);
    for (const key of (await cache.keys())) cache.delete(key);
}

async function replenishCache() {
    return;
    const cache = await caches.open(swName);
    if ((await cache.keys()).length > 2) return;  
    return cache.addAll([
        '/pages/forum',
        '/pages/home',
        '/pages/usersettings',
        '/pages/versionhistory',
        '/scripts/gen/main.js',
        '/scripts/gen/popup.js',
        '/scripts/sw/manager.js',
        '/scripts/init.js',
        '/scripts/load.js',
        '/scripts/quick.js',
        '/scripts/router.js',
        '/scripts/settings.js',
        '/scripts/storage.js',
        '/index',
        '/style.css',
        '/styleReset.css',
        '/forum',
        '/home',
        '/usersettings',
        '/versionhistory'
    ]);
}

function sendQuery(query, variables, headers, operationName, moreOptions, moreData) {
    let options = {
        method: 'POST',
        credentials: 'include',
        ...moreOptions
    };

    if (moreData == null) {
        options.headers = headers == null ? { 'Content-Type':'application/json', 'Cache-Control':'no-cache' } : headers;
        options.body = JSON.stringify({'query':query, 'variables':variables, 'operationName':operationName});
    } else {
        const data = new FormData();
        data.append('gqlQuery',JSON.stringify({'query':query}));
        if (variables != null) data.append('gqlVariables',JSON.stringify(variables));
        if (operationName != null) data.append('gqlOperationName',operationName);
        for (const k in moreData) data.append(k,moreData[k]);

        options.headers = headers == null ? { 'Cache-Control':'no-cache' } : headers;
        options.body = data;
    }

    return fetch("$graphql",options).then((res) => {
        if (!res.ok) return (async () => {});
        else return res.json();
    }).catch(async (e) => {
        console.error(e);
    });
}

async function sendMessageToOne(msg) {
    await self.clients.claim();
    self.clients.matchAll().then(clients => {
        clients[0]?.postMessage(msg);
    });
}

async function sendMessageToAll(msg) {
    await self.clients.claim();
    self.clients.matchAll().then(clients => {
        clients.forEach(client => client.postMessage(msg));
    });
}
JAVASCRIPT;
?>