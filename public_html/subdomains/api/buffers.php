<?php
namespace Schema;

$libDir = __DIR__.'/../../lib';
require_once __DIR__.'/../../vendor/autoload.php';
require_once $libDir.'/db.php';

use Ds\Set;
use GraphQL\Error\ClientAware;
use LDLib\Database\LDPDO;
use LDLib\Forum\{ForumSearchQuery,SearchSorting,ThreadPermission};
use LDLib\General\ {
    PageInfo,
    PaginationVals
};
use LDLib\User\RegisteredUser;

use function LDLib\Database\get_tracked_pdo;

enum DataType {
    case Record;
    case User;
    case Notification;
    case Emoji;
    case ForumThread;
    case ForumComment;
    case ForumTidThread;
    case ForumTidComment;
    case Octohit;
    case ForumSearch;
    case FirstUnreadComment;
    case TidUser;
    case TidAssociatedRegisteredUser;
    case S3KeyData;
}

class BufferManager {
    /** Contains all the fetched data, the other buffers references it. */
    public static array $result = [
        'records' => [],
        'recordsM' => [],
        'users' => [],
        'tidUsers' => [],
        'tidUsersM' => [],
        'forum' => [
            'threads' => [],
            'threadsM' => [],
            'comments' => [],
            'commentsM' => [],
            'tid_threads' => [],
            'tid_threadsM' => [],
            'tid_comments' => [],
            'tid_commentsM' => [],
            'firstUnreadComments' => []
        ],
        'notificationsM' => [],
        'emojis' => [],
        'emojisM' => [],
        'usersEmojis' => [],
        's3' => [
            'general' => []
        ]
    ];

    private static ?LDPDO $conn = null;
    /** A set containing the requested groups of data. */
    public static Set $reqGroup;
    /** A set containing the fetched groups of data. */
    public static Set $fetGroup;
    public static Set $req;
    public static Set $fet;

    /** Initializes the class. */
    public static function init():void {
        self::$reqGroup = new Set();
        self::$fetGroup = new Set();
        self::$req = new Set();
        self::$fet = new Set();
    }

    public static function request(DataType $dt, mixed $datumId) {
        if (self::$req->contains([$dt,$datumId])) return -1;
        else if (self::$fet->contains([$dt,$datumId])) return -2;

        self::$conn ??= get_tracked_pdo(true);
        self::$req->add([$dt,$datumId]);
        return 0;
    }

    public static function requestGroup(DataType $dt, mixed $dg):int {
        if (self::$reqGroup->contains([$dt,$dg])) return -1;
        else if (self::$fetGroup->contains([$dt,$dg])) return -2;

        self::$conn ??= get_tracked_pdo(true);
        self::$reqGroup->add([$dt,$dg]);
        return 0;
    }

    public static function get(array $path):?array {
        $checkPath = function() use($path) {
            $a =& BufferManager::$result;
            foreach ($path as $s) {
                if (array_key_exists($s,$a)) { $a =& $a[$s]; continue; }
                else return false;
            }
            return $a;
        };

        $v = $checkPath();
        if (is_array($v)) return $v;
        BufferManager::exec();
        $v = $checkPath();
        if (is_array($v)) return $v;
        
        return null;
    }

    public static function exec() {
        while (self::$reqGroup->count() > 0) {
            $start = self::$reqGroup->count();
            foreach (self::$reqGroup->getIterator() as $a) {
                switch ($a[0]) {
                    case DataType::ForumThread:
                    case DataType::ForumTidThread:
                    case DataType::ForumComment:
                    case DataType::ForumTidComment:
                    case DataType::Octohit:
                    case DataType::ForumSearch: ForumBuffer::exec(self::$conn); break;
                    case DataType::User:
                    case DataType::TidUser:
                    case DataType::Notification:
                    case DataType::Emoji: UsersBuffer::exec(self::$conn); break;
                    case DataType::Record: RecordsBuffer::exec(self::$conn); break;
                }
            }
            if ($start <= self::$reqGroup->count()) throw new \Error("ReqGroup error. ({$a[0]->name})");
        }
        while (self::$req->count() > 0) {
            $start = self::$req->count();
            foreach (self::$req->getIterator() as $a) {
                switch ($a[0]) {
                    case DataType::ForumThread:
                    case DataType::ForumTidThread:
                    case DataType::ForumComment:
                    case DataType::ForumTidComment:
                    case DataType::FirstUnreadComment: ForumBuffer::exec(self::$conn); break;
                    case DataType::User:
                    case DataType::TidUser:
                    case DataType::TidAssociatedRegisteredUser:
                    case DataType::Emoji: UsersBuffer::exec(self::$conn); break;
                    case DataType::Record: RecordsBuffer::exec(self::$conn); break;
                    case DataType::S3KeyData: S3Buffer::exec(self::$conn); break;
                }
            }
            if ($start <= self::$req->count()) throw new \Error("Req error. ({$a[0]->name})");
        }
    }

    public static function pagRequest(LDPDO $conn, string $dbName, string $whereCond="1=1", PaginationVals $pag, string|callable $cursorRow,
        callable $encodeCursor, callable $decodeCursor, callable $storeOne, callable $storeAll, string $select='*', ?array $executeVals = null) {
        if ($whereCond == "") $whereCond = "1=1";
        $first = $pag->first;
        $last = $pag->last;
        $after = $pag->getAfterCursor();
        $before = $pag->getBeforeCursor();

        $executeValsWhereOnly = null; 
        if ($executeVals != null) {
            $executeValsWhereOnly = [];
            foreach ($executeVals as $k => $v) if (str_contains($whereCond,$k)) $executeValsWhereOnly[$k] = $v;
            if (count($executeValsWhereOnly) == 0) $executeValsWhereOnly = null;
        }

        // Make and exec sql
        $sql = "SELECT $select FROM $dbName";
        $n = 0;
        $vCurs = null;
        $pageCount = null;
        $currPage = null;
        $getLastPage = $pag->lastPageSpecialBehavior && $last != null && ($before == null && $after == null);

        if ($getLastPage) { // if requesting last page: do this
            
            if ($executeValsWhereOnly != null) {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM $dbName WHERE $whereCond");
                $stmt->execute($executeValsWhereOnly);
                $count = $stmt->fetch(\PDO::FETCH_NUM)[0];
            } else $count = $conn->query("SELECT COUNT(*) FROM $dbName WHERE $whereCond")->fetch(\PDO::FETCH_NUM)[0];

            $n = $first != null ? $first : $last;
            $i = $count % $n;
            if ($i == 0) $i = $n;
            if ($executeVals != null) {
                $stmt = $conn->prepare("SELECT $select FROM $dbName WHERE $whereCond ORDER BY ".(is_callable($cursorRow) ? $cursorRow(null,4) : $cursorRow." DESC")." LIMIT $i");
                $stmt->execute($executeVals);
            } else $stmt = $conn->query("SELECT $select FROM $dbName WHERE $whereCond ORDER BY ".(is_callable($cursorRow) ? $cursorRow(null,4) : $cursorRow." DESC")." LIMIT $i");

            $before=null;
            $whereCondAfterCurs = "AND ($whereCond)";
        } else { // otherwise do as normal
            if ($after != null) {
                $vCurs = $decodeCursor($after);
                if (is_string($vCurs)) $vCurs = "'$vCurs'";
                $sql .= is_callable($cursorRow) ? " WHERE ".$cursorRow($vCurs,1) : " WHERE $cursorRow>$vCurs";
            }
            if ($before != null) {
                $vCurs = $decodeCursor($before);
                if (is_string($vCurs)) $vCurs = "'$vCurs'";
                $sql .= $after != null ? " AND " : " WHERE ";
                $sql .= is_callable($cursorRow) ? $cursorRow($vCurs,2) : "$cursorRow<$vCurs";
            }
            
            $whereCondAfterCurs = ($after == null && $before == null) ? "WHERE $whereCond" : "AND ($whereCond)";
            $sql .= " $whereCondAfterCurs";
            if ($pag->skipPages > 0) $whereCondAfterCurs = " AND ($whereCond)";

            if ($first != null && $first > 0) {
                $n = $first;
                $n2 = $pag->skipPages > 0 ? ($first*($pag->skipPages+1))+1 : $n+1;
                $sql .= is_callable($cursorRow) ? " ORDER BY ".$cursorRow($vCurs,3)." LIMIT $n2" : " ORDER BY $cursorRow LIMIT $n2";
            } else if ($last != null && $last > 0) {
                $n = $last;
                $n2 = $pag->skipPages > 0 ? ($last*($pag->skipPages+1))+1 : $n+1;
                $sql .= is_callable($cursorRow) ? " ORDER BY ".$cursorRow($vCurs,4)." LIMIT $n2" : " ORDER BY $cursorRow DESC LIMIT $n2";
            }

            if ($executeVals != null) {
                $stmt = $conn->prepare($sql);
                $stmt->execute($executeVals);
            } else $stmt = $conn->query($sql,\PDO::FETCH_ASSOC);
        }  

        // Store results
        $result = [];
        $aRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $hadMoreResults = false;
        for ($i=0; $i<count($aRows); $i++) {
            if ($i < $n*$pag->skipPages) continue;
            if (count($result) == $n) { $hadMoreResults = true; break; }
            $row = $aRows[$i];
            
            $v = ['data' => $row, 'metadata' => ['fromDb' => $dbName]];
            $storeOne($v);

            $refRow =& $v;
            $cursor = $encodeCursor($row);
            if (count($result) === 0) $startCursor = $cursor;
            $endCursor = $cursor;
            $result[] = ['edge' => $refRow, 'cursor' => $cursor];
        }
        $nResults = count($result);
        if ($last != null && $nResults > 0) {
            $result = array_reverse($result);
            $startCursor = $result[0]['cursor'];
            $endCursor = $result[$nResults-1]['cursor'];
        }

        // Set $after and $vCurs according to the result
        if (($getLastPage || $pag->skipPages > 0) && $nResults>0) {
            $after = $encodeCursor($result[0]['edge']['data']);
            $vCurs = $decodeCursor($after);
        }
        
        if ($nResults > 0) {
            if ($after != null || $before != null) {
                $where1 = (is_callable($cursorRow) ? $cursorRow($vCurs,5) : "$cursorRow<=$vCurs") . " $whereCondAfterCurs";
                $where2 = (is_callable($cursorRow) ? $cursorRow($vCurs,6) : "$cursorRow>=$vCurs") . " $whereCondAfterCurs";
            } else $where1 = $where2 = $whereCond;

            $hasPreviousPage = false;
            $hasNextPage = false;
            if ($last != null && $hadMoreResults) $hasPreviousPage = true;
            else if ($after != null) {
                if ($executeValsWhereOnly != null) {
                    $stmt = $conn->prepare("SELECT 1 FROM $dbName WHERE $where1 LIMIT 1");
                    $stmt->execute($executeValsWhereOnly);
                    $hasPreviousPage = $stmt->fetch() !== false;
                } else $hasPreviousPage = $conn->query("SELECT 1 FROM $dbName WHERE $where1 LIMIT 1")->fetch() !== false;
            }
            if ($first != null && $hadMoreResults) $hasNextPage = true;
            else if ($before != null) {
                if ($executeValsWhereOnly != null) {
                    $stmt = $conn->prepare("SELECT 1 FROM $dbName WHERE $where2 LIMIT 1");
                    $stmt->execute($executeValsWhereOnly);
                    $hasNextPage = $stmt->fetch() !== false;
                } else $hasNextPage = $conn->query("SELECT 1 FROM $dbName WHERE $where2 LIMIT 1")->fetch() !== false;
            }
        }

        if ($pag->requestPageCount == true) {            
            if ($executeValsWhereOnly != null) {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM $dbName WHERE $whereCond");
                $stmt->execute($executeValsWhereOnly);
                $v = $stmt->fetch(\PDO::FETCH_NUM)[0] / $n;
            } else $v = $conn->query("SELECT COUNT(*) FROM $dbName WHERE $whereCond")->fetch(\PDO::FETCH_NUM)[0] / $n;
            $pageCount = (int)((fmod($v,1) > 0) ? $v+1 : $v);
            if ($pageCount < 1) $pageCount = 1;
            
            if ($nResults == 0) $currPage = 1; 
            else if ($after == null && $before == null) $currPage = $first != null ? 1 : $pageCount;
            else {
                try {
                    $data = $result[0]['edge']['data'];
                    $vCurs2 = $decodeCursor($encodeCursor($data));
                    if (is_string($vCurs)) $vCurs2 = "'$vCurs2'";

                    $s = is_callable($cursorRow) ? "($whereCond) AND ".$cursorRow($vCurs2,5) : "($whereCond) AND $cursorRow<=$vCurs2";
                    
                    if ($executeValsWhereOnly != null) {
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM $dbName WHERE $s");
                        $stmt->execute($executeValsWhereOnly);
                        $nItemsBefore = $stmt->fetch()[0];
                    } else $nItemsBefore = $conn->query("SELECT COUNT(*) FROM $dbName WHERE $s")->fetch()[0];
                    
                    $currPage = ceil($nItemsBefore / $n);
                } catch (\Exception $e) {  }
            }
        }
        
        $storeAll([
            'data' => $result,
            'metadata' => [
                'fromDb' => $dbName,
                'pageInfo' => new PageInfo($startCursor??null,$endCursor??null,$hasPreviousPage??false,$hasNextPage??false,$pageCount,$currPage)
            ]
        ]);
    }
}

class UsersBuffer {
    public static function storeRegisteredUser(array $row, ?array $metadata = null):array {
        BufferManager::$req->remove([DataType::User,$row['id']]);
        BufferManager::$fet->add([DataType::User,$row['id']]);
        return BufferManager::$result['users'][$row['id']] = ['data' => $row, 'metadata' => $metadata];
    }

    public static function requestFromId(int $id):bool {
        return BufferManager::request(DataType::User, $id) == 0;
    }

    public static function requestTidUser(int $id):bool {
        return BufferManager::request(DataType::TidUser, $id) == 0;
    }

    public static function requestTidAssociatedRegisteredUser(int $id):bool {
        return BufferManager::request(DataType::TidAssociatedRegisteredUser, $id) == 0;
    }

    public static function requestUserNotifications(int $userId, PaginationVals $pag) {
        return BufferManager::requestGroup(DataType::Notification,[$userId,$pag]);
    }

    public static function requestEmoji(string $s, int $userId=0):bool {
        return BufferManager::request(DataType::Emoji,[$s,$userId]) == 0;
    }

    public static function requestUserEmojis(int $userId, PaginationVals $pag) {
        return BufferManager::requestGroup(DataType::Emoji,[$userId,$pag]);
    }

    public static function requestUsers(PaginationVals $pag, bool $twinoidUsers = false) {
        return BufferManager::requestGroup(($twinoidUsers ? DataType::TidUser : DataType::User),$pag);
    }

    public static function getFromId(int $id):?array {
        return BufferManager::get(['users',$id]);
    }

    public static function getTidUser(int $id):?array {
        return BufferManager::get(['tidUsers',$id]);
    }

    public static function getTidAssociatedRegisteredUser(int $id):?array {
        return BufferManager::get(['tidAssociatedRegisteredUsers',$id]);
    }

    public static function getUserNotifications(int $userId, PaginationVals $pag) {
        return BufferManager::get(['notificationsM',$userId,$pag->getString()]);
    }

    public static function getEmoji(string $s, int $userId=0) {
        return $userId <= 0 ? BufferManager::get(['emojis',$s]) : BufferManager::get(['usersEmojis',$userId,$s]);
    }

    public static function getUserEmojis(int $userId, PaginationVals $pag) {
        return BufferManager::get(['emojisM',$userId,$pag->getString()]);
    }

    public static function getUsers(PaginationVals $pag, bool $twinoidUsers = false) {
        return BufferManager::get([($twinoidUsers ? 'tidUsersM' : 'usersM'),$pag->getString()]);
    }

    public static function exec(LDPDO $conn) {
        $bufRes =& BufferManager::$result;
        $req =& BufferManager::$req;
        $fet =& BufferManager::$fet;
        $rg =& BufferManager::$reqGroup;
        $fg =& BufferManager::$fetGroup;
        $mandatoryEmojis = [
            'tid/AlphaBounce/',
            'tid/Arkadeo',
            'tid/Braziball Puzzle',
            'tid/CroqueMotel/',
            'tid/Die2Nite/',
            'tid/DinoRPG/',
            'tid/Fever!/',
            'tid/Hordes/',
            'tid/Intrusion/',
            'tid/Kadokado/',
            'tid/Kingdom/',
            'tid/Kube/',
            'tid/Majority/',
            'tid/Monster Hotel/',
            'tid/MotionBall 2/',
            'tid/Mush/',
            'tid/Muxxu/',
            // 'tid/Naturalchimie 2/',
            'tid/Odyssey/',
            'tid/Rockfaller Journey/',
            'tid/Snake/',
            'tid/Street Writer/',
            'tid/Studio Quiz/',
            'tid/Teacher Story/',
            'tid/Twinoid v1/',
            'tid/Twinoid v2/',
            'tid/Twinoid v3/',
            'tid/Twinoid/',
            'tid/Uppercup Football/'
        ];

        $toRemove = [];

        foreach ($rg->getIterator() as $v) switch($v[0]) {
            case DataType::Notification:
                $userId = $v[1][0];
                $pag = $v[1][1];

                $cursF = function($vCurs,$i) {
                    switch ($i) {
                        case 1: return "last_update_date<'{$vCurs[0]}'";
                        case 2: return "last_update_date>'{$vCurs[0]}'";
                        case 3: return "last_update_date DESC";
                        case 4: return "last_update_date";
                        case 5: return "last_update_date>='{$vCurs[0]}'";
                        case 6: return "last_update_date<='{$vCurs[0]}'";
                        default: throw new \Schema\SafeBufferException("cursorF ??");
                    }
                };
                
                BufferManager::pagRequest($conn, 'notifications', "user_id=$userId", $pag, $cursF,
                    fn($row) => base64_encode("{$row['number']}"),
                    fn($s) =>  (preg_match('/^\d+$/',base64_decode($s),$m) === 0) ? 0 : intval($m[0]),
                    function($row) use(&$bufRes,&$req,&$fet,&$userId) {
                        $bufRes['notifications'][$userId][$row['data']['number']] = $row;
                        $req->remove([DataType::Notification,[$userId,$row['data']['number']]]);
                        $fet->add([DataType::Notification,[$userId,$row['data']['number']]]);
                    },
                    function($rows) use(&$bufRes,&$pag,&$userId) { $bufRes['notificationsM'][$userId][$pag->getString()] = $rows; },
                    '*'
                );
                array_push($toRemove,$v);
                break;
            case DataType::Emoji:
                $userId = $v[1][0];
                $pag = $v[1][1];

                if ($userId > 0) {
                    if (!isset($bufRes['userEmojis'][$userId])) $bufRes['userEmojis'][$userId] = $conn->query("SELECT * FROM users_emojis WHERE user_id=$userId")->fetchAll();
                    $userEmojis =& $bufRes['userEmojis'][$userId];
                } else $userEmojis = [];

                $sqlWhere = "";
                foreach ($mandatoryEmojis as $s) {
                    if ($sqlWhere != '') $sqlWhere .= ' OR';
                    $sqlWhere .= " id LIKE '{$s}%'";
                }
                foreach ($userEmojis as $d) $sqlWhere .= " OR id='{$d['emoji_id']}'";

                BufferManager::pagRequest($conn, 'emojis', $sqlWhere, $pag, 'id',
                    fn($row) => base64_encode($row['id']),
                    fn($s) => preg_match('/^[\w\/\.]+$/',base64_decode($s),$m) > 0 ? $m[0] : '',
                    function($row) use(&$bufRes,&$req,&$fet,&$userId) {
                        $aliases = json_decode($row['data']['aliases']);
                        $bufRes['emojis'][$aliases[0]] = $row;
                        if ($userId > 0) foreach ($aliases as $alias) $bufRes['usersEmojis'][$userId][$alias] =& $row;
                        $req->remove([DataType::Emoji,[$userId,$aliases[0]]]);
                        $fet->add([DataType::Emoji,[$userId,$aliases[0]]]);
                    },
                    function($rows) use(&$bufRes,&$pag,&$userId) { $bufRes['emojisM'][$userId][$pag->getString()] = $rows; }
                );
                array_push($toRemove,$v);
                break;
            case DataType::User:
            case DataType::TidUser:
                $dataType = $v[0];
                $pag = $v[1];
                $dbName = $dataType == DataType::User ? 'users' : 'tid_users';
                $sDataType = $dataType == DataType::User ? 'users' : 'tidUsers';

                BufferManager::pagRequest($conn, $dbName, '1=1', $pag, 'id',
                    fn($row) => base64_encode($row['id']),
                    fn($s) => preg_match('/^\d+$/',base64_decode($s),$m) > 0 ? (int)$m[0] : 1,
                    function($row) use(&$bufRes,&$req,&$fet,&$sDataType,&$dataType) {
                        $bufRes[$sDataType][$row['data']['id']] = $row;
                        $req->remove([$dataType,$row['data']['id']]);
                        $fet->add([$dataType,$row['data']['id']]);
                    },
                    function($rows) use(&$bufRes,&$pag,&$sDataType) { $bufRes["{$sDataType}M"][$pag->getString()] = $rows; }
                );
                array_push($toRemove,$v);
                break;
        }
        foreach ($toRemove as $v) {
            $rg->remove($v);
            $fg->add($v);
        }
        foreach ($req->getIterator() as $v) switch ($v[0]) {
            case DataType::User:
            case DataType::TidUser:
                $userId = $v[1];
                $dbName = $v[0] == DataType::User ? 'users' : 'tid_users';
                $sDataType = $v[0] == DataType::User ? 'users' : 'tidUsers';

                $stmt = $conn->prepare("SELECT * FROM $dbName WHERE id=?");
                $stmt->execute([$userId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                $bufRes[$sDataType][$userId] = $row === false ? null : ['data' => $row, 'metadata' => $dbName];
                array_push($toRemove,$v);
                break;
            case DataType::TidAssociatedRegisteredUser:
                $tidId = $v[1];

                $stmt = $conn->prepare('SELECT id_a FROM id_links WHERE id_b=?');
                $stmt->execute([$tidId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                $bufRes['tidAssociatedRegisteredUsers'][$tidId] = ['data' => $row === false ? null : $row, 'metadata' => $dbName];
                array_push($toRemove,$v);
                break;
            case DataType::Emoji:
                $alias = $v[1][0];
                $userId = $v[1][1];

                $stmt = $conn->query("SELECT * FROM emojis WHERE JSON_CONTAINS(aliases,'\"$alias\"')");
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $aliases = json_decode($row['aliases']);
                    if (!is_array($aliases)) continue;

                    foreach ($aliases as $al) {
                        $bufRes['emojis'][$al] = ['data' => $row, 'metadata' => null];

                        if ($userId > 0) {
                            $authorized = false;
                            foreach ($mandatoryEmojis as $s) if (str_starts_with($row['id'], $s)) { $authorized = true; break; }
                            if (!$authorized) {
                                $row2 = $conn->query("SELECT * FROM users_emojis WHERE user_id=$userId AND emoji_id='{$row['id']}'")->fetch(\PDO::FETCH_ASSOC);
                                if ($row2 != false) $authorized = true;
                            }

                            if ($authorized) $bufRes['usersEmojis'][$userId][$al] =& $bufRes['emojis'][$al];
                            else $bufRes['usersEmojis'][$userId][$al] = null;
                        }
                    }
                }
                
                array_push($toRemove,$v);
                break;
        }
        foreach ($toRemove as $v) {
            $req->remove($v);
            $fet->add($v);
        }
    }
}

class ForumBuffer {
    public static function storeThread(array $row, ?array $metadata = null):array {
        BufferManager::$req->remove([DataType::ForumThread,$row['id']]);
        BufferManager::$fet->add([DataType::ForumThread,$row['id']]);
        return BufferManager::$result['forum']['threads'][$row['id']] = ['data' => $row, 'metadata' => $metadata];
    }

    public static function storeComment(array $row, ?array $metadata = null):array {
        $comment = \LDLib\Forum\Comment::initFromRow($row);
        BufferManager::$req->remove([DataType::ForumComment,$comment->nodeId]);
        BufferManager::$fet->add([DataType::ForumComment,$comment->nodeId]);
        return BufferManager::$result['forum']['comments'][$comment->nodeId] = ['data' => $row, 'metadata' => $metadata];
    }

    public static function forgetComment(string $id) {
        BufferManager::$fet->remove([DataType::ForumComment,$id]);
        unset(BufferManager::$result['forum']['comments'][$id]);
    }

    public static function requestThread(int|string $id) {
        if (is_string($id) && preg_match('/^forum_(\d+)$/',$id,$m) > 0) return BufferManager::request(DataType::ForumThread, (int)$m[1]);
        else if (is_int($id)) return BufferManager::request(DataType::ForumThread, $id);
        throw new SafeBufferException("requestThread: invalid id '$id'");
    }

    public static function requestTidThread(int|string $id) {
        if (is_string($id) && preg_match('/^forum_tid_(\d+)$/',$id,$m) > 0) return BufferManager::request(DataType::ForumTidThread, (int)$m[1]);
        else if (is_int($id)) return BufferManager::request(DataType::ForumTidThread, $id);
        throw new SafeBufferException("requestTidThread: invalid id '$id'");
    }

    public static function requestComment(string $id) {
        if (preg_match('/^forum_(\d+)-(\d+)$/',$id,$m) > 0) return BufferManager::request(DataType::ForumComment,[(int)$m[1],(int)$m[2]]);
        throw new SafeBufferException("requestComment: invalid id '$id'");
    }

    public static function requestFirstUnreadComment(int $userId, int|string $threadId) {
        if (is_string($threadId) && preg_match('/^forum_(\d+)$/',$threadId,$m) > 0) return BufferManager::request(DataType::FirstUnreadComment, [$userId,(int)$m[1]]);
        else if (is_int($threadId)) return BufferManager::request(DataType::FirstUnreadComment, [$userId,$threadId]);
        throw new SafeBufferException("requestFirstUnreadComment: invalid id '$threadId'");
    }

    public static function requestTidComment(string $id) {
        if (preg_match('/^forum_tid_(\d+)-(\d+)$/',$id,$m) > 0) return BufferManager::request(DataType::ForumTidComment,[(int)$m[1],(int)$m[2]]);
        throw new SafeBufferException("requestTidComment: invalid id '$id'");
    }

    public static function requestThreads(PaginationVals $pag, ?int $userId = null) {
        $userId = (!is_int($userId) || $userId < 1) ? 0 : $userId;
        if ($userId != 0) UsersBuffer::requestFromId($userId);
        return BufferManager::requestGroup(DataType::ForumThread,[$pag,$userId]);
    }

    public static function requestTidThreads(PaginationVals $pag) {
        return BufferManager::requestGroup(DataType::ForumTidThread,$pag);
    }

    public static function requestComments(int $threadId, PaginationVals $pag) {
        return BufferManager::requestGroup(DataType::ForumComment,[$threadId,$pag]);
    }

    public static function requestTidComments(int $threadId, PaginationVals $pag) {
        return BufferManager::requestGroup(DataType::ForumTidComment,[$threadId,$pag]);
    }

    public static function requestCommentOctohits(string $threadId, int $commNumber) {
        return BufferManager::requestGroup(DataType::Octohit,[$threadId,$commNumber]);
    }

    public static function requestSearch(ForumSearchQuery $fsq, PaginationVals $pag) {
        return BufferManager::requestGroup(DataType::ForumSearch,[$fsq,$pag]);
    }

    public static function getThread(int|string $id) {
        if (is_string($id) && preg_match('/^forum_(\d+)$/',$id,$m) > 0) return BufferManager::get(['forum','threads',(int)$m[1]]);
        else if (is_int($id)) return BufferManager::get(['forum','threads',$id]);
        throw new SafeBufferException("getThread: invalid id '$id'");
    }

    public static function getTidThread(int|string $id) {
        if (is_string($id) && preg_match('/^forum_tid_(\d+)$/',$id,$m) > 0) return BufferManager::get(['forum','tid_threads',(int)$m[1]]);
        else if (is_int($id)) return BufferManager::get(['forum','tid_threads',$id]);
        throw new SafeBufferException("getTidThread: invalid id '$id'");
    }

    public static function getThreads(PaginationVals $pag, ?int $userId = null) {
        $userId = (!is_int($userId) || $userId < 1) ? 0 : $userId;
        return BufferManager::get(['forum','threadsM',$userId,$pag->getString()]);
    }

    public static function getTidThreads(PaginationVals $pag) {
        return BufferManager::get(['forum','tid_threadsM',$pag->getString()]);
    }

    public static function getComment(string $id) {
        return BufferManager::get(['forum','comments',$id]);
    }

    public static function getFirstUnreadComment(int $userId, int|string $threadId) {
        if (is_string($threadId) && preg_match('/^forum_(\d+)$/',$threadId,$m) > 0) return BufferManager::get(['forum','firstUnreadComments',$userId,(int)$m[1]]);
        else if (is_int($threadId)) return BufferManager::get(['forum','firstUnreadComments',$userId,$threadId]);
        throw new SafeBufferException("getFirstUnreadComment: invalid id '$threadId'");
    }

    public static function getTidComment(string $id) {
        return BufferManager::get(['forum','tid_comments',$id]);
    }

    public static function getComments(int $threadId, PaginationVals $pag) {
        return BufferManager::get(['forum','commentsM',$threadId,$pag->getString()]);
    }

    public static function getTidComments(int $threadId, PaginationVals $pag) {
        return BufferManager::get(['forum','tid_commentsM',$threadId,$pag->getString()]);
    }

    public static function getCommentOctohits(string $threadId, int $commNumber) {
        return BufferManager::get(['forum','octohits',"{$threadId}_{$commNumber}"]);
    }

    public static function getSearch(ForumSearchQuery $fsq, PaginationVals $pag) {
        $s1 = $fsq->asString();
        $s2 = $pag->getString();
        return BufferManager::get(['forum','search',"$s1...$s2"]);
    }

    public static function exec(LDPDO $conn) {
        $bufRes =& BufferManager::$result;
        $rg =& BufferManager::$reqGroup;
        $fg =& BufferManager::$fetGroup;
        $req =& BufferManager::$req;
        $fet =& BufferManager::$fet;
        UsersBuffer::exec($conn);

        $toRemove = [];
        foreach ($rg->getIterator() as $v) switch ($v[0]) {
            case DataType::ForumThread:
                $pag = $v[1][0];
                $userId = $v[1][1];
                
                $rowUser = UsersBuffer::getFromId($userId);
                if ($rowUser != null && $rowUser['data'] != null) {
                    $user = RegisteredUser::initFromRow($rowUser);
                    $sRegistrationDate = $user->registrationDate->format('Y-m-d H:i:s');
                }

                $whereCond = $user->titles->contains('oldInteressant') ?  '' : "(creation_date>='$sRegistrationDate' OR permission='".ThreadPermission::ALL_USERS->value."')";
                if ($pag->data['onlyNotRead']??false) {
                    if ($whereCond != '') $whereCond .= ' AND ';
                    $whereCond .= "JSON_CONTAINS(read_by,$userId)=0";
                }

                if ($pag->sortBy == 'lastUpdate') {
                    $cursF = function($vCurs,$i) {
                        switch ($i) {
                            case 1: return "(last_update_date<'{$vCurs[0]}' OR (last_update_date='{$vCurs[0]}' AND id>{$vCurs[1]}))";
                            case 2: return "(last_update_date>'{$vCurs[0]}' OR (last_update_date='{$vCurs[0]}' AND id<{$vCurs[1]}))";
                            case 3: return "last_update_date DESC,id";
                            case 4: return "last_update_date ,id DESC";
                            case 5: return "(last_update_date>'{$vCurs[0]}' OR (last_update_date='{$vCurs[0]}' AND id<={$vCurs[1]}))";
                            case 6: return "(last_update_date<'{$vCurs[0]}' OR (last_update_date='{$vCurs[0]}' AND id>={$vCurs[1]}))";
                            // case 7: return "(last_update_date>'{$vCurs[0]}' OR (last_update_date='{$vCurs[0]}' AND id<={$vCurs[1]}))";
                            default: throw new \Schema\SafeBufferException("cursorF ??");
                        }
                    };

                    BufferManager::pagRequest($conn, 'threads', $whereCond, $pag, $cursF,
                        fn($row) => base64_encode("{$row['last_update_date']}!{$row['id']}"),
                        fn($s) => (preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)!(\d+)$/',base64_decode($s),$m) === 0) ? ['2000-01-01 00:00:00',1] : [$m[1],(int)$m[2]],
                        function ($row) use(&$bufRes,&$req,&$fet) {
                            $bufRes['forum']['threads'][$row['data']['id']] = $row;
                            $req->remove([DataType::ForumThread,$row['data']['id']]);
                            $fet->add([DataType::ForumThread,$row['data']['id']]);
                        },
                        function($rows) use(&$bufRes,&$pag,&$user) { $bufRes['forum']['threadsM'][$user->id][$pag->getString()] = $rows; }
                    );
                } else {
                    BufferManager::pagRequest($conn, 'threads', $whereCond, $pag, 'id',
                        fn($row) => base64_encode($row['id']),
                        fn($s) => (preg_match('/^\d+$/',base64_decode($s),$m) === 0) ? 1 : (int)$m[0],
                        function ($row) use(&$bufRes,&$req,&$fet) {
                            $bufRes['forum']['threads'][$row['data']['id']] = $row;
                            $req->remove([DataType::ForumThread,$row['data']['id']]);
                            $fet->add([DataType::ForumThread,$row['data']['id']]);
                        },
                        function($rows) use(&$bufRes,&$pag) { $bufRes['forum']['threadsM'][$pag->getString()] = $rows; }
                    );
                }
                array_push($toRemove,$v);
                break;
            case DataType::ForumTidThread:
                $pag = $v[1];
                
                $cursF = function($vCurs,$i) {
                    switch ($i) {
                        case 1: return "id<$vCurs";
                        case 2: return "id>$vCurs";
                        case 3: return "id DESC";
                        case 4: return "id";
                        case 5: return "id>=$vCurs";
                        case 6: return "id<=$vCurs";
                        default: throw new \Schema\SafeBufferException("cursorF ??");
                    }
                };

                BufferManager::pagRequest($conn, 'tid_threads', '1=1', $pag, $cursF,
                    fn($row) => base64_encode($row['id']),
                    fn($s) => (preg_match('/^\d+$/',base64_decode($s),$m) === 0) ? 1 : (int)$m[0],
                    function ($row) use(&$bufRes,&$req,&$fet) {
                        $bufRes['forum']['tid_threads'][$row['data']['id']] = $row;
                        $req->remove([DataType::ForumTidThread,$row['data']['id']]);
                        $fet->add([DataType::ForumTidThread,$row['data']['id']]);
                    },
                    function($rows) use(&$bufRes,&$pag) { $bufRes['forum']['tid_threadsM'][$pag->getString()] = $rows; }
                );
                array_push($toRemove,$v);
                break;
            case DataType::ForumComment:
                $threadId = $v[1][0];
                $pag = $v[1][1];
                
                BufferManager::pagRequest($conn, 'comments', "thread_id=$threadId", $pag, 'number',
                    fn($row) => base64_encode("{$row['thread_id']}-{$row['number']}"),
                    fn($s) => preg_match('/^\d+-(\d+)$/', base64_decode($s), $m) > 0 ? (int)$m[1] : 0,
                    function ($row) use(&$bufRes,&$req,&$fet) {
                        $comm = \LDLib\Forum\Comment::initFromRow($row);
                        $bufRes['forum']['comments'][$comm->nodeId] = $row;
                        $req->remove([DataType::ForumComment,[$comm->threadId,$comm->number]]);
                        $fet->add([DataType::ForumComment,[$comm->threadId,$comm->number]]);
                    },
                    function($rows) use(&$bufRes,&$threadId,&$pag) { $bufRes['forum']['commentsM'][$threadId][$pag->getString()] = $rows; }
                );
                array_push($toRemove,$v);
                break;
            case DataType::ForumTidComment:
                $threadId = $v[1][0];
                $pag = $v[1][1];

                BufferManager::pagRequest($conn, 'tid_comments', "thread_id=$threadId", $pag, 'id',
                    fn($row) => base64_encode("{$row['thread_id']}-{$row['id']}"),
                    fn($s) => preg_match('/^\d+-(\d+)$/', base64_decode($s), $m) > 0 ? (int)$m[1] : 0,
                    function ($row) use(&$bufRes,&$req,&$fet) {
                        $comm = \LDLib\Forum\TidComment::initFromRow($row);
                        $bufRes['forum']['tid_comments'][$comm->nodeId] = $row;
                        $req->remove([DataType::ForumTidComment,[$comm->threadId,$comm->id]]);
                        $fet->add([DataType::ForumTidComment,[$comm->threadId,$comm->id]]);
                    },
                    function($rows) use(&$bufRes,&$threadId,&$pag) { $bufRes['forum']['tid_commentsM'][$threadId][$pag->getString()] = $rows; }
                );
                array_push($toRemove,$v);
                break;
            case DataType::ForumSearch:
                $fsq = $v[1][0];
                $pag = $v[1][1];
                $keywords = $fsq->keywords;
                
                switch ($fsq->threadType) {
                    case \LdLib\Forum\ThreadType::Standard:
                        $dtThread = DataType::ForumThread;
                        $dtComment = DataType::ForumComment;
                        $threadsRow = 'threads';
                        $commsRow = 'comments';
                        $sCommNumRow = 'number';
                        $sDateRow = 'creation_date';
                        break;
                    case \LdLib\Forum\ThreadType::Twinoid:
                        $dtThread = DataType::ForumTidThread;
                        $dtComment = DataType::ForumTidComment;
                        $threadsRow = 'tid_threads';
                        $commsRow = 'tid_comments';
                        $sCommNumRow = 'id';
                        $sDateRow = 'deduced_date';
                        break;
                }

                $sqlWhere = "MATCH(content) AGAINST(:keywords IN BOOLEAN MODE)";
                if ($fsq->startDate != null) { $sStartDate = $fsq->startDate->format('Y-m-d H:i:s'); $sqlWhere .= " AND $sDateRow>='$sStartDate'"; }
                if ($fsq->endDate != null) { $sEndDate = $fsq->endDate->format('Y-m-d H:i:s'); $sqlWhere .= " AND $sDateRow<='$sEndDate'"; }
                if ($fsq->userIds != null) {
                    $sqlWhere .= ' AND (';
                    for ($i=0; $i<count($fsq->userIds); $i++) $sqlWhere .= $i > 0 ? " OR author_id={$fsq->userIds[$i]}" : "author_id={$fsq->userIds[$i]}";
                    $sqlWhere .= ')';
                }

                switch ($fsq->sortBy) {
                    case SearchSorting::ByDate:
                        $sRow = $sDateRow;
                        $cursF = function($vCurs,$i) use(&$sDateRow,&$sCommNumRow) {
                            if ($vCurs != null) {
                                $date = $vCurs[0];
                                $thId = $vCurs[1];
                                $id = $vCurs[2];
                            }
                            switch ($i) {
                                case 1: return "($sDateRow<'$date' OR ($sDateRow='$date' AND (thread_id>$thId OR (thread_id=$thId AND $sCommNumRow>$id))))";
                                case 2: return "($sDateRow>'$date' OR ($sDateRow='$date' AND (thread_id<$thId OR (thread_id=$thId AND $sCommNumRow<$id))))";
                                case 3: return "$sDateRow DESC,thread_id,$sCommNumRow";
                                case 4: return "$sDateRow,thread_id DESC,$sCommNumRow DESC";
                                case 5: return "($sDateRow>'$date' OR ($sDateRow='$date' AND (thread_id<$thId OR (thread_id=$thId AND $sCommNumRow<=$id))))";
                                case 6: return "($sDateRow<'$date' OR ($sDateRow='$date' AND (thread_id>$thId OR (thread_id=$thId AND $sCommNumRow>=$id))))";
                                default: throw new \Schema\SafeBufferException("cursorF ??");
                            }
                        };
                        break;
                    case SearchSorting::ByRelevance:
                        $sRow = 'relevance';
                        $cursF = function($vCurs,$i) use(&$sCommNumRow) {
                            $sRow = '(MATCH(content) AGAINST(:keywords IN BOOLEAN MODE))';
                            $tol = 0.00001;
                            if ($vCurs != null) {
                                $relevance = $vCurs[0];
                                $thId = $vCurs[1];
                                $id = $vCurs[2];
                            }
                            switch ($i) {
                                case 1: return "($sRow<$relevance-$tol OR (abs($relevance-$sRow)<=$tol AND (thread_id>$thId OR (thread_id=$thId AND $sCommNumRow>$id))))";
                                case 2: return "($sRow>$relevance+$tol OR (abs($relevance-$sRow)<=$tol AND (thread_id<$thId OR (thread_id=$thId AND $sCommNumRow<$id))))";
                                case 3: return "relevance DESC,thread_id,$sCommNumRow";
                                case 4: return "relevance,thread_id DESC,$sCommNumRow DESC";
                                case 5: return "($sRow>$relevance+$tol OR (abs($relevance-$sRow)<=$tol AND (thread_id<$thId OR (thread_id=$thId AND $sCommNumRow<=$id))))";
                                case 6: return "($sRow<$relevance-$tol OR (abs($relevance-$sRow)<=$tol AND (thread_id>$thId OR (thread_id=$thId AND $sCommNumRow>=$id))))";
                                default: throw new \Schema\SafeBufferException("cursorF ??");
                            }
                        };
                        break;
                    default: throw new \Exception('?????????azeae');
                }

                $threadIds = [];
                BufferManager::pagRequest($conn, $commsRow, $sqlWhere, $pag, $cursF,
                    function($row) use($sRow, &$sCommNumRow) { return base64_encode("{$row[$sRow]}!{$row['thread_id']}!{$row[$sCommNumRow]}"); },
                    fn($s) => (preg_match('/^(?:(\d{4}-\d\d-\d\d(?: \d\d:\d\d:\d\d)?|\d+\.?\d*))!(\d+)!(\d+)$/',base64_decode($s),$m) === 0) ? ["2001-01-01 00:00:00",1,1] : [$m[1],$m[2],$m[3]],
                    function (&$row) use(&$bufRes,&$req,&$fet,&$threadIds,&$commsRow,&$dtComment) {
                        switch ($dtComment) {
                            case DataType::ForumTidComment:
                                $comm = \LDLib\Forum\TidComment::initFromRow($row);
                                $commNum = $comm->id;
                                break;
                            case DataType::ForumComment:
                                $comm = \LDLib\Forum\Comment::initFromRow($row);
                                $commNum = $comm->number;
                                break;
                        }

                        $threadIds[] = $comm->threadId;
                        $bufRes['forum'][$commsRow][$comm->nodeId] = $row;
                        $req->remove([$dtComment,[$comm->threadId,$commNum]]);
                        $fet->add([$dtComment,[$comm->threadId,$commNum]]);
                    },
                    function($rows) use(&$bufRes,&$fsq,&$pag) {
                        $s1 = $fsq->asString();
                        $s2 = $pag->getString();
                        $bufRes['forum']['search']["$s1...$s2"] = $rows;
                    },
                    "*,MATCH(content) AGAINST(:keywords IN BOOLEAN MODE) AS relevance",
                    [':keywords' => $keywords]
                );
                
                if (count($threadIds) > 0) {
                    $sql = "SELECT * FROM $threadsRow WHERE ";
                    for ($i=0; $i<count($threadIds); $i++) {
                        if ($i != 0) $sql .= ' OR ';
                        $sql .= "id={$threadIds[$i]}";
                    }
                    $stmt = $conn->query($sql,\PDO::FETCH_ASSOC);
                    while ($row = $stmt->fetch()) {
                        $req->remove($dtThread,$row['id']);
                        $fet->add($dtThread,$row['id']);
                        $bufRes['forum'][$threadsRow][(int)$row['id']] = ['data' => $row, 'metadata' => null];
                    }
                }

                array_push($toRemove, $v);
                break;
            case DataType::Octohit:
                $threadId = $v[1][0];
                $commNumber = $v[1][1];

                $stmt = $conn->prepare("SELECT * FROM octohit_comments WHERE thread_id=? AND comm_number=?");
                $stmt->execute([$threadId,$commNumber]);
                $res = [];
                $total = 0;
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $res[] = ['data' => $row, 'metadata' => null];
                    $total += $row['amount'];
                }
                $bufRes['forum']['octohits']["{$threadId}_{$commNumber}"] = ['data' => $res, 'metadata' => ['totalAmount' => $total]];

                array_push($toRemove,$v);
                break;
        }
        foreach ($toRemove as $v) {
            $rg->remove($v);
            $fg->add($v);
        }
        $toRemove = [];
        foreach ($req->getIterator() as $v) switch ($v[0]) {
            case DataType::ForumThread:
                $threadId = $v[1];

                $stmt = $conn->prepare("SELECT * FROM threads WHERE id=? LIMIT 1");
                $stmt->execute([$threadId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                $bufRes['forum']['threads'][$threadId] = ['data' => $row === false ? null : $row, 'metadata' => null];
                array_push($toRemove, $v);
                break;
            case DataType::ForumTidThread:
                $threadId = $v[1];
                $stmt = $conn->prepare("SELECT * FROM tid_threads WHERE id=? LIMIT 1");
                $stmt->execute([$threadId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                $bufRes['forum']['tid_threads'][$threadId] = ['data' => $row === false ? null : $row, 'metadata' => null];
                array_push($toRemove, $v);
                break;
            case DataType::ForumComment:
                $threadId = $v[1][0];
                $number = $v[1][1];
                $stmt = $conn->prepare("SELECT * FROM comments WHERE thread_id=? AND number=? LIMIT 1");
                $stmt->execute([$threadId,$number]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                $bufRes['forum']['comments']["forum_{$threadId}-{$number}"] = ['data' => $row === false ? null : $row, 'metadata' => null];
                array_push($toRemove, $v);
                break;
            case DataType::ForumTidComment:
                $threadId = $v[1][0];
                $id = $v[1][1];
                $stmt = $conn->prepare("SELECT * FROM tid_comments WHERE thread_id=? AND id=? LIMIT 1");
                $stmt->execute([$threadId,$id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                $bufRes['forum']['tid_comments'][\LDLib\Forum\TidComment::getIdFromRow($row)] = ['data' => $row === false ? null : $row, 'metadata' => null];
                array_push($toRemove, $v);
                break;
            case DataType::FirstUnreadComment:
                $userId = $v[1][0];
                $threadId = $v[1][1];
                $metadata = null;

                $row = $conn->query("SELECT * FROM comments WHERE thread_id=$threadId AND JSON_CONTAINS(read_by, '$userId')=0 ORDER BY number LIMIT 1")->fetch();
                if ($row !== false) {
                    $pos = $conn->query("SELECT COUNT(*) FROM comments WHERE thread_id=$threadId AND number<{$row['number']}")->fetch(\PDO::FETCH_NUM)[0];
                    $metadata = ['pos' => $pos];
                }

                $bufRes['forum']['firstUnreadComments'][$userId][$threadId] = ['data' => $row === false ? null : $row, 'metadata' => $metadata];
                array_push($toRemove, $v);
                break;
        }
        foreach ($toRemove as $v) {
            $req->remove($v);
            $fet->add($v);
        }
    }
}

class RecordsBuffer {
    public static function requestFromId(int $id):bool {
        return BufferManager::request(DataType::Record, $id) == 0;
    }

    public static function requestMultiple(PaginationVals $pag):bool {
        return BufferManager::requestGroup(DataType::Record, $pag) == 0;
    }

    public static function getFromId(int $id):?array {
        return BufferManager::get(['records',$id]);
    }

    public static function getMultiple(PaginationVals $pag) {
        return BufferManager::get(['recordsM',$pag->getString()]);
    }

    public static function exec(LDPDO $conn) {
        $bufRes =& BufferManager::$result;
        $req =& BufferManager::$req;
        $fet =& BufferManager::$fet;
        $rg =& BufferManager::$reqGroup;
        $fg =& BufferManager::$fetGroup;

        $toRemove = [];
        foreach ($rg->getIterator() as $v) switch ($v[0]) {
            case DataType::Record:
                $pag = $v[1];
                $cursF = function($vCurs,$i) {
                    switch ($i) {
                        case 1: return "date<'{$vCurs[0]}' OR (date='{$vCurs[0]}' AND id<{$vCurs[1]})";
                        case 2: return "date>'{$vCurs[0]}' OR (date='{$vCurs[0]}' AND id>{$vCurs[1]})";
                        case 3: return "date DESC,id DESC";
                        case 4: return "date,id";
                        case 5: return "date>='{$vCurs[0]}' OR (date='{$vCurs[0]}' AND id>={$vCurs[1]})";
                        case 6: return "date<='{$vCurs[0]}' OR (date='{$vCurs[0]}' AND id<={$vCurs[1]})";
                        default: throw new \Schema\SafeBufferException("cursorF ??");
                    }
                };                
                BufferManager::pagRequest($conn, 'records', '1=1', $pag, $cursF,
                    fn($row) => base64_encode("{$row['date']}!{$row['id']}"),
                    fn($s) => (preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)!(\d+)$/',base64_decode($s),$m) ? ['2000-01-01 00:00:00',1] : [$m[1],(int)$m[2]]),
                    function($row) use(&$bufRes,&$req,&$fet) {
                        $bufRes['records'][$row['data']['id']] = $row;
                        $req->remove([DataType::Notification,[$row['data']['id']]]);
                        $fet->add([DataType::Notification,[$row['data']['id']]]);
                    },
                    function($rows) use(&$bufRes,&$pag) { $bufRes['recordsM'][$pag->getString()] = $rows; },
                    '*'
                );
                
                array_push($toRemove,$v);
                break;
        }
        foreach ($toRemove as $v) {
            $rg->remove($v);
            $fg->add($v);
        }
        $toRemove = [];
        foreach ($req->getIterator() as $v) switch ($v[0]) {
            case DataType::Record:
                $recordId = $v[1];

                $stmt = $conn->prepare("SELECT * FROM records WHERE id=?");
                $stmt->execute([$recordId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                $bufRes['records'][$recordId] = $row === false ? null : ['data' => $row, 'metadata' => null];
                array_push($toRemove,$v);
                break;
        }
        foreach ($toRemove as $v) {
            $req->remove($v);
            $fet->add($v);
        }
    }
}

class S3Buffer {
    public static function requestKeyData(string $keyName) {
        return BufferManager::request(DataType::S3KeyData,[$keyName]);
    }

    public static function getKeyData(string $keyName) {
        return BufferManager::get(['s3','general',$keyName]);
    }

    public static function exec(LDPDO $conn) {
        $bufRes =& BufferManager::$result;
        $req =& BufferManager::$req;
        $fet =& BufferManager::$fet;

        $toRemove = [];
        foreach ($req->getIterator() as $v) switch ($v[0]) {
            case DataType::S3KeyData:
                $keyName = $v[1][0];
                if (preg_match('/^(\d+)_/',$keyName,$m) > 0) $userId = (int)$m[1];

                if ($userId != null) {
                    $stmt = $conn->prepare("SELECT * FROM s3_general WHERE user_id=? and obj_key=?");
                    $stmt->execute([$userId,$keyName]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                }

                $bufRes['s3']['general'][$keyName] = ['data' => ($row??false) === false ? null : $row, 'metadata' => null];
                array_push($toRemove,$v);
                break;
        }
        foreach ($toRemove as $v) {
            $req->remove($v);
            $fet->add($v);
        }
    }
}

class SafeBufferException extends \Exception implements ClientAware {
    public function isClientSafe():bool { return true; }
}

BufferManager::init();
?>