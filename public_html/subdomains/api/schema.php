<?php
namespace Schema;

$libDir = __DIR__.'/../../lib';
require_once $libDir.'/utils/utils.php';
require_once $libDir.'/db.php';
require_once $libDir.'/parser.php';
require_once $libDir.'/auth.php';
require_once $libDir.'/user.php';
require_once __DIR__.'/buffers.php';
dotenv();

use GraphQL\Error\{Error, InvariantViolation};
use GraphQL\Language\AST\{Node, StringValueNode};
use GraphQL\Type\Definition\{InterfaceType, Type, ObjectType, PhpEnumType, ResolveInfo, ScalarType, UnionType};
use GraphQL\Utils\Utils;
use LDLib\Database\LDPDO;
use LDLib\General\{
    ErrorType,
    TypedException
};
use LDLib\User\RegisteredUser;
use React\Promise\Deferred;

use function LDLib\Auth\{
    get_user_from_sid,
    login_user,
    logout_user_from_everything,
    process_invite_code,
    register_user
};
use function LDLib\Parser\textToHTML;
use function LDLib\Database\get_tracked_pdo;

enum Data:string {
    case Empty = '[EMPTY DATA]';
}

function quickReactPromise(callable $f) {
    $d = new Deferred();
    $p = $d->promise();
    $p = $p->then($f);
    $d->resolve();
    return $p;
}

class QueryType extends ObjectType {
    public function __construct() {
        parent::__construct([
            'fields' => [
                'node' => [
                    'type' => fn() => Types::Node(),
                    'args' => [
                        'id' => Type::nonNull(Type::id())
                    ],
                    'resolve' => fn($_, $args) => $args['id']
                ],
                'parseText' => [
                    'type' => fn() => Type::string(),
                    'args' => [
                        'text' => Type::string()
                    ],
                    'resolve' => fn($o, $args) => textToHTML($args['text'])
                ],
                'viewer' => [
                    'type' => fn() => Types::RegisteredUser(),
                    'resolve' => function() {
                        if (!isset($_COOKIE['sid'])) return null;
                        $user = get_user_from_sid(DBManager::getConnection(), $_COOKIE['sid']);
                        if ($user == null) return null;
                        return $user['data']['id'];
                    }
                ]
            ]
        ]);
    }
}

class MutationType extends ObjectType {
    public function __construct() {
        parent::__construct([
            'fields' => [
                'loginUser' => [
                    'type' => fn() => Types::OperationOnRegisteredUser(),
                    'args' => [
                        'username' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o,$args) {
                        $user = login_user(DBManager::getConnection(),$args['username'],$args['password'],false,null);
                        return $user instanceof ErrorType ? $user : $user['data']['id'];
                    }
                ],
                'logoutUserFromEverything' => [
                    'type' => fn() => Types::SimpleOperation(),
                    'resolve' => function ($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return ErrorType::USER_INVALID;
                        return 'Disconnected from '.(string)logout_user_from_everything(DBManager::getConnection(), $user->id).' device(s).';
                    }
                ],
                'processInviteCode' => [
                    'type' => fn() => Types::SimpleOperation(),
                    'args' => [
                        'code' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o, $args) {
                        $v = process_invite_code(DBManager::getConnection(), $args['code']);
                        return $v instanceof ErrorType ? $v : true;
                    }
                ],
                'registerUser' => [
                    'type' => fn() => Types::OperationOnRegisteredUser(),
                    'args' => [
                        'username' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o, $args) {
                        if (!isset($_COOKIE['invite_sid'])) return ErrorType::OPERATION_UNAUTHORIZED;
                        $user = register_user(DBManager::getConnection(), $args['username'], $args['password'], $_COOKIE['invite_sid']);
                        return $user instanceof ErrorType ? $user : $user['data']['id'];
                    }
                ]
            ]
        ]);
    }
}

/***** Interfaces *****/

class NodeType extends InterfaceType {
    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'id' => Type::nonNull(Type::id())
            ],
            'resolveType' => function ($id) {
                switch (true) {
                    default: throw new TypedException("Couldn't find a node with id '$id'.", ErrorType::NOTFOUND);
                }

                if (isset($s)) try {
                    $rm = (new \ReflectionMethod(Types::class, $s));
                    return $rm->invoke(null);
                } catch (\Exception $e) { }
                
                throw new TypedException("Couldn't find a node with id '$id'.", ErrorType::NOTFOUND);
            }
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class OperationType extends InterfaceType {
    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'success' => [
                    'type' => fn() => Type::nonNull(Type::boolean()),
                    'resolve' => fn($o) => !($o instanceof ErrorType)
                ],
                'resultCode' => [
                    'type' => fn() => Type::nonNull(Type::string()),
                    'resolve' => fn($o) => $o instanceof ErrorType ? $o->name : 'no_problem'
                ],
                'resultMessage' => [
                    'type' => fn() => Type::nonNull(Type::string()),
                    'resolve' => fn($o) => $o instanceof ErrorType ? 'Something went wrong.' : 'No problem detected.'
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

/***** Parent Classes and Unions *****/

class SimpleOperationType extends ObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'interfaces' => [Types::Operation()],
            'fields' => [
                Types::Operation()->getField('success'),
                Types::Operation()->getField('resultCode'),
                'resultMessage' => [
                    'type' => fn() => Type::nonNull(Type::string()),
                    'resolve' => fn($o) => $o instanceof ErrorType ? 'Something went wrong.' : (is_string($o) ? $o : 'No problem detected.')
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class ConnectionType extends ObjectType {
    public function __construct(callable $edgeType, array $config2 = null) {
        $config = [
            'fields' => [
                'pageInfo' => [
                    'type' => fn() => Type::nonNull(Types::PageInfo())
                ],
                'edges' => [
                    'type' => fn() => Type::listOf($edgeType())
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class EdgeType extends ObjectType {
    public function __construct(callable $nodeType, array $config2 = null) {
        $config = [
            'fields' => [
                'node' => [
                    'type' => fn() => $nodeType(),
                ],
                'cursor' => [
                    'type' => Type::nonNull(Type::string())
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class PageInfoType extends ObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'hasNextPage' => [
                    'type' => fn() => Type::nonNull(Type::boolean()),
                    'resolve' => fn($o) => $o->hasNextPage
                ],
                'hasPreviousPage' => [
                    'type' => fn() => Type::nonNull(Type::boolean()),
                    'resolve' => fn($o) => $o->hasPreviousPage
                ],
                'startCursror' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => $o->startCursor
                ],
                'endCursor' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => $o->endCursor
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

/***** Operations *****/

class OperationOnRegisteredUserType extends ObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'interfaces' => [Types::Operation()],
            'fields' => [
                Types::Operation()->getField('success'),
                Types::Operation()->getField('resultCode'),
                Types::Operation()->getField('resultMessage'),
                'registeredUser' => [
                    'type' => Types::RegisteredUser(),
                    'resolve' => fn($o) => $o instanceof ErrorType ? null : $o
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

/*****  *****/

class RegisteredUserType extends ObjectType {
    public static function authCheck(array $row, ?ResolveInfo $ri=null) {
        $authUser = Context::getAuthenticatedUser();
        if ($authUser != null && ($authUser->id === $row['data']['id'] || $authUser->titles->contains('Administrator'))) return true;
        if ($ri != null) Context::addLog($ri->fieldNodes[0]->name->value, "User not authorized.");
        return false;
    }

    public function __construct(array $config2 = null) {
        $config = [
            'interfaces' => [Types::Node()],
            'fields' => [
                'id' => [
                    'type' => fn() => Type::id(),
                    'resolve' => function($o,$args,$context,$ri) {
                        UsersBuffer::requestFromId($o);
                        return quickReactPromise(function() use($o,$ri) {
                            $row = UsersBuffer::getFromId($o);
                            if ($row == null || !self::authCheck($row,$ri)) return null;
                            return "USER_{$row['data']['id']}";
                        });
                    }
                ],
                'name' => [
                    'type' => fn() => Type::string(),
                    'resolve' => function($o,$args,$context,$ri) {
                        UsersBuffer::requestFromId($o);
                        return quickReactPromise(function() use($o,$ri) {
                            $row = UsersBuffer::getFromId($o);
                            if ($row == null || !self::authCheck($row,$ri)) return null;
                            return $row['data']['name'];
                        });
                    }
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

/***** Support classes *****/

class Context {
    public static array $a = [];
    public static array $headers = [];
    public static array $logs = [];
    public static int $cost = 0;

    public static function init() {
        self::$a = [
            'authenticatedUser' => null
        ];
        foreach (getallheaders() as $k => $v) self::$headers[strtolower($k)] = $v;

        if (isset($_COOKIE['sid'])) self::$a['authenticatedUser'] = get_user_from_sid(DBManager::getConnection(), $_COOKIE['sid']);
    }

    public static function addLog(string $name, string $msg) {
        array_push(self::$logs, "$name: $msg");
    }

    public static function getAuthenticatedUser():?RegisteredUser {
        return self::$a['authenticatedUser'];
    }

    public static function setAuthenticatedUser(RegisteredUser $user):?RegisteredUser {
        return self::$a['authenticatedUser'] = $user;
    }
}

class DBManager {
    private static ?LDPDO $conn = null;

    public static function getConnection():LDPDO {
        return self::$conn ??= get_tracked_pdo();
    }
}

/***** Scalars *****/

class DateTimeType extends ScalarType {
    public function serialize($value) {
        if ($value instanceof \DateTimeInterface) $value->format('Y-m-d H:i:s');
        else if (is_string($value) && preg_match('/^\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/', $value) > 0) return $value;
        
        throw new InvariantViolation("Could not serialize following value as DateTime: ".Utils::printSafe($value));
    }

    public function parseValue($value) {
        if (!is_string($value) || preg_match('/^\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/', $value) == 0)
            throw new Error("Cannot represent following value as DateTime: ".Utils::printSafeJson($value));
        
        try {
            $v = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new Error("Cannot represent following value as DateTime: ".Utils::printSafeJson($value));
        }
        
        return $v;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null) {
        if (!$valueNode instanceof StringValueNode)
            throw new Error('Query error: Can only parse strings got: '.$valueNode->kind, [$valueNode]);

        $s = $valueNode->value;
        if (preg_match('/^\d\d\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/', $s) == 0) throw new Error("Not a valid datetime: '$s'", [$valueNode]);
        try { $v = new \DateTimeImmutable($s); } catch (\Exception $e) { throw new Error("Not a valid datetime: '$s'", [$valueNode]); }

        return $v;
    }
}

class DateType extends ScalarType {
    public function serialize($value) {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        else if (is_string($value) && preg_match('/^\d\d\d\d-\d\d-\d\d$/', $value, $m) > 0) return $value;

        throw new InvariantViolation("Could not serialize following value as Date: ".Utils::printSafe($value));
    }

    public function parseValue($value) {
        if (!is_string($value) || preg_match('/^\d\d\d\d-\d\d-\d\d$/', $value) == 0)
            throw new Error("Cannot represent following value as Date: ".Utils::printSafeJson($value));
        
        try {
            $v = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new Error("Cannot represent following value as Date: ".Utils::printSafeJson($value));
        }
        
        return $v;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null) {
        if (!$valueNode instanceof StringValueNode)
            throw new Error('Query error: Can only parse strings got: '.$valueNode->kind, [$valueNode]);

        $s = $valueNode->value;
        if (preg_match('/^\d\d\d\d-\d\d-\d\d$/', $s) == 0) throw new Error("Not a valid date: '$s'", [$valueNode]);
        try { $v = new \DateTimeImmutable($s); } catch (\Exception $e) { throw new Error("Not a valid date: '$s'", [$valueNode]); }

        return $v;
    }
}

class TimeType extends ScalarType {
    public function serialize($value) {
        if ($value instanceof \DateTimeInterface) return $value->format('H:i:s');
        else if (is_string($value) && preg_match('/^\d\d:\d\d:\d\d$/', $value, $m) > 0) return $value;

        throw new InvariantViolation("Could not serialize following value as Time: ".Utils::printSafe($value));
    }

    public function parseValue($value) {
        if (!is_string($value) || preg_match('/^\d\d:\d\d:\d\d$/', $value) == 0)
            throw new Error("Cannot represent following value as Time: ".Utils::printSafeJson($value));
        
        try {
            $v = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new Error("Cannot represent following value as Time: ".Utils::printSafeJson($value));
        }
        
        return $v;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null) {
        if (!$valueNode instanceof StringValueNode)
            throw new Error('Query error: Can only parse strings got: '.$valueNode->kind, [$valueNode]);

        $s = $valueNode->value;
        if (preg_match('/^\d\d:\d\d:\d\d$/', $s) == 0) throw new Error("Not a valid time: '$s'", [$valueNode]);
        try { $v = new \DateTimeImmutable($s); } catch (\Exception $e) { throw new Error("Not a valid time: '$s'", [$valueNode]); }

        return $v;
    }
}

class EmailType extends ScalarType {
    public function serialize($value) {
        if (is_string($value) && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value, $m) > 0) return $value;

        throw new InvariantViolation("Could not serialize following value as Email: ".Utils::printSafe($value));
    }

    public function parseValue($value) {
        if (!is_string($value) || preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value) == 0)
            throw new Error("Cannot represent following value as Email: ".Utils::printSafeJson($value));

        return $value;
    }

    public function parseLiteral(Node $valueNode, ?array $variables = null) {
        if (!$valueNode instanceof StringValueNode)
            throw new Error('Query error: Can only parse strings got: '.$valueNode->kind, [$valueNode]);

        $s = $valueNode->value;
        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $s) == 0) throw new Error("Not a valid email: '$s'", [$valueNode]);

        return $s;
    }
}

/*****  *****/

class Types {
    private static array $types = [];

    public static function Query():QueryType {
        return self::$types['Query'] ??= new QueryType();
    }

    public static function Mutation():MutationType {
        return self::$types['Mutation'] ??= new MutationType();
    }

    /***** Interfaces *****/

    public static function Node():NodeType {
        return self::$types['Node'] ??= new NodeType();
    }

    public static function Operation():OperationType {
        return self::$types['Operation'] ??= new OperationType();
    }

    /***** Parent Classes and Unions *****/

    public static function SimpleOperation():SimpleOperationType {
        return self::$types['SimpleOperation'] ??= new SimpleOperationType();
    }

    public static function PageInfo():PageInfoType {
        return self::$types['PageInfo'] ??= new PageInfoType();
    }

    /***** Operations *****/

    public static function OperationOnRegisteredUser():OperationOnRegisteredUserType {
        return self::$types['OperationOnRegisteredUser'] ??= new OperationOnRegisteredUserType();
    }

    /*****  *****/

    public static function RegisteredUser():RegisteredUserType {
        return self::$types['RegisteredUser'] ??= new RegisteredUserType();
    }

    /***** Scalars *****/

    public static function DateTime():DateTimeType {
        return self::$types['DateTime'] ??= new DateTimeType();
    }

    public static function Date():DateType {
        return self::$types['Date'] ??= new DateType();
    }

    public static function Time():TimeType {
        return self::$types['Time'] ??= new TimeType();
    }

    public static function Email():EmailType {
        return self::$types['Email'] ??= new EmailType();
    }
}
?>