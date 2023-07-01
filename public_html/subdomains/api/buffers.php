<?php
namespace Schema;

$libDir = __DIR__.'/../../lib';
require_once __DIR__.'/../../vendor/autoload.php';
require_once $libDir.'/db.php';

use Ds\Set;
use GraphQL\Error\ClientAware;
use LDLib\Database\LDPDO;
use LDLib\General\ {
    PageInfo,
    PaginationVals
};

use function LDLib\Database\get_tracked_pdo;

enum DataType {
    case User;
}

class BufferManager {
    /** Contains all the fetched data, the other buffers references it. */
    public static array $result = [
        /* ... */
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
                    /* ... */
                }
            }
            if ($start <= self::$reqGroup->count()) throw new \Error("ReqGroup error. ({$a[0]->name})");
        }
        while (self::$req->count() > 0) {
            $start = self::$req->count();
            foreach (self::$req->getIterator() as $a) {
                switch ($a[0]) {
                    case DataType::User: UsersBuffer::exec(self::$conn);
                }
            }
            if ($start <= self::$req->count()) throw new \Error("Req error. ({$a[0]->name})");
        }
    }

    public static function pagRequest(LDPDO $conn, string $dbName, string $whereCond="", PaginationVals $pag, string|callable $cursorRow,
        callable $encodeCursor, callable $decodeCursor, callable $storeOne, callable $storeAll) {
        $first = $pag->first;
        $last = $pag->last;
        $after = $pag->getAfterCursor();
        $before = $pag->getBeforeCursor();

        // Make and exec sql
        $n = 0;
        $vCurs = null;
        $sql = "SELECT * FROM $dbName";
        if ($after != null) {
            $vCurs = $decodeCursor($after);
            $sql .= is_callable($cursorRow) ? " WHERE ".$cursorRow($vCurs,1) : " WHERE $cursorRow>$vCurs";
        } else if ($before != null) {
            $vCurs = $decodeCursor($before);
            $sql .= is_callable($cursorRow) ? " WHERE ".$cursorRow($vCurs,2) : " WHERE $cursorRow<$vCurs";
        }
        if (is_string($vCurs)) $vCurs = "'$vCurs'";

        if ($whereCond != "") {
            $whereCond = ($after == null && $before == null) ? "WHERE $whereCond" : "AND $whereCond";
            $sql .= " $whereCond";
        }

        if ($first != null && $first > 0) {
            $n = $first+1;
            $sql .= is_callable($cursorRow) ? " ORDER BY ".$cursorRow($vCurs,3)." LIMIT $n" : " ORDER BY $cursorRow LIMIT $n";
        } else if ($last != null && $last > 0) {
            $n = $last+1;
            $sql .= is_callable($cursorRow) ? " ORDER BY ".$cursorRow($vCurs,4)." LIMIT $n" : " ORDER BY $cursorRow DESC LIMIT $n";
        }
        $stmt = $conn->query($sql,\PDO::FETCH_ASSOC);

        // Store results
        $result = [];
        $hadMoreResults = false;
        while ($row = $stmt->fetch()) {
            if (count($result) == $n-1) { $hadMoreResults = true; break; }
            
            $v = ['data' => $row, 'metadata' => null];
            $storeOne($v);

            $refRow =& $v;
            $cursor = $encodeCursor($row);
            if (count($result) === 0) $startCursor = $cursor;           
            $result[] = ['edge' => $refRow, 'cursor' => $cursor];
        }

        if (count($result) > 0) {
            if ($after != null || $before != null) {
                $cursWhere1 = is_callable($cursorRow) ? $cursorRow($vCurs,5) : "$cursorRow<=$vCurs";
                $cursWhere2 = is_callable($cursorRow) ? $cursorRow($vCurs,6) : "$cursorRow>=$vCurs";
            }

            $startCursor = $result[0]['cursor'] ?? null;
            $endCursor = $result[count($result)-1]['cursor'] ?? null;
            $hasPreviousPage = ($last != null && $hadMoreResults) ||
                ($after != null && $conn->query("SELECT 1 FROM $dbName WHERE $cursWhere1 $whereCond LIMIT 1")->fetch() !== false);
            $hasNextPage = ($first != null && $hadMoreResults) ||
                ($before != null && $conn->query("SELECT 1 FROM $dbName WHERE $cursWhere2 $whereCond LIMIT 1")->fetch() !== false);
        }

        $storeAll([
            'data' => $result,
            'metadata' => [
                'pageInfo' => new PageInfo($startCursor??null,$endCursor??null,$hasPreviousPage??false,$hasNextPage??false)
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

class SafeBufferException extends \Exception implements ClientAware {
    public function isClientSafe():bool { return true; }
}

BufferManager::init();
?>