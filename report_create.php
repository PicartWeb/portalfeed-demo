<?php
// report_create.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

function local_redirect_target(string $fallback = 'dashboard.php'): string
{
    $redirect = trim((string)($_POST['redirect'] ?? ''));
    if ($redirect === '' || preg_match('#^(?:https?:)?//#i', $redirect)) {
        return $fallback;
    }

    return ltrim($redirect, '/\\');
}

$userId      = $_SESSION['user_id'];
$targetType  = $_POST['target_type'] ?? '';
$targetId    = (int)($_POST['target_id'] ?? 0);
$reason      = trim($_POST['reason'] ?? '');
$details     = trim($_POST['details'] ?? '');
$redirect    = local_redirect_target();

$allowedTypes = ['post','comment','user'];

if (!$targetId || !in_array($targetType, $allowedTypes, true) || $reason === '') {
    header("Location: {$redirect}");
    exit;
}

$targetExists = false;
if ($targetType === 'post') {
    $targetStmt = $pdo->prepare("SELECT id FROM posts WHERE id = :id LIMIT 1");
} elseif ($targetType === 'comment') {
    $targetStmt = $pdo->prepare("SELECT id FROM comments WHERE id = :id LIMIT 1");
} else {
    $targetStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
}
$targetStmt->execute([':id' => $targetId]);
$targetExists = (bool)$targetStmt->fetch();

if (!$targetExists) {
    header("Location: {$redirect}");
    exit;
}

$stmt = $pdo->prepare("
  INSERT INTO reports (reporter_id, target_type, target_id, reason, details)
  VALUES (:rid, :type, :tid, :reason, :details)
");
$stmt->execute([
  ':rid'     => $userId,
  ':type'    => $targetType,
  ':tid'     => $targetId,
  ':reason'  => $reason,
  ':details' => $details
]);

header("Location: {$redirect}#reported");
exit;
