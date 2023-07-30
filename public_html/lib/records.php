<?php
namespace LdLib\Records;

use LDLib\Database\LDPDO;
use LdLib\General\ErrorType;
use Ds\Set;

use function LDLib\Database\{get_lock, release_lock};

enum NotificationGroup:string {
    case FORUM = 'forum';
}

function set_notification_to_read(LDPDO $conn, int $userId, string $recordId) {
    if (preg_match('/^\d+$/',$recordId) === 0) return ErrorType::INVALID;
    $lockName = "siteinteressant_recordUpdate_$recordId";

    if (get_lock($conn,$lockName) != 1) return ErrorType::DBLOCK_TAKEN;

    $stmt = $conn->prepare('SELECT notified_ids,readnotifs_ids FROM records WHERE id=?');
    $stmt->execute([$recordId]);
    $recordRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($recordRow == false) { release_lock($conn,$lockName); return ErrorType::NOTFOUND; }

    $notifiedIds = new Set(json_decode($recordRow['notified_ids'],null,512,JSON_OBJECT_AS_ARRAY));
    $readNotifsIds = new Set(json_decode($recordRow['readnotifs_ids'],null,512,JSON_OBJECT_AS_ARRAY));
    if (!$notifiedIds->contains($userId)) return ErrorType::USER_INVALID;
    if ($readNotifsIds->contains($userId)) return ErrorType::DUPLICATE;
    $readNotifsIds->add($userId);

    $stmt = $conn->prepare('UPDATE records SET readnotifs_ids=? WHERE id=?');
    $stmt->execute([json_encode($readNotifsIds->toArray()),$recordId]);

    release_lock($conn,$lockName);
    return true;
}
?>