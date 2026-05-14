<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'member';
$isStaff = in_array($role, ['owner','admin','moderator'], true);

$commentId = (int)($_POST['comment_id'] ?? 0);
if ($commentId <= 0) {
    header('Location: dashboard.php');
    exit;
}

// fetch comment + post
$stmt = $pdo->prepare("
  SELECT c.user_id, c.post_id, p.group_id
  FROM comments c
  JOIN posts p ON p.id = c.post_id
  WHERE c.id = :id
");
$stmt->execute([':id' => $commentId]);
$comment = $stmt->fetch();

if (!$comment) {
    header('Location: dashboard.php');
    exit;
}

if ($comment['user_id'] != $userId && !$isStaff) {
    header('Location: dashboard.php');
    exit;
}

$del = $pdo->prepare("DELETE FROM comments WHERE id = :id");
$del->execute([':id' => $commentId]);

if (!empty($comment['group_id'])) {
    header('Location: group_view.php?id=' . (int)$comment['group_id'] . '#post-' . (int)$comment['post_id']);
    exit;
}

header('Location: dashboard.php#post-' . (int)$comment['post_id']);
exit;
