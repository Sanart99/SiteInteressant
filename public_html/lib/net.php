<?php
namespace LDLib\Net;

require_once __DIR__."/gen.php";
require_once __DIR__."/utils/utils.php";
dotenv();

function graphql_query(string $json):array {
    $ch = curl_init($_SERVER['LD_LINK_GRAPHQL']);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type:application/json'],
        CURLOPT_POSTFIELDS => $json
    ]);
    
    $v = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (!$v) {
        if ((bool)$_SERVER['LD_LOCAL']) trigger_error(curl_error($ch));
    }

    curl_close($ch);
    return ['res' => $v,'httpCode' => $httpCode];
}

function curl_fetch(string $url, array $postFields = null) {
    $ch = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true];
    if ($postFields != null) {
        $options[CURLOPT_HTTPHEADER] = ['Content-Type:multipart/form-data'];
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $postFields;
    }
    curl_setopt_array($ch,$options);
    
    $v = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (!$v) {
        if ((bool)$_SERVER['LD_LOCAL']) trigger_error(curl_error($ch));
    }

    curl_close($ch);
    return ['res' => $v,'httpCode' => $httpCode];
}
?>