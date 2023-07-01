<?php
namespace LDLib\User;

use Ds\Set;
use LDLib\Forum\ThreadPermission;

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
?>