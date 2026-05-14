<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'member';
$isStaff = in_array($role, ['owner','admin','moderator'], true);

$postId = (int)($_POST['post_id'] ?? 0);
$body   = trim($_POST['body'] ?? '');

if ($postId <= 0 || $body === '') {
    header('Location: dashboard.php');
    exit;
}

// only post owner or staff can edit
$stmt = $pdo->prepare("SELECT user_id, group_id FROM posts WHERE id = :id");
$stmt->execute([':id' => $postId]);
$post = $stmt->fetch();
if (!$post) {
    header('Location: dashboard.php');
    exit;
}
if ($post['user_id'] != $userId && !$isStaff) {
    header('Location: dashboard.php');
    exit;
}

$u = $pdo->prepare("UPDATE posts SET body = :b WHERE id = :id");
$u->execute([':b' => $body, ':id' => $postId]);

if (!empty($post['group_id'])) {
    header('Location: group_view.php?id=' . (int)$post['group_id'] . '#post-' . $postId);
    exit;
}

header('Location: dashboard.php#post-' . $postId);
exit;
