<?php
namespace LdLib\Records;

use LDLib\Database\LDPDO;
use LdLib\General\ErrorType;
use Ds\Set;

use function LDLib\Database\{get_lock, release_lock};

enum ActionGroup:string {
    case FORUM = 'forum';
}
?>