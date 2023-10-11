<?php
namespace LDLib\Net;

require_once __DIR__."/gen.php";
require_once __DIR__."/utils/utils.php";
dotenv();

use LDLib\Database\LDPDO;
use Minishlink\WebPush\{WebPush, Subscription};
use LDLib\General\OperationResult;
use LDLib\General\SuccessType;

class LDWebPush {
    public WebPush $wp;

    public function __construct() {
        $this->wp = new WebPush([
            'VAPID' => [
                'subject' => "mailto:{$_SERVER['LD_SERVER_ADMIN_EMAIL']}",
                'publicKey' => $_SERVER['LD_VAPID_PUBLIC_KEY'],
                'privateKey' => $_SERVER['LD_VAPID_PRIVATE_KEY']
            ]
        ]);
        $this->wp->setReuseVAPIDHeaders(true);
    }

    public function send(LDPDO $conn, int $userId, ?string $payload = null):OperationResult {
        $stmt = $conn->query("SELECT * FROM push_subscriptions WHERE user_id=$userId");

        while ($row = $stmt->fetch()) {
            $notif = [
                'subscription' => Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'publicKey' => $row['remote_public_key'],
                    'authToken' => $row['auth_token']
                ]),
                'payload' => $payload
            ];
            $this->wp->queueNotification($notif['subscription'],$notif['payload']);
        }

        $reports = [];
        $allGood = true;
        $whereDelete = '';
        foreach ($this->wp->flush() as $report) {
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

        if (!$allGood) return new OperationResult(SuccessType::PARTIAL_SUCCESS, "Some (or all) weren't successfully sent. Cleaning done: Expired endpoints removed from the database.", [$reports], $reports);
        return new OperationResult(SuccessType::SUCCESS, null, [$reports], $reports);
    }

    public function sendNotification(LDPDO $conn, int $userId, string $title, ?string $body = null):OperationResult {
        return $this->send($conn, $userId, json_encode([
            'notifications' => [[
                'title' => $title,
                'body' => $body
                ]
            ]
        ]));
    }
}

function graphql_query(string $json, string $sid=''):array {
    $localMode = (bool)$_SERVER['LD_LOCAL'];
    $ch = curl_init($_SERVER['LD_LINK_GRAPHQL']);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type:application/json',"Cookie:sid=$sid"],
        CURLOPT_POSTFIELDS => $json
    ];
    if ($localMode) {
        $options[CURLOPT_SSL_VERIFYPEER] = false;
        $options[CURLOPT_SSL_VERIFYHOST] = false;
    }

    curl_setopt_array($ch,$options);
    
    $v = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (!$v) {
        if ($localMode) trigger_error(curl_error($ch));
    }

    curl_close($ch);
    return ['res' => $v,'httpCode' => $httpCode];
}

function curl_fetch(string $url, array $postFields = null) {
    $localMode = (bool)$_SERVER['LD_LOCAL'];
    $ch = curl_init($url);

    $options = [CURLOPT_RETURNTRANSFER => true];
    if ($postFields != null) {
        $options[CURLOPT_HTTPHEADER] = ['Content-Type:multipart/form-data'];
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $postFields;
    }
    if ($localMode) {
        $options[CURLOPT_SSL_VERIFYPEER] = false;
        $options[CURLOPT_SSL_VERIFYHOST] = false;
    }

    curl_setopt_array($ch,$options);
    
    $v = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (!$v) {
        if ($localMode) trigger_error(curl_error($ch));
    }

    curl_close($ch);
    return ['res' => $v,'httpCode' => $httpCode];
}
?>