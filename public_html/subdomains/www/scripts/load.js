<?php
$libDir = __DIR__.'/../../../lib';
require_once $libDir.'/utils/utils.php';
dotenv();

$graphql = $_SERVER['LD_LINK_GRAPHQL'];
$root = get_root_link();

header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
var map = new Map();
const dynMap = new Map();

function loadScript(src, callback) {
    var srcCount = Array.isArray(src) ? src.length : 1;
    var loadingDone = 0;
    function oneDone() {
        loadingDone++;
        if (loadingDone == srcCount) {
            if (__debug) console.log("All loaded.");
            if (callback != null) callback();
        }
    }
        
    for (let i = 0; i < srcCount; i++) {
        const s = srcCount > 1 ? src[i] : src;

        var check = map.get(s);
        if (check != undefined) {
            if (__debug) console.log(`Script already loaded(\${check.isLoaded}): "\${s}".`);
            oneDone();
            continue;
        }

        var e = document.createElement("script");
        e.type = "text/javascript";
        e.src = s;
        document.getElementsByTagName("head")[0].appendChild(e);
        
        var o = { isLoaded:false };
        map.set(s,o);
        e.onload = function() {
            if (__debug) console.log(`Script loaded: \${s}`);
            o.isLoaded = true;
            oneDone();
        }
    }
}

async function importScript(src, callback) {
    var srcCount = Array.isArray(src) ? src.length : 1;
    var nImported = 0;
    const result = [];
    function oneDone(module) {
        result.push(module);
        nImported++;
        if (nImported == srcCount) {
            if (__debug) console.log("All imported.");
            if (callback != null) callback(result);
        }
    }

    for (let i = 0; i < srcCount; i++) {
        const s = srcCount > 1 ? src[i] : src;

        var check = dynMap.get(s);
        if (check != undefined) {
            if (__debug) console.log(`Script already imported: "\${s}".`);
            oneDone(check.module);
        }

        if (__debug) console.log(`Importing script: \${s}`);
        var module = await import(src);
        var o = { module:module };
        dynMap.set(s,o);
        oneDone(module);
    }
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
        if (!navigator.onLine) { await switchToOffline(); return new Response('{}', {status:503}); }
        throw e;
    });
}

function switchToOffline() {
    if (!__online) return;
    console.log('You are offline.');
    __online = false;
    getActiveServiceWorker()?.then((res) => res.postMessage(JSON.stringify({ setParam:{ 'online':__online } })));
}

function switchToOnline() {
    if (__online) return;
    console.log('You are online.');
    __online = true;
    getActiveServiceWorker()?.then((res) => res.postMessage(JSON.stringify({ setParam:{ 'online':__online }, action:{ replenishCache:null } })));
}

function switchToAuthenticated() {
    if (__authenticated) return;
    __authenticated = true;
    getActiveServiceWorker()?.then((res) => res.postMessage(JSON.stringify({ setParam:{ 'authenticated':__authenticated }, action:{ replenishCache:null } })));
}

function switchToNotAuthenticated() {
    if (!__authenticated) return;
    __authenticated = false;
    getActiveServiceWorker()?.then((res) => res.postMessage(JSON.stringify({ setParam:{ 'authenticated':__authenticated }, action:{ emptyCache:null } })));
    if (location.href != "$root/home") location.href = "$root/home";
}

addEventListener('offline',() => switchToOffline());
addEventListener('online',() => switchToOnline());
JAVASCRIPT;
?>