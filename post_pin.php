<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$role = $_SESSION['role'] ?? 'member';
$userId = (int)($_SESSION['user_id'] ?? 0);
$isGlobalStaff = in_array($role, ['owner','admin'], true);

$postId = (int)($_POST['post_id'] ?? 0);
$pin    = ($_POST['pin'] ?? '0') === '1';

if ($postId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$postStmt = $pdo->prepare("SELECT id, group_id FROM posts WHERE id = :id LIMIT 1");
$postStmt->execute([':id' => $postId]);
$post = $postStmt->fetch();

if (!$post) {
    header('Location: dashboard.php');
    exit;
}

$groupId = isset($post['group_id']) ? (int)$post['group_id'] : 0;
$canPin = $isGlobalStaff;

if (!$canPin && $groupId > 0) {
    $memberStmt = $pdo->prepare("
      SELECT role
      FROM group_members
      WHERE group_id = :gid AND user_id = :uid
      LIMIT 1
    ");
    $memberStmt->execute([
        ':gid' => $groupId,
        ':uid' => $userId,
    ]);
    $membership = $memberStmt->fetch();
    $canPin = $membership && in_array($membership['role'], ['owner', 'moderator'], true);
}

if (!$canPin) {
    if ($groupId > 0) {
        header('Location: group_view.php?id=' . $groupId);
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

if ($pin) {
    if ($groupId > 0) {
        $pdo->prepare("UPDATE posts SET is_pinned = 0 WHERE group_id = :gid")
            ->execute([':gid' => $groupId]);
    } else {
        $pdo->exec("UPDATE posts SET is_pinned = 0 WHERE group_id IS NULL");
    }

    $stmt = $pdo->prepare("UPDATE posts SET is_pinned = 1 WHERE id = :id");
    $stmt->execute([':id' => $postId]);
} else {
    $stmt = $pdo->prepare("UPDATE posts SET is_pinned = 0 WHERE id = :id");
    $stmt->execute([':id' => $postId]);
}

if ($groupId > 0) {
    header('Location: group_view.php?id=' . $groupId . '#post-' . $postId);
    exit;
}

header('Location: dashboard.php#post-' . $postId);
exit;
