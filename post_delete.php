<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'member';
$isStaff = in_array($role, ['owner','admin','moderator'], true);

$postId = (int)($_POST['post_id'] ?? 0);
if ($postId <= 0) {
    header('Location: dashboard.php');
    exit;
}

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

$del = $pdo->prepare("DELETE FROM posts WHERE id = :id");
$del->execute([':id' => $postId]);

if (!empty($post['group_id'])) {
    header('Location: group_view.php?id=' . (int)$post['group_id']);
    exit;
}

header('Location: dashboard.php');
exit;
