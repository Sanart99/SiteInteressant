<?php
namespace LDLib\Auth;

require_once __DIR__.'/utils/utils.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/gen.php';
dotenv();

use Schema\{Context,UsersBuffer};
use LDLib\Database\LDPDO;
use LDLib\General\{ErrorType,TypedException};
use LDLib\User\RegisteredUser;

use function LDLib\Database\{get_lock,release_lock};

function login_user(LDPDO $conn, string $name, string $pwd, bool $rememberMe, ?string $appId) {
    $now = new \DateTime('now');
    $sNow = $now->format('Y-m-d H:i:s');
    $appId = ($appId == null && isset(Context::$headers['user-agent'])) ? Context::$headers['user-agent'] : 'EMPTY USER AGENT';
    $domain = $_SERVER['LD_LINK_DOMAIN'];

    $registerAttempt = function(?int $userId, bool $successful, ?string $errType) use($conn,$appId,$sNow) {
        $stmt = $conn->prepare("INSERT INTO connection_attempts (user_id,app_id,remote_address,date,successful,error_type) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$userId,$appId,$_SERVER['REMOTE_ADDR'],$sNow,(int)$successful,$errType]);
    };

    if (isset($_COOKIE['sid'])) return ErrorType::DUPLICATE;

    if ($conn->query("SELECT COUNT(*) FROM connection_attempts WHERE DATE(date)=DATE('$sNow') AND successful=0")->fetch()[0] > 10)
        return ErrorType::PROHIBITED;
    
    // Check name+pwd
    if (preg_match('/\w+/',$name) == 0) return ErrorType::USERNAME_INVALID; // Bad name
    $m = crypt_password($pwd);
    $stmt = $conn->prepare("SELECT * FROM users WHERE name=? AND password=? LIMIT 1");
    $stmt->execute([$name,$m[2]]);
    $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($userRow == false) { $registerAttempt(null,false,ErrorType::NOTFOUND->name); return ErrorType::NOTFOUND; }
    
    // Generate session id and register connection
    $sid = bin2hex(random_bytes(16));
    $stmt = $conn->prepare("INSERT INTO connections (user_id,session_id,app_id,created_at,last_activity_at) VALUES(?,?,?,?,?);");
    if ($stmt->execute([$userRow['id'],$sid,$appId,$sNow,$sNow]) === false) ErrorType::DATABASE_ERROR; // Couldn't register connection

    $registerAttempt($userRow['id'],true,null);

    // All good, create cookie
    $time = $rememberMe ? time()+(60*60*24*30) : 0;
    $secure = !((bool)$_SERVER['LD_LOCAL']);
    setcookie("sid", $sid, $time, "/", $domain, $secure, true);

    $user = RegisteredUser::initFromRow(UsersBuffer::storeRegisteredUser($userRow));
    Context::setAuthenticatedUser($user);
    return $user;
}

function logout_user(LDPDO $conn, int $userId) {
    setcookie("sid", "", time()-3600, "/", $_SERVER['LD_LINK_DOMAIN']);
    $stmt = $conn->prepare("DELETE FROM connections WHERE session_id=?");
    $stmt->execute([$_COOKIE['sid']]);
    return $stmt->rowCount() === 1;
}

function logout_user_from_everything(LDPDO $conn, int $userId) {
    setcookie("sid", "", time()-3600, "/", $_SERVER['LD_LINK_DOMAIN']);
    return $conn->query("DELETE FROM connections WHERE user_id=$userId")->rowCount();
}

function register_user(LDPDO $conn, string $username, string $password, string $inviteSid) {
    if (mb_strlen($username, "utf8") > 30) return ErrorType::USERNAME_INVALID;
    else if (strlen($password) < 6) return ErrorType::PASSWORD_INVALID;
    else if (strlen($password) > 150) return ErrorType::PASSWORD_INVALID;
    else if (preg_match('/^[\w\-_]+$/u', $username) < 1) return ErrorType::USERNAME_INVALID;
    else if ($conn->query("SELECT * FROM users WHERE name='$username' LIMIT 1")->fetch() !== false) return ErrorType::DUPLICATE;
    else if (!isset($inviteSid)) return ErrorType::INVITECODE_NOTFOUND;
    $inviteRow = verify_invite_sid($conn,$inviteSid);
    if (!is_array($inviteRow)) return $inviteRow; // supposed to be ErrorType
    else if ($inviteRow['user_id'] != null) { delete_cookie("invite_sid"); return ErrorType::UNKNOWN; } // sus

    $conn->query("START TRANSACTION");
    $stmt = $conn->prepare("INSERT INTO users (name,password,registration_date) VALUES (?,?,?) RETURNING *");
    $stmt->execute([$username,crypt_password($password)[2],(new \DateTime('now'))->format('Y-m-d H:i:s')]);
    if ($stmt->rowCount() != 1) return ErrorType::DATABASE_ERROR;

    $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);    
    $stmt = $conn->prepare("UPDATE invite_queues SET user_id=? WHERE code=? AND session_id=? LIMIT 1");
    $stmt->execute([$userRow['id'],$inviteRow['code'],$inviteSid]);
    if ($stmt->rowCount() != 1) return ErrorType::DATABASE_ERROR;
    $conn->query("COMMIT");
    setcookie("invite_sid", "", time()-3600, "/", $_SERVER['LD_LINK_DOMAIN']);

    return RegisteredUser::initFromRow(UsersBuffer::storeRegisteredUser($userRow));
}

function get_user_from_sid(LDPDO $conn, string $sid):?RegisteredUser {
    $stmt = $conn->prepare('SELECT * FROM connections WHERE session_id=? LIMIT 1;');
    $stmt->execute([$sid]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row === false) return null;
    UsersBuffer::requestFromId($row['user_id']);
    $userRow = UsersBuffer::getFromId($row['user_id']);
    return RegisteredUser::initFromRow($userRow);
}

function crypt_password(string $pwd):array {
    $res = preg_match('/^(.{28})(.{32})$/',crypt($pwd,$_SERVER['LD_CRYPT_PASSWORD']),$m);
    if ($res === false || $res === 0) throw new TypedException("Password encryption failure.", ErrorType::PASSWORD_INVALID);
    return $m;
}

function create_invite_code(LDPDO $conn, string $code) {
    $stmt = $conn->prepare('SELECT id FROM invite_codes WHERE code=? LIMIT 1');
    $stmt->execute([$code]);
    if ($stmt->fetch(\PDO::FETCH_NUM)[0] !== false) return ErrorType::DUPLICATE;
}

function process_invite_code(LDPDO $conn, string $code):array|ErrorType {
    if (preg_match('/^[\w0-9]+$/u',$code,$m) < 1) return ErrorType::INVITECODE_INVALID;

    $invite = $conn->query("SELECT * FROM invite_codes WHERE code='$code' LIMIT 1", \PDO::FETCH_ASSOC)->fetch();
    if ($invite === false) return ErrorType::NOTFOUND;

    // session id detected
    if (isset($_COOKIE['invite_sid'])) {
        $inviteRow = verify_invite_sid($conn,$_COOKIE['invite_sid']);
        if ($inviteRow instanceof ErrorType) setcookie("invite_sid", "", time()-3600, "/", $_SERVER['LD_LINK_DOMAIN']);
        else return ErrorType::INVITECODE_ALREADYPROCESSED;
    }

    $referreeCount = $conn->query("SELECT COUNT(*) FROM invite_queues WHERE code='$code'", \PDO::FETCH_NUM)->fetch()[0];
    if ($referreeCount >= $invite['max_referree_count']) return ErrorType::INVITECODE_LIMITREACHED;

    // Try to add to queue
    $now = new \DateTime('now');
    $lockName = "siteinteressant_invite_$code";
    $sid = bin2hex(random_bytes(16));
    if (get_lock($conn, $lockName) != 1) return ErrorType::DBLOCK_TAKEN;

    $stmt = $conn->prepare("INSERT INTO invite_queues (code,date,session_id) VALUES (?,?,?)");
    $stmt->execute([$code,$now->format('Y-m-d H:i:s'),$sid]);
    release_lock($conn, $lockName);
    setcookie("invite_sid", $sid, time()+(60*60*10), "/", $_SERVER['LD_LINK_DOMAIN']);
    return $invite;
}

function verify_invite_sid(LDPDO $conn, string $sid):array|ErrorType {
    if (preg_match('/^[.\/0-9A-Za-z]{32}$/', $sid) < 1) return ErrorType::INVITECODE_INVALID;

    $stmt = $conn->prepare('SELECT * FROM invite_queues WHERE session_id=? LIMIT 1');
    $stmt->execute([$sid]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($row === false) return ErrorType::INVITECODE_NOTFOUND;
    return $row;
}
?>