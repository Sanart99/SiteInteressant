<?php
$libDir = __DIR__.'/../../../lib';
require_once $libDir.'/utils/utils.php';
$root = get_root_link();
$rootForRegex = str_replace('/','\/',str_replace(['.'],'\.',$root));

header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
let _routerElement = null;
let _urlFormatter = null;

const StateAction = {
    None:-1,
    PushState:0,
    ReplaceState:1
}

class RouterError extends Error {
    constructor(message) {
        super(message);
        this.name = "RouterError";
    }
}

class LinkInterceptor {
    static preProcesses = [];
    static midProcesses = [];
    static namesTaken = new Map();

    static addPreProcess(name,f,priority) {
        if (LinkInterceptor.namesTaken.get(name) == true) return false;
        LinkInterceptor.namesTaken.set(name,true);

        LinkInterceptor.preProcesses.push({name:name,f:f,priority:priority});
        LinkInterceptor.preProcesses.sort((a,b) => {
            if (a.priority > b.priority) return -1;
            if (a.priority < b.priority) return 1;
            return 0;
        });
        return true;
    }

    static addMidProcess(name,f,priority,replace=true) {
        if (LinkInterceptor.namesTaken.get(name) == true) {
            if (replace) {
                for (const o of LinkInterceptor.midProcesses)
                    if (o.name == name) { LinkInterceptor.midProcesses.splice(LinkInterceptor.midProcesses.indexOf(o),1); break; }
            } else return false;
        }
        LinkInterceptor.namesTaken.set(name,true);

        LinkInterceptor.midProcesses.push({name:name,f:f,priority:priority});
        LinkInterceptor.midProcesses.sort((a,b) => {
            if (a.priority > b.priority) return -1;
            if (a.priority < b.priority) return 1;
            return 0;
        });
        return true;
    }
}

function setUrlFormatter(urlFormatter) {
    _urlFormatter = urlFormatter != null ? urlFormatter : function(url) {
        var res = /^(?:$rootForRegex)?\/pages\/([^?]*).*?(?:(?:\?|&)urlEnd=(.+))?$/.exec(url);
        if (res == null) {
            if (__debug) console.log('urlFormatter regex failed');
            return url;
        }

        const afterRoot = res[1].endsWith('.php') ? res[1].substr(0,res[1].length-4) : res[1];
        var displayedURL = `$root/\${afterRoot}`;
        if (res[2] != undefined) displayedURL += `\${res[2]}`;
        if (__debug) console.log(`urlFormatter: \${url} -> \${displayedURL}`);
        return displayedURL;
    };
}

function configRouter(rootElem, urlFormatter = null) {
    _routerElement = rootElem;
    setUrlFormatter(urlFormatter);
}

async function loadPage(url, stateAction=-1, urlFormatter = null, nonOkResponseHandler = null) {
    for (const o of LinkInterceptor.preProcesses) {
        url = o.f(url,stateAction);
        if (url === false) return;
    }

    return fetch(url, {cache: "no-cache"}).then((response) => {
        if (!response.ok) {
            if (nonOkResponseHandler == null) throw `Failed to load '\${url}'.`;
            else nonOkResponseHandler(url, stateAction);
        }
        return response.text();
    }).then((text) => {
        if (__debug) console.log("loading page at: "+url);

        displayedURL = urlFormatter == null ? _urlFormatter(url) : urlFormatter(url);
        for (const o of LinkInterceptor.midProcesses) if (o.f(url,displayedURL,stateAction) == true) return;

        switch (stateAction) {
            case StateAction.PushState: history.pushState({pageUrl:url}, "", displayedURL); break;
            case StateAction.ReplaceState: history.replaceState({pageUrl:url}, "", displayedURL); break;
            default: break;
        }

        _routerElement.innerHTML = "";
        const template = document.createElement("template");
        template.innerHTML = text.trim();
        template.content.childNodes.forEach(cNode => {
            if (cNode.tagName == undefined) {
                if (__debug && cNode.nodeName != "#comment") console.warn("Undefined tag: " + cNode.nodeName);
                return;
            }

            if (cNode.tagName == "SCRIPT") {
                var scrE = document.createElement("script");
                scrE.innerHTML = cNode.innerHTML;
                scrE.type = "module";
                scrE.async = true;
                _routerElement.appendChild(scrE);
            } else _routerElement.appendChild(cNode);
        });

        return url;
    });
}
JAVASCRIPT;
?>