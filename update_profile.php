<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

function goBack(): void
{
    header('Location: settings.php');
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    goBack();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    goBack();
}

$username  = trim($_POST['username'] ?? '');
$email     = trim($_POST['email'] ?? '');
$full_name = trim($_POST['full_name'] ?? '');
$bio       = trim($_POST['bio'] ?? '');
$skills    = trim($_POST['skills'] ?? '');
$country   = trim($_POST['country'] ?? '');
$timezone  = trim($_POST['timezone'] ?? '');

$link_tiktok    = trim($_POST['link_tiktok'] ?? '');
$link_instagram = trim($_POST['link_instagram'] ?? '');
$link_x         = trim($_POST['link_x'] ?? '');
$link_portfolio = trim($_POST['link_portfolio'] ?? '');

if ($username === '' || $email === '') {
    $_SESSION['profile_error'] = 'Username and email are required.';
    goBack();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['profile_error'] = 'Please enter a valid email address.';
    goBack();
}

try {
    // Check if username/email is already used by another user
    $checkStmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE (username = :username OR email = :email)
          AND id != :id
        LIMIT 1
    ");
    $checkStmt->execute([
        ':username' => $username,
        ':email'    => $email,
        ':id'       => $userId
    ]);

    if ($checkStmt->fetch()) {
        $_SESSION['profile_error'] = 'Username or email is already used by another account.';
        goBack();
    }

    $avatarPath = null;

    // Upload avatar if provided
    if (isset($_FILES['avatar']) && !empty($_FILES['avatar']['name'])) {
        if (!is_dir(__DIR__ . '/uploads/avatars')) {
            mkdir(__DIR__ . '/uploads/avatars', 0777, true);
        }

        if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['profile_error'] = 'Avatar upload failed.';
            goBack();
        }

        $tmpName = $_FILES['avatar']['tmp_name'];
        $originalName = $_FILES['avatar']['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($extension, $allowed, true)) {
            $_SESSION['profile_error'] = 'Avatar must be JPG, PNG, or WEBP.';
            goBack();
        }

        if ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            $_SESSION['profile_error'] = 'Avatar must be under 2MB.';
            goBack();
        }

        $newFileName = 'avatar_' . $userId . '_' . time() . '.' . $extension;
        $targetFs = __DIR__ . '/uploads/avatars/' . $newFileName;
        $targetDb = 'uploads/avatars/' . $newFileName;

        if (!move_uploaded_file($tmpName, $targetFs)) {
            $_SESSION['profile_error'] = 'Failed to save uploaded avatar.';
            goBack();
        }

        $avatarPath = $targetDb;
    }

    if ($avatarPath) {
        $stmt = $pdo->prepare("
            UPDATE users
            SET username       = :u,
                email          = :e,
                full_name      = :f,
                bio            = :b,
                skills         = :s,
                country        = :c,
                timezone       = :tz,
                link_tiktok    = :lt,
                link_instagram = :li,
                link_x         = :lx,
                link_portfolio = :lp,
                avatar         = :a
            WHERE id = :id
        ");
        $stmt->execute([
            ':u'   => $username,
            ':e'   => $email,
            ':f'   => ($full_name !== '' ? $full_name : $username),
            ':b'   => $bio,
            ':s'   => $skills,
            ':c'   => $country,
            ':tz'  => $timezone,
            ':lt'  => $link_tiktok,
            ':li'  => $link_instagram,
            ':lx'  => $link_x,
            ':lp'  => $link_portfolio,
            ':a'   => $avatarPath,
            ':id'  => $userId
        ]);

        $_SESSION['avatar'] = $avatarPath;
    } else {
        $stmt = $pdo->prepare("
            UPDATE users
            SET username       = :u,
                email          = :e,
                full_name      = :f,
                bio            = :b,
                skills         = :s,
                country        = :c,
                timezone       = :tz,
                link_tiktok    = :lt,
                link_instagram = :li,
                link_x         = :lx,
                link_portfolio = :lp
            WHERE id = :id
        ");
        $stmt->execute([
            ':u'   => $username,
            ':e'   => $email,
            ':f'   => ($full_name !== '' ? $full_name : $username),
            ':b'   => $bio,
            ':s'   => $skills,
            ':c'   => $country,
            ':tz'  => $timezone,
            ':lt'  => $link_tiktok,
            ':li'  => $link_instagram,
            ':lx'  => $link_x,
            ':lp'  => $link_portfolio,
            ':id'  => $userId
        ]);
    }

    $_SESSION['username'] = $username;
    $_SESSION['full_name'] = ($full_name !== '' ? $full_name : $username);
    $_SESSION['profile_success'] = 'Profile updated successfully.';

    header('Location: settings.php');
    exit;

} catch (PDOException $e) {
    $_SESSION['profile_error'] = 'Database error: ' . $e->getMessage();
    goBack();
}