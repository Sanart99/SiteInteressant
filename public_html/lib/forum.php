<?php
namespace LDLib\Forum;

use LDLib\General\ErrorType;
use LDLib\User\RegisteredUser;
use Schema\UsersBuffer;
use LDLib\Database\LDPDO;
use Schema\ForumBuffer;

use function LDLib\Parser\textToHTML;

enum ThreadPermission:string {
    case ALL_USERS = 'all_users';
    case CURRENT_USERS = 'current_users';
}

enum SearchSorting {
    case ByRelevance;
    case ByDate;
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

    private function __construct(int $threadId, int $number, int $authorId, string $content, \DateTimeInterface $creationDate, \DateTimeInterface $lastEditionDate) {
        $this->nodeId = "forum_{$threadId}-{$number}";
        $this->threadId = $threadId;
        $this->number = $number;
        $this->authorId = $authorId;
        $this->content = $content;
        $this->creationDate = $creationDate;
        $this->lastEditionDate = $lastEditionDate;
    }

    public static function initFromRow(array $row) {
        $data = isset($row['data']) ? $row['data'] : $row;
        return new self($data['thread_id'], $data['number'], $data['author_id'], $data['content'],
            new \DateTimeImmutable($data['creation_date']),new \DateTimeImmutable($data['last_edition_date']));
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
    public readonly string $keywords;
    public readonly SearchSorting $sortBy;
    public readonly ?\DateTimeInterface $startDate;
    public readonly ?\DateTimeInterface $endDate;
    public readonly ?array $userIds;

    public function __construct(string $keywords, SearchSorting $sortBy, ?\DateTimeInterface $startDate = null, ?\DateTimeInterface $endDate = null, ?array $userIds = null) {
        if (preg_match('/^[\w\+\~\-,\s]+$/', $keywords) == 0) throw new \Exception('Invalid keywords.');
        $this->keywords = $keywords;
        $this->sortBy = $sortBy;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->userIds = $userIds;
    }

    public function asString():string {
        $s = "`k:{$this->keywords}`sort:{$this->sortBy->name}";
        if ($this->startDate != null) $s .= "`sDate:{$this->startDate->format('Y-m-d H:i:s')}";
        if ($this->endDate != null) $s .= "`eDate:{$this->endDate->format('Y-m-d H:i:s')}";
        if ($this->userIds != null) { $v = implode(',',$this->userIds); $s .= "`ids:$v"; }
        return $s;
    }
}

function create_thread(LDPDO $conn, RegisteredUser $user, string $title, array $tags, ThreadPermission $permission, string $msg) {
    if (mb_strlen($title) > 175) return ErrorType::TITLE_TOOLONG;
    if (mb_strlen($msg) === 0) return ErrorType::MESSAGE_TOOSHORT;
    if (mb_strlen($msg) > 6000) return ErrorType::MESSAGE_TOOLONG;
    foreach ($tags as $tag) if (!is_string($tag) || str_contains($tag,',')) return ErrorType::TAG_INVALID;

    $conn->query('START TRANSACTION');
    $sNow = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $stmt = $conn->prepare('INSERT INTO threads (author_id,title,tags,creation_date,last_update_date,permission,following_ids) VALUES (?,?,?,?,?,?,?) RETURNING *');
    $stmt->execute([$user->id,$title,implode(',',$tags),$sNow,$sNow,$permission->value,"[$user->id]"]);
    $threadRow = $stmt->fetch(\PDO::FETCH_ASSOC);

    $stmt = $conn->prepare('INSERT INTO comments (thread_id,number,author_id,content,creation_date,readBy) VALUES (?,?,?,?,?,?) RETURNING *');
    $stmt->execute([$threadRow['id'],0,$user->id,textToHTML($user->id, $msg),$sNow,json_encode([$user->id])]);
    $commentRow = $stmt->fetch(\PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("INSERT INTO records (user_id,action_group,action,details,date) VALUES (?,?,?,?,?)");
    $stmt->execute([$user->id,'forum','addThread',json_encode(['threadId' => $threadRow['id']]),$sNow]);

    $conn->query('COMMIT');
    ForumBuffer::storeThread($threadRow);
    ForumBuffer::storeComment($commentRow);
    $thread = Thread::initFromRow($threadRow);
    $comment = Comment::initFromRow($commentRow);
    return [$thread,$comment];
}

function thread_add_comment(LDPDO $conn, RegisteredUser $user, int $threadId, string $msg) {
    if (mb_strlen($msg) === 0) return ErrorType::MESSAGE_TOOSHORT;
    if (mb_strlen($msg) > 6000) return ErrorType::MESSAGE_TOOLONG;

    $conn->query('START TRANSACTION');
    $sNow = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $actionGroup = 'forum';
    $action  = 'addComment';

    $n = $conn->query("SELECT MAX(number) FROM comments WHERE thread_id=$threadId")->fetch(\PDO::FETCH_NUM)[0] + 1;
    $stmt = $conn->prepare('INSERT INTO comments (thread_id,number,author_id,content,creation_date,readBy) VALUES (?,?,?,?,?,?) RETURNING *');
    $stmt->execute([$threadId,$n,$user->id,textToHTML($user->id, $msg),$sNow,json_encode([$user->id])]);
    $commentRow = $stmt->fetch(\PDO::FETCH_ASSOC);

    $conn->query("UPDATE threads SET last_update_date='$sNow' WHERE id=$threadId LIMIT 1");

    $rowThread = $conn->query("SELECT * FROM threads WHERE id=$threadId LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    $followingIds = new \Ds\Set(json_decode($rowThread['following_ids']));
    $followingIds->remove($user->id);
    $followingIds = $followingIds->toArray();
    $jsonFollowingIds = json_encode($followingIds);
    $stmt = $conn->prepare("INSERT INTO records (user_id,action_group,action,details,date,notified_ids) VALUES (?,?,?,?,?,?) RETURNING *");
    $stmt->execute([$user->id,$actionGroup,$action,json_encode(['threadId' => $commentRow['thread_id'], 'commentNumber' => $commentRow['number']]),$sNow,$jsonFollowingIds]);
    $recRow = $stmt->fetch(\PDO::FETCH_ASSOC);

    $maxN = $conn->query("SELECT MAX(number) FROM notifications WHERE user_id={$user->id}")->fetch(\PDO::FETCH_NUM)[0];
    if (!is_int($maxN)) $maxN = 0;
    $stmt = $conn->prepare('INSERT INTO notifications (user_id,number,creation_date,action_group,action,record_id) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$user->id,$maxN+1,$sNow,$actionGroup,$action,$recRow['id']]);

    $conn->query('COMMIT');
    ForumBuffer::storeComment($commentRow);
    $comment = Comment::initFromRow($commentRow);
    return $comment;
}

function thread_edit_comment(LDPDO $conn, RegisteredUser $user, int $threadId, int $commNumber, string $msg) {
    if (mb_strlen($msg) === 0) return ErrorType::MESSAGE_TOOSHORT;
    if (mb_strlen($msg) > 6000) return ErrorType::MESSAGE_TOOLONG;

    $conn->query('START TRANSACTION');
    $now = new \DateTimeImmutable('now');
    $sNow = $now->format('Y-m-d H:i:s');

    $oldCommRow = $conn->query("SELECT * FROM comments WHERE thread_id=$threadId AND number=$commNumber LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    $maxN = $conn->query("SELECT MAX(number) FROM comments WHERE thread_id=$threadId")->fetch(\PDO::FETCH_NUM)[0];
    $minutes = ($now->getTimestamp() - (new \DateTimeImmutable($oldCommRow['creation_date']))->getTimestamp()) / 60;
    if (!(($maxN === $oldCommRow['number'] && $minutes < 10) || $minutes < 1.5)) { $conn->query('ROLLBACK'); return ErrorType::EXPIRED; }

    $stmt = $conn->prepare("UPDATE comments SET content=?, last_edition_date=? readBy=? WHERE thread_id=? AND number=? LIMIT 1");
    $stmt->execute([textToHTML($user->id, $msg),$sNow,json_encode([$user->id]),$threadId,$commNumber]);
    $commentRow = $conn->query("SELECT * FROM comments WHERE thread_id=$threadId AND number=$commNumber LIMIT 1")->fetch(\PDO::FETCH_ASSOC);

    $conn->query("UPDATE threads SET last_update_date='$sNow' WHERE id=$threadId LIMIT 1");

    $stmt = $conn->prepare("INSERT INTO records (user_id,action_group,action,details,date) VALUES (?,?,?,?,?)");
    $stmt->execute([$user->id,'forum','editComment',json_encode(['threadId' => $commentRow['thread_id'], 'commentNumber' => $commentRow['number']]),$sNow]);

    $conn->query('COMMIT');
    ForumBuffer::storeComment($commentRow);
    $comment = Comment::initFromRow($commentRow);
    return $comment;
}

function thread_remove_comment(LDPDO $conn, RegisteredUser $user, int $threadId, int $commNumber) {
    $conn->query('START TRANSACTION');
    $now = new \DateTimeImmutable('now');
    $sNow = $now->format('Y-m-d H:i:s');

    $stmt = $conn->query("DELETE FROM comments WHERE thread_id=$threadId AND number=$commNumber LIMIT 1 RETURNING *");
    $commentRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    $minutes = ($now->getTimestamp() - (new \DateTimeImmutable($commentRow['creation_date']))->getTimestamp()) / 60;
    if ($minutes > 1.5) { $conn->query('ROLLBACK'); return ErrorType::EXPIRED; }

    $stmt = $conn->prepare("INSERT INTO records (user_id,action_group,action,details,date) VALUES (?,?,?,?,?)");
    $stmt->execute([$user->id,'forum','remComment',json_encode(['threadId' => $commentRow['thread_id'], 'commentNumber' => $commentRow['number']]),$sNow]);

    $conn->query('COMMIT');
    $comment = Comment::initFromRow($commentRow);
    ForumBuffer::forgetComment($comment->nodeId);
    return $comment;
}

function thread_follow(LDPDO $conn, RegisteredUser $user, int $threadId) {
    $ids = new \DS\Set(json_decode($conn->query("SELECT following_ids FROM threads WHERE id=$threadId")->fetch(\PDO::FETCH_NUM)[0],true));
    $ids->add($user->id);
    $stmt = $conn->prepare('UPDATE threads SET following_ids=? WHERE id=?');
    $stmt->execute([json_encode($ids),$threadId]);
    return true;
}

function thread_unfollow(LDPDO $conn, RegisteredUser $user, int $threadId) {
    $ids = new \DS\Set(json_decode($conn->query("SELECT following_ids FROM threads WHERE id=$threadId")->fetch(\PDO::FETCH_NUM)[0],true));
    $ids->remove($user->id);
    $stmt = $conn->prepare('UPDATE threads SET following_ids=? WHERE id=?');
    $stmt->execute([json_encode($ids),$threadId]);
    return true;
}
?>