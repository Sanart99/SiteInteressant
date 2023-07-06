<?php
namespace Schema;

$libDir = __DIR__.'/../../lib';
require_once __DIR__.'/../../vendor/autoload.php';
require_once $libDir.'/db.php';

use Ds\Set;
use GraphQL\Error\ClientAware;
use LDLib\Database\LDPDO;
use LDLib\Forum\{ForumSearchQuery,SearchSorting};
use LDLib\General\ {
    PageInfo,
    PaginationVals
};

use function LDLib\Database\get_tracked_pdo;

enum DataType {
    case User;
    case ForumThread;
    case ForumComment;
    case ForumTidThread;
    case ForumTidComment;
    case ForumSearch;
}

class BufferManager {
    /** Contains all the fetched data, the other buffers references it. */
    public static array $result = [
        'users' => [],
        'forum' => [
            'threads' => [],
            'threadsM' => [],
            'comments' => [],
            'commentsM' => [],
            'tid_threads' => [],
            'tid_threadsM' => [],
            'tid_comments' => [],
            'tid_commentsM' => []
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
                    case DataType::ForumSearch: ForumBuffer::exec(self::$conn); break;
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
                    case DataType::ForumTidComment: ForumBuffer::exec(self::$conn); break;
                    case DataType::User: UsersBuffer::exec(self::$conn); break;
                }
            }
            if ($start <= self::$req->count()) throw new \Error("Req error. ({$a[0]->name})");
        }
    }

    public static function pagRequest(LDPDO $conn, string $dbName, string $whereCond="", PaginationVals $pag, string|callable $cursorRow,
        callable $encodeCursor, callable $decodeCursor, callable $storeOne, callable $storeAll, string $select='*', ?array $executeVals = null) {
        $first = $pag->first;
        $last = $pag->last;
        $after = $pag->getAfterCursor();
        $before = $pag->getBeforeCursor();

        // Make and exec sql
        $n = 0;
        $vCurs = null;
        $sql = "SELECT $select FROM $dbName";
        if ($after != null) {
            $vCurs = $decodeCursor($after);
            if (is_string($vCurs)) $vCurs = "'$vCurs'";
            $sql .= is_callable($cursorRow) ? " WHERE ".$cursorRow($vCurs,1) : " WHERE $cursorRow>$vCurs";
        } else if ($before != null) {
            $vCurs = $decodeCursor($before);
            if (is_string($vCurs)) $vCurs = "'$vCurs'";
            $sql .= is_callable($cursorRow) ? " WHERE ".$cursorRow($vCurs,2) : " WHERE $cursorRow<$vCurs";
        }
        if ($whereCond != "") {
            $whereCondAfterCurs = ($after == null && $before == null) ? "WHERE $whereCond" : "AND $whereCond";
            $sql .= " $whereCondAfterCurs";
        }

        if ($first != null && $first > 0) {
            $n = $first+1;
            $sql .= is_callable($cursorRow) ? " ORDER BY ".$cursorRow($vCurs,3)." LIMIT $n" : " ORDER BY $cursorRow LIMIT $n";
        } else if ($last != null && $last > 0) {
            $n = $last+1;
            $sql .= is_callable($cursorRow) ? " ORDER BY ".$cursorRow($vCurs,4)." LIMIT $n" : " ORDER BY $cursorRow DESC LIMIT $n";
        }
        if ($executeVals != null) {
            $stmt = $conn->prepare($sql);
            $stmt->execute($executeVals);
        } else $stmt = $conn->query($sql,\PDO::FETCH_ASSOC);

        // Store results
        $result = [];
        $hadMoreResults = false;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (count($result) == $n-1) { $hadMoreResults = true; break; }
            
            $v = ['data' => $row, 'metadata' => null];
            $storeOne($v);

            $refRow =& $v;
            $cursor = $encodeCursor($row);
            if (count($result) === 0) $startCursor = $cursor;           
            $result[] = ['edge' => $refRow, 'cursor' => $cursor];
        }
        if ($last != null) $result = array_reverse($result);

        $nResults = count($result);
        if ($nResults > 0) {
            if ($after != null || $before != null) {
                $where1 = (is_callable($cursorRow) ? $cursorRow($vCurs,5) : "$cursorRow<=$vCurs") . " $whereCondAfterCurs";
                $where2 = (is_callable($cursorRow) ? $cursorRow($vCurs,6) : "$cursorRow>=$vCurs") . " $whereCondAfterCurs";
            } else $where1 = $where2 = $whereCond;

            $startCursor = $result[0]['cursor'] ?? null;
            $endCursor = $result[$nResults-1]['cursor'] ?? null;

            $hasPreviousPage = false;
            $hasNextPage = false;
            if ($last != null && $hadMoreResults) $hasPreviousPage = true;
            else if ($after != null) {
                if ($executeVals != null) {
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($executeVals);
                    $hasPreviousPage = $stmt->fetch() !== false;
                } else $hasPreviousPage = $conn->query("SELECT 1 FROM $dbName WHERE $where1 LIMIT 1")->fetch() !== false;
            }
            if ($first != null && $hadMoreResults) $hasNextPage = true;
            else if ($first != null) {
                if ($executeVals != null) {
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($executeVals);
                    $hasNextPage = $stmt->fetch() !== false;
                } else $hasNextPage = $conn->query("SELECT 1 FROM $dbName WHERE $where2 LIMIT 1")->fetch() !== false;
            }
        }

        $pageCount = null;
        if ($pag->requestPageCount == true) {
            if ($executeVals != null) {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM $dbName WHERE $whereCond");
                $stmt->execute($executeVals);
                $pageCount = ($stmt->fetch(\PDO::FETCH_NUM)[0] / ($n-1))+1;
            } else return $conn->query("SELECT COUNT(*) FROM $dbName WHERE $whereCond")->fetch(\PDO::FETCH_NUM)[0];
        }
        $storeAll([
            'data' => $result,
            'metadata' => [
                'pageInfo' => new PageInfo($startCursor??null,$endCursor??null,$hasPreviousPage??false,$hasNextPage??false,$pageCount)
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

    public static function getFromId(int $id):?array {
        return BufferManager::get(['users',$id]);
    }

    public static function exec(LDPDO $conn) {
        $bufRes =& BufferManager::$result;
        $req =& BufferManager::$req;
        $fet =& BufferManager::$fet;

        $toRemove = [];
        foreach ($req->getIterator() as $v) if ($v[0] === DataType::User) {
            $userId = $v[1];

            $stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $bufRes['users'][$userId] = $row === false ? null : ['data' => $row, 'metadata' => null];
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

    public static function requestTidComment(string $id) {
        if (preg_match('/^forum_tid_(\d+)-(\d+)$/',$id,$m) > 0) return BufferManager::request(DataType::ForumTidComment,[(int)$m[1],(int)$m[2]]);
        throw new SafeBufferException("requestTidComment: invalid id '$id'");
    }

    public static function requestThreads(PaginationVals $pag) {
        return BufferManager::requestGroup(DataType::ForumThread,$pag);
    }

    public static function requestComments(int $threadId, PaginationVals $pag) {
        return BufferManager::requestGroup(DataType::ForumComment,[$threadId,$pag]);
    }

    public static function requestTidComments(int $threadId, PaginationVals $pag) {
        return BufferManager::requestGroup(DataType::ForumTidComment,[$threadId,$pag]);
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

    public static function getThreads(PaginationVals $pag) {
        return BufferManager::get(['forum','threadsM',$pag->getString()]);
    }

    public static function getComment(string $id) {
        return BufferManager::get(['forum','comments',$id]);
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

        $toRemove = [];
        foreach ($rg->getIterator() as $v) switch ($v[0]) {
            case DataType::ForumThread:
                $pag = $v[1];

                if ($pag->sortBy == 'lastUpdate') {
                    $cursF = function($vCurs,$i) {
                        switch ($i) {
                            case 1: return "(last_update_date>'{$vCurs[0]}' OR (last_update_date='{$vCurs[0]}' AND id>{$vCurs[1]}))";
                            case 2: return "(last_update_date<'{$vCurs[0]}' OR (last_update_date='{$vCurs[0]}' AND id<{$vCurs[1]}))";
                            case 3: return "last_update_date,id";
                            case 4: return "last_update_date,id DESC";
                            case 5: return "(last_update_date<='{$vCurs[0]}' OR (last_update_date='{$vCurs[0]}' AND id<={$vCurs[1]}))";
                            case 6: return "(last_update_date>='{$vCurs[0]}' OR (last_update_date='{$vCurs[0]}' AND id>={$vCurs[1]}))";
                            default: throw new \Schema\SafeBufferException("cursorF ??");
                        }
                    };

                    BufferManager::pagRequest($conn, 'threads', '', $pag, $cursF,
                        fn($row) => base64_encode("{$row['last_update_date']}!{$row['id']}"),
                        fn($s) => (preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)!(\d+)$/',base64_decode($s),$m) === 0) ? ['2000-01-01 00:00:00',1] : [$m[1],(int)$m[2]],
                        function ($row) use(&$bufRes,&$req,&$fet) {
                            $bufRes['forum']['threads'][$row['data']['id']] = $row;
                            $req->remove([DataType::ForumThread,$row['data']['id']]);
                            $fet->add([DataType::ForumThread,$row['data']['id']]);
                        },
                        function($rows) use(&$bufRes,&$pag) { $bufRes['forum']['threadsM'][$pag->getString()] = $rows; },
                        
                    );
                } else {
                    BufferManager::pagRequest($conn, 'threads', '', $pag, 'id',
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
                
                $sqlWhere = "MATCH(content) AGAINST(:keywords IN BOOLEAN MODE)";
                if ($fsq->startDate != null) { $v = $fsq->startDate->format('Y-m-d'); $sqlWhere .= " AND deduced_date>='$v'"; }
                if ($fsq->endDate != null) { $v = $fsq->endDate->format('Y-m-d'); $sqlWhere .= " AND deduced_date<='$v'"; }
                if ($fsq->userIds != null) {
                    $sqlWhere .= ' AND (';
                    for ($i=0; $i<count($fsq->userIds); $i++) $sqlWhere .= $i > 0 ? " OR author_id={$fsq->userIds[$i]}" : "author_id={$fsq->userIds[$i]}";
                    $sqlWhere .= ')';
                }

                switch ($fsq->sortBy) {
                    case SearchSorting::ByDate:
                        $sRow = 'deduced_date';
                        $cursF = function($vCurs,$i) use($sRow) {
                            switch ($i) {
                                case 1: return "($sRow<'{$vCurs[0]}' OR ($sRow='{$vCurs[0]}' AND (thread_id>{$vCurs[1]} OR (thread_id={$vCurs[1]} AND id>{$vCurs[2]}))))";
                                case 2: return "($sRow>'{$vCurs[0]}' OR ($sRow='{$vCurs[0]}' AND (thread_id<{$vCurs[1]} OR (thread_id={$vCurs[1]} AND id<{$vCurs[2]}))))";
                                case 3: return "$sRow DESC,thread_id,id";
                                case 4: return "$sRow,thread_id DESC,id DESC";
                                case 5: return "($sRow>='{$vCurs[0]}' OR ($sRow='{$vCurs[0]}' AND (thread_id<={$vCurs[1]} OR (thread_id={$vCurs[1]} AND id<={$vCurs[2]}))))";
                                case 6: return "($sRow<='{$vCurs[0]}' OR ($sRow='{$vCurs[0]}' AND (thread_id>={$vCurs[1]} OR (thread_id={$vCurs[1]} AND id>={$vCurs[2]}))))";
                                default: throw new \Schema\SafeBufferException("cursorF ??");
                            }
                        };
                        break;
                    case SearchSorting::ByRelevance:
                        $sRow = 'relevance';
                        $cursF = function($vCurs,$i) {
                            $sRow = '(MATCH(content) AGAINST(:keywords IN BOOLEAN MODE))';
                            $tol = 0.00001;
                            switch ($i) {
                                case 1: return "($sRow<{$vCurs[0]}-$tol OR (abs({$vCurs[0]}-$sRow)<=$tol AND (thread_id>{$vCurs[1]} OR (thread_id={$vCurs[1]} AND id>{$vCurs[2]}))))";
                                case 2: return "($sRow>{$vCurs[0]}+$tol OR (abs({$vCurs[0]}-$sRow)<=$tol AND (thread_id<{$vCurs[1]} OR (thread_id={$vCurs[1]} AND id<{$vCurs[2]}))))";
                                case 3: return "relevance DESC,thread_id,id";
                                case 4: return "relevance,thread_id DESC,id DESC";
                                case 5: return "($sRow>={$vCurs[0]}-$tol OR (abs({$vCurs[0]}-$sRow)<=$tol AND (thread_id<={$vCurs[1]} OR (thread_id={$vCurs[1]} AND id<={$vCurs[2]}))))";
                                case 6: return "($sRow<={$vCurs[0]}+$tol OR (abs({$vCurs[0]}-$sRow)<=$tol AND (thread_id>={$vCurs[1]} OR (thread_id={$vCurs[1]} AND id>={$vCurs[2]}))))";
                                default: throw new \Schema\SafeBufferException("cursorF ??");
                            }
                        };
                        break;
                    default: throw new \Exception('?????????azeae');
                }

                $threadIds = [];
                BufferManager::pagRequest($conn, 'tid_comments', $sqlWhere, $pag, $cursF,
                    function($row) use($sRow) { return base64_encode("{$row[$sRow]}!{$row['thread_id']}!{$row['id']}"); },
                    fn($s) => (preg_match('/^(?:(\d{4}-\d\d-\d\d|\d+\.?\d*))!(\d+)!(\d+)$/',base64_decode($s),$m) === 0) ? ["2001-01-01",1,1] : [$m[1],$m[2],$m[3]],
                    function ($row) use(&$bufRes,&$req,&$fet,&$threadIds) {
                        $comm = \LDLib\Forum\TidComment::initFromRow($row);
                        $threadIds[] = $comm->threadId;
                        $bufRes['forum']['tid_comments'][$comm->nodeId] = $row;
                        $req->remove([DataType::ForumTidComment,[$comm->threadId,$comm->id]]);
                        $fet->add([DataType::ForumTidComment,[$comm->threadId,$comm->id]]);
                    },
                    function($rows) use(&$bufRes,&$fsq,&$pag) {
                        $s1 = $fsq->asString();
                        $s2 = $pag->getString();
                        $bufRes['forum']['search']["$s1...$s2"] = $rows;
                    },
                    "*,MATCH(content) AGAINST(:keywords IN BOOLEAN MODE) AS relevance",
                    [':keywords' => $keywords]
                );

                $sql = 'SELECT * FROM tid_threads WHERE ';
                for ($i=0; $i<count($threadIds); $i++) {
                    if ($i != 0) $sql .= ' OR ';
                    $sql .= "id={$threadIds[$i]}";
                }
                $stmt = $conn->query($sql,\PDO::FETCH_ASSOC);
                while ($row = $stmt->fetch()) {
                    $req->remove(DataType::ForumTidThread,$row['id']);
                    $fet->add(DataType::ForumTidThread,$row['id']);
                    $bufRes['forum']['tid_threads'][(int)$row['id']] = ['data' => $row, 'metadata' => null];
                }

                array_push($toRemove, $v);
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

                $bufRes['forum']['comments'][\LDLib\Forum\Comment::getIdFromRow($row)] = ['data' => $row === false ? null : $row, 'metadata' => null];
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