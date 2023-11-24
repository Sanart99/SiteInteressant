<?php
namespace Schema;

$libDir = __DIR__.'/../../lib';
require_once $libDir.'/utils/utils.php';
require_once $libDir.'/db.php';
require_once $libDir.'/parser.php';
require_once $libDir.'/auth.php';
require_once $libDir.'/user.php';
require_once $libDir.'/forum.php';
require_once $libDir.'/records.php';
require_once $libDir.'/net.php';
require_once $libDir.'/utils/arrayTools.php';
require_once $libDir.'/aws.php';
require_once __DIR__.'/buffers.php';
dotenv();

use Ds\Set;
use GraphQL\Error\{Error, InvariantViolation};
use GraphQL\Language\AST\{Node, StringValueNode};
use GraphQL\Type\Definition\{InputObjectType, InterfaceType, Type, ObjectType, PhpEnumType, ScalarType, UnionType};
use GraphQL\Utils\Utils;
use LDLib\Database\LDPDO;
use LDLib\General\{
    ErrorType,
    PageInfo,
    SuccessType,
    TypedException,
    OperationResult
};
use LDLib\Forum\{Thread, Comment, ForumSearchQuery, ThreadPermission, SearchSorting, TidComment, TidThread};
use LDLib\User\RegisteredUser;
use React\Promise\Deferred;
use LDLib\General\PaginationVals;
use LDLib\Net\LDWebPush;
use LdLib\Records\ActionGroup;
use LDLib\User\UserSettings;

use LDLib\AWS\AWS;

use function LDLib\Auth\{
    get_user_from_sid,
    login_user,
    logout_user,
    logout_user_from_everything,
    process_invite_code,
    register_user
};
use function LDLib\Parser\textToHTML;
use function LDLib\Database\get_tracked_pdo;
use function LDLib\Forum\{
    create_thread, remove_thread,
    kube_thread, unkube_thread, kube_comment, unkube_comment, octohit_comment,
    search,
    thread_add_comment, thread_edit_comment, thread_remove_comment,
    mark_all_threads_as_read, mark_thread_as_read, thread_mark_comments_as_read, thread_mark_comments_as_notread,
    thread_follow, thread_unfollow,
    check_can_remove_thread, check_can_edit_comment, check_can_remove_comment
};
use function LDLib\Net\{curl_fetch};
use function LdLib\User\{set_notification_to_read, set_user_setting};
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
                'records' => [
                    'type' => fn() => Types::getConnectionObjectType('Record'),
                    'args' => [
                        'first' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'last' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'after' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'before' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'withPageCount' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                        'withLastPageSpecialBehavior' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                    ],
                    'resolve' => function($o, $args, $__, $ri) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return null;

                        $pag = new PaginationVals($args['first'],$args['last'],$args['after'],$args['before'],$args['withPageCount'],$args['withLastPageSpecialBehavior']);
                        RecordsBuffer::requestMultiple($pag);
                        return quickReactPromise(function() use($pag) {
                            return RecordsBuffer::getMultiple($pag);
                        });
                    }
                ],
                'forum' => [
                    'type' => fn() => Types::Forum(),
                    'resolve' => fn() => Data::Empty
                ],
                'parseText' => [
                    'type' => fn() => Type::string(),
                    'args' => [
                        'text' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return null;
                        return textToHTML($user->id, $args['text']);
                    }
                ],
                'search' => [
                    'type' => fn() => Types::getConnectionObjectType('ForumSearchItem'),
                    'args' => [
                        'keywords' => Type::nonNull(Type::string()),
                        'sortBy' => [ 'type' => Type::nonNull(Types::SearchSorting()), 'defaultValue' => SearchSorting::ByDate ],
                        'startDate' => [ 'type' => Types::DateTime(), 'defaultValue' => null ],
                        'endDate' => [ 'type' => Types::DateTime(), 'defaultValue' => null ],
                        'userIds' => [ 'type' => Type::listOf(Type::nonNull(Type::int())), 'defaultValue' => null ],
                        'threadsType' => [ 'type' => Types::ThreadType(), 'defaultValue' => \LdLib\Forum\ThreadType::Twinoid ],
                        'first' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'last' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'after' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'before' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'withPageCount' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                        'withLastPageSpecialBehavior' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                        'skipPages' => ['type' => Type::nonNull(Type::int()), 'defaultValue' => 0]
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return ConnectionType::getEmptyConnection();
                        $pag = new PaginationVals($args['first'],$args['last'],$args['after'],$args['before'],$args['withPageCount'],$args['withLastPageSpecialBehavior']);
                        $pag->skipPages = $args['skipPages'];
                        
                        try {
                            $fsq = new ForumSearchQuery($args['threadsType'], $args['keywords'], $args['sortBy'], $args['startDate'], $args['endDate'], $args['userIds']);
                        } catch (TypedException $e) {
                            if ($e->getErrorType() == ErrorType::INVALID_DATA) return ConnectionType::getEmptyConnection();
                            else throw $e;
                        }
                        ForumBuffer::requestSearch($fsq,$pag);
                        return quickReactPromise(function() use($fsq,$pag) {
                            return ForumBuffer::getSearch($fsq,$pag);
                        });
                    }
                ],
                'viewer' => [
                    'type' => fn() => Types::RegisteredUser(),
                    'resolve' => function() {
                        $user = Context::getAuthenticatedUser();
                        return $user == null ? null : $user->id;
                    }
                ],
                'userlist' => [
                    'type' => fn() => Types::getConnectionObjectType('AnyUser'),
                    'args' => [
                        'first' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'last' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'after' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'before' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'twinoidUsers' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false]
                    ],
                    'resolve' => function($o,$args) {
                        if (Context::getAuthenticatedUser() == null) return null;
                        $pag = new PaginationVals($args['first'],$args['last'],$args['after'],$args['before']);
                        UsersBuffer::requestUsers($pag, $args['twinoidUsers']);
                        return quickReactPromise(function() use($pag,&$args) {
                            return UsersBuffer::getUsers($pag, $args['twinoidUsers']);
                        });
                    }
                ],
                'testMode' => [
                    'type' => fn() => Type::nonNull(Type::boolean()),
                    'resolve' => fn() => (bool)$_SERVER['LD_TEST']
                ],
                'getObjectFromBucket' => [
                    'type' => fn() => Type::string(),
                    'args' => [
                        'name' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return null;

                        $keyName = $user->id."_".$args['name'];

                        $vCache = Cache::get("s3:general:$keyName");
                        if ($vCache != null) return $vCache;

                        S3Buffer::requestKeyData($keyName);
                        return quickReactPromise(function() use (&$keyName) {
                            $row = S3Buffer::getKeyData($keyName);
                            if ($row['data'] == null) return null;

                            $res = AWS::getS3Client()->getObject($_SERVER['LD_AWS_BUCKET_GENERAL'],$row['data']['obj_key']);
                            
                            if ($res['@metadata']['statusCode'] !== 200) return null;

                            $v = base64_encode($res['Body']);
                            Cache::set("s3:general:$keyName",$v);
                            return $v;
                        });
                    }
                ],
                'getS3ObjectMetadata' => [
                    'type' => fn() => Types::S3ObjectMetadata(),
                    'args' => [
                        'key' => Type::string()
                    ],
                    'resolve' => function($o,$args) {
                        $s3Key = $args['key'];
                        $redisKey = "s3:general:meta:$s3Key";

                        $vCache = Cache::get($redisKey);
                        if ($vCache != null) return json_decode($vCache,true);

                        $s3Client = AWS::getS3Client();
                        $res = $s3Client->getObject($_SERVER['LD_AWS_BUCKET_GENERAL'],$s3Key,'bytes 0-1');
                        if (!($res instanceof \Aws\Result)) return null;

                        $meta = AWS::extractMetadata($res,$s3Key);
                        Cache::set($redisKey,json_encode($meta),172800);
                        return $meta;
                    }
                ],
                'getServiceWorkerName' => [
                    'type' => fn() => Type::nonNull(Type::string()),
                    'resolve' => fn() => $_SERVER['LD_SERVICEWORKER_NAME']
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
                    'type' => fn() => Type::nonNull(Types::getOperationObjectType('OnThread')),
                    'args' => [
                        'title' => Type::nonNull(Type::string()),
                        'tags' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                        'content' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return create_thread(DBManager::getConnection(),$user,$args['title'],$args['tags'],$user->settings->defaultThreadPermission,$args['content']);
                    }
                ],
                'forum_removeThread' => [
                    'type' => fn() => Type::nonNull(Types::getOperationObjectType('OnThread')),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return remove_thread(DBManager::getConnection(),$user,$args['threadId']);
                    }
                ],
                'forum_kubeThread' => [
                    'type' => fn() => Type::nonNull(Types::getOperationObjectType('OnThread')),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return kube_thread(DBManager::getConnection(),$user,$args['threadId']);
                    }
                ],
                'forum_unkubeThread' => [
                    'type' => fn() => Type::nonNull(Types::getOperationObjectType('OnThread')),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return unkube_thread(DBManager::getConnection(),$user,$args['threadId']);
                    }
                ],
                'forum_kubeComment' => [
                    'type' => fn() => Type::nonNull(Types::getOperationObjectType('OnThreadComment')),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'commNumber' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return kube_comment(DBManager::getConnection(),$user,$args['threadId'],$args['commNumber']);
                    }
                ],
                'forum_unkubeComment' => [
                    'type' => fn() => Type::nonNull(Types::getOperationObjectType('OnThreadComment')),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'commNumber' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return unkube_comment(DBManager::getConnection(),$user,$args['threadId'],$args['commNumber']);
                    }
                ],
                'forum_octohitComment' => [
                    'type' => fn() => Type::nonNull(Types::getOperationObjectType('OnOctohit')),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'commNumber' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return octohit_comment(DBManager::getConnection(),$user,$args['threadId'],$args['commNumber']);
                    }
                ],
                'forumThread_addComment' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'content' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return thread_add_comment(DBManager::getConnection(),$user,$args['threadId'],$args['content']);
                    }
                ],
                'forumThread_editComment' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'commentNumber' => Type::nonNull(Type::int()),
                        'content' => Type::nonNull(Type::string()),
                        'title' => Type::string(),
                        'markAsUnreadToUsers' => ['type' => Type::nonNull(Type::boolean()), 'defaultValue' => false]
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return thread_edit_comment(DBManager::getConnection(),$user,$args['threadId'],$args['commentNumber'],$args['content'],$args['title']??null,$args['markAsUnreadToUsers']);
                    }
                ],
                'forumThread_removeComment' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'commentNumber' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return thread_remove_comment(DBManager::getConnection(),$user,$args['threadId'],$args['commentNumber']);
                    }
                ],
                'forumThread_markAllThreadsAsRead' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'resolve' => function() {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return mark_all_threads_as_read(DBManager::getConnection(),$user);
                    }
                ],
                'forumThread_markThreadAsRead' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return mark_thread_as_read(DBManager::getConnection(),$user,$args['threadId']);
                    }
                ],
                'forumThread_markCommentsAsRead' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'commentNumbers' => Type::nonNull(Type::listOf(Type::nonNull(Type::int())))
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return thread_mark_comments_as_read(DBManager::getConnection(),$user,$args['threadId'],$args['commentNumbers']);
                    }
                ],
                'forumThread_markCommentsAsNotRead' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int()),
                        'commentNumbers' => Type::nonNull(Type::listOf(Type::nonNull(Type::int())))
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return thread_mark_comments_as_notread(DBManager::getConnection(),$user,$args['threadId'],$args['commentNumbers']);
                    }
                ],
                'forumThread_follow' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return thread_follow(DBManager::getConnection(),$user,$args['threadId']);
                    }
                ],
                'forumThread_unfollow' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'threadId' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return thread_unfollow(DBManager::getConnection(),$user,$args['threadId']);
                    }
                ],
                'loginUser' => [
                    'type' => fn() => Type::nonNull(Types::getOperationObjectType('OnRegisteredUser')),
                    'args' => [
                        'username' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string()),
                        'rememberMe' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ]
                    ],
                    'resolve' => function($o,$args) {
                        if (Context::getAuthenticatedUser() != null) return new OperationResult(ErrorType::CONTEXT_INVALID);
                        return login_user(DBManager::getConnection(),$args['username'],$args['password'],$args['rememberMe'],null);
                    }
                ],
                'logoutUser' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'resolve' => function ($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return logout_user(DBManager::getConnection(), $user->id);
                    }
                ],
                'logoutUserFromEverything' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'resolve' => function ($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return logout_user_from_everything(DBManager::getConnection(), $user->id);
                    }
                ],
                'processInviteCode' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'code' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o, $args) {
                        return process_invite_code(DBManager::getConnection(), $args['code']);
                    }
                ],
                'registerUser' => [
                    'type' => fn() => Type::nonNull(Types::getOperationObjectType('OnRegisteredUser')),
                    'args' => [
                        'username' => Type::nonNull(Type::string()),
                        'password' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o, $args) {
                        $authUser = Context::getAuthenticatedUser();
                        if ($authUser != null) return new OperationResult(ErrorType::CONTEXT_INVALID, "A user is currently authenticated.");
                        else if (!isset($_COOKIE['invite_sid'])) return new OperationResult(ErrorType::CONTEXT_INVALID, "Cookie 'invite_sid' not set.");

                        return register_user(DBManager::getConnection(), $args['username'], $args['password'], $_COOKIE['invite_sid']);
                    }
                ],
                'setNotificationToRead' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'number' => Type::nonNull(Type::int())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        return set_notification_to_read(DBManager::getConnection(),$user->id,$args['number']);
                    }
                ],
                'uploadAvatar' => [
                    'type' => fn() => Type::nonNull(Types::getOperationObjectType('OnRegisteredUser')),
                    'resolve' => function() {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);

                        if (!isset($_FILES['imgAvatar'])) return new OperationResult(ErrorType::CONTEXT_INVALID, "Image file not found.");
                        $file = $_FILES['imgAvatar'];

                        if (!isset($file['error']) || is_array($file['error']) || $file['error'] != UPLOAD_ERR_OK) return new OperationResult(ErrorType::CONTEXT_INVALID, "Image file error.");
                        else if ($file['size'] > 20000000) return new OperationResult(ErrorType::CONTEXT_INVALID, "File size must not be greater than 20MB.");

                        $ext = array_search(mime_content_type($file['tmp_name']),[
                            'jpg' => 'image/jpeg',
                            'gif' => 'image/gif',
                            'png' => 'image/png'
                        ], true);
                        if ($ext === false) return new OperationResult(ErrorType::INVALID, "Invalid image type.");
                        
                        $avatarName = "{$user->id}-".sha1_file($file['tmp_name']).".$ext";
                        $v = move_uploaded_file($file['tmp_name'],Context::$avatarsDir."/$avatarName");
                        if ($v === false) return new OperationResult(ErrorType::UNKNOWN, "Couldn't save file.");
                        if (DBManager::getConnection()->query("UPDATE users SET avatar_name='$avatarName' WHERE id={$user->id}") === false) return new OperationResult(ErrorType::DATABASE_ERROR);
                        return new OperationResult(SuccessType::SUCCESS,null,[$user->id]);
                    }
                ],
                'uploadFileToBucket' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'overwrite' => [ 'type' => Type::boolean(), 'defaultValue' => false ],
                        'newNameOnDuplicate'  => [ 'type' => Type::boolean(), 'defaultValue' => true ]
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);

                        if (!isset($_FILES['fileToUpload'])) return new OperationResult(ErrorType::CONTEXT_INVALID, "File not found.");
                        $file = $_FILES['fileToUpload'];
                        if (mb_strlen($file['name']) > 248) return new OperationResult(ErrorType::INVALID_DATA, "File name must not be greater than 248 characters.");
                        if ($file['size'] > 20000000) return new OperationResult(ErrorType::INVALID_DATA, "File size must not be greater than 20MB.");
                        $fileName = $file['name'];
                        $fileData = file_get_contents($file['tmp_name']);
                        $fileSize = $file['size'];
                        $mimeType = mime_content_type($file['tmp_name']);
                        $keyName = "{$user->id}_{$fileName}";
                        $bucketName = $_SERVER['LD_AWS_BUCKET_GENERAL'];
                        if ($mimeType === false) $mimeType = null;

                        $s3 = AWS::getS3Client();
                        if ($s3 == null) return new OperationResult(ErrorType::AWS_ERROR, "Couldn't connect to bucket.");

                        if ($args['overwrite'] !== true && $s3->client->doesObjectExistV2($bucketName,$keyName)) {
                            if ($args['newNameOnDuplicate']) {
                                $keyName = "{$user->id}_".time()."_$fileName";
                            } else return new OperationResult(ErrorType::PROHIBITED, "File already exists.");
                        }

                        $conn = DBManager::getConnection();
                        $stmt = $conn->prepare("INSERT IGNORE INTO s3_general (user_id,obj_key,filename,size,mime_type) VALUES (?,?,?,?,?)");
                        if (!$stmt->execute([$user->id,$keyName,$fileName,$fileSize,$mimeType])) return new OperationResult(ErrorType::DATABASE_ERROR);

                        try {
                            $res = $s3->client->putObject([
                                'Bucket' => $bucketName,
                                'Key' => $keyName,
                                'Body' => $fileData,
                                'ChecksumAlgorithm' => 'SHA256',
                                'ContentType' => $mimeType,
                                'Metadata' => [
                                    'userId' => $user->id,
                                    'username' => $user->username
                                ]
                            ]);
                        } catch (\Aws\Exception\AwsException $e) {
                            $stmt = $conn->prepare("DELETE FROM s3_general WHERE user_id=? AND obj_key=?");
                            $stmt->execute([$user->id,$keyName]);
                            return new OperationResult(ErrorType::AWS_ERROR, "Couldn't put object. (Permissions?)");
                        }

                        if (($res['@metadata']['statusCode']??null) != 200) {
                            $stmt = $conn->prepare("DELETE FROM s3_general WHERE user_id=? AND obj_key=?");
                            $stmt->execute([$user->id,$keyName]);
                            return new OperationResult(ErrorType::AWS_ERROR, "Bad AWS status code.");
                        }
                        
                        return new OperationResult(SuccessType::SUCCESS);
                    }
                ],
                'changeSetting' => [
                    'type' => fn() => Types::SimpleOperation(),
                    'args' => [
                        'vals' => Type::nonNull(Type::listOf(Type::nonNull(Types::SettingInput())))
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);

                        $names = [];
                        $values = [];
                        foreach ($args['vals'] as $v) {
                            $names[] = $v['name'];
                            $values[] = $v['value'];
                        }

                        return set_user_setting(DBManager::getConnection(), $user->id, $names, $values);
                    }
                ],
                'registerPushSubscription' => [
                    'type' => fn() => Type::nonNull(Types::SimpleOperation()),
                    'args' => [
                        'endpoint' => Type::nonNull(Type::string()),
                        'expirationTime' => ['type' => Type::float(), 'defaultValue' => null],
                        'userVisibleOnly'=> Type::nonNull(Type::boolean()),
                        'publicKey' => Type::nonNull(Type::string()),
                        'authToken' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function ($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        $sNow = (new \DateTime('now'))->format('Y-m-d H:i:s');
                        $conn = DBManager::getConnection();

                        $stmt = $conn->prepare("SELECT * FROM push_subscriptions WHERE user_id=? AND endpoint=?");
                        $stmt->execute([$user->id,$args['endpoint']]);
                        if ($stmt->fetch() !== false) return new OperationResult(ErrorType::DUPLICATE);
                        
                        $stmt = $conn->prepare(<<<SQL
                            INSERT INTO push_subscriptions (user_id,remote_public_key,date,endpoint,expiration_time,user_visible_only,auth_token)
                            VALUES (:userId,:remotePublicKey,:date,:endpoint,:expirationTime,:userVisibleOnly,:authToken)
                            SQL
                        );
                        $res = $stmt->execute([
                            ':userId' => $user->id,
                            ':endpoint' => $args['endpoint'],
                            ':expirationTime' => $args['expirationTime'],
                            ':userVisibleOnly' => $args['userVisibleOnly'],
                            ':remotePublicKey' => $args['publicKey'],
                            ':authToken' => $args['authToken'],
                            ':date' => $sNow
                        ]);
                        return new OperationResult($res === true ? SuccessType::SUCCESS : ErrorType::DATABASE_ERROR);
                    }
                ],
                'sendNotification' => [
                    'type' => fn() => Types::getOperationObjectType("OnPush"),
                    'args' => [
                        'userId' => Type::nonNull(Type::int()),
                        'title' => Type::nonNull(Type::string()),
                        'body' => ['type' => Type::string(), 'defaultValue' => null]
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        if ($user->isAdministrator() !== true) return new OperationResult(ErrorType::OPERATION_UNAUTHORIZED);
                        return (new LDWebPush())->sendNotification(DBManager::getConnection(),$user->id,$args['title'],$args['body']);
                    }
                ],
                'uploadTidEmojis' => [
                    'type' => fn() => Types::SimpleOperation(),
                    'args' => [
                        'forUserId' => Type::int()
                    ],
                    'resolve' => function($o, $args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        else if (!$user->titles->contains('Administrator')) return new OperationResult(ErrorType::NOT_ENOUGH_PRIVILEGES);
                        if (!isset($_FILES['smileysJSON'])) return new OperationResult(ErrorType::CONTEXT_INVALID, "JSON missing.");

                        $tidDir = __DIR__.'/../res/emojis/tid';
                        if (!file_exists($tidDir)) if (!mkdir($tidDir)) return new OperationResult(ErrorType::FILE_OPERATION_ERROR);
                        $tidDir = realpath($tidDir);

                        $json = json_decode(file_get_contents($_FILES['smileysJSON']['tmp_name']),true);
                        $conn = DBManager::getConnection();
                        $mandatoryEmojis = ['tid/Twinoid v1/','tid/Twinoid v2/','tid/Twinoid v3/'];
                        set_time_limit(300);
                        $userData = ($args['forUserId']??null) == null ? null : [];
                        foreach ($json as $category => $smData) {
                            $category = htmlspecialchars($category);
                            $dirCategory = "$tidDir/$category";
                            if (!file_exists($dirCategory)) if (!mkdir($dirCategory)) return new OperationResult(ErrorType::FILE_OPERATION_ERROR);
                            foreach ($smData as $sm) {
                                if (preg_match('/\/([^\/]*)$/',$sm['src'],$m) == 0 || !is_array($sm['txts']) ) continue;
                                $name = $m[1];
                                $id = "tid/$category/$name";
                                $img = null;
                                if (preg_match('/\.[^\\\\\/]+$/',$name,$m) == 0) {
                                    $img = curl_fetch($sm['src'])['res'];
                                    $tempLoc = "$dirCategory/{$name}.temp";
                                    file_put_contents($tempLoc,$img);
                                    $fExt = array_search(mime_content_type($tempLoc),[
                                        '.jpg' => 'image/jpeg',
                                        '.gif' => 'image/gif',
                                        '.png' => 'image/png',
                                        '.ico' => 'image/vnd.microsoft.icon'
                                    ], true);
                                    if ($fExt == false) {
                                        Context::addLog('uploadTidEmojis',"no accepted extension for '$id', mime_content_type:'".(string)mime_content_type($tempLoc)."'");
                                        unlink($tempLoc);
                                        continue;
                                    }
                                    $name .= $fExt;
                                    $id .= $fExt;
                                    rename($tempLoc,"$dirCategory/$name");
                                };

                                if (is_array($userData)) {
                                    $skip = false;
                                    foreach ($mandatoryEmojis as $s) if (str_starts_with($id,$s)) { $skip = true; break; }
                                    if (!$skip) $userData[] = ['emoji_id' => $id, 'amount' => $sm['amount']??null];
                                }
                                $stmt = $conn->prepare('INSERT INTO emojis(id,aliases,consommable) VALUES (:id,:aliases,:isConsommable) ON DUPLICATE KEY UPDATE aliases=JSON_MERGE_PATCH(VALUES(aliases),:aliases)');
                                $stmt->execute([':id' => $id, ':aliases' => json_encode($sm['txts']), ':isConsommable' => (int)(($sm['amount']??null) != null)]);

                                if (file_exists("$dirCategory/$name")) continue;
                                if ($img == null) $img = curl_fetch($sm['src'])['res'];
                                file_put_contents("$dirCategory/$name",$img);
                            }
                        }

                        if (is_array($userData)) foreach ($userData as $d) {
                            $stmt = $conn->prepare('INSERT INTO users_emojis(user_id,emoji_id,amount) VALUES(:userId,:id,:amount) ON DUPLICATE KEY UPDATE amount=:amount');
                            $stmt->execute([':userId' => $args['forUserId'], ':id' => $d['emoji_id'], ':amount' => $d['amount']??null]);
                        }
                    }
                ],
                'uploadTidThreads' => [
                    'type' => fn() => Types::SimpleOperation(),
                    'resolve' => function() {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);
                        else if (!$user->titles->contains('Administrator')) return new OperationResult(ErrorType::NOT_ENOUGH_PRIVILEGES);
                        if (!isset($_FILES['threads'])) return new OperationResult(ErrorType::CONTEXT_INVALID, "JSON missing.");
                        ini_set('memory_limit','300M');
                        $threads = json_decode(file_get_contents($_FILES['threads']['tmp_name']),true)['threads'];
                        $conn = DBManager::getConnection();
                        $conn->query('START TRANSACTION');


                        $sqlThreads = 'REPLACE INTO tid_threads (id,author_id,title,created_at,minor_tag,major_tag,states,kube_count,page_count,comment_count) VALUES ';
                        $valsThreads = [];
                        $iThr = 0;
                        foreach ($threads as $th) {
                            if ($iThr > 0) $sqlThreads .= ', ';
                            $sqlThreads .= "(:id_$iThr,:authorId_$iThr,:title_$iThr,:createdAt_$iThr,:minorTag_$iThr,:majorTag_$iThr,:states_$iThr,:kubeCount_$iThr,:pageCount_$iThr,:commentCount_$iThr)";
                            $firstComment = $th['comments'][0];
                            $valsThreads[":id_$iThr"] = $th['id'];
                            $valsThreads[":authorId_$iThr"] = $firstComment['authorID'];
                            $valsThreads[":title_$iThr"] = $th['title'];
                            $valsThreads[":createdAt_$iThr"] = $firstComment['deducedDate'];
                            $valsThreads[":minorTag_$iThr"] = $th['minorTag'];
                            $valsThreads[":majorTag_$iThr"] = $th['majorTag'];
                            $valsThreads[":states_$iThr"] = $th['states'] == null ? null : json_encode($th['states']);
                            $valsThreads[":kubeCount_$iThr"] = $th['kubes'];
                            $valsThreads[":pageCount_$iThr"] = $th['pages'];
                            $valsThreads[":commentCount_$iThr"] = count($th['comments']);
                            $iThr++;

                            $sqlComments = 'REPLACE INTO tid_comments (thread_id,id,author_id,states,content,content_warning,displayed_date,deduced_date,load_timestamp) VALUES ';
                            $valsComments = [];
                            $iComm = 0;
                            foreach ($th['comments'] as $comm) {
                                if ($iComm > 0) $sqlComments .= ', ';
                                $sqlComments .= "(:thread_id_$iComm,:id_$iComm,:author_id_$iComm,:states_$iComm,:content_$iComm,:content_warning_$iComm,:displayed_date_$iComm,:deduced_date_$iComm,:load_timestamp_$iComm)";
                                $valsComments[":thread_id_$iComm"] = $th['id'];
                                $valsComments[":id_$iComm"] = $comm['id'];
                                $valsComments[":author_id_$iComm"] = $comm['authorID'];
                                $valsComments[":states_$iComm"] = $comm['states'] == null ? null : json_encode($comm['states']);
                                $valsComments[":content_$iComm"] = $comm['content'];
                                $valsComments[":content_warning_$iComm"] = $comm['contentWarning'];
                                $valsComments[":displayed_date_$iComm"] = $comm['displayedDate'];
                                $valsComments[":deduced_date_$iComm"] = $comm['deducedDate'];
                                $valsComments[":load_timestamp_$iComm"] = $comm['loadTimestamp'];
                                $iComm++;
                            }
                            $stmt = $conn->prepare($sqlComments);
                            $stmt->execute($valsComments);
                        }
                        $stmt = $conn->prepare($sqlThreads);
                        $stmt->execute($valsThreads);

                        $conn->query('COMMIT');
                        return new OperationResult(SuccessType::SUCCESS);
                    }
                ],
                'userLog' => [
                    'type' => fn() => Types::SimpleOperation(),
                    'args' => [
                        'name' => Type::nonNull(Type::string()),
                        'data' => Type::nonNull(Type::string())
                    ],
                    'resolve' => function($o,$args) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return new OperationResult(ErrorType::NOT_AUTHENTICATED);

                        $sNow = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

                        $conn = DBManager::getConnection();
                        $number = ($conn->query("SELECT MAX(number) FROM user_logs WHERE user_id={$user->id}")->fetch(\PDO::FETCH_NUM)[0])??0;
                        $stmt = $conn->prepare('INSERT INTO user_logs(user_id,number,date,name,data) VALUES (?,?,?,?,?)');
                        $stmt->execute([$user->id,$number,$sNow,$args['name'],$args['data']]);

                        return new OperationResult(SuccessType::SUCCESS); 
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
                    case (preg_match('/^forum_tid_\d+$/',$id,$m) > 0): $s = 'TidThread'; break;
                    case (preg_match('/^forum_\d+-\d+$/',$id,$m) > 0): $s = 'Comment'; break;
                    case (preg_match('/^forum_tid_\d+-\d+$/',$id,$m) > 0): $s = 'TidComment'; break;
                    default: throw new TypedException("Couldn't find a node with id '$id'.", ErrorType::NOT_FOUND);
                }

                if (isset($s)) try {
                    $rm = (new \ReflectionMethod(Types::class, $s));
                    return $rm->invoke(null);
                } catch (\Exception $e) { }
                
                throw new TypedException("Couldn't find a node with id '$id'.", ErrorType::NOT_FOUND);
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
                    'resolve' => fn($o) => $o->resultType instanceof SuccessType
                ],
                'resultCode' => [
                    'type' => fn() => Type::nonNull(Type::string()),
                    'resolve' => fn($o) => $o->resultType->name
                ],
                'resultMessage' => [
                    'type' => fn() => Type::nonNull(Type::string()),
                    'resolve' => fn($o) => $o->resultMsg 
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class NotificationType extends InterfaceType {
    public static function process(mixed $o, callable $f) {
        $authUser = Context::getAuthenticatedUser();
        if ($authUser == null) return null;
        if (is_array($o) && isset($o['cursor'], $o['edge'])) $o = $o['edge'];
        return ($authUser->id == $o['data']['user_id'] || $authUser->isAdministrator()) ? $f($o) : null;
    }

    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'userId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['user_id'])
                ],
                'number' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['number'])
                ],
                'actionGroupName' => [
                    'type' => fn() => Types::ActionGroup(),
                    'resolve' => fn($o) => self::process($o,fn($o) => ActionGroup::from($o['data']['action_group']))
                ],
                'actionName' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['action'])
                ],
                'creationDate' => [
                    'type' => fn() => Types::DateTime(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['creation_date'])
                ],
                'lastUpdateDate' => [
                    'type' => fn() => Types::DateTime(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['last_update_date'])
                ],
                'readDate' => [
                    'type' => fn() => Types::DateTime(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['read_date'])
                ],
                'details' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['details'])
                ],
                'n' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['n'])
                ],
                'record' => [
                    'type' => Types::Record(),
                    'resolve'=> fn($o) => self::process($o,fn($o) => $o['data']['record_id']) 
                ]
            ],
            'resolveType' => fn($o) => self::process($o,function($o) {
                if ($o['data']['action_group'] == ActionGroup::FORUM->value) return Types::ForumNotification();
                return Types::BasicNotification();
            })
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
                Types::Operation()->getField('resultMessage')
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class ConnectionType extends ObjectType {
    public static function getEmptyConnection() {
        return ['data' => [], 'metadata' => ['pageInfo' => new PageInfo(null,null,false,false,1,1)]];
    }

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
                ],
                'currPage' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => $o->currPage
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class AnyUserType extends UnionType {
    public static function guess($o,$throwError=false) {
        if (isset($o['edge'], $o['cursor'])) $o = $o['edge'];
        $dbName = $o['metadata']['fromDb'];
        switch ($dbName) {
            case 'users': return Types::RegisteredUser();
            case 'tid_users': return Types::TidUser();
        }
        if ($throwError) throw new \Exception("Unknown dbName '$dbName'");
        return null;
    }

    public function __construct(array $config2 = null) {
        $config = [
            'types'=> [
                Types::RegisteredUser(),
                Types::TidUser()
            ],
            'resolveType' => fn($o) => self::guess($o,true)
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class AnyThreadType extends UnionType {
    public static function guess($o,$throwError=false) {
        $dbName = $o['metadata']['fromDb'];
        switch ($dbName) {
            case 'comments':
            case 'threads': return Types::Thread();
            case 'tid_comments': 
            case 'tid_threads': return Types::TidThread();
        }
        if ($throwError) throw new \Exception("Unknown dbName '$dbName'");
        return null;
    }

    public function __construct(array $config2 = null) {
        $config = [
            'types'=> [
                Types::Thread(),
                Types::TidThread()
            ],
            'resolveType' => fn($o) => self::guess($o,true)
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class AnyCommentType extends UnionType {
    public static function guess($o,$throwError=false) {
        $dbName = $o['metadata']['fromDb'];
        switch ($dbName) {
            case 'comments':
            case 'threads': return Types::Comment();
            case 'tid_comments': 
            case 'tid_threads': return Types::TidComment();
        }
        if ($throwError) throw new \Exception("Unknown dbName '$dbName'");
        return null;
    }

    public function __construct(array $config2 = null) {
        $config = [
            'types'=> [
                Types::Comment(),
                Types::TidComment()
            ],
            'resolveType' => fn($o) => self::guess($o,true)
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

/***** Operations *****/


/*****  *****/

class RegisteredUserType extends ObjectType {
    public static function process(mixed $o, callable $f) {
        $authUser = Context::getAuthenticatedUser();
        if ($authUser == null) return null;
        if (is_array($o) && isset($o['cursor'], $o['edge'])) $o = $o['edge']['data']['id'];

        UsersBuffer::requestFromId($o);
        return quickReactPromise(function() use($o,$f) {
            $row = UsersBuffer::getFromId($o);
            if ($row == null) return null;
            return $f($row);
        });
    }

    public function __construct(array $config2 = null) {
        $config = [
            'interfaces' => [Types::Node()],
            'fields' => [
                'id' => [
                    'type' => fn() => Type::id(),
                    'resolve' => fn($o) => self::process($o, fn($o) => "USER_{$o['data']['id']}")
                ],
                'dbId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, fn($o) => $o['data']['id'])
                ],
                'name' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o, fn($o) => $o['data']['name'])
                ],
                'titles' => [
                    'type' => fn() => Type::listOf(Type::nonNull(Type::string())),
                    'resolve' => fn($o) => self::process($o, fn($row) => explode(',',$row['data']['titles']))
                ],
                'avatarURL' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o, function($o) {
                        $avatarName = $o['data']['avatar_name'];
                        $root = get_root_link('res');
                        return $avatarName == null ? "{$root}/avatars/default.jpg" : "{$root}/avatars/$avatarName";
                    })
                ],
                'notifications' => [
                    'type' => fn() => Types::getConnectionObjectType('Notification'),
                    'args' => [
                        'first' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'last' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'after' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'before' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'withPageCount' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ]
                    ],
                    'resolve' => fn($o,$args) => self::process($o, function($o) use(&$args) {
                        $pag = new PaginationVals($args['first'],$args['last'],$args['after'],$args['before'],$args['withPageCount']);
                        $userId = (int)$o['data']['id'];
                        UsersBuffer::requestUserNotifications($userId,$pag);
                        return quickReactPromise(function() use(&$userId,&$pag) {
                            $row = UsersBuffer::getUserNotifications($userId,$pag);
                            if ($row == null) return null;
                            return $row;
                        });
                    })
                ],
                'emojis' => [
                    'type' => fn() => Type::nonNull(Types::getConnectionObjectType('Emoji')),
                    'args' => [
                        'first' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'last' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'after' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'before' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'withPageCount' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ]
                    ],
                    'resolve' => fn($o,$args) => self::process($o, function($o) use(&$args) {
                        $pag = new PaginationVals($args['first'],$args['last'],$args['after'],$args['before'],$args['withPageCount']);
                        $userId = (int)$o['data']['id'];
                        UsersBuffer::requestUserEmojis($userId,$pag);
                        return quickReactPromise(function() use(&$userId,&$pag) {
                            $row = UsersBuffer::getUserEmojis($userId,$pag);
                            if ($row == null) return null;
                            return $row;
                        });
                    })
                ],
                'settings' => [
                    'type' => fn() => Types::UserSettings(),
                    'resolve' => fn($o,$args) => self::process($o, fn($o) => $o)
                ],
                'stats' => [
                    'type' => fn() => Types::RegisteredUserStats(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['id'])
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class TidUserType extends ObjectType {
    public static function process(mixed $o, callable $f) {
        $authUser = Context::getAuthenticatedUser();
        if (!$authUser->titles->contains('oldInteressant')) return null;
        if (is_array($o) && isset($o['cursor'], $o['edge'])) $o = $o['edge']['data']['id'];

        UsersBuffer::requestTidUser($o);
        return quickReactPromise(function() use($o,$f) {
            $row = UsersBuffer::getTidUser($o);
            if ($row == null) return null;
            return $f($row);
        });
    }

    public function __construct(array $config2 = null) {
        $config = [
            'interfaces' => [Types::Node()],
            'fields' => [
                'id' => [
                    'type' => fn() => Type::id(),
                    'resolve' => fn($o) => self::process($o, fn($o) => "USER_TID_{$o['data']['id']}")
                ],
                'dbId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, fn($o) => $o['data']['id'])
                ],
                'name' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o, fn($o) => $o['data']['name'])
                ],
                'associatedRegisteredUser' => [
                    'type' => fn() => Types::RegisteredUser(),
                    'resolve' => fn($o) => self::process($o, function($o) {
                        $tidId =& $o['data']['id'];
                        UsersBuffer::requestTidAssociatedRegisteredUser($tidId);
                        return quickReactPromise(fn() => UsersBuffer::getTidAssociatedRegisteredUser($tidId)['data']['id_a']??null);
                    })
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class RegisteredUserStatsType extends ObjectType {
    public static function process(mixed $o, callable $f) {
        $authUser = Context::getAuthenticatedUser();
        if ($authUser == null) return null;
        return $f($o);
    }

    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'nAllThreads' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, function($userId) {
                        $cacheKey = "userStats:{$userId}:nAllThreads";
                        $cacheKey2 = "userStats:{$userId}:nTidThreads";
                        $vCache = Cache::get($cacheKey);
                        if ($vCache != null) return $vCache;

                        return quickReactPromise(function() use(&$userId,&$cacheKey,&$cacheKey2) {
                            $conn = DBManager::getConnection();
                            $v = $conn->query('SELECT COUNT(*) FROM threads WHERE author_id='.$userId)->fetch(\PDO::FETCH_NUM)[0];

                            $stmt = $conn->query("SELECT id_b FROM id_links WHERE id_a=$userId");
                            $sqlWhere = '';
                            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                                if ($sqlWhere != '') $sqlWhere .= ' OR ';
                                $sqlWhere .= "author_id={$row['id_b']}";
                            }
                            if ($sqlWhere != '') {
                                $vCacheTid = Cache::get($cacheKey2);
                                if ($vCacheTid != null) $v += $vCacheTid;
                                else {
                                    $nTid = $conn->query("SELECT COUNT(*) FROM tid_threads WHERE $sqlWhere")->fetch(\PDO::FETCH_NUM)[0];
                                    Cache::set($cacheKey2,$nTid,2000000000);
                                    $v += $nTid;
                                }
                            }

                            Cache::set($cacheKey,$v,21600);
                            return $v;
                        });
                    })
                ],
                'nAllComments' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, function($userId) {
                        $cacheKey = "userStats:{$userId}:nAllComments";
                        $cacheKey2 = "userStats:{$userId}:nTidComments";
                        $vCache = Cache::get($cacheKey);
                        if ($vCache != null) return $vCache;

                        return quickReactPromise(function() use(&$userId,&$cacheKey,&$cacheKey2) {
                            $conn = DBManager::getConnection();
                            $v = $conn->query('SELECT COUNT(*) FROM comments WHERE author_id='.$userId)->fetch(\PDO::FETCH_NUM)[0];

                            $stmt = $conn->query("SELECT id_b FROM id_links WHERE id_a=$userId");
                            $sqlWhere = '';
                            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                                if ($sqlWhere != '') $sqlWhere .= ' OR ';
                                $sqlWhere .= "author_id={$row['id_b']}";
                            }
                            if ($sqlWhere != '') {
                                $vCacheTid = Cache::get($cacheKey2);
                                if ($vCacheTid != null) $v += $vCacheTid;
                                else {
                                    $nTid = $conn->query("SELECT COUNT(*) FROM tid_comments WHERE $sqlWhere")->fetch(\PDO::FETCH_NUM)[0];
                                    Cache::set($cacheKey2,$nTid,2000000000);
                                    $v += $nTid;
                                }
                            }

                            Cache::set($cacheKey,$v,21600);
                            return $v;
                        });
                    })
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class UserSettingsType extends ObjectType {
    public static function process(mixed $o, callable $f) {
        if (!($o instanceof RegisteredUser)) {
            try {
                $o = RegisteredUser::initFromRow($o);
            } catch (\Exception $e) {
                return null;
            }
        }

        $authUser = Context::getAuthenticatedUser();
        if ($o->id == $authUser->id || $authUser->isAdministrator()) return $f($o);
        else return null;
    }

    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'forum_autoMarkPagesAsRead' => [
                    'type' => fn() => Type::nonNull(Type::boolean()),
                    'resolve' => fn($o) => self::process($o, fn(RegisteredUser $o) => $o->settings->forum_autoMarkPagesAsRead)
                ],
                'forum_followThreadsOnComment' => [
                    'type' => fn() => Type::nonNull(Type::boolean()),
                    'resolve' => fn($o) => self::process($o, fn(RegisteredUser $o) => $o->settings->forum_followThreadsOnComment)
                ],
                'notificationsEnabled' => [
                    'type' => fn() => Type::nonNull(Type::boolean()),
                    'resolve' => fn($o) => self::process($o, fn(RegisteredUser $o) => $o->settings->notificationsEnabled)
                ],
                'notif_newThread' => [
                    'type' => fn() => Type::nonNull(Type::boolean()),
                    'resolve' => fn($o) => self::process($o, fn(RegisteredUser $o) => $o->settings->notif_newThread)
                ],
                'notif_newCommentOnFollowedThread' => [
                    'type' => fn() => Type::nonNull(Type::boolean()),
                    'resolve' => fn($o) => self::process($o, fn(RegisteredUser $o) => $o->settings->notif_newCommentOnFollowedThread)
                ],
                'minusculeMode' => [
                    'type' => fn() => Type::boolean(),
                    'resolve' => fn($o) => self::process($o, fn(RegisteredUser $o) => $o->settings->minusculeMode)
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class ForumSearchItemType extends ObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'thread' => [
                    'type' => fn() => Types::AnyThread(),
                    'resolve' => fn($o) => $o['edge']
                ],
                'comment' => [
                    'type' => fn() => Types::AnyComment(),
                    'resolve' => fn($o) => $o['edge']
                ],
                'relevance' => [
                    'type' => fn() => Type::float(),
                    'resolve' => fn($o) => $o['edge']['data']['relevance']
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
                        'first' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'last' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'after' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'before' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'withPageCount' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                        'sortBy' => [ 'type' => Type::string(), 'defaultValue' => null ],
                        'withLastPageSpecialBehavior' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                        'skipPages' => ['type' => Type::nonNull(Type::int()), 'defaultValue' => 0],
                        'onlyNotRead' => ['type' => Type::nonNull(Type::boolean()), 'defaultValue' => false]
                    ],
                    'resolve' => function($o, $args, $__, $ri) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null) return null;

                        $pag = new PaginationVals($args['first'],$args['last'],$args['after'],$args['before'],$args['withPageCount'],$args['withLastPageSpecialBehavior']);
                        $pag->sortBy = $args['sortBy']??'';
                        $pag->skipPages = $args['skipPages'];
                        $pag->data['onlyNotRead'] = $args['onlyNotRead'];
                        ForumBuffer::requestThreads($pag,$user->id);
                        return quickReactPromise(function() use($o,$args,$pag,$ri,&$user) {
                            return ForumBuffer::getThreads($pag,$user->id);
                        });
                    }
                ],
                'tidThreads' => [
                    'type' => fn() => Types::getConnectionObjectType('TidThread'),
                    'args' => [
                        'first' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'last' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'after' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'before' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'withPageCount' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                        'sortBy' => [ 'type' => Type::string(), 'defaultValue' => null ],
                        'withLastPageSpecialBehavior' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                        'skipPages' => ['type' => Type::nonNull(Type::int()), 'defaultValue' => 0],
                    ],
                    'resolve' => function($o, $args, $__, $ri) {
                        $user = Context::getAuthenticatedUser();
                        if ($user == null || !$user->titles->contains('oldInteressant')) return null;

                        $pag = new PaginationVals($args['first'],$args['last'],$args['after'],$args['before'],$args['withPageCount'],$args['withLastPageSpecialBehavior']);
                        $pag->sortBy = $args['sortBy']??'';
                        $pag->skipPages = $args['skipPages'];
                        ForumBuffer::requestTidThreads($pag);
                        return quickReactPromise(function() use($pag) {
                            return ForumBuffer::getTidThreads($pag);
                        });
                    }
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class TidThreadType extends ObjectType {
    private static function process(mixed $o, callable $f) {
        $authUser = Context::getAuthenticatedUser();
        if ($authUser == null || !$authUser->titles->contains('oldInteressant')) return null;
        if (is_array($o)) {
            if (isset($o['cursor'], $o['edge'])) $o = $o['edge']['data']['id'];
            else if (isset($o['metadata']['fromDb']) && $o['metadata']['fromDb'] == 'tid_comments') $o = $o['data']['thread_id'];
        }
        ForumBuffer::requestTidThread($o);
        return quickReactPromise(function() use($o,$f) {
            $row = ForumBuffer::getTidThread($o);
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
                    'resolve' => fn($o) => self::process($o, fn($row) => TidThread::getIdFromRow($row))
                ],
                'dbId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['id'])
                ],
                'authorId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['author_id'])
                ],
                'author' => [
                    'type' => fn() => Types::TidUser(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['author_id'])
                ],
                'title' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['title'])
                ],
                'deducedDate' => [
                    'type' => fn() => Types::Date(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['created_at'])
                ],
                'minorTag' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['minor_tag'])
                ],
                'majorTag' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['major_tag'])
                ],
                'states' => [
                    'type' => fn() => Type::listOf(Type::string()),
                    'resolve' => fn($o) => self::process($o, function($row) { $v=explode(',',$row['data']['states']); return $v == [""] ? [] : $v; })
                ],
                'kubeCount' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['kube_count'])
                ],
                'pageCount' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['page_count'])
                ],
                'commentCount' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['comment_count'])
                ],
                'comments' => [
                    'type' => fn() => Types::getConnectionObjectType('TidComment'),
                    'args' => [
                        'first' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'last' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'after' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'before' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'withPageCount' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                        'skipPages' => ['type' => Type::nonNull(Type::int()), 'defaultValue' => 0]
                    ],
                    'resolve' => function($o, $args, $__, $ri) {
                        $pag = new PaginationVals($args['first'],$args['last'],$args['after'],$args['before'],$args['withPageCount']);
                        $pag->skipPages = $args['skipPages'];
                        return self::process($o,function($row) use($pag) {
                            ForumBuffer::requestTidComments($row['data']['id'],$pag);
                            return quickReactPromise(function() use ($row,$pag) {
                                $data = ForumBuffer::getTidComments($row['data']['id'],$pag);
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

class ThreadType extends ObjectType {
    private static function process(mixed $o, callable $f) {
        $authUser = Context::getAuthenticatedUser();
        if ($authUser == null) return null;
        if (is_array($o)) {
            if (isset($o['cursor'], $o['edge'])) $o = $o['edge']['data']['id'];
            else if (isset($o['metadata']['fromDb']) && $o['metadata']['fromDb'] == 'comments') $o = $o['data']['thread_id'];
        }
        ForumBuffer::requestThread($o);
        return quickReactPromise(function() use(&$o,&$f,&$authUser) {
            $row = ForumBuffer::getThread($o);
            if ($row == null || $row['data'] == null) return null;
            return ($authUser->titles->contains('oldInteressant') || $authUser->registrationDate <= new \DateTimeImmutable($row['data']['creation_date'])) ? $f($row) : null;
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
                'followingIds' => [
                    'type' => fn() => Type::listOf(Type::nonNull(Type::int())),
                    'resolve' => fn($o) => self::process($o,fn($row) => json_decode($row['data']['following_ids']) ),
                ],
                'kubedBy' => [
                    'type' => fn() => Type::listOf(Type::nonNull(Types::RegisteredUser())),
                    'resolve' => fn($o) => self::process($o,function($row) {
                        $stmt = DBManager::getConnection()->query("SELECT user_id FROM kubed_threads WHERE thread_id={$row['data']['id']}");
                        $res = [];
                        while ($row = $stmt->fetch(\PDO::FETCH_NUM)) $res[] = $row[0];
                        return $res;
                    })
                ],
                'comments' => [
                    'type' => fn() => Types::getConnectionObjectType('Comment'),
                    'args' => [
                        'toFirstUnreadComment' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false],
                        'first' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'last' => [ 'type' => Type::int(), 'defaultValue' => null ],
                        'after' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'before' => [ 'type' => Type::id(), 'defaultValue' => null ],
                        'withPageCount' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                        'withLastPageSpecialBehavior' => [ 'type' => Type::nonNull(Type::boolean()), 'defaultValue' => false ],
                        'skipPages' => ['type' => Type::nonNull(Type::int()), 'defaultValue' => 0]
                    ],
                    'resolve' => fn($o, $args, $__, $ri) => self::process($o,function($row) use(&$args) {
                        if ($args['toFirstUnreadComment']) {
                            $user = Context::getAuthenticatedUser();
                            ForumBuffer::requestFirstUnreadComment($user->id, $row['data']['id']);
                            return quickReactPromise(function() use(&$user,&$row,&$args) {
                                $rowUnreadComm = ForumBuffer::getFirstUnreadComment($user->id, $row['data']['id']);
                                if ($rowUnreadComm === null || $rowUnreadComm['data'] === null) {
                                    $pag = new PaginationVals(null,$args['last']??10,null,null,$args['withPageCount'],true);
                                    ForumBuffer::requestComments($row['data']['id'],$pag);
                                    return quickReactPromise(function() use ($row,$pag) {
                                        $data = ForumBuffer::getComments($row['data']['id'],$pag);
                                        return $data;
                                    });
                                }

                                $pag = new PaginationVals($args['first']??10,null,null,null,$args['withPageCount'],$args['withLastPageSpecialBehavior']);
                                $v = ($rowUnreadComm['metadata']['pos'] / $args['first']);
                                if ($v % 1 > 0) $v++;
                                else if ($v < 0) $v = 0;
                                $pag->skipPages = (int)$v;

                                ForumBuffer::requestComments($row['data']['id'],$pag);
                                return quickReactPromise(function() use ($row,$pag) {
                                    $data = ForumBuffer::getComments($row['data']['id'],$pag);
                                    return $data;
                                });
                            });
                        } else {
                            $pag = new PaginationVals($args['first'],$args['last'],$args['after'],$args['before'],$args['withPageCount'],$args['withLastPageSpecialBehavior']);
                            $pag->skipPages = $args['skipPages'];
                            ForumBuffer::requestComments($row['data']['id'],$pag);
                            return quickReactPromise(function() use ($row,$pag) {
                                $data = ForumBuffer::getComments($row['data']['id'],$pag);
                                return $data;
                            });
                        }
                    })
                ],
                'isRead' => [
                    'type' => fn() => Type::boolean(),
                    'resolve' => fn($o) => self::process($o,function($row) {
                        $user = Context::getAuthenticatedUser();
                        return in_array($user->id,json_decode($row['data']['read_by']));
                    })
                ],
                'canRemove' => [
                    'type' => fn() => Type::boolean(),
                    'resolve' => fn($o) => self::process($o,fn($row) => check_can_remove_thread(DBManager::getConnection(), Context::getAuthenticatedUser(), $row['data']['id'], new \DateTime('now')))
                ],
                'firstUnreadComment' => [
                    'type' => fn() => Types::FirstUnreadComment(),
                    'resolve' => fn($o) => self::process($o,function($row) {
                        $user = Context::getAuthenticatedUser();
                        ForumBuffer::requestFirstUnreadComment($user->id, $row['data']['id']);
                        return quickReactPromise(function() use(&$user,&$row) {
                            $row = ForumBuffer::getFirstUnreadComment($user->id, $row['data']['id']);
                            return ($row === null || $row['data'] === null) ? null : $row;
                        });
                    })
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class TidCommentType extends ObjectType {
    private static function process(mixed $o, callable $f) {
        $authUser = Context::getAuthenticatedUser();
        if ($authUser == null || !$authUser->titles->contains('oldInteressant')) return null;
        if (is_array($o) && isset($o['cursor'], $o['edge'])) $o = $o['edge'];
        
        $commNodeId = TidComment::getIdFromRow($o['data']);
        ForumBuffer::getTidComment($commNodeId);
        return quickReactPromise(function() use($commNodeId,$f) {
            $row = ForumBuffer::getTidComment($commNodeId);
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
                    'resolve' => fn($o) => self::process($o, fn($row) => TidComment::getIdFromRow($row))
                ],
                'dbId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['id'])
                ],
                'threadId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['thread_id'])
                ],
                'authorId' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['author_id'])
                ],
                'author' => [
                    'type' => fn() => Types::TidUser(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['author_id'])
                ],
                'states' => [
                    'type' => fn() => Type::listOf(Type::nonNull(Type::string())),
                    'resolve' => fn($o) => self::process($o, function($row) { $v=explode(',',$row['data']['states']); return $v == [""] ? [] : $v; })
                ],
                'content' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['content'])
                ],
                'contentWarning' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['content_warning'])
                ],
                'deducedDate' => [
                    'type' => fn() => Types::Date(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['deduced_date'])
                ],
                'loadTimestamp' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o, fn($row) => $row['data']['load_timestamp'])
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class CommentType extends ObjectType {
    private static function process(mixed $o, callable $f) {
        $authUser = Context::getAuthenticatedUser();
        if ($authUser == null) return null;
        if (is_array($o)) {
            if (isset($o['cursor'], $o['edge'])) $o = Comment::getIdFromRow($o['edge']['data']);
            else if (isset($o['data'], $o['metadata'])) $o = Comment::getIdFromRow($o['data']);
        }

        ForumBuffer::requestComment($o);
        return quickReactPromise(function() use(&$o,&$f,&$authUser) {
            $row = ForumBuffer::getComment($o);
            if ($row == null || $row['data'] == null) return null;
            if ($authUser->titles->contains('oldInteressant')) return $f($row);

            $comment = Comment::initFromRow($row['data']);
            ForumBuffer::requestThread($comment->threadId);
            return quickReactPromise(function() use(&$comment,&$authUser,&$f,&$row) {
                $threadRow = ForumBuffer::getThread($comment->threadId);
                if ($authUser->registrationDate > new \DateTimeImmutable($threadRow['data']['creation_date'])) return null;
                return $f($row);
            });
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
                'author' => [
                    'type' => fn() => Types::RegisteredUser(),
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
                ],
                'kubedBy' => [
                    'type' => fn() => Type::listOf(Type::nonNull(Types::RegisteredUser())),
                    'resolve' => fn($o) => self::process($o,function($row) {
                        $stmt = DBManager::getConnection()->query("SELECT user_id FROM kubed_comments WHERE thread_id={$row['data']['thread_id']} AND comm_number={$row['data']['number']}");
                        $res = [];
                        while ($row = $stmt->fetch(\PDO::FETCH_NUM)) $res[] = $row[0];
                        return $res;
                    })
                ],
                'octohits' => [
                    'type' => fn() => Type::listOf(Type::nonNull(Types::Octohit())),
                    'resolve' => fn($o) => self::process($o,function($row) {
                        $threadId = $row['data']['thread_id'];
                        $commNumber = $row['data']['number'];
                        ForumBuffer::requestCommentOctohits($threadId, $commNumber);
                        return quickReactPromise(function() use(&$threadId, &$commNumber) {
                            return ForumBuffer::getCommentOctohits($threadId, $commNumber)['data']??null;
                        });
                    })
                ],
                'totalOctohitAmount' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o,function($row) {
                        $threadId = $row['data']['thread_id'];
                        $commNumber = $row['data']['number'];
                        ForumBuffer::requestCommentOctohits($threadId, $commNumber);
                        return quickReactPromise(function() use(&$threadId, &$commNumber) {
                            $res = ForumBuffer::getCommentOctohits($threadId, $commNumber);
                            return $res == null ? null : $res['metadata']['totalAmount'];
                        });
                    })
                ],
                'isRead' => [
                    'type' => fn() => Type::boolean(),
                    'resolve' => fn($o) => self::process($o,fn($row) => in_array(Context::getAuthenticatedUser()->id, json_decode($row['data']['read_by'])))
                ],
                'canEdit' => [
                    'type' => fn() => Type::boolean(),
                    'resolve' => fn($o) => self::process($o,fn($row) =>
                        check_can_edit_comment(DBManager::getConnection(), Context::getAuthenticatedUser(),$row['data']['thread_id'],$row['data']['number'],new \DateTime('now')))
                ],
                'canRemove' => [
                    'type' => fn() => Type::boolean(),
                    'resolve' => fn($o) => self::process($o,fn($row) =>
                        check_can_remove_comment(DBManager::getConnection(), Context::getAuthenticatedUser(),$row['data']['thread_id'],$row['data']['number'],new \DateTime('now')))
                ],
                'canOctohit' => [
                    'type' => fn() => Type::boolean(),
                    'resolve' => fn($o) => self::process($o,function($row) {
                        $user = Context::getAuthenticatedUser();
                        $threadId = $row['data']['thread_id'];
                        $commNumber = $row['data']['number'];
                        ForumBuffer::requestCommentOctohits($threadId, $commNumber);
                        return quickReactPromise(function() use(&$threadId, &$commNumber, &$user) {
                            $res = ForumBuffer::getCommentOctohits($threadId, $commNumber);
                            $c = 0;
                            foreach ($res['data'] as $row) if ($row['data']['user_id'] == $user->id) $c++;
                            return $c < 5;
                        });
                    })
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class FirstUnreadCommentType extends ObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'comment' => [
                    'type' => fn() => Type::nonNull(Types::Comment()),
                    'resolve' => fn($o) => Comment::getIdFromRow($o['data'])
                ],
                'pos' => [
                    'type' => fn() => Type::nonNull(Type::int()),
                    'resolve' => fn($o) => $o['metadata']['pos']
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class EmojiType extends ObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'dbId' => [
                    'type' => fn() => Type::nonNull(Type::string()),
                    'resolve' => fn($o) => $o['edge']['data']['id']
                ],
                'srcPath' => [
                    'type' => fn() => Type::nonNull(Type::string()),
                    'resolve' => fn($o) => get_root_link('res')."/emojis/".$o['edge']['data']['id']
                ],
                'aliases' => [
                    'type' => fn() => Type::nonNull(Type::listOf(Type::string())),
                    'resolve' =>  fn($o) => json_decode($o['edge']['data']['aliases'])
                ],
                'consommable' => [
                    'type' => fn() => Type::nonNull(Type::boolean()),
                    'resolve' =>  fn($o) => (bool)$o['edge']['data']['consommable']
                ],
                'amount' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn() => null
                ],
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class PushReportType extends ObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'success' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'resolve' => fn($o) => $o->isSuccess()
                ],
                'endpoint' => [
                    'type' => Type::nonNull(Type::string()),
                    'resolve' => fn($o) => $o->getEndpoint()
                ],
                'reason' => [
                    'type' => Type::nonNull(Type::string()),
                    'resolve' => fn($o) => $o->getReason()
                ],
                'statusCode' => [
                    'type' => Type::nonNull(Type::int()),
                    'resolve' => fn($o) => $o->getResponse()?->getStatusCode()
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class SettingInputType extends InputObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'name' => fn() => Type::nonNull(Type::string()),
                'value' => fn() => Type::nonNull(Type::string())
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class OctohitType extends ObjectType {
    private static function process(mixed $o, callable $f) {
        $authUser = Context::getAuthenticatedUser();
        if ($authUser == null) return null;
        return $f($o);
    }

    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'user' => [
                    'type' => fn() => Types::RegisteredUser(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['user_id'])
                ],
                'amount' => [
                    'type' => fn() => Type::int(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['amount'])
                ],
                'date' => [
                    'type' => fn() => Types::DateTime(),
                    'resolve' => fn($o) => self::process($o,fn($row) => $row['data']['date'])
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class S3ObjectMetadataType extends ObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                '_key' => [
                    'type' => fn() => Type::string(),
                    'resolve' => function($res) {
                        return $res['_Key']??null;
                    }
                ],
                'contentType' => [
                    'type' => fn() => Type::string(),
                    'resolve' => function($res) {
                        return $res['ContentType']??null;
                    }
                ],
                'contentLength' => [
                    'type' => fn() => Type::int(),
                    'resolve' => function($res) {
                        return ((int)$res['ContentLength'])??null;
                    }
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

/***** Notifications *****/

class RecordType extends ObjectType {
    public static function process(mixed $o, callable $f) {
        $authUser = Context::getAuthenticatedUser();
        if ($authUser == null || !$authUser->isAdministrator()) return null;
        if (is_array($o) && isset($o['cursor'], $o['edge'])) $o = $o['edge']['data']['id'];

        RecordsBuffer::requestFromId($o);
        return quickReactPromise(function() use(&$o,&$f) {
            $recordRow = RecordsBuffer::getFromId($o);
            return ($recordRow == null || $recordRow['data'] == null) ? null : $f($recordRow); 
        });
    }

    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'dbId' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['id'])
                ],
                'associatedUser' => [
                    'type' => fn() => Types::RegisteredUser(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['user_id'])
                ],
                'actionGroupName' => [
                    'type' => fn() => Types::ActionGroup(),
                    'resolve' => fn($o) => self::process($o,fn($o) => ActionGroup::from($o['data']['action_group']))
                ],
                'actionName' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['action'])
                ],
                'details' => [
                    'type' => fn() => Type::string(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['details'])
                ],
                'date' => [
                    'type' => fn() => Types::DateTime(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['date'])
                ],
                'notifiedIds' => [
                    'type' => fn() => Type::boolean(),
                    'resolve' => fn($o) => self::process($o,fn($o) => $o['data']['notified_ids'])
                ],
                'associatedThread' => [
                    'type' => fn() => Types::Thread(),
                    'resolve' => fn($o) => self::process($o,function($o) {
                        $details = json_decode($o['data']['details'],true);
                        if (!isset($details['threadId'])) return null;
                        return $details['threadId'];
                    })
                ]
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class BasicNotificationType extends ObjectType {
    public function __construct(array $config2 = null) {
        $config = [
            'interfaces' => [Types::Notification()],
            'fields' => [
                Types::Notification()->getField('userId'),
                Types::Notification()->getField('number'),
                Types::Notification()->getField('actionGroupName'),
                Types::Notification()->getField('actionName'),
                Types::Notification()->getField('creationDate'),
                Types::Notification()->getField('lastUpdateDate'),
                Types::Notification()->getField('readDate'),
                Types::Notification()->getField('details'),
                Types::Notification()->getField('n'),
                Types::Notification()->getField('record')
            ]
        ];
        parent::__construct($config2 == null ? $config : array_merge_recursive_distinct($config,$config2));
    }
}

class ForumNotificationType extends BasicNotificationType {
    public static function process(mixed $o, callable $f) {
        return NotificationType::process($o, function($o) use(&$f) {
            if ($o['data']['record_id'] === null) return null; 
            RecordsBuffer::requestFromId($o['data']['record_id']);
            return quickReactPromise(function() use(&$o,&$f) {
                $recordRow = RecordsBuffer::getFromId($o['data']['record_id']);
                return $recordRow == null ? null : $f($o,$recordRow);
            });
        });
    }

    public function __construct(array $config2 = null) {
        $config = [
            'fields' => [
                'thread' => [
                    'type' => fn() => Types::Thread(),
                    'resolve' => fn($o) => self::process($o, fn($notifRow,$recordRow) => json_decode($recordRow['data']['details'],true)['threadId'])
                ],
                'comment' => [
                    'type' => fn() => Types::Comment(),
                    'resolve' => fn($o) => self::process($o, function($notifRow,$recordRow) {
                        $details = json_decode($recordRow['data']['details'],true);
                        return "forum_{$details['threadId']}-{$details['commentNumber']}";
                    })
                ],
                'associatedUsers' => [
                    'type' => fn() => Type::listOf(Type::nonNull(Types::RegisteredUser())),
                    'resolve' => fn($o) => self::process($o, function($notifRow,$recordRow) {
                        $details = json_decode($notifRow['data']['details'],true);
                        return $details['userIds'];
                    })
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

        if (isset($_COOKIE['sid'])) {
            $user = get_user_from_sid(DBManager::getConnection(), $_COOKIE['sid']);
            self::$a['authenticatedUser'] = $user;
            if ($user == null) delete_cookie('sid');
        }
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

class Cache {
    public static ?\Redis $redis = null;
    private static bool $initialized = false;
    public static int $getCount = 0;
    public static int $setCount = 0;
    public static int $keysNotFound = 0;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        if (!class_exists('\Redis')) return;

        self::$redis = new \Redis();
        try {
            $res = self::$redis->connect($_SERVER['LD_REDIS_HOST'],$_SERVER['LD_REDIS_HOST_PORT'],$_SERVER['LD_REDIS_TIMEOUT'],null,0,0,[
                'stream' => [
                    'verify_peer_name' => (bool)$_SERVER['LD_REDIS_VERIFY_PEER_NAME']
                ]
            ]);
            if ($res == false) {
                self::$redis = null;
                Context::addLog('Redis','Redis connection failure.');
            }
        } catch (\RedisException $e) {
            self::$redis = null;
            Context::addLog('Redis','Redis connection failure: '.$e->getMessage());
        }
    }
    
    public static function get(string $key):mixed {
        if (!self::$initialized) Cache::init();
        if (self::$redis == null) return null;

        self::$getCount++;
        $v = self::$redis->get($key);
        if ($v === false) self::$keysNotFound++;
        return $v === false ? null : $v;
    }

    public static function set(string $key, string $value, mixed $timeout = null):bool|\Redis {
        if (!self::$initialized) Cache::init();
        if (self::$redis == null) return false;

        self::$setCount++;
        return self::$redis->set($key, $value, $timeout);
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
       self::genQuickOperation('OnThreadComment',['thread' => 'Types::Thread()','comment' => 'Types::Comment()']);
       self::genQuickOperation('OnOctohit',['octohit' => 'Types::Octohit()','thread' => 'Types::Thread()','comment' => 'Types::Comment()']);
       self::genQuickOperation('OnPush',['reports' => 'Type::listOf(Type::nonNull(Types::PushReport()))']);
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
        $iKV = 0;
        foreach ($fieldsKV as $k => $v)
            if (preg_match('/^\w+$/',$k) === 0 || preg_match('/^[\w:\(\)$]+$/',$v) === 0) throw new \Exception("genOperation error: [$k=>$v]");
            else {
                $sFields .= <<<PHP
                '$k' => [
                    'type' => $v,
                    'resolve' => fn(\$o) => \$o->fieldsData[$iKV]??null
                ],
                PHP;
                $iKV++;
            }

        eval(<<<PHP
        namespace Schema;
        use GraphQL\Type\Definition\{ObjectType, Type};
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
        else if (is_string($value) && preg_match('/^\d\d\d\d-\d\d-\d\d$/', $value) > 0) return "$value 00:00:00";
        
        throw new InvariantViolation("Could not serialize following value as DateTime: ".Utils::printSafe($value));
    }

    public function parseValue($value) {
        if (!is_string($value) || preg_match('/^(?:\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:.\d{3})?Z|\d{4}-\d\d-\d\d(?: \d\d:\d\d:\d\d)?)$/', $value) == 0)
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
        if (preg_match('/^(?:\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:.\d{3})?Z|\d{4}-\d\d-\d\d(?: \d\d:\d\d:\d\d)?)$/', $s) == 0) throw new Error("Not a valid datetime: '$s'", [$valueNode]);
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

    public static function Notification():NotificationType {
        return self::$types['Notification'] ??= new NotificationType();
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

    public static function RegisteredUserStats():RegisteredUserStatsType {
        return self::$types['RegisteredUserStats'] ??= new RegisteredUserStatsType();
    }

    public static function TidUser():TidUserType {
        return self::$types['TidUser'] ??= new TidUserType();
    }

    public static function AnyUser():AnyUserType {
        return self::$types['AnyUser'] ??= new AnyUserType();
    }

    public static function UserSettings():UserSettingsType {
        return self::$types['UserSettings'] ??= new UserSettingsType();
    }

    public static function Record():RecordType {
        return self::$types['Record'] ??= new RecordType();
    }

    public static function Forum():ForumType {
        return self::$types['Forum'] ??= new ForumType();
    }

    public static function AnyThread():AnyThreadType {
        return self::$types['AnyThread'] ??= new AnyThreadType();
    }

    public static function Thread():ThreadType {
        return self::$types['Thread'] ??= new ThreadType();
    }

    public static function TidThread():TidThreadType {
        return self::$types['TidThread'] ??= new TidThreadType();
    }

    public static function AnyComment():AnyCommentType {
        return self::$types['AnyComment'] ??= new AnyCommentType();
    }

    public static function Comment():CommentType {
        return self::$types['Comment'] ??= new CommentType();
    }

    public static function TidComment():TidCommentType {
        return self::$types['TidComment'] ??= new TidCommentType();
    }

    public static function FirstUnreadComment():FirstUnreadCommentType {
        return self::$types['FirstUnreadComment'] ??= new FirstUnreadCommentType();
    }

    public static function ForumSearchItem():ForumSearchItemType {
        return self::$types['ForumSearchItem'] ??= new ForumSearchItemType();
    }

    public static function Emoji():EmojiType {
        return self::$types['Emoji'] ??= new EmojiType();
    }

    public static function PushReport():PushReportType {
        return self::$types['PushReport'] ??= new PushReportType();
    }

    public static function SettingInput():SettingInputType {
        return self::$types['SettingInput'] ??= new SettingInputType();
    }

    public static function Octohit():OctohitType {
        return self::$types['Octohit'] ??= new OctohitType();
    }

    public static function S3ObjectMetadata():S3ObjectMetadataType {
        return self::$types['S3ObjectMetadata'] ??= new S3ObjectMetadataType();
    }

    /***** Notifications *****/

    public static function BasicNotification():BasicNotificationType {
        return self::$types['BaseNotification'] ??= new BasicNotificationType();
    }

    public static function ForumNotification():ForumNotificationType {
        return self::$types['ForumNotification'] ??= new ForumNotificationType();
    }

    /***** Enums *****/

    public static function SearchSorting():PhpEnumType {
        return self::$types['SearchSorting'] ??= new PhpEnumType(SearchSorting::class);
    }

    public static function ThreadType():PhpEnumType {
        return self::$types['\LdLib\Forum\ThreadType'] ??= new PhpEnumType(\LdLib\Forum\ThreadType::class);
    }

    public static function ThreadPermission():PhpEnumType {
        return self::$types['ThreadPermission'] ??= new PhpEnumType(ThreadPermission::class);
    }

    public static function ActionGroup():PhpEnumType {
        return self::$types['ActionGroup'] ??= new PhpEnumType(ActionGroup::class);
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