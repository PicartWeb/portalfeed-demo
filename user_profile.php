<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

// Logged-in viewer
$viewerId   = (int)($_SESSION['user_id'] ?? 0);
$viewerRole = $_SESSION['role'] ?? 'member';

$profileId = isset($_GET['user_id']) && ctype_digit((string)$_GET['user_id'])
    ? (int)$_GET['user_id']
    : $viewerId;

if ($profileId <= 0) {
    echo "User not found.";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $profileId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "User not found.";
    exit;
}

// Load profile user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $profileId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "User not found.";
    exit;
}

$isOwner = ($viewerId === $profileId);
$isStaff = in_array($viewerRole, ['owner', 'admin', 'moderator'], true);

// Safe fields
$fullName = $user['full_name'] ?: $user['username'];
$username = $user['username'];
$email    = $user['email'];
$role     = $user['role'] ?? 'member';
$avatar   = $user['avatar'] ?? null;
$bio      = $user['bio'] ?? '';
$skills   = $user['skills'] ?? '';
$country  = $user['country'] ?? '';
$timezone = $user['timezone'] ?? '';

$link_tiktok    = $user['link_tiktok'] ?? '';
$link_instagram = $user['link_instagram'] ?? '';
$link_x         = $user['link_x'] ?? '';
$link_portfolio = $user['link_portfolio'] ?? '';

// Stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id = :uid");
$stmt->execute([':uid' => $profileId]);
$postsCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE user_id = :uid");
$stmt->execute([':uid' => $profileId]);
$commentsCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE following_id = :uid");
$stmt->execute([':uid' => $profileId]);
$followersCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM follows WHERE follower_id = :uid");
$stmt->execute([':uid' => $profileId]);
$followingCount = (int)$stmt->fetchColumn();

// Does viewer follow this user?
$isFollowing = false;
if (!$isOwner) {
    $check = $pdo->prepare("
      SELECT 1
      FROM follows
      WHERE follower_id = :me AND following_id = :them
      LIMIT 1
    ");
    $check->execute([
        ':me'   => $viewerId,
        ':them' => $profileId
    ]);
    $isFollowing = (bool)$check->fetch();
}

// Load this user's recent posts
$postStmt = $pdo->prepare("
  SELECT
    p.id,
    p.title,
    p.body,
    p.created_at,
    p.category,
    COALESCE(lc.like_count, 0) AS like_count,
    COALESCE(cc.comment_count, 0) AS comment_count
  FROM posts p
  LEFT JOIN (
    SELECT post_id, COUNT(*) AS like_count
    FROM post_likes
    GROUP BY post_id
  ) lc ON lc.post_id = p.id
  LEFT JOIN (
    SELECT post_id, COUNT(*) AS comment_count
    FROM comments
    GROUP BY post_id
  ) cc ON cc.post_id = p.id
  WHERE p.user_id = :uid
  ORDER BY p.created_at DESC
  LIMIT 20
");
$postStmt->execute([':uid' => $profileId]);
$posts = $postStmt->fetchAll(PDO::FETCH_ASSOC);

// Avatar fallback
$profileAvatar = $avatar ?: (
    'https://ui-avatars.com/api/?name=' .
    urlencode($fullName) .
    '&background=111827&color=fff&rounded=true&size=128'
);
?>
<?php require_once __DIR__ . '/includes/app_shell.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($fullName) ?> | Profile</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<link rel="stylesheet" href="assets/app-shell.css">

<style>
:root{
  --bg:#f4f7fb;
  --surface:#ffffff;
  --surface-soft:#f8fafc;
  --surface-alt:#eef4ff;
  --border:#e2e8f0;
  --border-strong:#cfd8e3;
  --text:#0f172a;
  --text-soft:#475569;
  --text-muted:#64748b;
  --accent:#2563eb;
  --accent-strong:#1d4ed8;
  --accent-soft:#dbeafe;
  --success:#16a34a;
  --danger:#dc2626;
  --shadow:0 22px 50px rgba(15,23,42,.08);
  --shadow-soft:0 12px 26px rgba(15,23,42,.06);
  --radius-xl:28px;
  --radius-lg:22px;
  --radius-md:18px;
  --radius-sm:14px;
}
*{box-sizing:border-box;}
body{
  margin:0;
  min-height:100vh;
  background:
    radial-gradient(circle at top left, rgba(37,99,235,.10), transparent 22%),
    radial-gradient(circle at bottom right, rgba(59,130,246,.08), transparent 22%),
    var(--bg);
  color:var(--text);
  font-family:"Plus Jakarta Sans","Segoe UI",sans-serif;
  -webkit-font-smoothing:antialiased;
}
a{color:inherit;text-decoration:none;}
.shell{
  min-height:100vh;
  padding:28px 22px 34px;
}
.page-wrap{
  max-width:1180px;
  margin:0 auto;
}
.topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  margin-bottom:22px;
  flex-wrap:wrap;
}
.topbar-copy h1{
  margin:0;
  font-size:2rem;
  font-weight:800;
  letter-spacing:-.03em;
}
.topbar-copy p{
  margin:8px 0 0;
  color:var(--text-soft);
  font-size:.98rem;
}
.topbar-actions{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}
.nav-button,
.action-button,
.follow-button{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  padding:12px 18px;
  border-radius:999px;
  border:1px solid var(--border);
  background:var(--surface);
  color:var(--text-soft);
  font-size:.9rem;
  font-weight:700;
  box-shadow:var(--shadow-soft);
  transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease, color .18s ease, background .18s ease;
}
.nav-button:hover,
.action-button:hover,
.follow-button:hover{
  transform:translateY(-1px);
  border-color:rgba(37,99,235,.28);
  color:var(--accent);
  box-shadow:0 16px 28px rgba(37,99,235,.10);
}
.action-button.primary{
  background:linear-gradient(135deg, #2563eb, #60a5fa);
  border-color:transparent;
  color:#fff;
}
.action-button.primary:hover{
  color:#fff;
  background:linear-gradient(135deg, #1d4ed8, #3b82f6);
}
.action-button.staff{
  color:var(--danger);
  border-color:rgba(220,38,38,.18);
  background:rgba(254,242,242,.88);
}
.follow-button.following{
  background:rgba(219,234,254,.88);
  border-color:rgba(37,99,235,.24);
  color:var(--accent);
}
.profile-hero{
  background:linear-gradient(180deg, rgba(255,255,255,.96), rgba(248,250,252,.98));
  border:1px solid rgba(226,232,240,.92);
  border-radius:var(--radius-xl);
  box-shadow:var(--shadow);
  overflow:hidden;
}
.hero-banner{
  min-height:170px;
  background:
    radial-gradient(circle at 10% 20%, rgba(96,165,250,.40), transparent 24%),
    radial-gradient(circle at 80% 20%, rgba(37,99,235,.18), transparent 22%),
    linear-gradient(135deg, #eff6ff, #f8fbff 55%, #ffffff);
  border-bottom:1px solid rgba(226,232,240,.9);
}
.hero-body{
  padding:0 28px 28px;
  margin-top:-54px;
}
.hero-head{
  display:flex;
  gap:22px;
  align-items:flex-end;
  flex-wrap:wrap;
}
.profile-avatar{
  width:132px;
  height:132px;
  border-radius:32px;
  object-fit:cover;
  border:6px solid rgba(255,255,255,.96);
  box-shadow:0 22px 40px rgba(15,23,42,.14);
  background:#dbeafe;
}
.profile-main{
  flex:1;
  min-width:260px;
  padding-top:16px;
}
.profile-title-row{
  display:flex;
  align-items:center;
  gap:12px;
  flex-wrap:wrap;
}
.profile-name{
  margin:0;
  font-size:2rem;
  font-weight:800;
  letter-spacing:-.03em;
}
.role-pill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:8px 14px;
  border-radius:999px;
  background:rgba(219,234,254,.72);
  color:var(--accent);
  border:1px solid rgba(37,99,235,.16);
  font-size:.78rem;
  font-weight:800;
  letter-spacing:.08em;
  text-transform:uppercase;
}
.profile-handle{
  margin:10px 0 0;
  font-size:1rem;
  font-weight:600;
  color:var(--text-soft);
}
.profile-meta{
  margin:10px 0 0;
  display:flex;
  align-items:center;
  gap:16px;
  flex-wrap:wrap;
  color:var(--text-muted);
  font-size:.9rem;
}
.profile-actions{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  margin-top:20px;
}
.stats-grid{
  display:grid;
  grid-template-columns:repeat(4, minmax(0, 1fr));
  gap:14px;
  margin-top:24px;
}
.stat-card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:20px;
  padding:18px 18px 16px;
  box-shadow:var(--shadow-soft);
}
.stat-label{
  font-size:.78rem;
  text-transform:uppercase;
  letter-spacing:.14em;
  color:var(--text-muted);
  font-weight:800;
}
.stat-number{
  margin-top:12px;
  font-size:1.8rem;
  font-weight:800;
  letter-spacing:-.03em;
}
.content-grid{
  display:grid;
  grid-template-columns:320px minmax(0, 1fr);
  gap:22px;
  margin-top:24px;
}
.side-stack,
.main-stack{
  display:flex;
  flex-direction:column;
  gap:18px;
}
.section-card{
  background:rgba(255,255,255,.96);
  border:1px solid rgba(226,232,240,.94);
  border-radius:24px;
  box-shadow:var(--shadow-soft);
  padding:22px;
}
.section-kicker{
  margin:0 0 10px;
  color:var(--text-muted);
  font-size:.76rem;
  font-weight:800;
  letter-spacing:.16em;
  text-transform:uppercase;
}
.section-title{
  margin:0 0 14px;
  font-size:1.2rem;
  font-weight:800;
  letter-spacing:-.02em;
}
.bio-text{
  margin:0;
  color:var(--text-soft);
  line-height:1.75;
  font-size:.95rem;
  white-space:pre-wrap;
}
.meta-list{
  display:flex;
  flex-direction:column;
  gap:12px;
  margin-top:16px;
}
.meta-item{
  display:flex;
  align-items:flex-start;
  gap:12px;
  color:var(--text-soft);
  font-size:.9rem;
}
.meta-icon{
  width:36px;
  height:36px;
  border-radius:12px;
  display:grid;
  place-items:center;
  background:var(--surface-alt);
  color:var(--accent);
  flex:0 0 auto;
}
.tags-wrap{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
}
.tag-pill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 14px;
  border-radius:999px;
  background:var(--surface-alt);
  border:1px solid rgba(37,99,235,.10);
  color:var(--accent);
  font-size:.86rem;
  font-weight:700;
}
.links-list{
  display:flex;
  flex-direction:column;
  gap:10px;
}
.link-card{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:14px 16px;
  border-radius:18px;
  background:var(--surface-soft);
  border:1px solid var(--border);
  color:var(--text-soft);
  font-weight:600;
}
.link-card:hover{
  border-color:rgba(37,99,235,.22);
  color:var(--accent);
}
.link-left{
  display:flex;
  align-items:center;
  gap:12px;
  min-width:0;
}
.link-left span:last-child{
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.activity-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:14px;
  flex-wrap:wrap;
}
.activity-note{
  color:var(--text-muted);
  font-size:.9rem;
}
.posts-list{
  display:flex;
  flex-direction:column;
  gap:14px;
}
.post-card{
  border:1px solid var(--border);
  background:linear-gradient(180deg, #ffffff, #fbfdff);
  border-radius:22px;
  padding:20px;
  box-shadow:var(--shadow-soft);
}
.post-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:14px;
  margin-bottom:10px;
}
.post-title{
  margin:0;
  font-size:1.02rem;
  font-weight:800;
}
.post-category{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:7px 12px;
  border-radius:999px;
  background:var(--surface-alt);
  border:1px solid rgba(37,99,235,.10);
  color:var(--accent);
  font-size:.74rem;
  font-weight:800;
  letter-spacing:.12em;
  text-transform:uppercase;
}
.post-body{
  margin:0;
  font-size:.93rem;
  line-height:1.75;
  color:var(--text-soft);
}
.post-meta{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:14px;
  flex-wrap:wrap;
  margin-top:16px;
  color:var(--text-muted);
  font-size:.86rem;
}
.post-stats{
  display:flex;
  align-items:center;
  gap:16px;
}
.empty-state{
  border:1px dashed var(--border-strong);
  border-radius:20px;
  padding:24px 18px;
  background:rgba(248,250,252,.9);
  color:var(--text-soft);
  font-size:.94rem;
  line-height:1.7;
}
@media (max-width: 991.98px){
  .content-grid{
    grid-template-columns:1fr;
  }
  .stats-grid{
    grid-template-columns:repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 767.98px){
  .shell{
    padding:16px 12px 22px;
  }
  .topbar-copy h1{
    font-size:1.65rem;
  }
  .hero-body{
    padding:0 16px 18px;
    margin-top:-44px;
  }
  .hero-head{
    gap:16px;
    align-items:flex-start;
  }
  .profile-avatar{
    width:104px;
    height:104px;
    border-radius:26px;
  }
  .profile-name{
    font-size:1.55rem;
  }
  .stats-grid{
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:12px;
  }
  .stat-card,
  .section-card,
  .post-card{
    padding:16px;
  }
  .profile-actions,
  .topbar-actions{
    width:100%;
  }
  .nav-button,
  .action-button,
  .follow-button{
    flex:1 1 auto;
  }
  .post-head,
  .activity-head{
    flex-direction:column;
    align-items:flex-start;
  }
}
</style>
</head>

<?php
render_app_shell_start([
    'active' => 'profile',
    'context_label' => 'Profile',
    'context_title' => $fullName,
    'context_subtitle' => 'Identity, activity, and social proof in one creator-style profile.'
]);
?>

<div class="shell">
  <div class="page-wrap">
    <div class="topbar">
      <div class="topbar-copy">
        <h1>Profile overview</h1>
        <p>See public identity, recent activity, social proof, and profile links.</p>
      </div>

      <div class="topbar-actions">
        <a href="dashboard.php" class="nav-button">
          <i class="bi bi-arrow-left"></i>
          <span>Back to feed</span>
        </a>

        <?php if ($isOwner): ?>
          <a href="settings.php" class="action-button primary">
            <i class="bi bi-pencil-square"></i>
            <span>Edit profile</span>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <section class="profile-hero">
      <div class="hero-banner"></div>
      <div class="hero-body">
        <div class="hero-head">
          <img src="<?= htmlspecialchars($profileAvatar) ?>" alt="avatar" class="profile-avatar">

          <div class="profile-main">
            <div class="profile-title-row">
              <h2 class="profile-name"><?= htmlspecialchars($fullName) ?></h2>
              <span class="role-pill">
                <i class="bi bi-patch-check-fill"></i>
                <?= htmlspecialchars($role) ?>
              </span>
            </div>

            <p class="profile-handle">@<?= htmlspecialchars($username) ?></p>

            <div class="profile-meta">
              <span><i class="bi bi-envelope"></i> <?= htmlspecialchars($email) ?></span>
              <?php if ($country): ?>
                <span><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($country) ?></span>
              <?php endif; ?>
              <?php if ($timezone): ?>
                <span><i class="bi bi-clock-history"></i> <?= htmlspecialchars($timezone) ?></span>
              <?php endif; ?>
            </div>

            <div class="profile-actions">
              <?php if (!$isOwner): ?>
                <a href="messages.php?user_id=<?= $profileId ?>" class="action-button primary">
                  <i class="bi bi-chat-dots"></i>
                  <span>Message</span>
                </a>

                <button
                  class="follow-button follow-toggle <?= $isFollowing ? 'following' : '' ?>"
                  type="button"
                  data-user-id="<?= $profileId ?>"
                  data-following="<?= $isFollowing ? '1' : '0' ?>">
                  <i class="bi <?= $isFollowing ? 'bi-person-check-fill' : 'bi-person-plus' ?>"></i>
                  <?= $isFollowing ? 'Following' : 'Follow' ?>
                </button>
              <?php endif; ?>

              <?php if ($isStaff && !$isOwner): ?>
                <a href="admin_users.php?user_id=<?= $profileId ?>" class="action-button staff">
                  <i class="bi bi-shield-lock"></i>
                  <span>Staff controls</span>
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="stats-grid">
          <div class="stat-card">
            <div class="stat-label">Posts</div>
            <div class="stat-number"><?= $postsCount ?></div>
          </div>

          <div class="stat-card">
            <div class="stat-label">Comments</div>
            <div class="stat-number"><?= $commentsCount ?></div>
          </div>

          <div class="stat-card">
            <div class="stat-label">Followers</div>
            <div class="stat-number" data-followers-count-for="<?= $profileId ?>"><?= $followersCount ?></div>
          </div>

          <div class="stat-card">
            <div class="stat-label">Following</div>
            <div class="stat-number"><?= $followingCount ?></div>
          </div>
        </div>
      </div>
    </section>

    <div class="content-grid">
      <aside class="side-stack">
        <section class="section-card">
          <p class="section-kicker">About</p>
          <h3 class="section-title">Bio and identity</h3>
          <p class="bio-text"><?= $bio ? nl2br(htmlspecialchars($bio)) : 'No bio yet.' ?></p>

          <div class="meta-list">
            <div class="meta-item">
              <div class="meta-icon"><i class="bi bi-person-badge"></i></div>
              <div>
                <strong style="display:block;color:var(--text);font-size:.92rem;">Account role</strong>
                <span><?= htmlspecialchars($role) ?></span>
              </div>
            </div>

            <div class="meta-item">
              <div class="meta-icon"><i class="bi bi-at"></i></div>
              <div>
                <strong style="display:block;color:var(--text);font-size:.92rem;">Username</strong>
                <span>@<?= htmlspecialchars($username) ?></span>
              </div>
            </div>
          </div>
        </section>

        <section class="section-card">
          <p class="section-kicker">Skills</p>
          <h3 class="section-title">What they do</h3>

          <?php if ($skills): ?>
            <div class="tags-wrap">
              <?php
                $tags = array_filter(array_map('trim', explode(',', $skills)));
                foreach ($tags as $tag):
              ?>
                <span class="tag-pill"><i class="bi bi-lightning-charge"></i><?= htmlspecialchars($tag) ?></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="empty-state">No skills listed yet.</div>
          <?php endif; ?>
        </section>

        <section class="section-card">
          <p class="section-kicker">Links</p>
          <h3 class="section-title">Social and portfolio</h3>

          <?php if ($link_tiktok || $link_instagram || $link_x || $link_portfolio): ?>
            <div class="links-list">
              <?php if ($link_tiktok): ?>
                <a href="<?= htmlspecialchars($link_tiktok) ?>" target="_blank" class="link-card">
                  <span class="link-left"><i class="bi bi-tiktok"></i><span>TikTok</span></span>
                  <i class="bi bi-arrow-up-right"></i>
                </a>
              <?php endif; ?>

              <?php if ($link_instagram): ?>
                <a href="<?= htmlspecialchars($link_instagram) ?>" target="_blank" class="link-card">
                  <span class="link-left"><i class="bi bi-instagram"></i><span>Instagram</span></span>
                  <i class="bi bi-arrow-up-right"></i>
                </a>
              <?php endif; ?>

              <?php if ($link_x): ?>
                <a href="<?= htmlspecialchars($link_x) ?>" target="_blank" class="link-card">
                  <span class="link-left"><i class="bi bi-twitter-x"></i><span>X</span></span>
                  <i class="bi bi-arrow-up-right"></i>
                </a>
              <?php endif; ?>

              <?php if ($link_portfolio): ?>
                <a href="<?= htmlspecialchars($link_portfolio) ?>" target="_blank" class="link-card">
                  <span class="link-left"><i class="bi bi-globe2"></i><span>Portfolio</span></span>
                  <i class="bi bi-arrow-up-right"></i>
                </a>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="empty-state">No social links yet.</div>
          <?php endif; ?>
        </section>
      </aside>

      <section class="main-stack">
        <section class="section-card">
          <div class="activity-head">
            <div>
              <p class="section-kicker">Activity</p>
              <h3 class="section-title mb-0">Recent posts</h3>
            </div>
            <div class="activity-note">Latest updates, thoughts, and profile activity.</div>
          </div>

          <div class="posts-list">
            <?php if (empty($posts)): ?>
              <div class="empty-state">Nothing posted yet.</div>
            <?php else: ?>
              <?php foreach ($posts as $p): ?>
                <?php
                  $title = $p['title'] ?: ucfirst($p['category']) . ' post';
                  $bodyPreview = mb_substr($p['body'], 0, 220);
                  if (mb_strlen($p['body']) > 220) {
                      $bodyPreview .= '...';
                  }
                  $catLabel = ucfirst($p['category'] ?? 'post');
                ?>
                <article class="post-card">
                  <div class="post-head">
                    <div>
                      <h4 class="post-title"><?= htmlspecialchars($title) ?></h4>
                    </div>
                    <span class="post-category"><?= htmlspecialchars($catLabel) ?></span>
                  </div>

                  <p class="post-body"><?= htmlspecialchars($bodyPreview) ?></p>

                  <div class="post-meta">
                    <span><i class="bi bi-calendar3"></i> <?= htmlspecialchars(date('M j, Y \a\t H:i', strtotime($p['created_at']))) ?></span>
                    <span class="post-stats">
                      <span><i class="bi bi-chat-left-text"></i> <?= (int)$p['comment_count'] ?></span>
                      <span><i class="bi bi-heart"></i> <?= (int)$p['like_count'] ?></span>
                    </span>
                  </div>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      </section>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.follow-toggle').forEach(btn => {
  btn.addEventListener('click', async () => {
    const userId = btn.dataset.userId;
    if (!userId) return;

    const formData = new FormData();
    formData.append('user_id', userId);
    formData.append('ajax', '1');

    btn.disabled = true;

    try {
      const res = await fetch('follow_toggle.php', {
        method: 'POST',
        body: formData
      });

      const data = await res.json();

      if (data.status === 'ok') {
        if (data.following) {
          btn.classList.add('following');
          btn.dataset.following = '1';
          btn.innerHTML = '<i class="bi bi-person-check-fill"></i> Following';
        } else {
          btn.classList.remove('following');
          btn.dataset.following = '0';
          btn.innerHTML = '<i class="bi bi-person-plus"></i> Follow';
        }

        const counter = document.querySelector('[data-followers-count-for="<?= $profileId ?>"]');
        if (counter && typeof data.followers_count !== 'undefined') {
          counter.textContent = data.followers_count;
        }
      }
    } catch (e) {
      console.error(e);
    } finally {
      btn.disabled = false;
    }
  });
});
</script>

<?php render_app_shell_end(['active' => 'profile']); ?>
</body>
</html>