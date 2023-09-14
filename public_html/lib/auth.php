<?php
namespace LDLib\Auth;

require_once __DIR__.'/utils/utils.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/gen.php';
dotenv();

use Schema\{Context,UsersBuffer};
use LDLib\Database\LDPDO;
use LDLib\General\{ErrorType,TypedException,OperationResult,SuccessType};
use LDLib\User\RegisteredUser;

use function LDLib\Database\{get_lock,release_lock};

function login_user(LDPDO $conn, string $name, string $pwd, bool $rememberMe, ?string $appId):OperationResult {
    $now = new \DateTime('now');
    $sNow = $now->format('Y-m-d H:i:s');
    $appId = ($appId == null && isset(Context::$headers['user-agent'])) ? Context::$headers['user-agent'] : 'EMPTY USER AGENT';
    $domain = $_SERVER['LD_LINK_DOMAIN'];

    $registerAttempt = function(?int $userId, bool $successful, ?string $errType) use($conn,$appId,$sNow) {
        $stmt = $conn->prepare("INSERT INTO connection_attempts (user_id,app_id,remote_address,date,successful,error_type) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$userId,$appId,$_SERVER['REMOTE_ADDR'],$sNow,(int)$successful,$errType]);
    };

    if (isset($_COOKIE['sid'])) return new OperationResult(ErrorType::CONTEXT_INVALID, 'A user is already authenticated.');

    if ($conn->query("SELECT COUNT(*) FROM connection_attempts WHERE DATE(date)=DATE('$sNow') AND successful=0")->fetch()[0] >= 10) return new OperationResult(ErrorType::PROHIBITED, 'Too many failed connection attempts for today.');
    
    // Check name+pwd
    if (preg_match('/\w+/',$name) == 0) return new OperationResult(ErrorType::INVALID_DATA, "The username contains invalid characters.");
    $m = crypt_password($pwd);
    $stmt = $conn->prepare("SELECT * FROM users WHERE name=? AND password=? LIMIT 1");
    $stmt->execute([$name,$m[2]]);
    $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($userRow == false) { $registerAttempt(null,false,ErrorType::NOT_FOUND->name); return new OperationResult(ErrorType::NOT_FOUND, 'User not found. Verify name and password.'); }
    
    // Generate session id and register connection
    $sid = bin2hex(random_bytes(16));
    $stmt = $conn->prepare("INSERT INTO connections (user_id,session_id,app_id,created_at,last_activity_at) VALUES(?,?,?,?,?);");
    if ($stmt->execute([$userRow['id'],$sid,$appId,$sNow,$sNow]) === false) return new OperationResult(ErrorType::DATABASE_ERROR);

    $registerAttempt($userRow['id'],true,null);

    // All good, create cookie
    $time = $rememberMe ? time()+(60*60*24*30) : 0;
    $secure = !((bool)$_SERVER['LD_LOCAL']);
    setcookie("sid", $sid, $time, "/", $domain, $secure, true);

    $user = RegisteredUser::initFromRow(UsersBuffer::storeRegisteredUser($userRow));
    Context::setAuthenticatedUser($user);
    return new OperationResult(SuccessType::SUCCESS, null, [$user->id], [$user]);
}

function logout_user(LDPDO $conn, int $userId):OperationResult {
    setcookie("sid", "", time()-3600, "/", $_SERVER['LD_LINK_DOMAIN']);
    $stmt = $conn->prepare("DELETE FROM connections WHERE session_id=?");
    $stmt->execute([$_COOKIE['sid']]);
    return new OperationResult(SuccessType::SUCCESS);
}

function logout_user_from_everything(LDPDO $conn, int $userId):OperationResult {
    setcookie("sid", "", time()-3600, "/", $_SERVER['LD_LINK_DOMAIN']);
    $c = $conn->query("DELETE FROM connections WHERE user_id=$userId")->rowCount();
    return new OperationResult(SuccessType::SUCCESS, "Disconnected $c sessions.");
}

function register_user(LDPDO $conn, string $username, string $password, string $inviteSid):OperationResult {
    if (mb_strlen($username, "utf8") > 30) return new OperationResult(ErrorType::INVALID_DATA, 'The username must not have more than 30 characters.');
    else if (strlen($password) < 6) return new OperationResult(ErrorType::INVALID_DATA, 'The password length must be greater than 5 characters.');
    else if (strlen($password) > 150) return new OperationResult(ErrorType::INVALID_DATA, 'The password length must not be greater than 150 characters.');
    else if (preg_match('/^[\w\-_]+$/u', $username) < 1) return new OperationResult(ErrorType::INVALID_DATA, 'The username contains invalid characters.');
    else if ($conn->query("SELECT * FROM users WHERE name='$username' LIMIT 1")->fetch() !== false) return new OperationResult(ErrorType::DUPLICATE, 'This username is already taken.');
    else if (!isset($inviteSid)) return new OperationResult(ErrorType::CONTEXT_INVALID, 'Invite session ID not set.');
    $inviteRow = verify_invite_sid($conn,$inviteSid);
    if (!is_array($inviteRow)) return new OperationResult($inviteRow, 'Invite session ID invalid.');
    else if ($inviteRow['user_id'] != null) { delete_cookie("invite_sid"); return new OperationResult(ErrorType::UNKNOWN); } // sus

    $conn->query("START TRANSACTION");
    $stmt = $conn->prepare("INSERT INTO users (name,password,registration_date) VALUES (?,?,?) RETURNING *");
    $stmt->execute([$username,crypt_password($password)[2],(new \DateTime('now'))->format('Y-m-d H:i:s')]);
    if ($stmt->rowCount() != 1) return new OperationResult(ErrorType::DATABASE_ERROR);

    $userRow = $stmt->fetch(\PDO::FETCH_ASSOC);    
    $stmt = $conn->prepare("UPDATE invite_queues SET user_id=? WHERE code=? AND session_id=? LIMIT 1");
    $stmt->execute([$userRow['id'],$inviteRow['code'],$inviteSid]);
    if ($stmt->rowCount() != 1) return new OperationResult(ErrorType::DATABASE_ERROR);
    $conn->query("COMMIT");
    setcookie("invite_sid", "", time()-3600, "/", $_SERVER['LD_LINK_DOMAIN']);

    $user = RegisteredUser::initFromRow(UsersBuffer::storeRegisteredUser($userRow));
    return new OperationResult(SuccessType::SUCCESS, null, [$user->id], [$user]);
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
    $sCrypt = (bool)$_SERVER['LD_TEST'] ? $_SERVER['TEST_LD_CRYPT_PASSWORD'] : $_SERVER['LD_CRYPT_PASSWORD'];
    $res = preg_match('/^(.{28})(.{32})$/',crypt($pwd, $sCrypt),$m);
    if ($res === false || $res === 0) throw new TypedException("Password encryption failure.", ErrorType::INVALID_DATA);
    return $m;
}

function create_invite_code(LDPDO $conn, string $code) {
    $stmt = $conn->prepare('SELECT id FROM invite_codes WHERE code=? LIMIT 1');
    $stmt->execute([$code]);
    if ($stmt->fetch(\PDO::FETCH_NUM)[0] !== false) return ErrorType::DUPLICATE;
}

function process_invite_code(LDPDO $conn, string $code):OperationResult {
    if (preg_match('/^[\w0-9]+$/u',$code,$m) < 1) return new OperationResult(ErrorType::INVALID_DATA, 'Invite code contains invalid characters.');

    $invite = $conn->query("SELECT * FROM invite_codes WHERE code='$code' LIMIT 1", \PDO::FETCH_ASSOC)->fetch();
    if ($invite === false) return new OperationResult(ErrorType::NOT_FOUND, "There is no invite code '$code'.");

    // session id detected
    if (isset($_COOKIE['invite_sid'])) {
        $inviteRow = verify_invite_sid($conn,$_COOKIE['invite_sid']);
        if ($inviteRow == ErrorType::NOT_FOUND) setcookie("invite_sid", "", time()-3600, "/", $_SERVER['LD_LINK_DOMAIN']);
        else if (is_array($inviteRow)) return new OperationResult(ErrorType::USELESS, 'Invite code already processed.', [], [$invite]);
        else return new OperationResult(ErrorType::UNKNOWN);
    }

    $referreeCount = $conn->query("SELECT COUNT(*) FROM invite_queues WHERE code='$code'", \PDO::FETCH_NUM)->fetch()[0];
    if ($referreeCount >= $invite['max_referree_count']) return new OperationResult(ErrorType::LIMIT_REACHED, 'This invite code has expired. (Limit reached.)');

    // Try to add to queue
    $now = new \DateTime('now');
    $lockName = "siteinteressant_invite_$code";
    $sid = bin2hex(random_bytes(16));
    if (get_lock($conn, $lockName) != 1) return new OperationResult(ErrorType::DBLOCK_TAKEN);

    $stmt = $conn->prepare("INSERT INTO invite_queues (code,date,session_id) VALUES (?,?,?)");
    $stmt->execute([$code,$now->format('Y-m-d H:i:s'),$sid]);
    release_lock($conn, $lockName);
    setcookie("invite_sid", $sid, time()+(60*60*10), "/", $_SERVER['LD_LINK_DOMAIN']);
    return new OperationResult(SuccessType::SUCCESS, null, [], [$invite]);
}

function verify_invite_sid(LDPDO $conn, string $sid):array|ErrorType {
    if (preg_match('/^[.\/0-9A-Za-z]{32}$/', $sid) < 1) return ErrorType::INVALID;

    $stmt = $conn->prepare('SELECT * FROM invite_queues WHERE session_id=? LIMIT 1');
    $stmt->execute([$sid]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($row === false) return ErrorType::NOT_FOUND;
    return $row;
}
?>