<?php
// user_search.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if ($q === '' || strlen($q) < 2) {
    echo json_encode(['users' => []]);
    exit;
}

$stmt = $pdo->prepare("
  SELECT id, username, full_name, avatar
  FROM users
  WHERE username LIKE :q OR full_name LIKE :q
  ORDER BY username
  LIMIT 10
");
$stmt->execute([':q' => "%$q%"]);
$rows = $stmt->fetchAll();

$users = [];
foreach ($rows as $r) {
    $name   = $r['full_name'] ?: $r['username'];
    $avatar = $r['avatar'] ?: 'https://ui-avatars.com/api/?name='
              . urlencode($name) . '&background=111827&color=fff&rounded=true&size=64';
    $users[] = [
        'id'        => (int)$r['id'],
        'username'  => $r['username'],
        'full_name' => $name,
        'avatar'    => $avatar,
    ];
}

echo json_encode(['users' => $users]);
