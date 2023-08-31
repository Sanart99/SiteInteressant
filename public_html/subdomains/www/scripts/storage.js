<?php
header('Content-Type: text/javascript');
echo <<<JAVASCRIPT
const globalMap = new Map();
let testedTypes = [];
function storageAvailable(sType) {
    let storage;
    try {
        storage = window[sType];
        const x = "__storage_test__";
        storage.setItem(x, x);
        storage.removeItem(x);
        testedTypes.push(sType);
        return true;
    } catch (e) {
        return false;
    }
}

function sessionSet(key,value) {
    if (testedTypes.indexOf("sessionStorage") != -1 || storageAvailable("sessionStorage")) {
        sessionStorage.setItem(key,value);
        return true;
    } else return false;
}
function sessionGet(key) {
    if (testedTypes.indexOf("sessionStorage") != -1 || storageAvailable("sessionStorage")) {
        return sessionStorage.getItem(key);
    } else return undefined;
}
function sessionRem(key) {
    if (testedTypes.indexOf("sessionStorage") != -1 || storageAvailable("sessionStorage")) {
        sessionStorage.removeItem(key);
        return true;
    } else return false;
}

function sessionRemAll(pattern) {
    if (pattern != null) {
        let toRemove = [];
        for (let i=0; i<sessionStorage.length; i++) {
            let k = sessionStorage.key(i);
            let regex = new RegExp(pattern);
            if (regex.test(k)) toRemove.push(k);
        }
        for (k of toRemove) sessionRem(k);
    } else sessionStorage.clear();
}

function localSet(key,value) {
    if (testedTypes.indexOf("localStorage") != -1 || storageAvailable("localStorage")) {
        localStorage.setItem(key,value);
        return true;
    } else return false;
}
function localGet(key) {
    if (testedTypes.indexOf("localStorage") != -1 || storageAvailable("localStorage")) {
        return localStorage.getItem(key);
    } else return undefined;
}

function localFindKeys(pattern) {
    let a = [];
    for (let i=0; i<sessionStorage.length; i++) {
        let k = sessionStorage.key(i);
        let m = pattern.exec(k);
        if (m != null) a.push(m);
    }
    return a;
}

function getCookie(name) {
    const regex = new RegExp(`(?:^|;\\s*)\${name}=([^;]*)`);
    const v = regex.exec(document.cookie);
    return v == null ? null : v[1];
}
JAVASCRIPT;
?>