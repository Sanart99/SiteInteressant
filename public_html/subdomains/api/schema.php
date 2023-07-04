<?php
namespace Schema;

$libDir = __DIR__.'/../../lib';
require_once $libDir.'/utils/utils.php';
require_once $libDir.'/db.php';
require_once $libDir.'/parser.php';
require_once $libDir.'/auth.php';
require_once $libDir.'/user.php';
require_once $libDir.'/forum.php';
require_once $libDir.'/utils/arrayTools.php';
require_once __DIR__.'/buffers.php';
dotenv();

use Ds\Set;
use GraphQL\Error\{Error, InvariantViolation};
use GraphQL\Language\AST\{Node, StringValueNode};
use GraphQL\Type\Definition\{InterfaceType, Type, ObjectType, PhpEnumType, ResolveInfo, ScalarType, UnionType};
use GraphQL\Utils\Utils;
use LDLib\Database\LDPDO;
use LDLib\General\{
    ErrorType,
    TypedException
};
use LDLib\Forum\{Thread, Comment, ThreadPermission};
use LDLib\User\RegisteredUser;
use React\Promise\Deferred;
use LDLib\General\PaginationVals;

use function LDLib\Auth\{
    get_user_from_sid,
    login_user,
    logout_user_from_everything,
    process_invite_code,
    register_user
};
use function LDLib\Parser\textToHTML;
use function LDLib\Database\get_tracked_pdo;
use function LDLib\Forum\{
    create_thread,
    thread_add_comment,
    thread_edit_comment,
    thread_remove_comment
};
use function LDLib\Utils\ArrayTools\array_merge_recursive_distinct;

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
                'forum' => [
                    'type' => fn() => Types::Forum(),
                    'resolve' => fn() => Data::Empty
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
                        return $user->id;
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
                'forum_newThread' => [
                    'type' => fn() => Types::getOperationObjectType('OnThread'),
                    'args' => [
                        'title' => Type::nonNull(Type::string()),
                        'tags' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                        'content' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return ErrorType::USER_INVALID;
                        $v = create_thread(DBManager::getConnection(),$user,$args['title'],$args['tags'],$user->settings->defaultThreadPermission,$args['content']);
                        return $v instanceof ErrorType ? $v : $v[0]->id;
                    }
                ],
                'forumThread_addComment' => [
                    'type' => fn() => Types::SimpleOperation(),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'content' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return ErrorType::USER_INVALID;
                        $v = thread_add_comment(DBManager::getConnection(),$user,$args['threadId'],$args['content']);
                        return $v instanceof ErrorType ? $v : true;
                    }
                ],
                'forumThread_editComment' => [
                    'type' => fn() => Types::SimpleOperation(),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'commentNumber' => Type::nonNull(Type::int()),
                        'content' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return ErrorType::USER_INVALID;
                        $v = thread_edit_comment(DBManager::getConnection(),$user,$args['threadId'],$args['commentNumber'],$args['content']);
                        return $v instanceof ErrorType ? $v : true;
                    }
                ],
                'forumThread_removeComment' => [
                    'type' => fn() => Types::SimpleOperation(),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'commentNumber' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return ErrorType::USER_INVALID;
                        $v = thread_remove_comment(DBManager::getConnection(),$user,$args['threadId'],$args['commentNumber']);
                        return $v instanceof ErrorType ? $v : true;
                    }
                ],
                'loginUser' => [
                    'type' => fn() => Types::getOperationObjectType('OnRegisteredUser'),
                    'args' => [
                        'username' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o,$args) {
                        $user = login_user(DBManager::getConnection(),$args['username'],$args['password'],false,null);
                        return $user instanceof ErrorType ? $user : $user->id;
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
                    'type' => fn() => Types::getOperationObjectType('OnRegisteredUser'),
                    'args' => [
                        'username' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o, $args) {
                        if (!isset($_COOKIE['invite_sid'])) return ErrorType::OPERATION_UNAUTHORIZED;
                        $user = register_user(DBManager::getConnection(), $args['username'], $args['password'], $_COOKIE['invite_sid']);
                        return $user instanceof ErrorType ? $user : $user->id;
                    }
                ],
                'uploadAvatar' => [
                    'type' => fn() => Types::getOperationObjectType('OnRegisteredUser'),
                    'resolve' => function() {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return ErrorType::OPERATION_UNAUTHORIZED;
                        if (!isset($_FILES['imgAvatar'])) return ErrorType::NOTFOUND;
                        $file = $_FILES['imgAvatar'];
                        if (!isset($file['error']) || is_array($file['error']) || $file['error'] != UPLOAD_ERR_OK || $file['size'] > 20000) return ErrorType::INVALID;
                        $ext = array_search(mime_content_type($file['tmp_name']),[
                            'jpg' => 'image/jpeg',
                            'gif' => 'image/gif',
                            'png' => 'image/png'
                        ], true);
                        if ($ext === false) return ErrorType::INVALID;
                        
                        $avatarName = "{$user->id}-".sha1_file($file['tmp_name']).".$ext";
                        $v = move_uploaded_file($file['tmp_name'],Context::$avatarsDir."/$avatarName");
                        if ($v === false) return ErrorType::UNKNOWN;
                        if (DBManager::getConnection()->query("UPDATE users SET avatar_name='$avatarName' WHERE id={$user->id}") === false) return ErrorType::DATABASE_ERROR;
                        return $user->id;
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
                    case (preg_match('/^forum_\d+$/',$id,$m) > 0): $s = 'Thread'; break;
                    case (preg_match('/^forum_\d+-\d+$/',$id,$m) > 0): $s = 'Comment'; break;
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
                'startCursor' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => $o->startCursor
                ],
                'endCursor' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => $o->endCursor
                ],
                'pageCount' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => $o->pageCount
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

/***** Operations *****/


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

class ForumType extends ObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'threads' => [
                    'type' => fn() => Types::getConnectionObjectType('Thread'),
                    'args' => [
                        'first' => Type::int(),
                        'last' => Type::int(),
                        'after' => Type::id(),
                        'before' => Type::id(),
                        'sortBy' => Type::string()
                    ],
                    'resolve' => function($o, $args, $__, $ri) {
                        if (Context::getAuthenticatedUser() == null) return null;
                        $pag = new PaginationVals($args['first']??null,$args['last']??null,$args['after']??null,$args['before']??null);
                        $pag->sortBy = $args['sortBy']??'';
                        ForumBuffer::requestThreads($pag);
                        return quickReactPromise(function() use($o,$args,$pag,$ri) {
                            $threadData = ForumBuffer::getThreads($pag);
                            return $threadData;
                        });
                    }
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class ThreadType extends ObjectType {
    private static function process(mixed $o, callable $f) {
        if (Context::getAuthenticatedUser() == null) return null;
        if (is_array($o) && isset($o['cursor'], $o['edge'])) $o = $o['edge']['data']['id'];
        ForumBuffer::requestThread($o);
        return quickReactPromise(function() use($o,$f) {
            $row = ForumBuffer::getThread($o);
            if ($row == null || $row['data'] == null) return null;
            return $f($row);
        });
    }

    public function __construct(array $config2 = null) {
        $config = [
            'interfaces' => [Types::Node()],
            'fields' => [
                'id' => [
                    'type' => fn() => Type::id(),
                    'resolve' => fn($o) => self::process($o,fn($row) => Thread::initFromRow($row)->nodeId)
                ],
                'dbId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['id'])
                ],
                'authorId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['author_id'])
                ],
                'title' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['title'])
                ],
                'tags' => [
                    'type' => fn() => Type::listOf(Type::string()),
                    'resolve' => fn($o) => self::process($o,fn($row) => explode(',',$row['data']['tags']))
                ],
                'creationDate' => [
                    'type' => fn() => Types::DateTime(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['creation_date'])
                ],
                'lastUpdateDate' => [
                    'type' => fn() => Types::DateTime(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['last_update_date'])
                ],
                'permission' => [
                    'type' => fn() => Types::ThreadPermission(),
                    'resolve' => fn($o) => self::process($o,fn($row) => ThreadPermission::from($row['data']['permission']))
                ],
                'comments' => [
                    'type' => fn() => Types::getConnectionObjectType('Comment'),
                    'args' => [
                        'first' => Type::int(),
                        'last' => Type::int(),
                        'after' => Type::id(),
                        'before' => Type::id()
                    ],
                    'resolve' => function($o, $args, $__, $ri) {
                        $pag = new PaginationVals($args['first']??null,$args['last']??null,$args['after']??null,$args['before']??null);
                        return self::process($o,function($row) use($pag) {
                            ForumBuffer::requestComments($row['data']['id'],$pag);
                            return quickReactPromise(function() use ($row,$pag) {
                                $data = ForumBuffer::getComments($row['data']['id'],$pag);
                                return $data;
                            });
                        });
                    }
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class CommentType extends ObjectType {
    private static function process(mixed $o, callable $f) {
        if (Context::getAuthenticatedUser() == null) return null;
        if (is_array($o) && isset($o['cursor'], $o['edge'])) $o = Comment::initFromRow($o['edge']['data'])->nodeId;
        ForumBuffer::requestComment($o);
        return quickReactPromise(function() use($o,$f) {
            $row = ForumBuffer::getComment($o);
            if ($row == null || $row['data'] == null) return null;
            return $f($row);
        });
    }

    public function __construct(array $config2 = null) {
        $config = [
            'interfaces' => [Types::Node()],
            'fields' => [
                'id' => [
                    'type' => fn() => Type::id(),
                    'resolve' => fn($o) => self::process($o,fn($row) => Comment::initFromRow($row)->nodeId)
                ],
                'threadId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['thread_id'])
                ],
                'number' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['number'])
                ],
                'authorId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['author_id'])
                ],
                'creationDate' => [
                    'type' => fn() => Types::DateTime(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['creation_date'])
                ],
                'lastEditionDate' => [
                    'type' => fn() => Types::DateTime(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['last_edition_date'])
                ],
                'content' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['content'])
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
    public static string $avatarsDir = __DIR__.'/../res/avatars';

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

class Generator {
    public static Set $generatedConnections;

    public static function init() {
       self::$generatedConnections = new Set();

       self::genQuickOperation('OnRegisteredUser',['registeredUser' => 'Types::RegisteredUser()']);
       self::genQuickOperation('OnThread',['thread' => 'Types::Thread()']);
    }

    public static function genConnection(string $objectType) {
        if (preg_match('/^\w+$/',$objectType) === 0) throw new \Exception("genConnection error: $objectType");
        eval(<<<PHP
        namespace Schema;

        class {$objectType}sConnectionType extends ConnectionType {
            public function __construct(array \$config2 = null) {
                \$config = [
                    'fields' => [
                        'edges' => [
                            'resolve' => fn(\$o) => \$o['data']
                        ],
                        'pageInfo' => [
                            'resolve' => fn(\$o) =>  \$o['metadata']['pageInfo']
                        ]
                    ]
                ];
                parent::__construct(fn() => Types::getEdgeObjectType('{$objectType}'), \$config2 == null ? \$config : array_merge_recursive_distinct(\$config,\$config2));
            }
        }
        
        class {$objectType}EdgeType extends EdgeType {
            public function __construct(array \$config2 = null) {
                \$config = [
                    'fields' => [
                        'node' => [
                            'resolve' => fn(\$o) => \$o
                        ],
                        'cursor' => [
                            'resolve' => fn(\$o) => \$o['cursor']
                        ]
                    ]
                ];
                parent::__construct(fn() => Types::{$objectType}(), \$config2 == null ? \$config : array_merge_recursive_distinct(\$config,\$config2));
            }
        }
        PHP);
        self::$generatedConnections[] = $objectType;
    }

    public static function genQuickOperation(string $name, array $fieldsKV) {
        if (preg_match('/^\w+$/',$name) === 0) throw new \Exception("genOperation error: $name");
        $sFields = '';
        foreach ($fieldsKV as $k => $v)
            if (preg_match('/^\w+$/',$k) === 0 || preg_match('/^[\w:\(\)$]+$/',$v) === 0) throw new \Exception("genOperation error: [$k=>$v]");
            else {
                $sFields .= <<<PHP
                '$k' => [
                    'type' => $v,
                    'resolve' => fn(\$o) => \$o instanceof ErrorType ? null : \$o
                ],
                PHP;
            }

        eval(<<<PHP
        namespace Schema;
        use GraphQL\Type\Definition\ObjectType;
        use LDLib\General\ErrorType;

        class Operation{$name}Type extends ObjectType {
            public function __construct(array \$config2 = null) {
                \$config = [
                    'interfaces' => [Types::Operation()],
                    'fields' => [
                        Types::Operation()->getField('success'),
                        Types::Operation()->getField('resultCode'),
                        Types::Operation()->getField('resultMessage'),
                        $sFields
                    ]
                ];
                parent::__construct(\$config2 == null ? \$config : array_merge_recursive_distinct(\$config,\$config2));
            }
        }
        PHP);
    }
}
Generator::init();

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

    public static function getConnectionObjectType(string $s) {
        if (!Generator::$generatedConnections->contains($s)) Generator::genConnection($s);
        return self::$types["{$s}sConnection"] ??= (new \ReflectionClass("\\Schema\\{$s}sConnectionType"))->newInstance();
    }

    public static function getEdgeObjectType(string $s) {
        if (!Generator::$generatedConnections->contains($s)) Generator::genConnection($s);
        return self::$types["{$s}Edge"] ??= (new \ReflectionClass("\\Schema\\{$s}EdgeType"))->newInstance();
    }

    public static function getOperationObjectType(string $name) {
        return self::$types["Operation{$name}Type"] ??= (new \ReflectionClass("\\Schema\\Operation{$name}Type"))->newInstance();
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

    /*****  *****/

    public static function RegisteredUser():RegisteredUserType {
        return self::$types['RegisteredUser'] ??= new RegisteredUserType();
    }

    public static function Forum():ForumType {
        return self::$types['Forum'] ??= new ForumType();
    }

    public static function Thread():ThreadType {
        return self::$types['Thread'] ??= new ThreadType();
    }

    public static function Comment():CommentType {
        return self::$types['Comment'] ??= new CommentType();
    }

    /***** Enums *****/

    public static function ThreadPermission():PhpEnumType {
        return self::$types['ThreadPermission'] ??= new PhpEnumType(ThreadPermission::class);
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