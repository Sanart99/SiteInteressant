<?php
header('Content-Type: text/javascript');
$libDir = __DIR__.'/../../../lib';
require_once $libDir.'/utils/utils.php';
dotenv();

$testMode = (int)$_SERVER['LD_TEST'] == 1 ? 'true' : 'false'; 
echo <<<JAVASCRIPT
function _(s) { return document.querySelector(s); }
function _all(s) { return document.querySelectorAll(s); }

const tpl = document.createElement("template");
function stringToNodes(s) {
    tpl.innerHTML = s;
    return tpl.content.cloneNode(true).childNodes;
}

function doubleToFloat(v) {
    return new Float32Array([v])[0];
}

function basicQueryResultCheck(operationResult, preventThrow = false) {
    if (!__online && operationResult == null) { alert('No internet connection detected.'); return false; }
    if (__authenticated && operationResult == null) {
        sendQuery('query { viewer { id } }').then((json) => {
            if (json?.data?.viewer == null) switchToNotAuthenticated();
            return;
        });
    }

    if (operationResult == null) {
        alert('Erreur interne.');
        if (preventThrow != true) throw new Error('Internal error.');
        console.error('Internal error.');
        return false;
    } else if (!operationResult.success) {
        alert(queryOperationResultMessage(operationResult))
        return false;
    }

    return true;
}

function queryOperationResultMessage(operationResult) {
    const loadedInTestMode = $testMode;
    return loadedInTestMode ? `[\${operationResult.resultCode}] \${operationResult.resultMessage}` : `\${operationResult.resultMessage}`;
}

function getDateAsString(date) {
    const a = new Intl.DateTimeFormat('fr-FR', { dateStyle:'full', timeStyle:'long' }).format(date).split(' ');
    const a2 = [];
    a2[0] = a[0].charAt(0).toUpperCase() + a[0].slice(1);
    a2[1] = a[1];
    a2[2] = a[2].charAt(0).toUpperCase() + a[2].slice(1);
    a2[3] = a[3];
    a2[4] = a[5];
    a2[5] = a[6];
    return a2;
}

function getDateAsString2(date) {
    let s = '';
    const milliDiff = Date.now()-date.getTime();
    const secondsDiff = milliDiff / 1000;
    if (secondsDiff < 60) return "Il y a moins d'une minute";
    const minutesDiff = secondsDiff / 60;
    if (minutesDiff < 60) return `Il y a \${Math.floor(minutesDiff)} min`;
    const hoursDiff = minutesDiff / 60;
    if (hoursDiff < 3) {
        let rest = Math.floor(minutesDiff%60);
        s = `Il y a \${Math.floor(hoursDiff)} h \${rest} min`;        
        return s;
    }

    const nowDate = new Date(Date.now());
    const daysDiff = hoursDiff / 24;
    const sDate = getDateAsString(date);
    if (nowDate.getDate() == date.getDate()) return `Aujourd'hui à \${sDate[4].substr(0,2)}h\${sDate[4].substr(3,2)}`;
    if (nowDate.getDate() == date.getDate() + 1) return `Hier à \${sDate[4].substr(0,2)}h\${sDate[4].substr(3,2)}`;
    return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle:'medium'}).format(date);
}

async function isServerInTestMode() {
    return await sendQuery(`query { testMode }`).then((json) => json?.data?.testMode == true);
}

JAVASCRIPT;
?>