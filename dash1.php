<?php
// dashboard.php — full responsive rebuild

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId   = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'];
$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? 'member';
$avatar   = $_SESSION['avatar'] ?? null;

$isStaff        = in_array($role, ['owner','admin','moderator'], true);
$isAdminOrOwner = in_array($role, ['owner','admin'], true);

/* ------------ Notifications ------------ */
$notifUnreadCountStmt = $pdo->prepare("
  SELECT COUNT(*) FROM notifications
  WHERE to_user_id = :uid AND is_read = 0
");
$notifUnreadCountStmt->execute([':uid'=>$userId]);
$notifUnreadCount = (int)$notifUnreadCountStmt->fetchColumn();

$dmUnreadStmt = $pdo->prepare("
  SELECT COUNT(*) FROM notifications
  WHERE to_user_id = :uid AND type='message' AND is_read=0
");
$dmUnreadStmt->execute([':uid'=>$userId]);
$dmUnreadCount = (int)$dmUnreadStmt->fetchColumn();

$notifStmt = $pdo->prepare("
  SELECT n.*, u.username, u.full_name, u.avatar
  FROM notifications n
  JOIN users u ON u.id = n.from_user_id
  WHERE n.to_user_id = :uid
  ORDER BY n.created_at DESC
  LIMIT 15
");
$notifStmt->execute([':uid'=>$userId]);
$notifications = $notifStmt->fetchAll();

/* ------------ Filters & search ------------ */
$filter = $_GET['filter'] ?? 'all';
$cat    = $_GET['cat'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$allowedFilters = ['all','following','staff','mine'];
$allowedCats    = ['all','post','highlight','project'];

if (!in_array($filter, $allowedFilters, true)) $filter = 'all';
if (!in_array($cat, $allowedCats, true)) $cat = 'all';

$searchMode = 'none';
$searchTerm = '';

if ($search !== '') {
    if ($search[0] === '@') {
        $searchMode = 'user';
        $searchTerm = substr($search,1);
    } elseif ($search[0] === '#') {
        $searchMode = 'tag';
        $searchTerm = substr($search,1);
    } else {
        $searchMode = 'text';
        $searchTerm = $search;
    }
}

$searchHint = '';
if ($searchMode === 'user' && $searchTerm) {
    $searchHint = 'Filtering by user @'.$searchTerm;
} elseif ($searchMode === 'tag' && $searchTerm) {
    $searchHint = 'Filtering by hashtag #'.$searchTerm;
} elseif ($searchMode === 'text' && $searchTerm) {
    $searchHint = 'Searching: “'.$searchTerm.'”';
}

/* ------------ Feed query ------------ */
$whereParts = [];
$params = [];

if ($cat !== 'all') {
  $whereParts[] = 'p.category = :cat';
  $params[':cat'] = $cat;
}

if ($filter === 'following') {
  $whereParts[] = 'p.user_id IN (SELECT following_id FROM follows WHERE follower_id=:me_f)';
  $params[':me_f'] = $userId;
} elseif ($filter === 'staff') {
  $whereParts[] = "u.role IN ('owner','admin','moderator')";
} elseif ($filter === 'mine') {
  $whereParts[] = 'p.user_id=:me_m';
  $params[':me_m'] = $userId;
}

if ($searchMode === 'text' && $searchTerm) {
  $whereParts[] = '(p.title LIKE :q OR p.body LIKE :q)';
  $params[':q'] = '%'.$searchTerm.'%';
} elseif ($searchMode === 'tag' && $searchTerm) {
  $whereParts[] = '(p.title LIKE :tq OR p.body LIKE :tq)';
  $params[':tq'] = '%#'.$searchTerm.'%';
} elseif ($searchMode === 'user' && $searchTerm) {
  $whereParts[] = '(u.username LIKE :uq OR u.full_name LIKE :uq)';
  $params[':uq'] = '%'.$searchTerm.'%';
}

$whereSQL = $whereParts ? 'WHERE '.implode(' AND ',$whereParts) : '';

$postSql = "
  SELECT p.id,p.title,p.body,p.is_pinned,p.user_id,p.created_at,p.category,
         u.username,u.full_name,u.avatar,u.role AS user_role,
         COALESCE(lc.like_count,0) AS like_count
  FROM posts p
  JOIN users u ON p.user_id = u.id
  LEFT JOIN (
    SELECT post_id,COUNT(*) AS like_count
    FROM post_likes GROUP BY post_id
  ) lc ON lc.post_id=p.id
  $whereSQL
  ORDER BY p.is_pinned DESC, p.created_at DESC
";
$stmt = $pdo->prepare($postSql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

$postIds = array_column($posts,'id');
$inList  = $postIds ? implode(',',array_map('intval',$postIds)) : '0';

/* ------------ Comments ------------ */
$commentsByPost = [];
if ($postIds) {
  $cStmt = $pdo->query("
    SELECT c.*,u.username,u.full_name,u.avatar
    FROM comments c
    JOIN users u ON u.id=c.user_id
    WHERE c.post_id IN ($inList)
    ORDER BY c.created_at ASC
  ");
  foreach ($cStmt as $c) {
    $commentsByPost[$c['post_id']][] = $c;
  }
}

/* ------------ Likes of current user ------------ */
$likedPosts = [];
if ($postIds) {
  $likeStmt = $pdo->prepare("
    SELECT post_id FROM post_likes
    WHERE user_id=:uid AND post_id IN ($inList)
  ");
  $likeStmt->execute([':uid'=>$userId]);
  foreach ($likeStmt as $lp) {
    $likedPosts[(int)$lp['post_id']] = true;
  }
}

/* ------------ Media ------------ */
$mediaByPost = [];
if ($postIds) {
  $mStmt = $pdo->query("
    SELECT id,post_id,path,type
    FROM post_media
    WHERE post_id IN ($inList)
    ORDER BY id ASC
  ");
  foreach ($mStmt as $m) {
    $mediaByPost[$m['post_id']][] = $m;
  }
}

/* ------------ Following map & counts ------------ */
$followingMap = [];
$fs = $pdo->prepare("SELECT following_id FROM follows WHERE follower_id=:me");
$fs->execute([':me'=>$userId]);
foreach ($fs as $f) {
  $followingMap[(int)$f['following_id']] = true;
}

$followersCount = (int)$pdo->query("
  SELECT COUNT(*) FROM follows WHERE following_id=".(int)$userId
)->fetchColumn();
$followingCount = (int)$pdo->query("
  SELECT COUNT(*) FROM follows WHERE follower_id=".(int)$userId
)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard • Cyber Glass Portal</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{
  --bg:#020617;
  --bg-soft:#030712;
  --panel:#020617;
  --panel-alt:#050818;
  --border:rgba(148,163,184,.55);
  --border-soft:rgba(148,163,184,.28);
  --text:#e5e7eb;
  --text-soft:#cbd5f5;
  --text-muted:#9ca3af;
  --accent:#22d3ee;
  --accent-strong:#06b6d4;
  --accent-purple:#a855f7;
  --danger:#f97373;
}

*{box-sizing:border-box;}

body{
  margin:0;
  background:var(--bg);
  color:var(--text);
  font-family:system-ui,-apple-system,"Segoe UI",sans-serif;
  -webkit-font-smoothing:antialiased;
}

.app-shell{
  min-height:100vh;
  display:flex;
  flex-direction:column;
}

/* --------- HEADER --------- */
.dash-top{
  position:sticky;top:0;z-index:60;
  padding:.55rem .85rem;
  border-bottom:1px solid var(--border-soft);
  background:rgba(15,23,42,.96);
  backdrop-filter:blur(16px);
}
.avatar-lg{
  width:42px;height:42px;border-radius:50%;object-fit:cover;
  border:2px solid var(--accent-strong);
}
.badge-role{
  display:inline-flex;align-items:center;gap:.25rem;
  font-size:.65rem;
  padding:.12rem .7rem;
  border-radius:999px;
  border:1px solid var(--border-soft);
  text-transform:uppercase;
  letter-spacing:.14em;
  color:#bfdbfe;
  background:linear-gradient(120deg,rgba(34,211,238,.18),rgba(168,85,247,.18));
}
.btn-top{
  border-radius:999px;
  border:1px solid var(--border-soft);
  background:#020617;
  color:var(--text-soft);
  font-size:.8rem;
  padding:.35rem .9rem;
  display:inline-flex;
  align-items:center;
  gap:.3rem;
  text-decoration:none;
}
.btn-top:hover{
  border-color:var(--accent-strong);
  color:var(--text);
}

/* header search */
.header-search-desktop{max-width:420px;width:100%;}
.header-search-wrap{position:relative;}
.header-search-wrap i{
  position:absolute;left:.75rem;top:50%;transform:translateY(-50%);
  color:var(--text-muted);font-size:.9rem;
}
.header-search-input{
  width:100%;
  border-radius:999px;
  border:1px solid var(--border-soft);
  background:#020617;
  color:var(--text-soft);
  font-size:.8rem;
  padding:.35rem .85rem .35rem 2.1rem;
}
.header-search-input:focus{
  outline:none;
  border-color:var(--accent-strong);
  box-shadow:0 0 0 .09rem rgba(34,211,238,.4);
}

/* mobile search bar (hidden until icon tapped) */
.mobile-search-bar{
  display:none;
  padding:.4rem .7rem .5rem;
  border-bottom:1px solid var(--border-soft);
  background:#020617;
}
.mobile-search-inner{
  position:relative;
}
.mobile-search-inner i{
  position:absolute;left:.75rem;top:50%;transform:translateY(-50%);
  color:var(--text-muted);font-size:.9rem;
}
.mobile-search-input{
  width:100%;
  border-radius:999px;
  border:1px solid var(--border-soft);
  background:#020617;
  color:var(--text-soft);
  font-size:.85rem;
  padding:.42rem .85rem .42rem 2.1rem;
}

/* username suggestions */
.user-suggest-box{
  position:absolute;
  top:100%;left:0;right:0;
  margin-top:.25rem;
  background:#020617;
  border-radius:14px;
  border:1px solid var(--border-soft);
  box-shadow:0 18px 40px rgba(0,0,0,.85);
  max-height:260px;
  overflow-y:auto;
  z-index:80;
  display:none;
}
.user-suggest-item{
  display:flex;align-items:center;gap:.5rem;
  padding:.35rem .8rem;
  font-size:.8rem;
  cursor:pointer;
}
.user-suggest-item:hover{background:#020617;}
.user-suggest-item img{
  width:26px;height:26px;border-radius:50%;object-fit:cover;
  border:1px solid var(--accent);
}
.user-suggest-username{font-weight:600;}
.user-suggest-muted{color:var(--text-muted);font-size:.74rem;}

/* --------- MAIN GRID --------- */
.layout-grid{
  flex:1;
  width:100%;
  max-width:1180px;
  margin:0 auto;
  padding:1rem 1rem 4.5rem;
  display:grid;
  grid-template-columns:260px minmax(0,1.6fr) 300px;
  gap:1rem;
}
@media(max-width:992px){
  .layout-grid{
    max-width:100%;
    margin:0;
    padding:.8rem .55rem 4.5rem;
    display:block;   /* no extra grid tracks on mobile */
  }
}

.card-glass{
  background:var(--panel-alt);
  border-radius:18px;
  border:1px solid var(--border-soft);
  padding:1rem;
  box-shadow:0 16px 40px rgba(0,0,0,.75);
}
.text-label{
  font-size:.72rem;
  text-transform:uppercase;
  letter-spacing:.16em;
  color:#9ca3af;
}

/* sticky cols on desktop */
.left-column,.right-column{
  position:sticky;
  top:78px;
  height:min-content;
}
@media(max-width:992px){
  .left-column,.right-column{position:static;}
}

/* --------- Stories --------- */
.stories-strip{
  display:flex;
  gap:.7rem;
  overflow-x:auto;
  padding-bottom:.25rem;
}
.story-item{width:70px;flex:0 0 auto;text-align:center;}
.story-ring{
  border-radius:999px;
  padding:3px;
  background:linear-gradient(135deg,var(--accent-strong),var(--accent-purple));
}
.story-inner{
  border-radius:999px;
  background:#020617;
  padding:2px;
}
.story-inner img{
  width:60px;height:60px;border-radius:999px;object-fit:cover;
}
.story-item .small{color:#cbd5f5;}

/* --------- Filters --------- */
.filter-pill{
  border-radius:999px;
  border:1px solid var(--border-soft);
  background:#020617;
  color:var(--text-soft);
  font-size:.78rem;
  padding:.27rem .9rem;
  text-decoration:none;
}
.filter-pill.active{
  background:var(--accent-strong);
  border-color:var(--accent-strong);
  color:#0b1120;
}

/* --------- Composer --------- */
.compose-trigger{display:flex;align-items:center;gap:.7rem;}
.compose-input-fake{
  flex:1;
  border-radius:999px;
  border:1px solid var(--border-soft);
  background:#020617;
  color:var(--text-muted);
  padding:.45rem 1rem;
  font-size:.85rem;
}

/* --------- Posts --------- */
.post-card{
  background:var(--panel-alt);
  border-radius:18px;
  border:1px solid var(--border-soft);
  padding:.9rem 1rem .75rem;
  margin-bottom:.9rem;
}
.post-header-meta{
  font-size:.8rem;
  color:#c7d2fe;   /* bright on dark mobile */
}
.post-badge{
  border-radius:999px;
  border:1px solid var(--border-soft);
  padding:.08rem .55rem;
  font-size:.7rem;
  color:#e0e7ff;
}
.post-title{
  font-weight:600;
  font-size:1rem;
}
.post-body-text{
  font-size:.9rem;
  color:var(--text-soft);
  white-space:pre-wrap;
}
.post-media img{
  max-width:100%;
  max-height:260px;
  border-radius:12px;
  object-fit:cover;
  border:1px solid var(--border-soft);
}

/* actions */
.post-actions{
  border-top:1px solid rgba(148,163,184,.5);
  margin-top:.4rem;
  padding-top:.3rem;
  display:flex;
  justify-content:space-around;
}
.post-action-btn{
  border:none;
  background:none;
  color:var(--text-soft);
  font-size:.8rem;
  display:inline-flex;
  align-items:center;
  gap:.3rem;
}
.post-action-btn.liked{
  color:var(--accent-strong);
}

/* comments */
.comment-row{
  display:flex;
  gap:.4rem;
  margin-top:.4rem;
}
.comment-bubble{
  flex:1;
  background:#020617;
  border-radius:12px;
  border:1px solid var(--border-soft);
  padding:.3rem .6rem;
  font-size:.8rem;
}
.comment-meta{
  font-size:.76rem;
  color:#c7d2fe;
}

/* comment form */
.comment-form textarea{
  background:#020617;
  border-radius:999px;
  border:1px solid var(--border-soft);
  color:var(--text-soft);
  font-size:.8rem;
  resize:none;
}

/* bottom nav (mobile) */
.bottom-nav{
  position:fixed;
  left:0;right:0;bottom:0;
  height:60px;
  background:#020617;
  border-top:1px solid var(--border-soft);
  display:none;
  z-index:70;
}
.bottom-nav a{
  flex:1;
  text-align:center;
  font-size:.75rem;
  color:var(--text-soft);
  text-decoration:none;
  padding-top:.15rem;
}
.bottom-nav i{display:block;font-size:1.2rem;}
.bottom-nav a.active{color:var(--accent-strong);}
@media(max-width:992px){
  .bottom-nav{display:flex;}
}

/* FAB */
.fab-post{
  position:fixed;
  right:1rem;
  bottom:4.2rem;
  width:54px;height:54px;
  border-radius:999px;
  border:none;
  background:linear-gradient(135deg,var(--accent-purple),var(--accent-strong));
  color:#020617;
  font-size:1.4rem;
  display:none;
  align-items:center;
  justify-content:center;
  box-shadow:0 16px 38px rgba(0,0,0,.95);
  z-index:70;
}
@media(max-width:992px){
  .fab-post{display:flex;}
}

/* modal */
.modal-content{
  background:#020617;
  border-radius:20px;
  border:1px solid var(--border-soft);
}

/* small tweaks */
@media(max-width:576px){
  .dash-top{padding:.45rem .55rem;}
  .card-glass{padding:.8rem .8rem;}
}
</style>
</head>
<body>
<div class="app-shell">
  <!-- ================= HEADER ================= -->
  <header class="dash-top">
    <div class="d-flex align-items-center flex-wrap gap-2">

      <!-- user summary -->
      <div class="d-flex align-items-center gap-2 me-1">
        <img
          src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name='.urlencode($fullName).'&background=020617&color=fff' ?>"
          alt="avatar" class="avatar-lg">
        <div>
          <div style="font-size:.95rem;font-weight:600;"><?= htmlspecialchars($fullName) ?></div>
          <div style="font-size:.8rem;color:var(--text-muted);">@<?= htmlspecialchars($username) ?></div>
        </div>
      </div>

      <!-- desktop search -->
      <div class="flex-grow-1 header-search-desktop d-none d-md-block mx-2">
        <form method="GET" autocomplete="off">
          <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
          <input type="hidden" name="cat"    value="<?= htmlspecialchars($cat) ?>">
          <div class="header-search-wrap">
            <i class="bi bi-search"></i>
            <input
              type="search"
              name="q"
              class="header-search-input global-search"
              value="<?= htmlspecialchars($search) ?>"
              placeholder="Search posts, people, or #tags">
            <div class="user-suggest-box" data-suggest-for="desktop"></div>
          </div>
        </form>
      </div>

      <!-- right header actions -->
      <div class="d-flex align-items-center gap-2 ms-auto">

        <!-- mobile search icon -->
        <button class="btn-top d-md-none" id="mobileSearchToggle" type="button">
          <i class="bi bi-search"></i>
        </button>

        <a href="index.php" class="btn-top d-none d-md-inline-flex">
          <i class="bi bi-house-door-fill"></i><span class="d-none d-lg-inline">Portal</span>
        </a>

        <a href="groups.php" class="btn-top d-none d-md-inline-flex">
          <i class="bi bi-people-fill"></i><span class="d-none d-lg-inline">Groups</span>
        </a>

        <?php if ($isAdminOrOwner): ?>
          <a href="admin_dashboard.php" class="btn-top d-none d-md-inline-flex">
            <i class="bi bi-cpu-fill"></i><span class="d-none d-lg-inline">Admin</span>
          </a>
        <?php endif; ?>

        <a href="messages.php" class="btn-top position-relative">
          <i class="bi bi-chat-dots-fill"></i>
          <?php if ($dmUnreadCount > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                  style="font-size:.62rem;box-shadow:0 0 12px rgba(239,68,68,.9);">
              <?= $dmUnreadCount > 9 ? '9+' : $dmUnreadCount ?>
            </span>
          <?php endif; ?>
        </a>

        <!-- notifications -->
        <div class="dropdown">
          <button class="btn-top position-relative" type="button" data-bs-toggle="dropdown">
            <i class="bi <?= $notifUnreadCount ? 'bi-bell-fill' : 'bi-bell' ?>"></i>
            <?php if ($notifUnreadCount): ?>
              <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger rounded-circle"
                    style="box-shadow:0 0 12px rgba(239,68,68,.9);"></span>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end" style="min-width:260px;background:#020617;border:1px solid var(--border-soft);border-radius:16px;">
            <div class="px-3 py-2 d-flex justify-content-between align-items-center">
              <span class="text-label">Notifications</span>
              <form action="notifications_mark_read.php" method="POST">
                <input type="hidden" name="redirect" value="dashboard.php">
                <button class="btn btn-link p-0" style="font-size:.72rem;color:var(--text-muted);">Mark all</button>
              </form>
            </div>
            <div style="max-height:260px;overflow-y:auto;">
              <?php if (empty($notifications)): ?>
                <div class="px-3 pb-3 small text-muted">Nothing yet. Likes, comments, follows and DMs will appear here.</div>
              <?php else: ?>
                <?php foreach ($notifications as $n): ?>
                  <?php
                    $nName   = $n['full_name'] ?: $n['username'];
                    $nAvatar = $n['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($nName).'&background=020617&color=fff';
                    $isUnread = !$n['is_read'];
                    $targetUrl = 'dashboard.php';
                    if ($n['type']==='follow')   $targetUrl='user_profile.php?user_id='.(int)$n['from_user_id'];
                    if ($n['type']==='message')  $targetUrl='messages.php?conversation_id='.(int)$n['ref_id'];
                  ?>
                  <a href="<?= htmlspecialchars($targetUrl) ?>"
                     class="dropdown-item d-flex align-items-start gap-2 <?= $isUnread?'':'opacity-75' ?>">
                    <img src="<?= htmlspecialchars($nAvatar) ?>" alt=""
                         style="width:26px;height:26px;border-radius:50%;object-fit:cover;border:1px solid var(--accent);">
                    <div>
                      <div style="font-size:.8rem;color:#e5e7eb;">
                        <strong><?= htmlspecialchars($nName) ?></strong> <?= htmlspecialchars($n['message']) ?>
                      </div>
                      <div style="font-size:.72rem;color:#c7d2fe;">
                        <?= htmlspecialchars(date('M j, H:i', strtotime($n['created_at']))) ?>
                      </div>
                    </div>
                    <?php if ($isUnread): ?>
                      <span style="width:6px;height:6px;border-radius:50%;background:var(--accent);margin-left:auto;"></span>
                    <?php endif; ?>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <a href="settings.php" class="btn-top d-none d-md-inline-flex">
          <i class="bi bi-person-circle"></i>
        </a>

        <form action="logout.php" method="POST" class="m-0">
          <button type="submit" class="btn-top" style="border-color:rgba(248,113,113,.6);color:#fecaca;">
            <i class="bi bi-box-arrow-right"></i>
          </button>
        </form>

      </div>
    </div>
  </header>

  <!-- mobile search bar -->
  <div class="mobile-search-bar d-md-none" id="mobileSearchBar">
    <form method="GET" autocomplete="off">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <input type="hidden" name="cat"    value="<?= htmlspecialchars($cat) ?>">
      <div class="mobile-search-inner">
        <i class="bi bi-search"></i>
        <input
          type="search"
          name="q"
          class="mobile-search-input global-search"
          value="<?= htmlspecialchars($search) ?>"
          placeholder="Search people, posts, #tags">
        <div class="user-suggest-box" data-suggest-for="mobile"></div>
      </div>
    </form>
  </div>

  <!-- ================= MAIN GRID ================= -->
  <main class="layout-grid">

    <!-- LEFT column (desktop) -->
    <aside class="left-column d-none d-lg-block">
      <div class="card-glass mb-3">
        <div class="d-flex align-items-center gap-2">
          <img
            src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name='.urlencode($fullName).'&background=020617&color=fff' ?>"
            style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:2px solid var(--accent-strong);">
          <div>
            <div style="font-size:1rem;font-weight:600;"><?= htmlspecialchars($fullName) ?></div>
            <div style="font-size:.82rem;color:var(--text-muted);">@<?= htmlspecialchars($username) ?></div>
          </div>
        </div>
        <div class="d-flex justify-content-between text-center mt-3 small">
          <div><strong><?= count($posts) ?></strong><br><span class="text-muted">Posts</span></div>
          <div><strong><?= $followersCount ?></strong><br><span class="text-muted">Followers</span></div>
          <div><strong><?= $followingCount ?></strong><br><span class="text-muted">Following</span></div>
        </div>
        <div class="mt-3 d-grid gap-2">
          <button class="btn btn-sm btn-info text-dark" data-bs-toggle="modal" data-bs-target="#composeModal">
            <i class="bi bi-pencil-square me-1"></i>Create post
          </button>
          <a href="create_gig.php" class="btn btn-sm btn-outline-info">
            <i class="bi bi-lightning-fill me-1"></i>Create gig
          </a>
        </div>
        <div class="mt-3 d-grid gap-1 small">
          <a href="groups.php" class="text-decoration-none text-soft"><i class="bi bi-people-fill me-1"></i>My groups</a>
          <a href="messages.php" class="text-decoration-none text-soft"><i class="bi bi-chat-dots-fill me-1"></i>Messages</a>
          <a href="settings.php" class="text-decoration-none text-soft"><i class="bi bi-gear-fill me-1"></i>Settings</a>
        </div>
        <div class="mt-4">
          <div class="small text-muted mb-1">Profile 60% complete</div>
          <div class="progress" style="height:6px;border-radius:999px;background:#020617;">
            <div class="progress-bar bg-info" style="width:60%;border-radius:999px;"></div>
          </div>
        </div>
      </div>
    </aside>

    <!-- CENTER column -->
    <section class="center-column">

      <!-- stories -->
      <div class="card-glass mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-label">Stories / Highlights</span>
        </div>
        <div class="stories-strip">
          <div class="story-item">
            <div class="story-ring">
              <div class="story-inner">
                <img src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name='.urlencode($fullName).'&background=020617&color=fff' ?>">
              </div>
            </div>
            <div class="small mt-1">You</div>
          </div>
          <?php for($i=1;$i<=7;$i++): ?>
          <div class="story-item">
            <div class="story-ring">
              <div class="story-inner">
                <img src="https://i.pravatar.cc/80?img=<?= $i ?>">
              </div>
            </div>
            <div class="small mt-1">User <?= $i ?></div>
          </div>
          <?php endfor; ?>
        </div>
      </div>

      <!-- filters -->
      <div class="card-glass mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="text-label">Feed filters</span>
          <?php if ($searchHint): ?>
            <span class="small" style="color:#c7d2fe;"><?= htmlspecialchars($searchHint) ?></span>
          <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <?php
          $filters = ['all'=>'All','following'=>'Following','staff'=>'Staff','mine'=>'My posts'];
          foreach($filters as $key=>$label):
          ?>
            <a href="dashboard.php?filter=<?= $key ?>&cat=<?= $cat ?>&q=<?= urlencode($search) ?>"
               class="filter-pill <?= $filter===$key?'active':'' ?>">
              <?= htmlspecialchars($label) ?>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <?php
          $cats = ['all'=>'All types','post'=>'Posts','highlight'=>'Highlights','project'=>'Projects'];
          foreach($cats as $key=>$label):
          ?>
            <a href="dashboard.php?filter=<?= $filter ?>&cat=<?= $key ?>&q=<?= urlencode($search) ?>"
               class="filter-pill <?= $cat===$key?'active':'' ?>">
              <?= htmlspecialchars($label) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- composer -->
      <div class="card-glass mb-3" data-bs-toggle="modal" data-bs-target="#composeModal" style="cursor:pointer;">
        <div class="compose-trigger">
          <img
            src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name='.urlencode($fullName).'&background=020617&color=fff' ?>"
            style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);">
          <div class="compose-input-fake">What’s on your mind?</div>
        </div>
      </div>

      <!-- FEED -->
      <div id="feedArea">
      <?php if (empty($posts)): ?>
        <div class="card-glass text-center">
          <p class="text-muted m-0">No posts yet. Be the first to share something.</p>
        </div>
      <?php else: ?>
        <?php foreach($posts as $post): ?>
          <?php
            $postId       = (int)$post['id'];
            $authorName   = $post['full_name'] ?: $post['username'];
            $authorAvatar = $post['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($authorName).'&background=020617&color=fff';
            $isOwnerPost  = ($post['user_id'] == $userId);
            $postComments = $commentsByPost[$postId] ?? [];
            $likeCount    = (int)$post['like_count'];
            $userLiked    = isset($likedPosts[$postId]);
            $authorFollowed = isset($followingMap[(int)$post['user_id']]);
          ?>
          <article class="post-card" id="post-<?= $postId ?>">

            <!-- header -->
            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
              <div class="d-flex gap-2">
                <a href="user_profile.php?user_id=<?= (int)$post['user_id'] ?>" class="text-decoration-none">
                  <img src="<?= htmlspecialchars($authorAvatar) ?>" alt=""
                       style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid var(--accent-strong);">
                </a>
                <div>
                  <a href="user_profile.php?user_id=<?= (int)$post['user_id'] ?>"
                     class="text-decoration-none text-light fw-semibold">
                    <?= htmlspecialchars($authorName) ?>
                  </a>
                  <div class="post-header-meta">
                    <span>@<?= htmlspecialchars($post['username']) ?></span>
                    <span>• <?= date('M j, H:i', strtotime($post['created_at'])) ?></span>
                    <?php if ($post['is_pinned']): ?>
                      <span>• <i class="bi bi-pin-angle-fill"></i> pinned</span>
                    <?php endif; ?>
                    <span class="post-badge ms-1"><?= ucfirst($post['category'] ?? 'post') ?></span>
                  </div>
                </div>
              </div>

              <div class="d-flex align-items-start gap-1">
                <?php if (!$isOwnerPost): ?>
                  <button
                    class="btn btn-sm <?= $authorFollowed ? 'btn-info text-dark':'btn-outline-info' ?> follow-toggle"
                    data-user-id="<?= (int)$post['user_id'] ?>"
                    data-following="<?= $authorFollowed ? '1':'0' ?>">
                    <i class="bi <?= $authorFollowed ? 'bi-person-check-fill':'bi-person-plus' ?>"></i>
                  </button>
                <?php endif; ?>

                <!-- menu -->
                <div class="dropdown">
                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-three-dots"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end">

                    <?php if ($isOwnerPost || $isStaff): ?>
                      <li>
                        <button class="dropdown-item" data-bs-toggle="collapse" data-bs-target="#editPost<?= $postId ?>">
                          <i class="bi bi-pencil-square me-1"></i>Edit post
                        </button>
                      </li>
                      <li>
                        <form action="post_delete.php" method="POST" onsubmit="return confirm('Delete this post?');">
                          <input type="hidden" name="post_id" value="<?= $postId ?>">
                          <button class="dropdown-item text-danger">
                            <i class="bi bi-trash3 me-1"></i>Delete
                          </button>
                        </form>
                      </li>
                      <?php if ($isStaff): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                          <form action="post_pin.php" method="POST">
                            <input type="hidden" name="post_id" value="<?= $postId ?>">
                            <input type="hidden" name="pin" value="<?= $post['is_pinned'] ? '0':'1' ?>">
                            <button class="dropdown-item">
                              <i class="bi bi-pin-angle-fill me-1"></i>
                              <?= $post['is_pinned'] ? 'Unpin post':'Pin to top' ?>
                            </button>
                          </form>
                        </li>
                      <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!$isOwnerPost): ?>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <form action="report_create.php" method="POST">
                          <input type="hidden" name="target_type" value="post">
                          <input type="hidden" name="target_id" value="<?= $postId ?>">
                          <input type="hidden" name="reason" value="Inappropriate content">
                          <button class="dropdown-item text-warning">
                            <i class="bi bi-flag"></i> Report post
                          </button>
                        </form>
                      </li>
                    <?php endif; ?>

                  </ul>
                </div>
              </div>
            </div>

            <!-- body -->
            <div class="mt-1">
              <?php if (!empty($post['title'])): ?>
                <div class="post-title mb-1"><?= htmlspecialchars($post['title']) ?></div>
              <?php endif; ?>
              <div class="post-body-text">
                <?= nl2br(htmlspecialchars($post['body'])) ?>
              </div>

              <?php if (!empty($mediaByPost[$postId])): ?>
                <div class="post-media mt-2 d-flex flex-wrap gap-2">
                  <?php foreach($mediaByPost[$postId] as $m): ?>
                    <img src="<?= htmlspecialchars($m['path']) ?>" alt="media">
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- edit -->
            <?php if ($isOwnerPost || $isStaff): ?>
              <div class="collapse mt-2" id="editPost<?= $postId ?>">
                <form action="post_update.php" method="POST">
                  <input type="hidden" name="post_id" value="<?= $postId ?>">
                  <textarea name="body" rows="3" class="form-control mb-2"
                            style="background:#020617;color:var(--text-soft);border:1px solid var(--border-soft);">
<?= htmlspecialchars($post['body']) ?></textarea>
                  <button class="btn btn-sm btn-info text-dark"><i class="bi bi-check2-circle me-1"></i>Save</button>
                </form>
              </div>
            <?php endif; ?>

            <!-- actions -->
            <div class="post-actions">
              <form action="post_like_toggle.php" method="POST">
                <input type="hidden" name="post_id" value="<?= $postId ?>">
                <button class="post-action-btn <?= $userLiked?'liked':'' ?>" type="submit">
                  <i class="bi <?= $userLiked?'bi-heart-fill':'bi-heart' ?>"></i>
                  <span><?= $likeCount ?></span>
                </button>
              </form>

              <button class="post-action-btn" type="button">
                <i class="bi bi-chat-left-text"></i>
                <span><?= count($postComments) ?></span>
              </button>

              <button class="post-action-btn share-btn" type="button" data-post-id="<?= $postId ?>">
                <i class="bi bi-share-fill"></i><span>Share</span>
              </button>
            </div>

            <!-- comments -->
            <div class="mt-2">
              <?php foreach($postComments as $c): ?>
                <?php
                  $cName = $c['full_name'] ?: $c['username'];
                  $cAv   = $c['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($cName).'&background=020617&color=fff';
                  $canDeleteComment = ($c['user_id']==$userId) || $isStaff;
                ?>
                <div class="comment-row">
                  <img src="<?= htmlspecialchars($cAv) ?>" alt=""
                       style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1px solid var(--accent);">
                  <div class="comment-bubble">
                    <div class="comment-meta">
                      <?= htmlspecialchars($cName) ?> • <?= date('M j, H:i', strtotime($c['created_at'])) ?>
                    </div>
                    <div><?= nl2br(htmlspecialchars($c['body'])) ?></div>
                  </div>
                  <?php if ($canDeleteComment): ?>
                    <form action="comment_delete.php" method="POST" onsubmit="return confirm('Delete this comment?');">
                      <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                      <button class="btn btn-sm text-danger" type="submit"><i class="bi bi-trash"></i></button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>

            <!-- add comment -->
            <form action="comment_add.php" method="POST" class="mt-2">
              <input type="hidden" name="post_id" value="<?= $postId ?>">
              <div class="d-flex gap-2">
                <textarea name="body" rows="1" class="form-control comment-form" placeholder="Write a comment..." required></textarea>
                <button type="submit" class="btn btn-info btn-sm text-dark">
                  <i class="bi bi-arrow-right-short"></i>
                </button>
              </div>
            </form>

          </article>
        <?php endforeach; ?>
      <?php endif; ?>

      </div> <!-- /feedArea -->
    </section> <!-- /center-column -->

    <!-- RIGHT column -->
    <aside class="right-column d-none d-lg-block">

      <!-- people to follow -->
      <div class="card-glass mb-3">
        <div class="text-label mb-2">People to follow</div>
        <?php
        $s = $pdo->prepare("
          SELECT id,full_name,username,avatar
          FROM users
          WHERE id != :me
          ORDER BY RAND()
          LIMIT 5
        ");
        $s->execute([':me'=>$userId]);
        $suggestedUsers = $s->fetchAll();
        ?>
        <?php foreach($suggestedUsers as $u): ?>
          <?php $alreadyFollow = isset($followingMap[(int)$u['id']]); ?>
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="d-flex align-items-center gap-2">
              <img src="<?= $u['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($u['full_name']).'&background=020617&color=fff' ?>"
                   style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
              <div class="small">
                <div class="fw-semibold"><?= htmlspecialchars($u['full_name']) ?></div>
                <div class="text-muted">@<?= htmlspecialchars($u['username']) ?></div>
              </div>
            </div>
            <button class="btn btn-sm <?= $alreadyFollow?'btn-info text-dark':'btn-outline-info' ?> follow-toggle"
                    data-user-id="<?= (int)$u['id'] ?>"
                    data-following="<?= $alreadyFollow?'1':'0' ?>">
              <i class="bi <?= $alreadyFollow?'bi-person-check-fill':'bi-person-plus' ?>"></i>
            </button>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- trending tags -->
      <div class="card-glass mb-3">
        <div class="text-label mb-2">Trending tags</div>
        <?php
        $tags = $pdo->query("
          SELECT SUBSTRING_INDEX(SUBSTRING(body, LOCATE('#', body)), ' ', 1) AS tag
          FROM posts
          WHERE body LIKE '%#%'
          ORDER BY RAND()
          LIMIT 10
        ")->fetchAll();
        ?>
        <div class="d-flex flex-wrap gap-2 small">
          <?php foreach($tags as $tg):
            $tag = ltrim($tg['tag'],'#');
            if(!$tag) continue;
          ?>
            <a href="dashboard.php?filter=all&cat=all&q=%23<?= urlencode($tag) ?>"
               class="badge px-2 py-1"
               style="background:#020617;border:1px solid var(--border-soft);color:#7dd3fc;">
              #<?= htmlspecialchars($tag) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- featured gigs -->
      <div class="card-glass mb-3">
        <div class="text-label mb-2">Featured gigs</div>
        <?php
        try{
          $gStmt=$pdo->query("
            SELECT g.id,g.title,g.price_from,g.rating,u.full_name,u.avatar
            FROM gigs g
            JOIN users u ON u.id=g.user_id
            ORDER BY RAND()
            LIMIT 3
          ");
          $gigs=$gStmt->fetchAll();
        }catch(Exception $e){ $gigs=[]; }
        ?>
        <?php foreach($gigs as $gig): ?>
          <div class="mb-3 small">
            <div class="d-flex gap-2">
              <img src="<?= $gig['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($gig['full_name']).'&background=020617&color=fff' ?>"
                   style="width:34px;height:34px;border-radius:50%;object-fit:cover;">
              <div>
                <div class="fw-semibold"><?= htmlspecialchars($gig['title']) ?></div>
                <div class="text-muted"><?= htmlspecialchars($gig['full_name']) ?></div>
                <div class="mt-1">
                  ⭐ <?= number_format((float)$gig['rating'],1) ?> • from $<?= (int)$gig['price_from'] ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <a href="gigs.php" class="btn btn-sm btn-outline-info w-100">View all gigs</a>
      </div>

      <!-- groups -->
      <div class="card-glass">
        <div class="text-label mb-2">Groups you may like</div>
        <?php
        try{
          $grpStmt=$pdo->query("
            SELECT g.id, g.name,
                   (
                     SELECT COUNT(*)
                     FROM group_members gm
                     WHERE gm.group_id = g.id
                   ) AS members_count
            FROM groups g
            ORDER BY RAND()
            LIMIT 4
          ");
          $groups=$grpStmt->fetchAll();
        }catch(Exception $e){ $groups=[]; }
        ?>
        <?php foreach($groups as $gr): ?>
          <div class="d-flex justify-content-between align-items-center mb-2 small">
            <div>
              <div class="fw-semibold"><?= htmlspecialchars($gr['name']) ?></div>
              <div class="text-muted"><?= (int)$gr['members_count'] ?> members</div>
            </div>
            <a href="group_join.php?id=<?= (int)$gr['id'] ?>" class="btn btn-sm btn-info text-dark">Join</a>
          </div>
        <?php endforeach; ?>
      </div>

    </aside>

  </main> <!-- /layout-grid -->

  <!-- bottom nav mobile -->
  <nav class="bottom-nav d-lg-none">
    <a href="dashboard.php" class="active">
      <i class="bi bi-house-fill"></i>Feed
    </a>
    <a href="explore.php">
      <i class="bi bi-compass-fill"></i>Explore
    </a>
    <a href="groups.php">
      <i class="bi bi-people-fill"></i>Groups
    </a>
    <a href="settings.php">
      <i class="bi bi-person-circle"></i>Profile
    </a>
  </nav>

  <!-- FAB -->
  <button class="fab-post d-lg-none" data-bs-toggle="modal" data-bs-target="#composeModal">
    <i class="bi bi-plus-lg"></i>
  </button>

  <!-- compose modal -->
  <div class="modal fade" id="composeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header border-0">
          <h5 class="modal-title text-label">Create post</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form action="post_create.php" method="POST" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <input type="hidden" name="cat"    value="<?= htmlspecialchars($cat) ?>">
            <input type="hidden" name="q"      value="<?= htmlspecialchars($search) ?>">

            <div class="mb-2">
              <input type="text" name="title" class="form-control form-control-sm"
                     placeholder="Title (optional)">
            </div>

            <div class="mb-2 small d-flex gap-3">
              <label><input type="radio" name="category" value="post" checked> Post</label>
              <label><input type="radio" name="category" value="highlight"> Highlight</label>
              <label><input type="radio" name="category" value="project"> Project</label>
            </div>

            <div class="mb-2">
              <button type="button" id="imageBtn" class="btn btn-sm btn-outline-info">
                <i class="bi bi-image"></i> Add images
              </button>
              <input type="file" id="imageInput" name="images[]" class="d-none" multiple accept="image/*">
              <div id="imageInfo" class="small text-muted mt-1">
                Up to 4 images (JPG, PNG, WEBP, max 5MB each).
              </div>
            </div>

            <div class="mb-2">
              <textarea name="body" rows="4" class="form-control"
                        placeholder="Share an update, question, or idea..." required></textarea>
            </div>
          </div>
          <div class="modal-footer border-0">
            <button type="submit" class="btn btn-info text-dark">
              <i class="bi bi-send-fill me-1"></i>Post
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div> <!-- /app-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// mobile search toggle
const mobileSearchToggle = document.getElementById('mobileSearchToggle');
const mobileSearchBar    = document.getElementById('mobileSearchBar');
if (mobileSearchToggle && mobileSearchBar){
  mobileSearchToggle.addEventListener('click', ()=>{
    mobileSearchBar.style.display = mobileSearchBar.style.display === 'block' ? 'none' : 'block';
  });
}

// share buttons
document.querySelectorAll('.share-btn').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const id = btn.dataset.postId;
    const url = location.origin + location.pathname + '#post-' + id;
    if (navigator.share){
      try{ await navigator.share({title:'Portal post',url}); return;}catch(e){}
    }
    try{
      await navigator.clipboard.writeText(url);
      alert('Link copied to clipboard.');
    }catch(e){
      prompt('Copy this link:',url);
    }
  });
});

// follow toggle ajax
document.querySelectorAll('.follow-toggle').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const userId = btn.dataset.userId;
    const fd = new FormData();
    fd.append('user_id',userId);
    fd.append('ajax','1');
    btn.disabled = true;
    try{
      const res = await fetch('follow_toggle.php',{method:'POST',body:fd});
      const data = await res.json();
      if (data.status === 'ok'){
        if (data.following){
          btn.classList.remove('btn-outline-info');
          btn.classList.add('btn-info','text-dark');
          btn.dataset.following='1';
          btn.innerHTML = '<i class="bi bi-person-check-fill"></i>';
        }else{
          btn.classList.remove('btn-info','text-dark');
          btn.classList.add('btn-outline-info');
          btn.dataset.following='0';
          btn.innerHTML = '<i class="bi bi-person-plus"></i>';
        }
      }
    }catch(e){console.error(e);}
    btn.disabled = false;
  });
});

// image picker
const imageBtn  = document.getElementById('imageBtn');
const imageInput= document.getElementById('imageInput');
const imageInfo = document.getElementById('imageInfo');
if (imageBtn && imageInput){
  imageBtn.addEventListener('click',()=>imageInput.click());
  imageInput.addEventListener('change',()=>{
    if (!imageInput.files.length){
      imageInfo.textContent = 'Up to 4 images (JPG, PNG, WEBP, max 5MB each).';
      return;
    }
    const names = Array.from(imageInput.files).slice(0,4).map(f=>f.name);
    imageInfo.textContent = 'Selected: '+names.join(', ');
  });
}

// username / name autocomplete (desktop + mobile)
const searchInputs = document.querySelectorAll('.global-search');
let suggestTimer = null;

function attachSuggest(input){
  const wrapper = input.closest('form').querySelector('.user-suggest-box');
  if (!wrapper) return;

  function hide(){
    wrapper.style.display='none';
    wrapper.innerHTML='';
  }

  document.addEventListener('click',e=>{
    if (!wrapper.contains(e.target) && e.target!==input) hide();
  });

  input.addEventListener('input',()=>{
    const val = input.value.trim();
    if (val.length < 2){ hide(); return; }
    const query = val[0]==='@' ? val.substring(1) : val;

    clearTimeout(suggestTimer);
    suggestTimer = setTimeout(async ()=>{
      try{
        const res = await fetch('user_search.php?q='+encodeURIComponent(query));
        if (!res.ok) return;
        const data = await res.json();
        wrapper.innerHTML='';
        if (!data.users || !data.users.length){ hide(); return; }

        data.users.forEach(u=>{
          const item = document.createElement('div');
          item.className='user-suggest-item';
          item.innerHTML = `
            <img src="${u.avatar}" alt="">
            <div>
              <div class="user-suggest-username">@${u.username}</div>
              <div class="user-suggest-muted">${u.full_name}</div>
            </div>`;
          item.addEventListener('click',()=>{
            window.location.href = 'user_profile.php?user_id='+u.id;
          });
          wrapper.appendChild(item);
        });
        wrapper.style.display='block';
      }catch(e){ hide(); }
    },200);
  });
}

searchInputs.forEach(attachSuggest);
</script>
</body>
</html>
