<?php
// admin_users.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$currentRole = $_SESSION['role'] ?? 'member';
if ($currentRole !== 'owner' && $currentRole !== 'admin') {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// handle role change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['role'])) {
    $userId  = (int)$_POST['user_id'];
    $newRole = $_POST['role'];

    // only owner can set role=owner
    if ($newRole === 'owner' && $currentRole !== 'owner') {
        // ignore
    } else {
        $allowed = ['owner','admin','moderator','member'];
        if (in_array($newRole, $allowed, true)) {
            // don't allow changing your own role here for safety
            if ($userId !== ($_SESSION['user_id'] ?? 0)) {
                $stmt = $pdo->prepare("UPDATE users SET role = :r WHERE id = :id");
                $stmt->execute([':r'=>$newRole, ':id'=>$userId]);
            }
        }
    }
    header('Location: admin_users.php');
    exit;
}

$users = $pdo->query("SELECT id,username,full_name,email,role,created_at FROM users ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Users • Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-light py-4">
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Users & roles</h1>
    <a href="dashboard.php" class="btn btn-sm btn-outline-light">Back to dashboard</a>
  </div>

  <div class="table-responsive">
    <table class="table table-dark table-striped align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Username</th>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Change role</th>
          <th>Joined</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['full_name']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['role']) ?></td>
            <td>
              <?php if ((int)$u['id'] === ($_SESSION['user_id'] ?? 0)): ?>
                <span class="text-muted" style="font-size:.8rem;">(you)</span>
              <?php else: ?>
                <form method="POST" class="d-flex gap-1">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <select name="role" class="form-select form-select-sm">
                    <?php foreach (['owner','admin','moderator','member'] as $r): ?>
                      <?php if ($r === 'owner' && $currentRole !== 'owner') continue; ?>
                      <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>>
                        <?= $r ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-light" type="submit">Save</button>
                </form>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($u['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
