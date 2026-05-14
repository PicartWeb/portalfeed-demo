<?php
// post_create.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId = $_SESSION['user_id'];

// NEW: group_id (optional, for group posts)
$groupId = null;
if (isset($_POST['group_id']) && $_POST['group_id'] !== '' && ctype_digit((string)$_POST['group_id'])) {
    $groupId = (int)$_POST['group_id'];
}

$title    = trim($_POST['title'] ?? '');
$body     = trim($_POST['body'] ?? '');
$category = $_POST['category'] ?? 'post';

// keep filters/search when coming back to dashboard
$redirectFilter = $_POST['filter'] ?? 'all';
$redirectCat    = $_POST['cat'] ?? 'all';
$redirectQ      = trim($_POST['q'] ?? '');

$allowedCategories = ['post','highlight','project'];
if (!in_array($category, $allowedCategories, true)) {
    $category = 'post';
}

if ($body === '') {
    // if we came from a group, go back there even if empty
    if ($groupId) {
        header('Location: group_view.php?id=' . $groupId);
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

if ($groupId) {
    $groupStmt = $pdo->prepare("
      SELECT g.id,
             EXISTS(
               SELECT 1
               FROM group_members gm
               WHERE gm.group_id = g.id AND gm.user_id = :uid
             ) AS is_member
      FROM groups g
      WHERE g.id = :gid
      LIMIT 1
    ");
    $groupStmt->execute([
        ':gid' => $groupId,
        ':uid' => $userId,
    ]);
    $group = $groupStmt->fetch();

    if (!$group || empty($group['is_member'])) {
        header('Location: groups.php');
        exit;
    }
}

// 1) insert post (with optional group_id)
$stmt = $pdo->prepare("
    INSERT INTO posts (user_id, group_id, title, body, category)
    VALUES (:uid, :gid, :t, :b, :cat)
");
$stmt->execute([
    ':uid' => $userId,
    ':gid' => $groupId,            // NULL for normal feed, group id for groups
    ':t'   => $title ?: null,
    ':b'   => $body,
    ':cat' => $category,
]);

$postId = (int)$pdo->lastInsertId();

// 2) handle images (optional)
$uploadDirFs  = __DIR__ . '/uploads/posts/';
$uploadDirWeb = 'uploads/posts/';

if (!is_dir($uploadDirFs)) {
    @mkdir($uploadDirFs, 0777, true);
}

if (!empty($_FILES['images']['name'][0])) {
    $names  = $_FILES['images']['name'];
    $tmp    = $_FILES['images']['tmp_name'];
    $errors = $_FILES['images']['error'];
    $sizes  = $_FILES['images']['size'];

    $maxFiles = min(count($names), 4);

    for ($i = 0; $i < $maxFiles; $i++) {
        if ($errors[$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        if ($sizes[$i] <= 0 || $sizes[$i] > 5 * 1024 * 1024) {
            // >5MB skip
            continue;
        }

        $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
            continue;
        }

        $newName   = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $targetFs  = $uploadDirFs . $newName;
        $targetWeb = $uploadDirWeb . $newName;

        if (move_uploaded_file($tmp[$i], $targetFs)) {
            $m = $pdo->prepare("
                INSERT INTO post_media (post_id, path, type)
                VALUES (:pid, :path, 'image')
            ");
            $m->execute([
                ':pid'  => $postId,
                ':path' => $targetWeb,
            ]);
        }
    }
}

// 3) redirect back
if ($groupId) {
    // if post was created inside a group, go back to that group
    header('Location: group_view.php?id=' . $groupId);
    exit;
}

// otherwise, back to dashboard with filters
$q = http_build_query([
    'filter' => $redirectFilter,
    'cat'    => $redirectCat,
    'q'      => $redirectQ,
]);

header('Location: dashboard.php' . ($q ? '?' . $q : ''));
exit;
