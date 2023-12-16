<?php
namespace LDLib\User;

require_once __DIR__.'/utils/utils.php';

use Ds\Set;
use LDLib\Forum\ThreadPermission;
use LDLib\Database\LDPDO;
use LDLib\General\{ErrorType,OperationResult,SuccessType};

abstract class User {
    public readonly int $id;
    public readonly string $username;
    public readonly Set $titles;
    public readonly \DateTimeImmutable $registrationDate;
    public readonly UserSettings $settings;

    public function __construct(int $id, Set $titles, string $username, \DateTimeImmutable $registrationDate, UserSettings $settings) {
        $this->id = $id;
        $this->titles = $titles;
        $this->username = $username;
        $this->registrationDate = $registrationDate;
        $this->settings = $settings;
    }
}

class RegisteredUser extends User {
    public readonly ?string $avatarName;

    public function __construct(int $id, Set $titles, string $username, \DateTimeImmutable $registrationDate, UserSettings $settings, ?string $avatarName=null) {
        parent::__construct($id,$titles,$username,$registrationDate,$settings);
        $this->avatarName = $avatarName;
    }

    public function isAdministrator():bool {
        return $this->titles->contains('Administrator');
    }

    public function getAvatarURL():string {
        $res = get_root_link('res');
        return $this->avatarName == null ? $res.'/avatars/default.jpg' : $res.'/avatars/'.$this->avatarName;
    }

    public function saveSettings(LDPDO $conn):bool {
        $stmt = $conn->prepare("UPDATE users SET settings=:settings WHERE id=:userId LIMIT 1");
        $stmt->execute([
            ':userId' => $this->id,
            ':settings' => json_encode([
                'notifications' => $this->settings->notificationsEnabled,
                'forum' => [
                    'defaultThreadPermission' => $this->settings->defaultThreadPermission,
                    'autoMarkPagesAsRead' => $this->settings->forum_autoMarkPagesAsRead,
                    'followThreadsOnComment' => $this->settings->forum_followThreadsOnComment,
                    'msgPreProcess' => [
                        'insertLinks' => $this->settings->forum_msgPreProcess_insertLinks
                    ],
                    'notif_newThread' => $this->settings->notif_newThread,
                    'notif_newCommentOnFollowedThread' => $this->settings->notif_newCommentOnFollowedThread,
                ],
                'minusculeMode' => $this->settings->minusculeMode
            ],JSON_THROW_ON_ERROR)
        ]);

        return true;
    }

    public static function initFromRow(array $row) {
        $data = array_key_exists('data',$row) && array_key_exists('metadata',$row) ? $row['data'] : $row;
        $settings = new UserSettings(json_decode($data['settings'],true));
        return new self($data['id'],new Set(explode(',',$data['titles'])),$data['name'],new \DateTimeImmutable($data['registration_date']),$settings,$data['avatar_name']);
    }
}

class UserSettings {
    public ThreadPermission $defaultThreadPermission;
    public bool $forum_autoMarkPagesAsRead;
    public bool $forum_followThreadsOnComment;
    public bool $forum_msgPreProcess_insertLinks;

    public bool $notificationsEnabled;
    public bool $notif_newThread;
    public bool $notif_newCommentOnFollowedThread;

    public bool $minusculeMode;

    public function __construct(?array $settings) {
        $this->notificationsEnabled = (bool)($settings['notifications']??false);
        $this->minusculeMode = (bool)($settings['minusculeMode']??false);
        
        if ($settings != null && isset($settings['forum'])) {
            $a = $settings['forum'];
            if (isset($a['defaultThreadPermission'])) switch ($a['defaultThreadPermission']) {
                case 'current_users': $this->defaultThreadPermission = ThreadPermission::CURRENT_USERS; break;
                case 'all_users': $this->defaultThreadPermission = ThreadPermission::ALL_USERS; break;
            }
            $this->forum_autoMarkPagesAsRead = (bool)($a['autoMarkPagesAsRead']??false);
            $this->forum_followThreadsOnComment = (bool)($a['followThreadsOnComment']??false);
            if (isset($a['msgPreProcess'])) {
                $aMsgPreProcess = $a['msgPreProcess'];
                $this->forum_msgPreProcess_insertLinks = (bool)($aMsgPreProcess['insertLinks']??false);
            }
            

            $this->notif_newThread = (bool)($a['notif_newThread']??false);
            $this->notif_newCommentOnFollowedThread = (bool)($a['notif_newCommentOnFollowedThread']??false);
        }
        
        $this->defaultThreadPermission ??= ThreadPermission::CURRENT_USERS;
        $this->forum_autoMarkPagesAsRead ??= false;
        $this->forum_followThreadsOnComment ??= false;
        $this->forum_msgPreProcess_insertLinks ??= false;
        $this->notif_newThread ??= false;
        $this->notif_newCommentOnFollowedThread ??= false;
    }
}

function set_user_setting(LDPDO $conn, int $userId, array $names, array $values):OperationResult {
    $userRow = $conn->query("SELECT * FROM users WHERE id=$userId LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    if ($userRow == false) return new OperationResult(ErrorType::NOT_FOUND, "User not found.");
    $user = RegisteredUser::initFromRow($userRow);

    for ($i=0; $i<count($names) || $i<count($values); $i++) {
        try {
            switch ($names[$i]) {
                case 'defaultThreadPermission': $user->settings->defaultThreadPermission = ThreadPermission::from($values[$i]); break;
                case 'forum_autoMarkPagesAsRead': $user->settings->forum_autoMarkPagesAsRead = (bool)$values[$i]; break;
                case 'forum_followThreadsOnComment': $user->settings->forum_followThreadsOnComment = (bool)$values[$i]; break;
                case 'forum_msgPreProcess_insertLinks': $user->settings->forum_msgPreProcess_insertLinks = (bool)$values[$i]; break;
                case 'notifications': $user->settings->notificationsEnabled = (bool)$values[$i]; break;
                case 'notif_newThread': $user->settings->notif_newThread = (bool)$values[$i]; break;
                case 'notif_newCommentOnFollowedThread': $user->settings->notif_newCommentOnFollowedThread = (bool)$values[$i]; break;
                case 'minusculeMode': $user->settings->minusculeMode = (bool)$values[$i]; break;
                default: throw new \Exception("");
            }
        } catch (\Throwable $e) { return new OperationResult(ErrorType::INVALID_DATA, "Setting '{$names[$i]}' is either invalid or was set to an invalid value."); }
    }
    
    return $user->saveSettings($conn) ? new OperationResult(SuccessType::SUCCESS) : new OperationResult(ErrorType::UNKNOWN);
}

function set_notification_to_read(LDPDO $conn, int $userId, int $number, ?\DateTimeInterface $dt = null):OperationResult {
    $notification = $conn->query("SELECT * FROM notifications WHERE user_id=$userId AND number=$number")->fetch(\PDO::FETCH_ASSOC);
    if ($notification == false) return new OperationResult(ErrorType::NOT_FOUND, "The notification doesn't exist.");
    if ($notification['read_date'] != null) return true;
    
    $sNow = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    // $conn->query("UPDATE notifications SET read_date='$sNow' WHERE user_id=$userId AND number=$number");
    $conn->query("DELETE FROM notifications WHERE user_id=$userId AND number=$number LIMIT 1");
    return new OperationResult(SuccessType::SUCCESS);
}

function ban_user(LDPDO $conn, int $userId, \DateTimeInterface $endDate, ?string $reason=null):OperationResult {
    $rowUser = $conn->query("SELECT * FROM users WHERE id=$userId")->fetch(\PDO::FETCH_ASSOC);
    if ($rowUser == null) return new OperationResult(ErrorType::NOT_FOUND, 'User not found.');

    $now = new \DateTimeImmutable('now');
    $sNow = $now->format('Y-m-d H:i:s');
    $sEnd = $endDate->format('Y-m-d H:i:s');
    if ($endDate <= $now) return new OperationResult(ErrorType::INVALID_DATA, 'Current date greater than given end date.');

    if ($conn->query("SELECT * FROM user_bans WHERE user_id=$userId AND ((start_date<='$sNow' AND end_date>='$sNow') OR (start_date<='$sEnd' AND end_date>='$sEnd')) LIMIT 1")->fetch() !== false)
        return new OperationResult(ErrorType::DUPLICATE, 'There already is a ban for the given time period.');
    
    $conn->query('START TRANSACTION');
    $stmt = $conn->prepare("INSERT INTO user_bans(user_id,start_date,end_date,reason) VALUES (?,?,?,?)");
    $stmt->execute([$userId,$sNow,$sEnd,$reason]);
    $conn->query("DELETE FROM connections WHERE user_id=$userId");
    $conn->query('COMMIT');
    
    return new OperationResult(SuccessType::SUCCESS);
}
?>