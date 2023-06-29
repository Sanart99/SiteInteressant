<?php
namespace LDLib\User;

use Ds\Set;

abstract class User {
    public readonly int $id;
    public readonly string $username;
    public readonly Set $titles;
    public readonly \DateTimeImmutable $registrationDate;

    public function __construct(int $id, Set $titles, string $username, \DateTimeImmutable $registrationDate) {
        $this->id = $id;
        $this->titles = $titles;
        $this->username = $username;
        $this->registrationDate = $registrationDate;
    }
}

class RegisteredUser extends User {
    public function __construct(int $id, Set $titles, string $username, \DateTimeImmutable $registrationDate) {
        parent::__construct($id,$titles,$username,$registrationDate);
    }

    public function isAdministrator():bool {
        return $this->titles->contains('Administrator');
    }

    public static function initFromRow($row) {
        $data = $row['data'];
        return new self($data['id'],new Set(explode(',',$data['titles'])),$data['name'],new \DateTimeImmutable($data['registration_date']));
    }
}
?>