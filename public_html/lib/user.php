<?php
namespace LDLib\User;

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
    public function __construct(int $id, Set $titles, string $username, \DateTimeImmutable $registrationDate, UserSettings $settings) {
        parent::__construct($id,$titles,$username,$registrationDate,$settings);
    }

    public function isAdministrator():bool {
        return $this->titles->contains('Administrator');
    }

    public function saveSettings(LDPDO $conn):bool {
        $stmt = $conn->prepare("UPDATE users SET settings=:settings WHERE id=:userId LIMIT 1");
        $stmt->execute([
            ':userId' => $this->id,
            ':settings' => json_encode([
                'notifications' => $this->settings->notificationsEnabled,
                'forum' => [
                    'defaultThreadPermission' => $this->settings->defaultThreadPermission,
                    'notif_newThread' => $this->settings->notif_newThread,
                    'notif_newCommentOnFollowedThread' => $this->settings->notif_newCommentOnFollowedThread
                ]
            ],JSON_THROW_ON_ERROR)
        ]);

        return true;
    }

    public static function initFromRow(array $row) {
        $data = array_key_exists('data',$row) && array_key_exists('metadata',$row) ? $row['data'] : $row;
        $settings = new UserSettings(json_decode($data['settings'],true));
        return new self($data['id'],new Set(explode(',',$data['titles'])),$data['name'],new \DateTimeImmutable($data['registration_date']),$settings);
    }
}

class UserSettings {
    public ThreadPermission $defaultThreadPermission;
    public bool $notificationsEnabled;
    public bool $notif_newThread;
    public bool $notif_newCommentOnFollowedThread;

    public function __construct(?array $settings) {
        $this->notificationsEnabled = (bool)($settings['notifications']??false);
        
        if ($settings != null && isset($settings['forum'])) {
            $a = $settings['forum'];
            if (isset($a['defaultThreadPermission'])) switch ($a['defaultThreadPermission']) {
                case 'current_users': $this->defaultThreadPermission = ThreadPermission::CURRENT_USERS; break;
                case 'all_users': $this->defaultThreadPermission = ThreadPermission::ALL_USERS; break;
            }
            $this->notif_newThread = (bool)($a['notif_newThread']??false);
            $this->notif_newCommentOnFollowedThread = (bool)($a['notif_newCommentOnFollowedThread']??false);
        }
        
        $this->defaultThreadPermission ??= ThreadPermission::CURRENT_USERS;
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
                case 'notifications': $user->settings->notificationsEnabled = (bool)$values[$i]; break;
                case 'notif_newThread': $user->settings->notif_newThread = (bool)$values[$i]; break;
                case 'notif_newCommentOnFollowedThread': $user->settings->notif_newCommentOnFollowedThread = (bool)$values[$i]; break;
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
?>