<?php
namespace LDLib\Forum;

use DateTimeInterface;
use LDLib\General\ErrorType;
use LDLib\User\RegisteredUser;
use LDLib\Database\LDPDO;
use LDLib\General\OperationResult;
use LDLib\General\SuccessType;
use LDLib\General\TypedException;
use LDLib\Net\LDWebPush;
use Schema\ForumBuffer;
use Schema\OperationType;

use function LDLib\Database\{get_lock,release_lock};
use function LDLib\Parser\textToHTML;

enum ThreadPermission:string {
    case ALL_USERS = 'all_users';
    case CURRENT_USERS = 'current_users';
}

enum SearchSorting {
    case ByRelevance;
    case ByDate;
}

enum ThreadType {
    case Standard;
    case Twinoid;
}

class Thread {
    public readonly string $nodeId;
    public readonly int $id;
    public readonly string $title;
    public readonly int $authorId;
    public readonly array $tags;
    public readonly ThreadPermission $permission;
    public readonly \DateTimeInterface $creationDate;
    public readonly \DateTimeInterface $lastUpdateDate;

    private function __construct(int $id, string $title, int $authorId, array $tags, ThreadPermission $permission, \DateTimeInterface $creationDate, \DateTimeInterface $lastUpdateDate) {
        $this->nodeId = "forum_$id";
        $this->id = $id;
        $this->title = $title;
        $this->authorId = $authorId;
        $this->tags = $tags;
        $this->permission = $permission;
        $this->creationDate = $creationDate;
        $this->lastUpdateDate = $lastUpdateDate;
    }

    public function isAccessibleToUser(RegisteredUser $user) {
        return $this->permission == ThreadPermission::ALL_USERS || ($this->permission == ThreadPermission::CURRENT_USERS && $user->registrationDate <= $this->creationDate);
    }

    public static function initFromRow(array $row) {
        $data = isset($row['data']) ? $row['data'] : $row;
        return new self($data['id'], $data['title'], $data['author_id'], explode(',',$data['tags']),
            ThreadPermission::from($data['permission']), new \DateTimeImmutable($data['creation_date']),new \DateTimeImmutable($data['last_update_date']));
    }
}

class TidThread {
    public static function getIdFromRow(array $row) {
        $data = isset($row['data']) ? $row['data'] : $row;
        return "forum_tid_{$data['id']}";
    }
}

class Comment {
    public readonly string $nodeId;
    public readonly int $threadId;
    public readonly int $number;
    public readonly int $authorId;
    public readonly string $content;
    public readonly \DateTimeInterface $creationDate;
    public readonly \DateTimeInterface $lastEditionDate;
    public readonly array $readBy;

    private function __construct(int $threadId, int $number, int $authorId, string $content, \DateTimeInterface $creationDate, \DateTimeInterface $lastEditionDate, array $readBy) {
        $this->nodeId = "forum_{$threadId}-{$number}";
        $this->threadId = $threadId;
        $this->number = $number;
        $this->authorId = $authorId;
        $this->content = $content;
        $this->creationDate = $creationDate;
        $this->lastEditionDate = $lastEditionDate;
        $this->readBy = $readBy;
    }

    public static function initFromRow(array $row) {
        $data = isset($row['data']) ? $row['data'] : $row;
        return new self($data['thread_id'], $data['number'], $data['author_id'], $data['content'],
            new \DateTimeImmutable($data['creation_date']),new \DateTimeImmutable($data['last_edition_date']),
            json_decode($data['read_by']));
    }

    public static function getIdFromRow(array $row) {
        $data = isset($row['data']) ? $row['data'] : $row;
        return "forum_{$data['thread_id']}-{$data['number']}";
    }
}

class TidComment {
    public readonly string $nodeId;
    public readonly int $threadId;
    public readonly int $id;
    public readonly ?int $authorId;
    public readonly string $content;
    public readonly \DateTimeInterface $deducedDate;

    private function __construct(int $threadId, int $id, ?int $authorId, string $content, \DateTimeInterface $deducedDate) {
        $this->nodeId = "forum_tid_{$threadId}-{$id}";
        $this->threadId = $threadId;
        $this->id = $id;
        $this->authorId = $authorId;
        $this->content = $content;
        $this->deducedDate = $deducedDate;
    }

    public static function initFromRow(array $row) {
        $data = isset($row['data']) ? $row['data'] : $row;
        return new self($data['thread_id'], $data['id'], $data['author_id'], $data['content'],
            new \DateTimeImmutable($data['deduced_date']));
    }

    public static function getIdFromRow(array $row) {
        $data = isset($row['data']) ? $row['data'] : $row;
        return "forum_tid_{$data['thread_id']}-{$data['id']}";
    }
}

class ForumSearchQuery {
    public readonly ThreadType $threadType;
    public readonly string $keywords;
    public readonly SearchSorting $sortBy;
    public readonly ?\DateTimeInterface $startDate;
    public readonly ?\DateTimeInterface $endDate;
    public readonly ?array $userIds;

    public function __construct(ThreadType $threadType, string $keywords, SearchSorting $sortBy, ?\DateTimeInterface $startDate = null, ?\DateTimeInterface $endDate = null, ?array $userIds = null) {
        if (preg_match('/^[\w\+\~\-,\s]+$/', $keywords) == 0) throw new TypedException('Invalid keywords.',ErrorType::INVALID_DATA);
        $this->threadType = $threadType;
        $this->keywords = $keywords;
        $this->sortBy = $sortBy;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->userIds = $userIds;
    }

    public function asString():string {
        $s = "`type:{$this->threadType->name}";
        $s .= "`k:{$this->keywords}`sort:{$this->sortBy->name}";
        if ($this->startDate != null) $s .= "`sDate:{$this->startDate->format('Y-m-d H:i:s')}";
        if ($this->endDate != null) $s .= "`eDate:{$this->endDate->format('Y-m-d H:i:s')}";
        if ($this->userIds != null) { $v = implode(',',$this->userIds); $s .= "`ids:$v"; }
        return $s;
    }
}

function create_thread(LDPDO $conn, RegisteredUser $user, string $title, array $tags, ThreadPermission $permission, string $msg):OperationResult {
    if (mb_strlen($title) > 175) return new OperationResult(ErrorType::INVALID_DATA, 'The title must be less than 175 characters.');
    if (mb_strlen($msg) === 0) return new OperationResult(ErrorType::INVALID_DATA, 'The content must contain at least one character.');
    if (mb_strlen($msg) > 6000) return new OperationResult(ErrorType::INVALID_DATA, 'The content must not contain more than 6000 characters.');
    foreach ($tags as $tag) if (!is_string($tag) || str_contains($tag,',')) return new OperationResult(ErrorType::INVALID_DATA, 'One or multiple tags are invalid.');

    $conn->query('START TRANSACTION');
    $sNow = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $stmt = $conn->prepare('INSERT INTO threads (author_id,title,tags,creation_date,last_update_date,permission,following_ids) VALUES (?,?,?,?,?,?,?) RETURNING *');
    $stmt->execute([$user->id,$title,implode(',',$tags),$sNow,$sNow,$permission->value,"[$user->id]"]);
    $threadRow = $stmt->fetch(\PDO::FETCH_ASSOC);

    $stmt = $conn->prepare('INSERT INTO comments (thread_id,number,author_id,content,creation_date,read_by) VALUES (?,?,?,?,?,?) RETURNING *');
    $stmt->execute([$threadRow['id'],0,$user->id,textToHTML($user->id, $msg),$sNow,json_encode([$user->id])]);
    $commentRow = $stmt->fetch(\PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("INSERT INTO records (user_id,action_group,action,details,date) VALUES (?,?,?,?,?)");
    $stmt->execute([$user->id,'forum','addThread',json_encode(['threadId' => $threadRow['id']]),$sNow]);

    $conn->query('COMMIT');

    $wp = new LDWebPush();
    $stmt = $conn->query("SELECT id FROM users WHERE id!={$user->id} AND JSON_CONTAINS(settings,'true','$.notifications')=1 AND JSON_CONTAINS(settings,'true','$.forum.notif_newThread')=1");
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) $wp->sendNotification($conn,$row['id'],"Nouveau topic de $user->username",$title);

    ForumBuffer::storeThread($threadRow);
    ForumBuffer::storeComment($commentRow);
    $thread = Thread::initFromRow($threadRow);
    $comment = Comment::initFromRow($commentRow);
    return new OperationResult(SuccessType::SUCCESS, null, [$thread->id], [$thread,$comment]);
}

function remove_thread(LDPDO $conn, RegisteredUser $user, int $threadId):OperationResult {
    $now = new \DateTimeImmutable('now');
    $sNow = $now->format('Y-m-d H:i:s');
    if (!check_can_remove_thread($conn,$user,$threadId,$now)) return new OperationResult(ErrorType::PROHIBITED, "Thread can't be deleted.");

    $conn->query('START TRANSACTION');
    $threadRow = $conn->query("DELETE FROM threads WHERE id=$threadId RETURNING *")->fetch(\PDO::FETCH_ASSOC);
    $conn->query("DELETE FROM comments WHERE thread_id=$threadId");

    $stmt = $conn->prepare("INSERT INTO records (user_id,action_group,action,details,date) VALUES (?,?,?,?,?)");
    $stmt->execute([$user->id,'forum','remThread',json_encode(['threadId' => $threadRow['id']]),$sNow]);

    $thread = Thread::initFromRow($threadRow);
    $conn->query('COMMIT');
    return new OperationResult(SuccessType::SUCCESS, null, [$thread->id], [$thread]);
}

function kube_thread(LDPDO $conn, RegisteredUser $user, int $threadId):OperationResult {
    $now = new \DateTimeImmutable('now');
    $sNow = $now->format('Y-m-d H:i:s');
    
    $conn->query('START TRANSACTION');
    $threadRow = $conn->query("SELECT * FROM threads WHERE id=$threadId")->fetch(\PDO::FETCH_ASSOC);
    if ($threadRow === false) return new OperationResult(ErrorType::NOT_FOUND, 'Thread not found.');
    $thread = Thread::initFromRow($threadRow);
    if (!$thread->isAccessibleToUser($user)) return new OperationResult(ErrorType::PROHIBITED, 'User unauthorized to access thread.');
    if ($conn->query("SELECT * FROM kubed_threads WHERE user_id={$user->id} AND thread_id={$thread->id}")->fetch() !== false)
        return new OperationResult(ErrorType::USELESS, 'Thread already kubed.', [$thread->id], [$thread]);
    
    $conn->query("INSERT INTO kubed_threads (user_id,thread_id,date) VALUES ({$user->id},{$thread->id},'$sNow')");

    $stmt = $conn->prepare('INSERT INTO records (user_id,action_group,action,details,date) VALUES (?,?,?,?,?)');
    $stmt->execute([$user->id,'forum','kubeThread',json_encode(['threadId' => $thread->id]),$sNow]);
    
    $conn->query('COMMIT');
    return new OperationResult(SuccessType::SUCCESS, null, [$thread->id], [$thread]);
}

function unkube_thread(LDPDO $conn, RegisteredUser $user, int $threadId):OperationResult {
    $now = new \DateTimeImmutable('now');
    $sNow = $now->format('Y-m-d H:i:s');
    
    $conn->query('START TRANSACTION');
    $threadRow = $conn->query("SELECT * FROM threads WHERE id=$threadId")->fetch(\PDO::FETCH_ASSOC);
    if ($threadRow === false) return new OperationResult(ErrorType::NOT_FOUND, 'Thread not found.');
    $thread = Thread::initFromRow($threadRow);
    if (!$thread->isAccessibleToUser($user)) return new OperationResult(ErrorType::PROHIBITED, 'The user is unauthorized to access the thread.');

    $kThreadRow = $conn->query("DELETE FROM kubed_threads WHERE user_id={$user->id} AND thread_id={$thread->id} LIMIT 1 RETURNING *")->fetch(\PDO::FETCH_ASSOC);
    if ($kThreadRow === false) return new OperationResult(ErrorType::USELESS, 'Thread isn\'t kubed.', [$thread->id], [$thread]);

    $stmt = $conn->prepare('INSERT INTO records (user_id,action_group,action,details,date) VALUES (?,?,?,?,?)');
    $stmt->execute([$user->id,'forum','unkubeThread',json_encode(['threadId' => $thread->id]),$sNow]);
    
    $conn->query('COMMIT');
    return new OperationResult(SuccessType::SUCCESS, null, [$thread->id], [$thread]);
}

function check_can_remove_thread(LDPDO $conn, RegisteredUser $user, int $threadId, DateTimeInterface $currDate) {
    if ($user->isAdministrator()) return true;

    $row = $conn->query("SELECT * FROM threads WHERE id=$threadId")->fetch(\PDO::FETCH_ASSOC);
    if ($row === false || ($user->id != $row['author_id'])) return false;
    
    $minutes = ($currDate->getTimestamp() - (new \DateTimeImmutable($row['creation_date']))->getTimestamp()) / 60;
    return $minutes < 1.5;
}

function thread_add_comment(LDPDO $conn, RegisteredUser $user, int $threadId, string $msg):OperationResult {
    if (mb_strlen($msg) === 0) return new OperationResult(ErrorType::INVALID_DATA, 'The content must contain at least one character.');
    if (mb_strlen($msg) > 6000) return new OperationResult(ErrorType::INVALID_DATA, 'The content must not contain more than 6000 characters.');

    $conn->query('START TRANSACTION');
    $sNow = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $actionGroup = 'forum';
    $action  = 'addComment';

    // add comment
    $n = $conn->query("SELECT MAX(number) FROM comments WHERE thread_id=$threadId")->fetch(\PDO::FETCH_NUM)[0] + 1;
    $stmt = $conn->prepare('INSERT INTO comments (thread_id,number,author_id,content,creation_date,read_by) VALUES (?,?,?,?,?,?) RETURNING *');
    $stmt->execute([$threadId,$n,$user->id,textToHTML($user->id, $msg),$sNow,json_encode([$user->id])]);
    $commentRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    $aDetails = ['threadId' => $commentRow['thread_id'], 'commentNumber' => $commentRow['number']];

    // update thread
    $conn->query("UPDATE threads SET last_update_date='$sNow' WHERE id=$threadId LIMIT 1");

    // add record
    $rowThread = $conn->query("SELECT * FROM threads WHERE id=$threadId LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    $followingIds = new \Ds\Set(json_decode($rowThread['following_ids']));
    $followingIds->remove($user->id);
    $followingIds = $followingIds->toArray();
    $jsonFollowingIds = json_encode($followingIds);
    $stmt = $conn->prepare("INSERT INTO records (user_id,action_group,action,details,date,notified_ids) VALUES (?,?,?,?,?,?) RETURNING *");
    $stmt->execute([$user->id,$actionGroup,$action,json_encode($aDetails),$sNow,$jsonFollowingIds]);
    $recRow = $stmt->fetch(\PDO::FETCH_ASSOC);

    // add/update notification
    $locks = [];
    foreach ($followingIds as $follId) {
        $lockName = "siteinteressant_notif_$follId";
        $lockTry = 0;
        while (!in_array($lockName,$locks) && get_lock($conn,$lockName) == 0) {
            if ($lockTry++ == 5) return new OperationResult(ErrorType::DBLOCK_TAKEN);
            usleep(250000);
        }
        $locks[] = $lockName;
    
        $notification = $conn->query("SELECT * FROM notifications WHERE user_id=$follId AND read_date IS NULL AND JSON_CONTAINS(details, '{\"threadId\":$threadId}')=1 LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if ($notification == false) {
            $details = $aDetails;
            $details['userIds'] = [$user->id];

            $maxN = $conn->query("SELECT MAX(number) FROM notifications WHERE user_id=$follId")->fetch(\PDO::FETCH_NUM)[0];
            if (!is_int($maxN)) $maxN = 0;

            $stmt = $conn->prepare('INSERT INTO notifications (user_id,number,creation_date,last_update_date,action_group,action,details,n,record_id) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$follId,$maxN+1,$sNow,$sNow,$actionGroup,$action,json_encode($details),1,$recRow['id']]);
        } else {
            $details = json_decode($notification['details'], true);
            if (!in_array($user->id,$details['userIds'])) $details['userIds'][] = $user->id;

            $stmt = $conn->prepare("UPDATE notifications SET n=n+1, last_update_date=:lastUpdateDate, details=:details WHERE user_id=:userId AND read_date IS NULL AND JSON_CONTAINS(details, :sJson)=1 LIMIT 1");
            $stmt->execute([
                ':lastUpdateDate' => $sNow,
                ':details' => json_encode($details),
                ':userId' => $follId,
                ':sJson' => "{\"threadId\":$threadId}"
            ]);
        }
    }

    $conn->query('COMMIT');
    foreach ($locks as $lock) release_lock($conn, $lock);

    // push notifications
    $wp = new LDWebPush();
    $sTitle = mb_strlen($rowThread['title']) > 60 ? substr($rowThread['title'],0,57).'...' : $rowThread['title'];
    foreach ($followingIds as $id) $wp->sendNotification($conn,$id,"Nouveau commentaire de $user->username",$sTitle);

    ForumBuffer::storeComment($commentRow);
    $comment = Comment::initFromRow($commentRow);
    return new OperationResult(SuccessType::SUCCESS, null, [$comment->nodeId], [$comment]);
}

function thread_edit_comment(LDPDO $conn, RegisteredUser $user, int $threadId, int $commNumber, string $msg, ?string $title = null):OperationResult {
    if (mb_strlen($msg) === 0) return new OperationResult(ErrorType::INVALID_DATA, 'The content must contain at least one character.');
    if (mb_strlen($msg) > 6000) return new OperationResult(ErrorType::INVALID_DATA, 'The content must not contain more than 6000 characters.');

    $conn->query('START TRANSACTION');
    $now = new \DateTimeImmutable('now');
    $sNow = $now->format('Y-m-d H:i:s');
    if (!check_can_edit_comment($conn,$user,$threadId,$commNumber,$now)) return new OperationResult(ErrorType::PROHIBITED, "This comment can't be edited.");

    // Edit comment
    $stmt = $conn->prepare("UPDATE comments SET content=?, last_edition_date=?, read_by=? WHERE thread_id=? AND number=? LIMIT 1");
    $stmt->execute([textToHTML($user->id, $msg),$sNow,json_encode([$user->id]),$threadId,$commNumber]);
    $commentRow = $conn->query("SELECT * FROM comments WHERE thread_id=$threadId AND number=$commNumber LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

    // Update thread data
    $sSet = "last_update_date=:sNow";
    $v = [
        ':sNow' => $sNow,
        ':threadId' => $threadId
    ];
    if ($title != null && $commentRow['number'] == 0) { $v[':title'] = $title; $sSet .= ', title=:title'; }
    $stmt = $conn->prepare("UPDATE threads SET $sSet WHERE id=:threadId LIMIT 1");
    $stmt->execute($v);

    // Insert record
    $stmt = $conn->prepare("INSERT INTO records (user_id,action_group,action,details,date) VALUES (?,?,?,?,?)");
    $stmt->execute([$user->id,'forum','editComment',json_encode(['threadId' => $commentRow['thread_id'], 'commentNumber' => $commentRow['number']]),$sNow]);

    // End
    $conn->query('COMMIT');
    ForumBuffer::storeComment($commentRow);
    $comment = Comment::initFromRow($commentRow);
    return new OperationResult(SuccessType::SUCCESS, null, [$comment->nodeId], [$comment]);
}

function check_can_edit_comment(LDPDO $conn, RegisteredUser $user, int $threadId, int $commNumber, DateTimeInterface $currDate):bool {
    if ($user->isAdministrator()) return true;

    $commRow = $conn->query("SELECT * FROM comments WHERE thread_id=$threadId AND number=$commNumber LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if ($commRow === false || $user->id != $commRow['author_id']) return false;
    if ($commRow['number'] === 0) return true;

    $maxN = $conn->query("SELECT MAX(number) FROM comments WHERE thread_id=$threadId")->fetch(\PDO::FETCH_NUM)[0];
    $minutes = ($currDate->getTimestamp() - (new \DateTimeImmutable($commRow['creation_date']))->getTimestamp()) / 60;
    return (($maxN === $commRow['number'] && $minutes < 10) || $minutes < 1.5);
}

function thread_remove_comment(LDPDO $conn, RegisteredUser $user, int $threadId, int $commNumber):OperationResult {
    $conn->query('START TRANSACTION');
    $now = new \DateTimeImmutable('now');
    $sNow = $now->format('Y-m-d H:i:s');
    if (!check_can_remove_comment($conn,$user,$threadId,$commNumber,$now)) return new OperationResult(ErrorType::PROHIBITED, "Can't remove this comment.");

    $stmt = $conn->query("DELETE FROM comments WHERE thread_id=$threadId AND number=$commNumber LIMIT 1 RETURNING *");
    $commentRow = $stmt->fetch(\PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("INSERT INTO records (user_id,action_group,action,details,date) VALUES (?,?,?,?,?)");
    $stmt->execute([$user->id,'forum','remComment',json_encode(['threadId' => $commentRow['thread_id'], 'commentNumber' => $commentRow['number']]),$sNow]);

    $conn->query('COMMIT');
    $comment = Comment::initFromRow($commentRow);
    ForumBuffer::forgetComment($comment->nodeId);
    return new OperationResult(SuccessType::SUCCESS, null, [$comment->nodeId], [$comment]);
}

function check_can_remove_comment(LDPDO $conn, RegisteredUser $user, int $threadId, int $commNumber, DateTimeInterface $currDate):bool {
    if ($commNumber <= 0) return false;
    if ($user->isAdministrator()) return true;

    $commRow = $conn->query("SELECT * FROM comments WHERE thread_id=$threadId AND number=$commNumber LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if ($commRow === false || $user->id != $commRow['author_id']) return false;

    $minutes = ($currDate->getTimestamp() - (new \DateTimeImmutable($commRow['creation_date']))->getTimestamp()) / 60;
    return $minutes < 1.5;
}

function thread_mark_comment_as_read(LDPDO $conn, RegisteredUser $user, int $threadId, int $commNumber):OperationResult {
    $comment = $conn->query("SELECT * FROM comments WHERE thread_id=$threadId AND number=$commNumber LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if ($comment == false) return new OperationResult(ErrorType::NOT_FOUND, 'Comment not found.');
    $readBy = json_decode($comment['read_by']);
    if (in_array($user->id,$readBy)) return true;
    $readBy[] = $user->id;
    
    $stmt = $conn->prepare("UPDATE comments SET read_by=? WHERE thread_id=$threadId AND number=$commNumber LIMIT 1");
    $stmt->execute([json_encode($readBy)]);
    return new OperationResult(SuccessType::SUCCESS);
}

function thread_follow(LDPDO $conn, RegisteredUser $user, int $threadId):OperationResult {
    $ids = new \DS\Set(json_decode($conn->query("SELECT following_ids FROM threads WHERE id=$threadId")->fetch(\PDO::FETCH_NUM)[0],true));
    $ids->add($user->id);
    $stmt = $conn->prepare('UPDATE threads SET following_ids=? WHERE id=?');
    $stmt->execute([json_encode($ids),$threadId]);
    return new OperationResult(SuccessType::SUCCESS);
}

function thread_unfollow(LDPDO $conn, RegisteredUser $user, int $threadId):OperationResult {
    $ids = new \DS\Set(json_decode($conn->query("SELECT following_ids FROM threads WHERE id=$threadId")->fetch(\PDO::FETCH_NUM)[0],true));
    $ids->remove($user->id);
    $stmt = $conn->prepare('UPDATE threads SET following_ids=? WHERE id=?');
    $stmt->execute([json_encode($ids),$threadId]);
    return new OperationResult(SuccessType::SUCCESS);
}
?>