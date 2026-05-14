<?php
// login.php
session_start();
require __DIR__ . '/db.php';

function goBack() {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    goBack();
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['auth_error_login'] = "Enter username and password.";
    goBack();
}

/* --- FETCH USER --- */
$stmt = $pdo->prepare("
    SELECT id, username, password_hash, full_name, role, avatar
    FROM users
    WHERE username = :u
    LIMIT 1
");
$stmt->execute([':u' => $username]);
$user = $stmt->fetch();


if (!$user || !password_verify($password, $user['password_hash'])) {
    $_SESSION['auth_error_login'] = "Invalid username or password.";
    goBack();
}

$userId = (int)$user['id'];

/* --- CHECK IF USER IS BANNED --- */
$banStmt = $pdo->prepare("
    SELECT reason, expires_at, active
    FROM user_bans
    WHERE user_id = :uid AND active = 1
    ORDER BY created_at DESC
    LIMIT 1
");
$banStmt->execute([':uid' => $userId]);
$ban = $banStmt->fetch();

if ($ban) {
    if ($ban['expires_at'] && strtotime($ban['expires_at']) < time()) {
        // ban expired → deactivate
        $pdo->prepare("UPDATE user_bans SET active = 0 WHERE user_id = :uid")
            ->execute([':uid' => $userId]);
    } else {
        // Still banned → deny login
        $_SESSION['auth_error_login'] =
            "Your account is suspended. Reason: " . htmlspecialchars($ban['reason']);

        goBack();
    }
}

/* --- LOGIN SUCCESS → CREATE SESSION --- */
$_SESSION['user_id']   = $user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role']      = $user['role'];
$_SESSION['avatar']    = $user['avatar'];

/* --- UPDATE LAST LOGIN + IP --- */
$userIp = $_SERVER['REMOTE_ADDR'] ?? null;

$update = $pdo->prepare("
    UPDATE users
    SET last_login_at = NOW(),
        last_login_ip = :ip
    WHERE id = :id
");
$update->execute([
    ':ip' => $userIp,
    ':id' => $userId
]);

/* --- SEND TO DASHBOARD --- */
header("Location: dashboard.php");
exit;
