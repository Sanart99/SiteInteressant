<?php
ob_start();
$libDir = '../../../lib/';
require_once $libDir.'/aws.php';
require_once $libDir.'/net.php';
require_once $libDir.'/utils/utils.php';
ob_end_clean();

use LDLib\AWS\AWS;
use Aws\Exception\AwsException;

header('Accept-Ranges: bytes');

if (preg_match('/^\/file\/(\d+_[^\/?]*)/',urldecode($_SERVER['REQUEST_URI']),$m) == 0) {
    http_response_code(404);
    echo 'File not found.';
    return;
}
$s3Key = $m[1];
$redisKey = "s3:general:$s3Key";
$headers = [];
foreach (getallheaders() as $k => $v) $headers[strtolower($k)] = $v;

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
                echo serveData($vCache['data'],$vCache['mimetype']);
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

function serveData(string $data, string $mimeType) {
    global $headers;

    if (isset($headers['range'])) {
        $sRanges = str_replace(['bytes=',' '],'',$headers['range']);
        $aRanges = explode(',',$sRanges);
        $isMultipart = count($aRanges) > 1;
        $boundary = bin2hex(random_bytes(8));
        $dataLength = strlen($data);
        if ($isMultipart) header("Content-Type: multipart/byteranges; boundary=$boundary");
        else header("Content-Type: $mimeType");
        http_response_code(206);
        $s = '';

        $i = 0;
        foreach ($aRanges as $sRange) {
            if (preg_match('/^(\d+)?\-(\d+)?$/',$sRange,$m,PREG_UNMATCHED_AS_NULL) == 0) continue;

            if (($m[2]??0) > $dataLength) { // abort
                http_response_code(200);
                header("Content-Type: $mimeType");
                echo $data;
                return;
            }

            $v = substr($data, $m[1]??0, ($m[2] == null ? null : ($m[2]-$m[1]) + 1));
            
            if ($isMultipart) {
                if ($i++ != 0) $s .= "\n";
                $s .= <<< EOF
                --$boundary
                Content-Type: $mimeType
                Content-Range: bytes {$sRange}/{$dataLength}

                $v
                EOF;
            } else {
                preg_match('/^(\d+)?\-(\d+)?$/',$sRange,$m,PREG_UNMATCHED_AS_NULL);
                $v1 = $m[1]??0;
                $v2 = $m[2]??($dataLength-1);
                header("Content-Range: bytes {$v1}-{$v2}/{$dataLength}");
                $s .= $v;
            }
        }
        if ($isMultipart) $s .= "\n--$boundary--";

        echo $s;
    } else {
        header("Content-Type: $mimeType");
        echo $data;
    }
}
serveData($data, $mimeType);
?>