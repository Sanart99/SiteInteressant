<?php
namespace LDLib\User;

use Ds\Set;
use LDLib\Forum\ThreadPermission;
use LDLib\Database\LDPDO;
use LDLib\General\ErrorType;

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

    public static function initFromRow($row) {
        $data = $row['data'];
        $settings = new UserSettings(json_decode($data['settings'],true));
        return new self($data['id'],new Set(explode(',',$data['titles'])),$data['name'],new \DateTimeImmutable($data['registration_date']),$settings);
    }
}

class UserSettings {
    public readonly ThreadPermission $defaultThreadPermission;

    public function __construct(?array $settings) {
        if ($settings != null && isset($settings['forum'])) {
            $a = $settings['forum'];
            if (isset($a['defaultThreadPermission'])) switch ($a['defaultThreadPermission']) {
                case 'current_users': $this->defaultThreadPermission = ThreadPermission::CURRENT_USERS; break;
                case 'all_users': $this->defaultThreadPermission = ThreadPermission::ALL_USERS; break;
            }
        }
        
        if (!isset($this->defaultThreadPermission)) $this->defaultThreadPermission = ThreadPermission::CURRENT_USERS;
    }
}

function set_notification_to_read(LDPDO $conn, int $userId, int $number, ?\DateTimeInterface $dt = null) {
    $notification = $conn->query("SELECT * FROM notifications WHERE user_id=$userId AND number=$number")->fetch(\PDO::FETCH_ASSOC);
    if ($notification == false) return ErrorType::NOTFOUND;
    if ($notification['read_date'] != null) return true;
    
    $sNow = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $conn->query("UPDATE notifications SET read_date='$sNow' WHERE user_id=$userId AND number=$number");
    return true;
}
?>