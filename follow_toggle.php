<?php
// follow_toggle.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/notify.php';

$userId   = $_SESSION['user_id'] ?? null;
$targetId = (int)($_POST['user_id'] ?? 0);
$isAjax   = isset($_POST['ajax']) && $_POST['ajax'] === '1';

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
}

if (!$userId || !$targetId || $userId === $targetId) {
    if ($isAjax) {
        echo json_encode(['status' => 'error', 'message' => 'invalid']);
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

$userStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
$userStmt->execute([':id' => $targetId]);
if (!$userStmt->fetch()) {
    if ($isAjax) {
        echo json_encode(['status' => 'error', 'message' => 'user_not_found']);
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

// Check current follow status
$check = $pdo->prepare("
  SELECT 1 FROM follows
  WHERE follower_id = :me AND following_id = :them
");
$check->execute([':me' => $userId, ':them' => $targetId]);
$isFollowing = (bool)$check->fetch();

if ($isFollowing) {
    // UNFOLLOW
    $del = $pdo->prepare("
      DELETE FROM follows
      WHERE follower_id = :me AND following_id = :them
    ");
    $del->execute([':me' => $userId, ':them' => $targetId]);
    $nowFollowing = false;
} else {
    // FOLLOW
    $ins = $pdo->prepare("
      INSERT INTO follows (follower_id, following_id, created_at)
      VALUES (:me, :them, NOW())
    ");
    $ins->execute([':me' => $userId, ':them' => $targetId]);
    $nowFollowing = true;

    create_notification(
        $pdo,
        $targetId,
        $userId,
        'follow',
        $userId,
        'started following you'
    );
}

// New followers count for that target
$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE following_id = :them");
$stmt->execute([':them' => $targetId]);
$followersCount = (int)$stmt->fetchColumn();

if (!$isAjax) {
    // normal form fallback
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
    exit;
}

// AJAX response
echo json_encode([
    'status'          => 'ok',
    'following'       => $nowFollowing,
    'followers_count' => $followersCount,
]);
