<?php
$libDir = __DIR__.'/../../../lib';
require_once $libDir.'/utils/utils.php';
$root = get_root_link();

echo <<<JAVASCRIPT
let _routerElement = null;
let _urlFormatter = null;
let _lastLoadedPage = "";

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

function setUrlFormatter(urlFormatter) {
    _urlFormatter = urlFormatter != null ? urlFormatter : function(url) {
        var res = /^.*?\/pages\/(.*)\.php.*(?:(?:\?|&)urlEnd=)(.*)$/.exec(url);
        if (res == null) {
            if (__debug) console.log('urlFormatter regex failed');
            return url;
        }

        var displayedURL = `$root/\${res[1]}`;
        if (res[2] != undefined) displayedURL += `\${res[2]}`;
        if (__debug) console.log(`urlFormatter: \${url} -> \${displayedURL}`);
        return displayedURL;
    };
}

function configRouter(rootElem, urlFormatter = null) {
    _routerElement = rootElem;
    setUrlFormatter(urlFormatter);
}

function loadPage(url, stateAction=-1, urlFormatter = null, nonOkResponseHandler = null) {
    fetch(url, {cache: "no-cache"}).then((response) => {
        if (!response.ok) {
            if (nonOkResponseHandler == null) throw `Failed to load '\${url}'.`;
            else nonOkResponseHandler(url, stateAction);
        }
        return response.text();
    }).then((text) => {
        if (_lastLoadedPage == url) return url;
        if (__debug) console.log("loading page at: "+url);

        _lastLoadedPage = url;
        _routerElement.innerHTML = "";
        displayedURL = urlFormatter == null ? _urlFormatter(url) : urlFormatter(url);

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
        
        switch (stateAction) {
            case StateAction.PushState: history.pushState({pageUrl:url}, "", displayedURL); break;
            case StateAction.ReplaceState: history.replaceState({pageUrl:url}, "", displayedURL); break;
            default: break;
        }

        return url;
    });
}
JAVASCRIPT;
?>