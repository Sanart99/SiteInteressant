<?php
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
JAVASCRIPT;
?>