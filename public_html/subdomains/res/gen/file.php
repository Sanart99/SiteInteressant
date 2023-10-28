<?php
ob_start();
$libDir = '../../../lib/';
require_once $libDir.'/aws.php';
require_once $libDir.'/net.php';
require_once $libDir.'/utils/utils.php';
ob_end_clean();

use LDLib\AWS\AWS;
use Aws\Exception\AwsException;

if (preg_match('/^\/file\/(\d+_[^\/?]*)/',urldecode($_SERVER['REQUEST_URI']),$m) == 0) {
    http_response_code(404);
    echo 'File not found.';
    return;
}
$s3Key = $m[1];
$redisKey = "s3:general:$s3Key";

if (class_exists('\Redis')) {
    $redis = new \Redis();
    try {
        $redisRes = $redis->connect($_SERVER['LD_REDIS_HOST'],$_SERVER['LD_REDIS_HOST_PORT'],0.5);
    } catch (\RedisException $e) {
        $redisRes = false;
    }
    
    if ($redisRes !== false) {
        $vCache = $redis->hGetAll($redisKey);
        if ($vCache != null)  {
            if (isset($vCache['errorCode'])) {
                http_response_code($vCache['errorCode']);
                echo $vCache['errorMsg'];
            } else {
                header("Content-Type: {$vCache['mimetype']}");
                echo $vCache['data'];
            }
            return;
        }
    }
}

$s3Client = AWS::getS3Client();
$res = $s3Client->getObject($_SERVER['LD_AWS_BUCKET_GENERAL'],$s3Key);

if ($res instanceof AwsException) {
    $errMsg = '';
    $errCode = 500;
    switch ($res->getAwsErrorCode()) {
        case 'NoSuchKey': $errCode = 404; $errMsg = 'File not found.'; break;
        case 'AccessDenied': $errCode = 403; $errMsg = 'Access denied.'; break;
        default: $errMsg = $_SERVER['LD_DEBUG'] ? "AWS Error Code: {$res->getAwsErrorCode()}" : 'Unknown error.'; break;
    }

    if ($redisRes === true) {
        $redis->hMSet($redisKey,['errorCode' => $errCode, 'errorMsg' => $errMsg]);
        $redis->expire($redisKey,15);
    }

    http_response_code($errCode);
    echo $errMsg;
    return;
}

$mimeType = $res['ContentType'];
$data = strval($res['Body']);

if ($redisRes === true && $res['ContentLength'] <= 25000000) {
    $redis->hMSet($redisKey,['mimetype' => $mimeType, 'data' => $data]);
    $redis->expire($redisKey,3600);
}

header("Content-Type: $mimeType");
echo $data;
?>