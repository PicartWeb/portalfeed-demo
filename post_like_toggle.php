<?php
// post_like_toggle.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/notify.php';

function redirect_for_post(array $postRow, int $postId): void
{
    $groupId = isset($postRow['group_id']) ? (int)$postRow['group_id'] : 0;
    if ($groupId > 0) {
        header('Location: group_view.php?id=' . $groupId . '#post-' . $postId);
        exit;
    }

    header('Location: dashboard.php#post-' . $postId);
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: index.php');
    exit;
}

$postId = (int)($_POST['post_id'] ?? 0);
if ($postId <= 0) {
    header('Location: dashboard.php');
    exit;
}

// get post owner + group context
$ownerStmt = $pdo->prepare("
  SELECT p.user_id, p.group_id,
         EXISTS(
           SELECT 1
           FROM group_members gm
           WHERE gm.group_id = p.group_id AND gm.user_id = :uid
         ) AS is_group_member
  FROM posts p
  WHERE p.id = :id
  LIMIT 1
");
$ownerStmt->execute([':id' => $postId, ':uid' => $userId]);
$postRow = $ownerStmt->fetch();
if (!$postRow) {
    header('Location: dashboard.php');
    exit;
}

if (!empty($postRow['group_id']) && empty($postRow['is_group_member'])) {
    redirect_for_post($postRow, $postId);
}

$postOwnerId = (int)$postRow['user_id'];

// check if like exists
$stmt = $pdo->prepare("SELECT id FROM post_likes WHERE post_id = :pid AND user_id = :uid");
$stmt->execute([':pid' => $postId, ':uid' => $userId]);
$like = $stmt->fetch();

if ($like) {
    // unlike
    $del = $pdo->prepare("DELETE FROM post_likes WHERE id = :id");
    $del->execute([':id' => $like['id']]);
} else {
    // like
    $ins = $pdo->prepare("INSERT INTO post_likes (post_id, user_id) VALUES (:pid, :uid)");
    $ins->execute([':pid' => $postId, ':uid' => $userId]);

    // notify post owner
    create_notification(
        $pdo,
        $postOwnerId,
        $userId,
        'like',
        $postId,
        'liked your post'
    );
}

redirect_for_post($postRow, $postId);
