<?php
namespace LDLib\Net;

require_once __DIR__."/gen.php";
require_once __DIR__."/utils/utils.php";
dotenv();

use LDLib\Database\LDPDO;
use Minishlink\WebPush\{WebPush, Subscription};
use LDLib\General\OperationResult;
use LDLib\General\SuccessType;

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

function send_push_notification(LDPDO $conn, int $userId, string $title, ?string $body = null):OperationResult {
    $stmt = $conn->query("SELECT * FROM push_subscriptions WHERE user_id=$userId");

    $webPush = new WebPush([
        'VAPID' => [
            'subject' => "mailto:{$_SERVER['LD_SERVER_ADMIN_EMAIL']}",
            'publicKey' => $_SERVER['LD_VAPID_PUBLIC_KEY'],
            'privateKey' => $_SERVER['LD_VAPID_PRIVATE_KEY']
        ]
    ]);
    $webPush->setReuseVAPIDHeaders(true);

    while ($row = $stmt->fetch()) {
        $notif = [
            'subscription' => Subscription::create([
                'endpoint' => $row['endpoint'],
                'publicKey' => $row['remote_public_key'],
                'authToken' => $row['auth_token']
            ]),
            'payload' => json_encode([
                'notifications' => [[
                    'title' => $title,
                    'body' => $body
                    ]
                ]
            ])
        ];
        $webPush->queueNotification($notif['subscription'],$notif['payload']);
    }
    
    $reports = [];
    $allGood = true;
    $whereDelete = '';
    foreach ($webPush->flush() as $report) {
        $reports[] = $report;
        if (!$report->isSuccess()) {
            $allGood = false;
            $statusCode = $report->getResponse()?->getStatusCode();
            if ($statusCode == 410 || $statusCode == 404) {
                $endpoint = $report->getEndpoint();
                if ($whereDelete != '') $whereDelete .= ' OR ';
                $whereDelete .= "endpoint=\"{$endpoint}\"";
            }
        }
    }
    if ($whereDelete != '') $conn->query("DELETE FROM push_subscriptions WHERE user_id=$userId AND ($whereDelete)");

    if (!$allGood) return new OperationResult(SuccessType::PARTIAL_SUCCESS, "Some (or all) weren't successfully sent. Cleaning done: Expired endpoints removed from the database.", [$reports]);
    return new OperationResult(SuccessType::SUCCESS, null, [$reports]);
}
?>