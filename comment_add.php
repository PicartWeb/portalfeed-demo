<?php
// comment_add.php
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
$body   = trim($_POST['body'] ?? '');

if ($postId <= 0 || $body === '') {
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
$ownerStmt->execute([
    ':id' => $postId,
    ':uid' => $userId,
]);
$postRow = $ownerStmt->fetch();
if (!$postRow) {
    header('Location: dashboard.php');
    exit;
}

if (!empty($postRow['group_id']) && empty($postRow['is_group_member'])) {
    redirect_for_post($postRow, $postId);
}

$postOwnerId = (int)$postRow['user_id'];

// insert comment
$stmt = $pdo->prepare("
  INSERT INTO comments (post_id, user_id, body)
  VALUES (:pid, :uid, :body)
");
$stmt->execute([
    ':pid'  => $postId,
    ':uid'  => $userId,
    ':body' => $body,
]);

$commentId = (int)$pdo->lastInsertId();

// notify post owner
create_notification(
    $pdo,
    $postOwnerId,
    $userId,
    'comment',
    $commentId,
    'commented on your post'
);

redirect_for_post($postRow, $postId);
