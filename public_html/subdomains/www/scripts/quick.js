<?php
header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
function _(s) { return document.querySelector(s); }
function _all(s) { return document.querySelectorAll(s); }

const tpl = document.createElement("template");
function stringToNodes(s) {
    tpl.innerHTML = s;
    return tpl.content.cloneNode(true).childNodes;
}

function basicQueryError(msg) {
    alert(msg instanceof String ? msg : 'Erreur interne.');
    throw new Error('Internal error.');
}

function getDateAsString(date) {
    const a = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'full'}).format(date).split(' ');
    a[0] = a[0].charAt(0).toUpperCase() + a[0].slice(1);
    a[2] = a[2].charAt(0).toUpperCase() + a[2].slice(1);
    return a;
}

async function isServerInTestMode() {
    return await sendQuery(`query { testMode }`).then((res) => {
        if (!res.ok) basicQueryError();
        else return res.json();
    }).then((json) => json?.data?.testMode == true);
}

JAVASCRIPT;
?>