<?php
// admin_dashboard.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId   = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'];
$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? 'member';
$avatar   = $_SESSION['avatar'] ?? null;

if (!in_array($role, ['owner','admin'], true)) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}


$totalMembers   = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalPosts     = (int)$pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
$totalComments  = (int)$pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn();
$totalLikes     = (int)$pdo->query("SELECT COUNT(*) FROM post_likes")->fetchColumn();
$totalPinned    = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE is_pinned = 1")->fetchColumn();
$totalFollows   = (int)$pdo->query("SELECT COUNT(*) FROM follows")->fetchColumn();


$staffStmt = $pdo->query("
  SELECT id, username, full_name, avatar, role
  FROM users
  WHERE role IN ('owner','admin','moderator')
  ORDER BY FIELD(role,'owner','admin','moderator'), id
");
$staffUsers = $staffStmt->fetchAll();

$topStmt = $pdo->query("
  SELECT 
    u.id,
    u.username,
    u.full_name,
    u.avatar,
    u.role,
    COALESCE(p.post_count,0)     AS posts,
    COALESCE(c.comment_count,0)  AS comments,
    COALESCE(l.like_count,0)     AS likes,
    COALESCE(pin.pin_count,0)    AS pins,
    COALESCE(f.followers,0)      AS followers
  FROM users u
  LEFT JOIN (
    SELECT user_id, COUNT(*) AS post_count
    FROM posts GROUP BY user_id
  ) p ON p.user_id = u.id
  LEFT JOIN (
    SELECT user_id, COUNT(*) AS comment_count
    FROM comments GROUP BY user_id
  ) c ON c.user_id = u.id
  LEFT JOIN (
    SELECT user_id, COUNT(*) AS like_count
    FROM post_likes GROUP BY user_id
  ) l ON l.user_id = u.id
  LEFT JOIN (
    SELECT user_id, COUNT(*) AS pin_count
    FROM posts WHERE is_pinned = 1 GROUP BY user_id
  ) pin ON pin.user_id = u.id
  LEFT JOIN (
    SELECT following_id AS user_id, COUNT(*) AS followers
    FROM follows GROUP BY following_id
  ) f ON f.user_id = u.id
  ORDER BY (COALESCE(p.post_count,0)*2 + COALESCE(c.comment_count,0)*1.5 + COALESCE(l.like_count,0) + COALESCE(pin.pin_count,0)*3 + COALESCE(f.followers,0)*1.2) DESC
  LIMIT 50
");
$topUsers = $topStmt->fetchAll();

/* ====== POSTS + LIKERS (for inspector) ====== */
$postStmt = $pdo->query("
  SELECT p.id, p.title, p.body, p.is_pinned, p.created_at,
         u.username, u.full_name, u.avatar
  FROM posts p
  JOIN users u ON u.id = p.user_id
  ORDER BY p.created_at DESC
  LIMIT 50
");
$posts = $postStmt->fetchAll();

$postIds = array_column($posts, 'id');
$likersByPost = [];
$commentsCountByPost = [];

if (!empty($postIds)) {
    $in = implode(',', array_map('intval', $postIds));

    $likeStmt = $pdo->query("
      SELECT pl.post_id, u.id, u.username, u.full_name, u.avatar
      FROM post_likes pl
      JOIN users u ON u.id = pl.user_id
      WHERE pl.post_id IN ($in)
      ORDER BY pl.created_at DESC
    ");
    foreach ($likeStmt as $row) {
        $likersByPost[$row['post_id']][] = $row;
    }

    $cCountStmt = $pdo->query("
      SELECT post_id, COUNT(*) AS ccount
      FROM comments
      WHERE post_id IN ($in)
      GROUP BY post_id
    ");
    foreach ($cCountStmt as $row) {
        $commentsCountByPost[$row['post_id']] = (int)$row['ccount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Control • Cyber Glass Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    :root{
      --bg-body:#040410;
      --bg-panel:rgba(14,16,40,.98);
      --glass-border:rgba(255,255,255,.16);
      --glass-border-soft:rgba(255,255,255,.08);
      --neon-cyan:#0ff0fc;
      --neon-purple:#e400ff;
      --neon-green:#46ff91;
      --text-main:#f6f7ff;
      --text-soft:#dde0ff;
      --text-muted:#9da4d1;
      --danger:#ff5c7a;
    }
    body{
      margin:0;
      min-height:100vh;
      background:
        radial-gradient(circle at 0 0,#272768,transparent 60%),
        radial-gradient(circle at 100% 100%,#0f314c,transparent 60%),
        var(--bg-body);
      color:var(--text-main);
      font-family:system-ui,-apple-system,"Poppins",sans-serif;
    }
    .shell{min-height:100vh;display:flex;flex-direction:column;}
    .topbar{
      padding:.7rem 1rem;
      border-bottom:1px solid rgba(255,255,255,.1);
      background:linear-gradient(120deg,rgba(6,7,24,.98),rgba(10,13,32,.98));
      backdrop-filter:blur(18px);
      position:sticky;top:0;z-index:30;
    }
    .avatar-lg{
      width:48px;height:48px;border-radius:50%;object-fit:cover;
      border:2px solid var(--neon-cyan);
      box-shadow:0 0 18px rgba(15,240,252,.8);
    }
    .badge-role{
      border-radius:999px;
      border:1px solid rgba(255,255,255,.28);
      padding:.12rem .7rem;
      font-size:.68rem;
      letter-spacing:.14em;
      text-transform:uppercase;
      color:var(--text-soft);
      background:linear-gradient(120deg,rgba(15,240,252,.16),rgba(228,0,255,.1));
    }
    .btn-top{
      border-radius:999px;
      border:1px solid var(--glass-border-soft);
      font-size:.78rem;
      padding:.32rem .8rem;
      background:rgba(5,6,20,.95);
      color:var(--text-soft);
      display:inline-flex;align-items:center;gap:.35rem;
      transition:all .2s ease;
    }
    .btn-top:hover{
      border-color:var(--neon-cyan);
      color:var(--text-main);
      transform:translateY(-1px);
      box-shadow:0 0 16px rgba(15,240,252,.3);
    }
    .btn-top-danger{
      border-color:rgba(255,92,122,.6);
      color:var(--danger);
    }
    .btn-top-danger:hover{
      background:rgba(255,92,122,.2);
      color:#ffd6df;
    }
    .main{
      flex:1;
      padding:1rem .75rem 1.4rem;
      max-width:1180px;
      margin:0 auto;
      width:100%;
    }
    .card-glass{
      background:var(--bg-panel);
      border-radius:22px;
      border:1px solid var(--glass-border);
      box-shadow:0 26px 80px rgba(0,0,0,.95);
      padding:1rem 1.1rem;
      margin-bottom:1rem;
    }
    .section-label{
      font-size:.72rem;text-transform:uppercase;
      letter-spacing:.18em;color:var(--text-muted);
    }
    .metrics-grid{
      display:grid;gap:.7rem;
      grid-template-columns:repeat(3,minmax(0,1fr));
    }
    .metric-card{
      background:rgba(9,10,28,.96);
      border-radius:16px;
      border:1px solid var(--glass-border-soft);
      padding:.6rem .7rem;
    }
    .metric-label{
      font-size:.7rem;
      color:var(--text-muted);
      text-transform:uppercase;
      letter-spacing:.16em;
    }
    .metric-value{
      font-size:1.4rem;
      font-weight:600;
      color:var(--text-soft);
    }
    .metric-sub{
      font-size:.78rem;color:var(--text-muted);
    }
    .staff-chip{
      display:inline-flex;align-items:center;gap:.3rem;
      padding:.22rem .6rem;border-radius:999px;
      border:1px solid var(--glass-border-soft);
      background:rgba(9,10,28,.96);
      font-size:.75rem;margin:.08rem;
    }
    .staff-mini-avatar{
      width:22px;height:22px;border-radius:50%;
      object-fit:cover;border:1px solid rgba(15,240,252,.7);
    }
    .staff-chip-role{
      font-size:.68rem;text-transform:uppercase;
      letter-spacing:.14em;color:var(--text-muted);
    }
    .top-users-list{
      max-height:260px;
      overflow-y:auto;
      padding-right:.3rem;
    }
    .top-user-row{
      display:grid;
      grid-template-columns:minmax(0,1.8fr) repeat(4,minmax(0,.9fr)) minmax(0,.9fr);
      gap:.35rem;
      font-size:.78rem;
      align-items:center;
      padding:.25rem .2rem;
      border-radius:10px;
    }
    .top-user-row:nth-child(odd){background:rgba(255,255,255,.02);}
    .top-user-row:hover{background:rgba(255,255,255,.06);}
    .btn-soft{
      border-radius:999px;
      border:1px solid var(--glass-border-soft);
      padding:.18rem .6rem;
      font-size:.75rem;
      background:rgba(7,8,26,.98);
      color:var(--text-soft);
    }
    .btn-soft:hover{
      border-color:var(--neon-cyan);
      color:var(--text-main);
    }

    .posts-table{
      max-height:340px;
      overflow-y:auto;
    }
    .post-row{
      border-bottom:1px solid rgba(255,255,255,.06);
      padding:.5rem 0;
      font-size:.8rem;
    }
    .post-row-title{
      font-weight:500;color:var(--text-soft);
    }
    .badge-pill{
      border-radius:999px;
      padding:.15rem .5rem;
      border:1px solid var(--glass-border-soft);
      font-size:.7rem;
    }
    .badge-green{
      border-color:rgba(70,255,145,.6);
      color:var(--neon-green);
    }
    .liker-avatar{
      width:24px;height:24px;border-radius:50%;object-fit:cover;
      border:1px solid rgba(15,240,252,.7);
      margin:0 2px;
    }

    @media(max-width:992px){
      .metrics-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
    }
    @media(max-width:768px){
      .metrics-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
      .top-user-row{
        grid-template-columns:minmax(0,1.8fr) repeat(3,minmax(0,1fr));
      }
      .top-user-row > div:nth-child(5){display:none;}
      .top-user-row > div:nth-child(6){display:none;}
    }
  </style>
</head>
<body>
<div class="shell">

  <div class="topbar">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <img
          src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=111827&color=fff&rounded=true&size=128' ?>"
          class="avatar-lg" alt="avatar">
        <div>
          <div style="font-size:.95rem;font-weight:600;"><?= htmlspecialchars($fullName) ?></div>
          <div style="font-size:.78rem;color:var(--text-muted);">@<?= htmlspecialchars($username) ?></div>
          <div class="mt-1 badge-role"><?= htmlspecialchars($role) ?> • Admin Panel</div>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2 ms-auto">
        <a href="dashboard.php" class="btn-top">
          <i class="bi bi-globe2"></i><span>Today's World (Member View)</span>
        </a>
        <a href="admin_users.php" class="btn-top">
          <i class="bi bi-people-fill"></i><span>Users</span>
        </a>
        <a href="groups.php" class="btn-top">
  <i class="bi bi-people-fill"></i> Groups
</a>

        <a href="admin_moderation.php" class="btn-top">
  <i class="bi bi-flag-fill"></i> Reports
</a>

        <a href="reports.php" class="btn-top">
          <i class="bi bi-flag-fill"></i><span>Reports</span>
        </a>
        <form action="logout.php" method="POST" class="m-0 p-0">
          <button class="btn-top btn-top-danger" type="submit">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="main">

    <!-- METRICS -->
    <div class="card-glass mb-3">
      <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="section-label">Control room • <span style="color:var(--text-soft);">Numbers</span></div>
        <div style="font-size:.78rem;color:var(--text-muted);">
          Use this to see the health of your little “real world”.
        </div>
      </div>
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-label">Members</div>
          <div class="metric-value"><?= $totalMembers ?></div>
          <div class="metric-sub">Total accounts</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">Posts</div>
          <div class="metric-value"><?= $totalPosts ?></div>
          <div class="metric-sub">Drops in the feed</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">Comments</div>
          <div class="metric-value"><?= $totalComments ?></div>
          <div class="metric-sub">Replies & conversations</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">Likes</div>
          <div class="metric-value"><?= $totalLikes ?></div>
          <div class="metric-sub">Reactions on posts</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">Pinned posts</div>
          <div class="metric-value"><?= $totalPinned ?></div>
          <div class="metric-sub">Posts of the day</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">Follows</div>
          <div class="metric-value"><?= $totalFollows ?></div>
          <div class="metric-sub">Connections between members</div>
        </div>
      </div>
    </div>

    <div class="card-glass mb-3">
      <div class="row g-3">
        <div class="col-12 col-lg-5">
          <div class="section-label mb-2">Staff</div>
          <div class="mb-2" style="font-size:.78rem;color:var(--text-muted);">
            Owners, admins, moderators. Tap a face to open profile.
          </div>
          <div>
            <?php foreach ($staffUsers as $su): ?>
              <?php
                $sName = $su['full_name'] ?: $su['username'];
                $sAvatar = $su['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($sName) . '&background=111827&color=fff&rounded=true&size=64';
              ?>
              <a href="user_profile.php?user_id=<?= (int)$su['id'] ?>" class="text-decoration-none">
                <div class="staff-chip">
                  <img src="<?= htmlspecialchars($sAvatar) ?>" class="staff-mini-avatar" alt="">
                  <div>
                    <div><?= htmlspecialchars($sName) ?></div>
                    <div class="staff-chip-role"><?= htmlspecialchars($su['role']) ?></div>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="col-12 col-lg-7">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <div class="section-label">Top members</div>
            <div style="font-size:.75rem;color:var(--text-muted);">
              Ranked by posts, comments, likes, pins & followers.
            </div>
          </div>
          <div class="top-users-list">
            <div class="top-user-row" style="font-weight:600;color:var(--text-soft);font-size:.75rem;">
              <div>User</div><div class="text-center">Posts</div>
              <div class="text-center">Comments</div>
              <div class="text-center">Likes</div>
              <div class="text-center">Pins</div>
              <div class="text-center">Followers</div>
            </div>
            <?php foreach ($topUsers as $u): ?>
              <?php
                $tuName = $u['full_name'] ?: $u['username'];
                $tuAvatar = $u['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($tuName) . '&background=111827&color=fff&rounded=true&size=64';
              ?>
              <div class="top-user-row">
                <div class="d-flex align-items-center gap-2">
                  <img src="<?= htmlspecialchars($tuAvatar) ?>" alt="" style="width:22px;height:22px;border-radius:50%;object-fit:cover;border:1px solid rgba(15,240,252,.65);">
                  <div>
                    <a href="user_profile.php?user_id=<?= (int)$u['id'] ?>" class="text-decoration-none" style="color:var(--text-soft);">
                      <?= htmlspecialchars($tuName) ?>
                    </a>
                    <div style="font-size:.68rem;color:var(--text-muted);">
                      @<?= htmlspecialchars($u['username']) ?> • <?= htmlspecialchars($u['role']) ?>
                    </div>
                  </div>
                </div>
                <div class="text-center"><?= (int)$u['posts'] ?></div>
                <div class="text-center"><?= (int)$u['comments'] ?></div>
                <div class="text-center"><?= (int)$u['likes'] ?></div>
                <div class="text-center"><?= (int)$u['pins'] ?></div>
                <div class="text-center"><?= (int)$u['followers'] ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="card-glass">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="section-label">Posts inspector</div>
        <div style="font-size:.75rem;color:var(--text-muted);">
          See likes, comments and open user profiles. Last 50 posts.
        </div>
      </div>
      <div class="posts-table">
        <?php foreach ($posts as $p): ?>
          <?php
            $pid = (int)$p['id'];
            $authorName = $p['full_name'] ?: $p['username'];
            $shortBody = mb_strimwidth($p['body'], 0, 90, '…', 'UTF-8');
            $commentsCount = $commentsCountByPost[$pid] ?? 0;
            $likers = $likersByPost[$pid] ?? [];
          ?>
          <div class="post-row">
            <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
              <div style="min-width:0;flex:1;">
                <div class="post-row-title">
                  <?= htmlspecialchars($p['title'] ?: $shortBody ?: 'Untitled post') ?>
                </div>
                <div style="font-size:.74rem;color:var(--text-muted);">
                  by <a href="user_profile.php?user_id=<?= (int)$p['id'] ?>" class="text-decoration-none" style="color:var(--text-soft);">
                    <?= htmlspecialchars($authorName) ?>
                  </a>
                  • <?= htmlspecialchars(date('M j, H:i', strtotime($p['created_at']))) ?>
                  <?php if ($p['is_pinned']): ?>
                    <span class="badge-pill badge-green ms-1"><i class="bi bi-pin-angle-fill"></i> Pinned</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="d-flex flex-column align-items-end gap-1">
                <div style="font-size:.75rem;color:var(--text-muted);">
                  ❤️ <?= count($likers) ?> • 💬 <?= $commentsCount ?>
                </div>
                <div class="d-flex gap-1">
                  <button class="btn-soft btn-sm" type="button"
                          data-bs-toggle="collapse" data-bs-target="#likes<?= $pid ?>">
                    <i class="bi bi-people"></i> Likes
                  </button>
                  <a href="dashboard.php#post-<?= $pid ?>" class="btn-soft btn-sm">
                    <i class="bi bi-chat-left-text"></i> Comments
                  </a>
                </div>
              </div>
            </div>
            <div class="collapse mt-2" id="likes<?= $pid ?>">
              <?php if (empty($likers)): ?>
                <div style="font-size:.76rem;color:var(--text-muted);">No likes yet.</div>
              <?php else: ?>
                <div style="font-size:.76rem;color:var(--text-muted);">Liked by:</div>
                <div class="d-flex flex-wrap align-items-center mt-1">
                  <?php foreach ($likers as $lk): ?>
                    <?php
                      $ln = $lk['full_name'] ?: $lk['username'];
                      $lav = $lk['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($ln) . '&background=111827&color=fff&rounded=true&size=64';
                    ?>
                    <a href="user_profile.php?user_id=<?= (int)$lk['id'] ?>" class="text-decoration-none me-1 mb-1">
                      <img src="<?= htmlspecialchars($lav) ?>" class="liker-avatar" alt="">
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
