<?php
// notifications_mark_read.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

function local_redirect_target(string $fallback = 'dashboard.php'): string
{
    $redirect = trim($_POST['redirect'] ?? '');
    if ($redirect === '' || preg_match('#^(?:https?:)?//#i', $redirect)) {
        return $fallback;
    }

    return ltrim($redirect, '/\\');
}

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("
  UPDATE notifications
  SET is_read = 1, read_at = NOW()
  WHERE to_user_id = :uid AND is_read = 0
");
$stmt->execute([':uid' => $userId]);

$redirect = local_redirect_target();
header('Location: ' . $redirect);
exit;
