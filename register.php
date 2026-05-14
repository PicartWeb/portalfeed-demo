<?php
// register.php
session_start();
require __DIR__ . '/db.php';

function back() {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back();

$username  = trim($_POST['username'] ?? '');
$email     = trim($_POST['email'] ?? '');
$full_name = trim($_POST['full_name'] ?? '');
$pass      = $_POST['password'] ?? '';
$pass2     = $_POST['password_confirm'] ?? '';

if ($username === '' || $email === '' || $pass === '' || $pass2 === '') {
    $_SESSION['auth_error_register'] = 'Fill in all fields.';
    back();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['auth_error_register'] = 'Invalid email.';
    back();
}

if ($pass !== $pass2) {
    $_SESSION['auth_error_register'] = 'Passwords do not match.';
    back();
}

if (strlen($pass) < 6) {
    $_SESSION['auth_error_register'] = 'Password must be at least 6 characters.';
    back();
}

// check existing
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1");
$stmt->execute([':u'=>$username, ':e'=>$email]);
if ($stmt->fetch()) {
    $_SESSION['auth_error_register'] = 'Username or email already exists.';
    back();
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO users (username,email,password_hash,full_name,role)
    VALUES (:u,:e,:p,:f,'member')
");
$stmt->execute([
    ':u'=>$username,
    ':e'=>$email,
    ':p'=>$hash,
    ':f'=>$full_name ?: $username,
]);

$_SESSION['auth_success_register'] = 'Account created. You can login now.';
back();
