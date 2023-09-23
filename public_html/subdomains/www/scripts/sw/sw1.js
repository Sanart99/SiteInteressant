<?php
header('Content-Type: text/javascript');

$urlRegex1 = '/^\/(?:(?:index|forum|home|versionhistory)(?:\.php)?|style\.css|styleReset\.css)$/';
$urlRegex2 = '/^\/scripts\/(?:init|load|quick|router|storage)\.js$/';
$urlRegex3 = '/^\/scripts\/gen\/(?:main|popup)\.js$/';
$urlRegex4 = '/^\/pages\/(?:forum|home|versionhistory)(?:\.php)?$/';
$trimRegex = '/^(.*)\.php$/';

echo <<<JAVASCRIPT
const swName = 'sw1_v1.0';
const __feat_cacheStorage = 'caches' in self;
const __feat_notifications = 'body' in Notification?.prototype;

self.addEventListener('install', (event) => {
    if (!__feat_cacheStorage) return;
    event.waitUntil(caches.open(swName).then(c => c.addAll([
        '/pages/forum',
        '/pages/home',
        '/pages/versionhistory',
        '/scripts/gen/main.js',
        '/scripts/gen/popup.js',
        '/scripts/init.js',
        '/scripts/load.js',
        '/scripts/quick.js',
        '/scripts/router.js',
        '/scripts/storage.js',
        '/index',
        '/style.css',
        '/styleReset.css',
        '/forum',
        '/home',
        '/versionhistory'
    ])));
});

self.addEventListener('activate', (event) => {
    if (!__feat_cacheStorage) return;

    const keep = [swName];
    event.waitUntil((async () => {
        const keys = await caches.keys();
        const promises = [];
        for (const k of keys) for (const s of keep) if (k != s) promises.push(caches.delete(k));
        await Promise.all(promises);
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

self.addEventListener('push', (event) => {
    if (!__feat_notifications || Notification.permission !== 'granted') return;

    let data = null;
    try { data = event.data?.json(); }
    catch (e) { console.error(e); return; }

    if (data?.notifications != null && Array.isArray(data.notifications)) for (const notif of data?.notifications) {
        const options = {};
        if (notif?.title == null) continue;
        if (notif?.body != null) options.body = notif.body;

        try { self.registration.showNotification(notif.title, options); }
        catch (e) { console.error(e); continue; }
    }
});
JAVASCRIPT;
?>