<?php
// admin_moderation.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
require __DIR__ . '/audit_helper.php';

$meId   = $_SESSION['user_id'];
$myRole = $_SESSION['role'] ?? 'member';

if (!in_array($myRole, ['owner','admin','moderator'], true)) {
    http_response_code(403);
    echo "No access.";
    exit;
}

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    $action   = $_POST['action'] ?? '';

    if ($reportId && $action) {
        // Load report
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = :id");
        $stmt->execute([':id' => $reportId]);
        $rep = $stmt->fetch();

        if ($rep) {
            if ($action === 'dismiss') {
                $up = $pdo->prepare("
                  UPDATE reports
                  SET status = 'dismissed', handled_by = :admin, handled_at = NOW(),
                      action_taken = 'Dismissed'
                  WHERE id = :id
                ");
                $up->execute([':admin' => $meId, ':id' => $reportId]);

                audit_log($pdo, $meId, 'report_dismiss', "Report #{$reportId}");

            } elseif ($action === 'handle') {
                $up = $pdo->prepare("
                  UPDATE reports
                  SET status = 'handled', handled_by = :admin, handled_at = NOW(),
                      action_taken = 'Marked handled'
                  WHERE id = :id
                ");
                $up->execute([':admin' => $meId, ':id' => $reportId]);

                audit_log($pdo, $meId, 'report_handle', "Report #{$reportId}");

            } elseif ($action === 'delete_post' && $rep['target_type'] === 'post') {
                // delete post + mark report handled
                $pid = (int)$rep['target_id'];

                $pdo->prepare("DELETE FROM comments WHERE post_id = :pid")->execute([':pid' => $pid]);
                $pdo->prepare("DELETE FROM post_likes WHERE post_id = :pid")->execute([':pid' => $pid]);
                $pdo->prepare("DELETE FROM post_media WHERE post_id = :pid")->execute([':pid' => $pid]);
                $pdo->prepare("DELETE FROM posts WHERE id = :pid")->execute([':pid' => $pid]);

                $up = $pdo->prepare("
                  UPDATE reports
                  SET status = 'handled', handled_by = :admin, handled_at = NOW(),
                      action_taken = 'Post deleted'
                  WHERE id = :id
                ");
                $up->execute([':admin' => $meId, ':id' => $reportId]);

                audit_log($pdo, $meId, 'delete_post', "Report #{$reportId}, post #{$pid}");

            } elseif ($action === 'delete_comment' && $rep['target_type'] === 'comment') {
                $cid = (int)$rep['target_id'];

                $pdo->prepare("DELETE FROM comments WHERE id = :cid")->execute([':cid' => $cid]);

                $up = $pdo->prepare("
                  UPDATE reports
                  SET status = 'handled', handled_by = :admin, handled_at = NOW(),
                      action_taken = 'Comment deleted'
                  WHERE id = :id
                ");
                $up->execute([':admin' => $meId, ':id' => $reportId]);

                audit_log($pdo, $meId, 'delete_comment', "Report #{$reportId}, comment #{$cid}");

            } elseif ($action === 'ban_user') {
                $banUserId = (int)$_POST['ban_user_id'];
                $days      = (int)($_POST['ban_days'] ?? 0);
                $reason    = trim($_POST['ban_reason'] ?? 'Violation of rules');

                $expires = null;
                if ($days > 0) {
                    $expiresStmt = $pdo->prepare("SELECT DATE_ADD(NOW(), INTERVAL :d DAY)");
                    $expiresStmt->execute([':d' => $days]);
                    $expires = $expiresStmt->fetchColumn();
                }

                $banStmt = $pdo->prepare("
                  INSERT INTO user_bans (user_id, reason, created_at, expires_at, banned_by, active)
                  VALUES (:uid, :reason, NOW(), :exp, :admin, 1)
                ");
                $banStmt->execute([
                    ':uid'    => $banUserId,
                    ':reason' => $reason,
                    ':exp'    => $expires,
                    ':admin'  => $meId
                ]);

                // Also close this report
                $pdo->prepare("
                  UPDATE reports
                  SET status = 'handled', handled_by = :admin, handled_at = NOW(),
                      action_taken = CONCAT('User banned (', :days, ' days)')
                  WHERE id = :id
                ")->execute([
                    ':admin' => $meId,
                    ':days'  => $days,
                    ':id'    => $reportId
                ]);

                audit_log($pdo, $meId, 'ban_user', "Report #{$reportId}, user #{$banUserId}, days={$days}");
            }
        }
    }

    header('Location: admin_moderation.php');
    exit;
}

// load open & recent reports (rest of your file continues here)
$reports = $pdo->query("
  SELECT r.*,
         ru.username AS reporter_username,
         tu.username AS target_username
  FROM reports r
  JOIN users ru ON ru.id = r.reporter_id
  LEFT JOIN users tu ON tu.id = r.target_id
  ORDER BY
    FIELD(r.status, 'open','handled','dismissed'),
    r.created_at DESC
  LIMIT 50
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Moderation • Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
:root{
  --bg-body:#020215;
  --bg-panel:#07041e;
  --glass-border:rgba(255,255,255,.14);
  --glass-soft:rgba(255,255,255,.08);
  --neon-cyan:#0ff0fc;
  --neon-purple:#e400ff;
  --danger:#ff5c7a;
  --text-main:#ffffff;
  --text-soft:#f5f6ff;
  --text-muted:#a1a6de;
}
body{
  margin:0;
  min-height:100vh;
  background:
    radial-gradient(circle at 0 0,#2a2c6d,transparent 60%),
    radial-gradient(circle at 100% 100%,#062a4a,transparent 60%),
    var(--bg-body);
  color:var(--text-main);
  font-family:system-ui,-apple-system,"Poppins",sans-serif;
}
.shell{
  max-width:1024px;
  margin:0 auto;
  padding:1rem .75rem 1.4rem;
}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:.9rem;
}
.top-title{
  font-size:1.05rem;
  font-weight:600;
}
.chip{
  font-size:.73rem;
  text-transform:uppercase;
  letter-spacing:.16em;
  color:var(--text-muted);
}
.panel{
  background:rgba(5,3,23,.96);
  border-radius:24px;
  border:1px solid var(--glass-border);
  box-shadow:0 26px 70px rgba(0,0,0,.92);
  padding:1rem .95rem;
}
.report-card{
  border-radius:16px;
  border:1px solid var(--glass-soft);
  background:#050319;
  padding:.7rem .75rem;
  margin-bottom:.6rem;
}
.report-header{
  display:flex;
  justify-content:space-between;
  gap:.6rem;
}
.badge-status{
  font-size:.7rem;
  border-radius:999px;
  padding:.16rem .55rem;
}
.badge-open{
  background:rgba(15,240,252,.18);
  color:var(--text-soft);
  border:1px solid rgba(15,240,252,.6);
}
.badge-handled{
  background:rgba(46,204,113,.12);
  border:1px solid rgba(46,204,113,.6);
}
.badge-dismissed{
  background:rgba(149,165,166,.18);
  border:1px solid rgba(149,165,166,.6);
}
.report-meta{
  font-size:.78rem;
  color:var(--text-muted);
}
.report-reason{
  font-size:.85rem;
}
.btn-pill{
  border-radius:999px;
  border:1px solid var(--glass-soft);
  font-size:.75rem;
  padding:.25rem .7rem;
  color:var(--text-soft);
  background:transparent;
}
.btn-pill:hover{
  border-color:var(--neon-cyan);
  color:var(--text-main);
}
.btn-bad{
  border-color:rgba(255,92,122,.7);
  color:var(--danger);
}
.btn-bad:hover{
  background:rgba(255,92,122,.18);
}
.btn-good{
  border-color:rgba(46,204,113,.7);
}
.ban-form{
  display:flex;
  flex-wrap:wrap;
  gap:.4rem;
  margin-top:.4rem;
  font-size:.75rem;
}
.ban-form input, .ban-form select{
  background:#05041a;
  border-radius:999px;
  border:1px solid var(--glass-soft);
  color:var(--text-soft);
}
@media (max-width:768px){
  .panel{padding:.85rem .75rem;}
}
</style>
</head>
<body>
<div class="shell">
  <div class="topbar">
    <div>
      <div class="top-title">Moderation queue</div>
      <div class="chip">Reports • content &amp; users</div>
    </div>
    <div>
      <a href="admin_dashboard.php" class="btn-pill">
        <i class="bi bi-speedometer2"></i> Admin home
      </a>
      <a href="dashboard.php" class="btn-pill">
        <i class="bi bi-house-door"></i> Feed
      </a>
    </div>
  </div>

  <div class="panel">
    <?php if (empty($reports)): ?>
      <p style="font-size:.9rem;color:var(--text-muted);">
        No reports yet. If users flag posts or comments, they will show up here.
      </p>
    <?php else: ?>
      <?php foreach ($reports as $r): ?>
        <?php
          $status = $r['status'];
          $badgeClass = $status === 'open' ? 'badge-open' :
                        ($status === 'handled' ? 'badge-handled' : 'badge-dismissed');
          $statusLabel = ucfirst($status);
          $targetLabel = ucfirst($r['target_type']) . ' #' . (int)$r['target_id'];
        ?>
        <div class="report-card">
          <div class="report-header">
            <div>
              <div class="report-reason">
                <strong><?= htmlspecialchars($r['reason']) ?></strong>
                <span style="font-size:.75rem;color:var(--text-muted);">
                  (<?= htmlspecialchars($targetLabel) ?>)
                </span>
              </div>
              <?php if ($r['details']): ?>
                <div style="font-size:.8rem;color:var(--text-soft);margin-top:.15rem;">
                  <?= nl2br(htmlspecialchars($r['details'])) ?>
                </div>
              <?php endif; ?>
              <div class="report-meta mt-1">
                Reported by <strong>@<?= htmlspecialchars($r['reporter_username']) ?></strong>
                • <?= htmlspecialchars(date('M j, H:i', strtotime($r['created_at']))) ?>
                <?php if ($r['target_username']): ?>
                  • Target: <strong>@<?= htmlspecialchars($r['target_username']) ?></strong>
                <?php endif; ?>
              </div>
              <?php if ($r['status'] !== 'open' && $r['handled_by']): ?>
                <div class="report-meta">
                  Handled: <?= htmlspecialchars($r['action_taken'] ?? '') ?>
                </div>
              <?php endif; ?>
            </div>
            <div>
              <span class="badge-status <?= $badgeClass ?>"><?= $statusLabel ?></span>
            </div>
          </div>

          <?php if ($status === 'open'): ?>
            <div class="mt-2 d-flex flex-wrap gap-2">
              <!-- mark handled -->
              <form method="POST" class="m-0">
                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="handle">
                <button class="btn-pill btn-good" type="submit">
                  <i class="bi bi-check2-circle"></i> Mark handled
                </button>
              </form>

              <!-- dismiss -->
              <form method="POST" class="m-0">
                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="dismiss">
                <button class="btn-pill" type="submit">
                  <i class="bi bi-slash-circle"></i> Dismiss
                </button>
              </form>

              <?php if ($r['target_type'] === 'post'): ?>
                <form method="POST" class="m-0" onsubmit="return confirm('Delete this post and all its comments?');">
                  <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="delete_post">
                  <button class="btn-pill btn-bad" type="submit">
                    <i class="bi bi-trash"></i> Delete post
                  </button>
                </form>
              <?php elseif ($r['target_type'] === 'comment'): ?>
                <form method="POST" class="m-0" onsubmit="return confirm('Delete this comment?');">
                  <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="delete_comment">
                  <button class="btn-pill btn-bad" type="submit">
                    <i class="bi bi-trash"></i> Delete comment
                  </button>
                </form>
              <?php endif; ?>
            </div>

            <?php if ($r['target_type'] !== 'user' && $r['target_id']): ?>
              <!-- quick ban form -->
              <form method="POST" class="ban-form" onsubmit="return confirm('Ban this user?');">
                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="ban_user">
                <input type="hidden" name="ban_user_id" value="<?= (int)$r['target_id'] ?>">
                <div>
                  <select name="ban_days" class="form-select form-select-sm">
                    <option value="1">Ban 1 day</option>
                    <option value="3">Ban 3 days</option>
                    <option value="7">Ban 7 days</option>
                    <option value="30">Ban 30 days</option>
                    <option value="0">Ban permanent</option>
                  </select>
                </div>
                <div class="flex-grow-1">
                  <input type="text"
                         name="ban_reason"
                         class="form-control form-control-sm"
                         placeholder="Reason (visible to staff)">
                </div>
                <div>
                  <button class="btn-pill btn-bad" type="submit">
                    <i class="bi bi-shield-exclamation"></i> Ban user
                  </button>
                </div>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
