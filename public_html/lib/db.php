<?php
namespace LDLib\Database;

require_once __DIR__.'/utils/utils.php';
require_once __DIR__.'/../subdomains/api/schema.php';

use Schema\Context;

class LDPDO {
    public \PDO $pdo;

    public function __construct() {
        $this->pdo = connect_to_database(true);
    }

    public function query(string $query, ?int $fetchMode = null, ?int $cost=null):\PDOStatement|false {
        Context::$cost += $cost ?? 1;
        return $this->pdo->query($query,$fetchMode);
    }

    public function prepare(string $query, array $options=[], ?int $cost=null):\PDOStatement|false {
        Context::$cost += $cost ?? 1;
        return $this->pdo->prepare($query,$options);
    }
}

function connect_to_database(bool $exitOnError = true):\PDO|LDPDO|null {
    dotenv();
    try {
        $conn = new \PDO("mysql:host={$_SERVER['LD_DB_HOST']};dbname={$_SERVER['LD_DB_NAME']}", $_SERVER['LD_DB_USER'], $_SERVER['LD_DB_PWD']);
        $conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch(\PDOException $e) {
        if ($exitOnError) {
            if ((bool)$_SERVER['LD_DEBUG']) echo "Connection failed: " . $e->getMessage();
            exit(1);
        }
        return null;
    }
}

function get_tracked_pdo():LDPDO {
    return new LDPDO();
}
?>