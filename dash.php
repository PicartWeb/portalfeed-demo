<?php
// dashboard.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId   = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'];
$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? 'member';
$avatar   = $_SESSION['avatar'] ?? null;

$isStaff        = in_array($role, ['owner','admin','moderator'], true);
$isAdminOrOwner = in_array($role, ['owner','admin'], true);

/* ========== NOTIFICATIONS ========== */
$notifUnreadCount = 0;
$notifications = [];

$notifUnreadCountStmt = $pdo->prepare("
  SELECT COUNT(*) FROM notifications
  WHERE to_user_id = :uid AND is_read = 0
");
$notifUnreadCountStmt->execute([':uid' => $userId]);
$notifUnreadCount = (int)$notifUnreadCountStmt->fetchColumn();

$dmUnreadStmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM notifications
  WHERE to_user_id = :uid
    AND type = 'message'
    AND is_read = 0
");
$dmUnreadStmt->execute([':uid' => $userId]);
$dmUnreadCount = (int)$dmUnreadStmt->fetchColumn();

$notifStmt = $pdo->prepare("
  SELECT n.*, u.username, u.full_name, u.avatar
  FROM notifications n
  JOIN users u ON u.id = n.from_user_id
  WHERE n.to_user_id = :uid
  ORDER BY n.created_at DESC
  LIMIT 15
");
$notifStmt->execute([':uid' => $userId]);
$notifications = $notifStmt->fetchAll();

/* ========== FILTERS / SEARCH ========== */

$filter = $_GET['filter'] ?? 'all';      // all, following, staff, mine
$cat    = $_GET['cat'] ?? 'all';         // all, post, highlight, project
$search = trim($_GET['q'] ?? '');

$allowedFilters = ['all','following','staff','mine'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'all';

$allowedCats = ['all','post','highlight','project'];
if (!in_array($cat, $allowedCats, true)) $cat = 'all';

// smart search: @user, #tag, text
$searchMode = 'none';
$searchTerm = '';
if ($search !== '') {
    if ($search[0] === '@') {
        $searchMode = 'user';
        $searchTerm = substr($search, 1);
    } elseif ($search[0] === '#') {
        $searchMode = 'tag';
        $searchTerm = substr($search, 1);
    } else {
        $searchMode = 'text';
        $searchTerm = $search;
    }
}

$searchHint = '';
if ($searchMode === 'user' && $searchTerm !== '') {
    $searchHint = 'Filtering by user @' . $searchTerm;
} elseif ($searchMode === 'tag' && $searchTerm !== '') {
    $searchHint = 'Filtering by hashtag #' . $searchTerm;
} elseif ($searchMode === 'text' && $searchTerm !== '') {
    $searchHint = 'Searching: “' . $searchTerm . '”';
}

/* ========== FEED DATA ========== */

$whereParts = [];
$params     = [];

if ($cat !== 'all') {
    $whereParts[] = 'p.category = :cat';
    $params[':cat'] = $cat;
}

if ($filter === 'following') {
    $whereParts[] =
      'p.user_id IN (SELECT following_id FROM follows WHERE follower_id = :me_follow)';
    $params[':me_follow'] = $userId;
} elseif ($filter === 'staff') {
    $whereParts[] = "u.role IN ('owner','admin','moderator')";
} elseif ($filter === 'mine') {
    $whereParts[] = 'p.user_id = :me_mine';
    $params[':me_mine'] = $userId;
}

if ($searchMode === 'text' && $searchTerm !== '') {
    $whereParts[] = '(p.title LIKE :q OR p.body LIKE :q)';
    $params[':q'] = '%' . $searchTerm . '%';
} elseif ($searchMode === 'tag' && $searchTerm !== '') {
    $whereParts[] = '(p.title LIKE :tq OR p.body LIKE :tq)';
    $params[':tq'] = '%#' . $searchTerm . '%';
} elseif ($searchMode === 'user' && $searchTerm !== '') {
    $whereParts[] = '(u.username LIKE :uq OR u.full_name LIKE :uq)';
    $params[':uq'] = '%' . $searchTerm . '%';
}

$whereSql = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$postSql = "
  SELECT p.id, p.title, p.body, p.is_pinned, p.user_id, p.created_at, p.category,
         u.username, u.full_name, u.avatar, u.role AS user_role,
         COALESCE(lc.like_count,0) AS like_count
  FROM posts p
  JOIN users u ON p.user_id = u.id
  LEFT JOIN (
    SELECT post_id, COUNT(*) AS like_count
    FROM post_likes
    GROUP BY post_id
  ) lc ON lc.post_id = p.id
  $whereSql
  ORDER BY p.is_pinned DESC, p.created_at DESC
";
$stmt = $pdo->prepare($postSql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

$postIds = array_column($posts, 'id');

/* COMMENTS */
$commentsByPost = [];
if (!empty($postIds)) {
    $in = implode(',', array_map('intval', $postIds));
    $cStmt = $pdo->query("
      SELECT c.id, c.post_id, c.body, c.user_id, c.created_at,
             u.username, u.full_name, u.avatar
      FROM comments c
      JOIN users u ON c.user_id = u.id
      WHERE c.post_id IN ($in)
      ORDER BY c.created_at ASC
    ");
    foreach ($cStmt as $row) {
        $commentsByPost[$row['post_id']][] = $row;
    }
}

/* LIKES of current user */
$likedPosts = [];
if (!empty($postIds)) {
    $likeStmt = $pdo->prepare("
      SELECT post_id FROM post_likes
      WHERE user_id = :uid AND post_id IN (" . implode(',', array_map('intval', $postIds)) . ")
    ");
    $likeStmt->execute([':uid' => $userId]);
    foreach ($likeStmt as $lp) {
        $likedPosts[(int)$lp['post_id']] = true;
    }
}

/* MEDIA */
$mediaByPost = [];
if (!empty($postIds)) {
    $in = implode(',', array_map('intval', $postIds));
    $mStmt = $pdo->query("
      SELECT id, post_id, path, type
      FROM post_media
      WHERE post_id IN ($in)
      ORDER BY id ASC
    ");
    foreach ($mStmt as $m) {
        $mediaByPost[$m['post_id']][] = $m;
    }
}

/* FOLLOWING map */
$followingMap = [];
$followStmt = $pdo->prepare("SELECT following_id FROM follows WHERE follower_id = :me");
$followStmt->execute([':me' => $userId]);
foreach ($followStmt as $f) {
    $followingMap[(int)$f['following_id']] = true;
}
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
  --bg-body:#020312;
  --bg-panel:#070a1e;
  --bg-panel-soft:#05071a;
  --glass-border:rgba(255,255,255,.18);
  --glass-border-soft:rgba(255,255,255,.08);
  --neon-cyan:#0ff0fc;
  --neon-purple:#e400ff;
  --danger:#ff5c7a;
  --text-main:#ffffff;
  --text-soft:#f5f6ff;
  --text-muted:#9fa4da;
}
*{box-sizing:border-box;}
body{
  margin:0;
  min-height:100vh;
  background:
    radial-gradient(circle at 0 0,#272768,transparent 60%),
    radial-gradient(circle at 100% 100%,#0f314c,transparent 60%),
    var(--bg-body);
  color:var(--text-main);
  font-family:system-ui,-apple-system,"Poppins",sans-serif;
  -webkit-font-smoothing:antialiased;
}
.dash-shell{min-height:100vh;display:flex;flex-direction:column;}

/* HEADER */
.dash-top{
  padding:.5rem .8rem;
  background:
    radial-gradient(circle at 0 0,#111633,transparent 60%),
    radial-gradient(circle at 100% 100%,#13163f,transparent 60%),
    #05061a;
  border-bottom:1px solid rgba(255,255,255,.08);
  backdrop-filter:blur(22px);
  position:sticky;top:0;z-index:40;
}
.avatar-lg{
  width:46px;height:46px;border-radius:50%;object-fit:cover;
  border:2px solid var(--neon-cyan);
  box-shadow:0 0 18px rgba(15,240,252,.8);
}
.badge-role{
  border-radius:999px;
  border:1px solid rgba(255,255,255,.5);
  padding:.14rem .8rem;
  font-size:.68rem;
  text-transform:uppercase;
  letter-spacing:.16em;
  color:var(--text-soft);
  background:linear-gradient(120deg,rgba(15,240,252,.25),rgba(228,0,255,.18));
}
.btn-top{
  border-radius:999px;
  border:1px solid var(--glass-border-soft);
  font-size:.78rem;
  padding:.3rem .8rem;
  background:rgba(3,4,16,.9);
  color:var(--text-soft);
  display:inline-flex;
  align-items:center;
  gap:.3rem;
  transition:all .16s ease;
}
.btn-top:hover{
  border-color:var(--neon-cyan);
  color:var(--text-main);
  box-shadow:0 0 16px rgba(15,240,252,.35);
  transform:translateY(-1px);
}
.btn-top-danger{
  border-color:rgba(255,92,122,.7);
  color:var(--danger);
}
.btn-top-danger:hover{
  background:rgba(255,92,122,.16);
  color:#ffe7ee;
}

/* header search */
.header-search{max-width:420px;width:100%;}
.header-search-wrap{position:relative;width:100%;}
.header-search-wrap i{
  position:absolute;
  left:.6rem;
  top:50%;
  transform:translateY(-50%);
  color:var(--text-muted);
  font-size:.9rem;
}
.header-search-input{
  background:#05061f;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.22);
  color:var(--text-soft);
  font-size:.8rem;
  padding:.36rem .8rem .36rem 2.1rem;
}
.header-search-input:focus{
  border-color:var(--neon-cyan);
  box-shadow:0 0 0 .09rem rgba(15,240,252,.6);
  background:#060829;
}

/* username suggestions */
.user-suggest-box{
  position:absolute;
  left:0;right:0;
  top:100%;margin-top:.25rem;
  background:#050618;
  border-radius:14px;
  border:1px solid rgba(255,255,255,.16);
  box-shadow:0 20px 50px rgba(0,0,0,.9);
  padding:.3rem 0;
  z-index:60;
  max-height:260px;
  overflow-y:auto;
  display:none;
}
.user-suggest-item{
  display:flex;align-items:center;
  gap:.45rem;
  padding:.35rem .75rem;
  cursor:pointer;
  font-size:.8rem;
  color:var(--text-soft);
}
.user-suggest-item:hover{
  background:rgba(15,240,252,.12);
}
.user-suggest-item img{
  width:26px;height:26px;border-radius:50%;object-fit:cover;
  border:1px solid rgba(15,240,252,.7);
}
.user-suggest-username{color:var(--text-main);font-weight:500;}
.user-suggest-muted{color:var(--text-muted);font-size:.74rem;}

/* MAIN */
.dash-main{
  flex:1;
  padding:1rem .7rem 1.5rem;
  max-width:960px;
  margin:0 auto;
  width:100%;
}
.card-glass{
  background:var(--bg-panel);
  border-radius:20px;
  border:1px solid var(--glass-border);
  box-shadow:0 24px 70px rgba(0,0,0,.9);
  padding:1rem 1.1rem;
  margin-bottom:1rem;
}
.card-soft{
  background:var(--bg-panel-soft);
  border-radius:18px;
  border:1px solid var(--glass-border-soft);
  padding:.75rem .9rem;
  margin-bottom:1rem;
}
.section-label{
  font-size:.72rem;
  text-transform:uppercase;
  letter-spacing:.18em;
  color:var(--text-muted);
}

/* filters */
.feed-filter-bar{
  display:flex;
  flex-wrap:wrap;
  gap:.35rem;
}
.filter-pill{
  border-radius:999px;
  padding:.12rem .9rem;
  border:1px solid var(--glass-border-soft);
  font-size:.75rem;
  text-decoration:none;
  color:var(--text-soft);
  background:rgba(8,9,30,.98);
  transition:all .16s ease;
}
.filter-pill:hover{
  border-color:var(--neon-cyan);
  color:var(--text-main);
}
.filter-pill.active{
  border-color:var(--neon-cyan);
  background:rgba(15,240,252,.2);
  color:var(--text-main);
}

/* composer trigger */
.compose-trigger{
  display:flex;
  align-items:center;
  gap:.6rem;
  background:linear-gradient(120deg,#05071c,#060a27);
  border-radius:16px;
  border:1px solid rgba(255,255,255,.14);
  padding:.5rem .75rem;
}
.compose-input-fake{
  flex:1;
  border-radius:999px;
  background:#05061f;
  border:1px solid rgba(255,255,255,.2);
  padding:.32rem .8rem;
  font-size:.8rem;
  color:var(--text-muted);
}
.compose-plus{
  width:34px;height:34px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,var(--neon-purple),var(--neon-cyan));
  color:#05060c;
  box-shadow:0 0 18px rgba(15,240,252,.9);
}

/* modal composer */
.modal-content{
  background:#05061b;
  border-radius:20px;
  border:1px solid rgba(255,255,255,.18);
}
.modal-header{
  border-bottom:1px solid rgba(255,255,255,.08);
}
.modal-title{
  font-size:.9rem;
  text-transform:uppercase;
  letter-spacing:.18em;
  color:var(--text-muted);
}
.new-post-box textarea{
  background:#060823;
  border-radius:16px;
  border:1px solid rgba(255,255,255,.22);
  color:var(--text-soft);
  font-size:.88rem;
}
.new-post-box textarea:focus{
  border-color:var(--neon-cyan);
  box-shadow:0 0 0 .09rem rgba(15,240,252,.55);
}
.new-post-box input[type="text"]{
  background:#05071f;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.2);
  color:var(--text-soft);
  font-size:.82rem;
}
.btn-post-primary{
  background:linear-gradient(120deg,var(--neon-purple),var(--neon-cyan));
  border:none;
  border-radius:999px;
  padding:.42rem 1.4rem;
  font-size:.78rem;
  letter-spacing:.16em;
  text-transform:uppercase;
  font-weight:600;
  color:#05060c;
  box-shadow:0 0 22px rgba(15,240,252,.85);
}
.btn-post-primary:hover{
  filter:brightness(1.05);
  box-shadow:0 0 28px rgba(15,240,252,.95);
}
.image-btn{
  border-radius:999px;
  border:1px dashed rgba(255,255,255,.35);
  font-size:.78rem;
  padding:.3rem .9rem;
  background:#05071d;
  color:var(--text-soft);
  display:inline-flex;
  align-items:center;
  gap:.3rem;
}
.image-btn:hover{
  border-style:solid;
  border-color:var(--neon-cyan);
  color:var(--text-main);
}

/* posts */
.post{
  border-radius:18px;
  background:#070820;
  border:1px solid rgba(255,255,255,.16);
  padding:.85rem .95rem .7rem;
  margin-bottom:.9rem;
}
.post-pinned{
  border-color:var(--neon-cyan);
  box-shadow:0 0 24px rgba(15,240,252,.7);
}
.post-header{display:flex;align-items:flex-start;gap:.6rem;}
.post-avatar{
  width:36px;height:36px;border-radius:50%;object-fit:cover;
  border:2px solid rgba(15,240,252,.8);
  box-shadow:0 0 14px rgba(15,240,252,.7);
}
.post-author{font-size:.9rem;font-weight:500;}
.post-meta{font-size:.72rem;color:var(--text-muted);}
.post-body{font-size:.9rem;margin-top:.45rem;color:var(--text-soft);white-space:pre-wrap;}
.post-title{font-size:.94rem;font-weight:600;margin-bottom:.05rem;}
.badge-cat{
  font-size:.7rem;
  border-radius:999px;
  border:1px solid var(--glass-border-soft);
  padding:.04rem .45rem;
  text-transform:uppercase;
  letter-spacing:.14em;
  margin-left:.25rem;
  color:var(--text-muted);
}

/* gallery */
.post-gallery{
  margin-top:.55rem;
  display:flex;
  gap:.35rem;
  overflow-x:auto;
  padding-bottom:.2rem;
}
.post-gallery img{
  border-radius:12px;
  max-height:140px;
  width:auto;
  object-fit:cover;
  border:1px solid rgba(255,255,255,.14);
}
@media(max-width:768px){
  .post-gallery img{max-height:110px;}
}

/* actions */
.post-actions{
  display:flex;align-items:center;gap:.6rem;
  margin-top:.4rem;font-size:.8rem;flex-wrap:wrap;
}
.icon-btn{
  border:none;background:transparent;color:var(--text-muted);
  display:inline-flex;align-items:center;gap:.2rem;
  padding:.2rem .5rem;border-radius:999px;
}
.icon-btn:hover{
  color:var(--text-main);
  background:rgba(255,255,255,.08);
}
.icon-btn-liked{color:var(--neon-cyan);}

.follow-pill{
  border-radius:999px;
  border:1px solid rgba(255,255,255,.28);
  padding:.18rem .7rem;
  font-size:.74rem;
  background:rgba(8,10,30,.98);
  color:var(--text-soft);
  display:inline-flex;align-items:center;gap:.25rem;
}
.follow-pill.following{
  border-color:var(--neon-cyan);
  background:rgba(15,240,252,.18);
  color:var(--text-main);
}

/* comments */
.comment{display:flex;gap:.4rem;margin-top:.4rem;}
.comment-avatar{
  width:26px;height:26px;border-radius:50%;object-fit:cover;
  border:1px solid rgba(15,240,252,.75);
}
.comment-body-box{
  background:#060821;
  border-radius:12px;
  padding:.25rem .55rem .32rem;
  border:1px solid rgba(255,255,255,.13);
  font-size:.78rem;
}
.comment-meta{font-size:.7rem;color:var(--text-muted);}
.comment-form textarea{
  background:#05061d;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.18);
  font-size:.8rem;
  color:var(--text-soft);
  resize:none;min-height:36px;
}
.btn-comment{
  border-radius:999px;border:none;
  padding:.22rem .85rem;font-size:.75rem;
  background:rgba(15,240,252,.18);
  color:var(--text-main);
}

/* misc */
.three-dots{border:none;background:transparent;color:var(--text-muted);padding:0 .18rem;}
.three-dots:hover{color:var(--text-main);background:rgba(255,255,255,.08);}
.dropdown-menu{
  font-size:.8rem;background:#050619;
  border-radius:14px;border:1px solid var(--glass-border-soft);
}

/* floating + for mobile */
.fab-post{
  position:fixed;
  right:1rem;
  bottom:1rem;
  width:50px;height:50px;
  border-radius:50%;
  background:linear-gradient(135deg,var(--neon-purple),var(--neon-cyan));
  box-shadow:0 0 24px rgba(15,240,252,.95);
  display:flex;align-items:center;justify-content:center;
  color:#05060c;
  border:none;
  z-index:45;
}
@media(min-width:768px){
  .fab-post{display:none;}
}

/* responsive header tweaks */
@media(max-width:768px){
  .dash-top{padding:.5rem .55rem;}
  .dash-main{padding:.9rem .55rem 1.4rem;}
  .card-glass{padding:.9rem .9rem;}
}
</style>
</head>
<body>
<div class="dash-shell">

  <!-- HEADER -->
  <div class="dash-top">
    <div class="d-flex align-items-center flex-wrap gap-2">

      <!-- user -->
      <div class="d-flex align-items-center gap-2 me-2">
        <img
          src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=111827&color=fff&rounded=true&size=128' ?>"
          alt="avatar" class="avatar-lg">
        <div>
          <div style="font-size:.95rem;font-weight:600;"><?= htmlspecialchars($fullName) ?></div>
          <div style="font-size:.78rem;color:var(--text-muted);">@<?= htmlspecialchars($username) ?></div>
          <div class="mt-1 badge-role"><?= htmlspecialchars($role) ?></div>
        </div>
      </div>

      <!-- search -->
      <div class="flex-grow-1 header-search order-3 order-md-2 mt-2 mt-md-0 mx-md-2">
        <form method="GET" autocomplete="off">
          <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
          <input type="hidden" name="cat"    value="<?= htmlspecialchars($cat) ?>">
          <div class="header-search-wrap">
            <i class="bi bi-search"></i>
            <input
              id="globalSearch"
              type="search"
              name="q"
              value="<?= htmlspecialchars($search) ?>"
              class="form-control header-search-input"
              placeholder="Search posts, @user, or #tag">
            <div id="userSuggest" class="user-suggest-box"></div>
          </div>
        </form>
      </div>

      <!-- menu -->
      <div class="d-flex align-items-center gap-2 ms-auto order-2 order-md-3">

        <a href="index.php" class="btn-top">
          <i class="bi bi-house-door-fill"></i><span class="d-none d-sm-inline">Portal</span>
        </a>
        <!-- Groups -->
<a href="groups.php" class="btn-top">
  <i class="bi bi-people-fill"></i>
  <span class="d-none d-sm-inline">Groups</span>
</a>


        <?php if ($isAdminOrOwner): ?>
          <a href="admin_dashboard.php" class="btn-top d-none d-md-inline-flex">
            <i class="bi bi-cpu-fill"></i><span class="d-none d-sm-inline">Admin</span>
          </a>
        <?php endif; ?>

        <a href="messages.php" class="btn-top position-relative">
          <i class="bi bi-chat-dots-fill"></i><span class="d-none d-sm-inline">Messages</span>
          <?php if ($dmUnreadCount > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                  style="font-size:.62rem;box-shadow:0 0 12px rgba(255,92,122,.9);">
              <?= $dmUnreadCount > 9 ? '9+' : $dmUnreadCount ?>
            </span>
          <?php endif; ?>
        </a>

        <!-- notifications -->
        <div class="dropdown">
          <button class="btn-top position-relative" type="button" data-bs-toggle="dropdown">
            <i class="bi <?= $notifUnreadCount ? 'bi-bell-fill' : 'bi-bell' ?>"></i>
            <span class="d-none d-sm-inline">Alerts</span>
            <?php if ($notifUnreadCount): ?>
              <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger rounded-circle"
                    style="box-shadow:0 0 12px rgba(255,92,122,.9);"></span>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end" style="min-width:260px;">
            <div class="px-3 py-2 d-flex justify-content-between align-items-center">
              <span style="font-size:.78rem;text-transform:uppercase;letter-spacing:.14em;color:#9da4d1;">Notifications</span>
              <form action="notifications_mark_read.php" method="POST">
                <input type="hidden" name="redirect" value="dashboard.php">
                <button class="btn btn-link p-0" style="font-size:.75rem;color:#9da4d1;">Mark all read</button>
              </form>
            </div>
            <div style="max-height:260px;overflow-y:auto;">
              <?php if (empty($notifications)): ?>
                <div class="px-3 py-2" style="font-size:.8rem;color:#9da4d1;">
                  Nothing yet. Likes, comments, follows and DMs will show here.
                </div>
              <?php else: ?>
                <?php foreach ($notifications as $n): ?>
                  <?php
                    $nName   = $n['full_name'] ?: $n['username'];
                    $nAvatar = $n['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($nName) . '&background=111827&color=fff&rounded=true&size=64';
                    $isUnread = !$n['is_read'];
                    $targetUrl = 'dashboard.php';
                    if ($n['type'] === 'follow') {
                      $targetUrl = 'user_profile.php?user_id='.(int)$n['from_user_id'];
                    } elseif ($n['type'] === 'message') {
                      $targetUrl = 'messages.php?conversation_id='.(int)$n['ref_id'];
                    }
                  ?>
                  <a href="<?= htmlspecialchars($targetUrl) ?>"
                     class="dropdown-item d-flex align-items-start gap-2 <?= $isUnread ? '' : 'opacity-75'; ?>">
                    <img src="<?= htmlspecialchars($nAvatar) ?>" alt=""
                         style="width:28px;height:28px;border-radius:50%;object-fit:cover;border:1px solid rgba(15,240,252,.7);">
                    <div>
                      <div style="font-size:.8rem;color:#e0e2ff;">
                        <strong><?= htmlspecialchars($nName) ?></strong> <?= htmlspecialchars($n['message']) ?>
                      </div>
                      <div style="font-size:.72rem;color:#9da4d1;">
                        <?= htmlspecialchars(date('M j, H:i', strtotime($n['created_at']))) ?>
                      </div>
                    </div>
                    <?php if ($isUnread): ?>
                      <span style="width:6px;height:6px;border-radius:50%;background:#0ff0fc;
                                   box-shadow:0 0 12px rgba(15,240,252,.9);margin-left:auto;"></span>
                    <?php endif; ?>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <a href="settings.php" class="btn-top">
          <i class="bi bi-person-circle"></i><span class="d-none d-sm-inline">Profile</span>
        </a>

        <form action="logout.php" method="POST" class="m-0 p-0">
          <button class="btn-top btn-top-danger" type="submit">
            <i class="bi bi-box-arrow-right"></i><span class="d-none d-sm-inline">Logout</span>
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- MAIN -->
  <div class="dash-main">

    <!-- FILTERS BAR -->
    <div class="card-soft mb-3">
      <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-2">
        <div class="section-label">Feed • choose what you see</div>
        <?php if ($searchHint): ?>
          <div style="font-size:.72rem;color:var(--text-muted);"><?= htmlspecialchars($searchHint) ?></div>
        <?php endif; ?>
      </div>

      <div class="d-flex flex-wrap justify-content-between gap-3">
        <div class="feed-filter-bar">
          <?php
            $filters = ['all'=>'All','following'=>'Following','staff'=>'Staff','mine'=>'My feed'];
            foreach ($filters as $key=>$label):
              $active = ($filter === $key);
              $url = 'dashboard.php?filter='.$key.'&cat='.$cat.'&q='.urlencode($search);
          ?>
            <a href="<?= htmlspecialchars($url) ?>" class="filter-pill <?= $active?'active':'' ?>">
              <?= htmlspecialchars($label) ?>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="feed-filter-bar">
          <?php
            $cats = ['all'=>'All types','post'=>'Posts','highlight'=>'Highlights','project'=>'Projects'];
            foreach ($cats as $key=>$label):
              $active = ($cat === $key);
              $url = 'dashboard.php?filter='.$filter.'&cat='.$key.'&q='.urlencode($search);
          ?>
            <a href="<?= htmlspecialchars($url) ?>" class="filter-pill <?= $active?'active':'' ?>">
              <?= htmlspecialchars($label) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- COMPOSE TRIGGER -->
      <div class="mt-3 compose-trigger" data-bs-toggle="modal" data-bs-target="#composeModal" style="cursor:pointer;">
        <img
          src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name=' . urlencode($fullName) . '&background=111827&color=fff&rounded=true&size=64' ?>"
          alt="avatar" style="width:34px;height:34px;border-radius:50%;object-fit:cover;border:1px solid rgba(15,240,252,.7);">
        <div class="compose-input-fake">What’s on your mind?</div>
        <div class="compose-plus"><i class="bi bi-plus-lg"></i></div>
      </div>
    </div>

    <!-- POSTS -->
    <?php if (empty($posts)): ?>
      <div class="card-glass text-center">
        <p style="font-size:.9rem;color:var(--text-muted);margin:0;">No posts yet. Be the first one to drop something.</p>
      </div>
    <?php else: ?>
      <?php foreach ($posts as $post): ?>
        <?php
          $postId      = (int)$post['id'];
          $authorName  = $post['full_name'] ?: $post['username'];
          $authorAv    = $post['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($authorName) . '&background=111827&color=fff&rounded=true&size=64';
          $isOwnerPost = ($post['user_id'] == $userId);
          $postComments = $commentsByPost[$postId] ?? [];
          $likeCount   = (int)$post['like_count'];
          $userLiked   = isset($likedPosts[$postId]);
          $catLabel    = ucfirst($post['category'] ?? 'post');
          $authorFollowed = isset($followingMap[(int)$post['user_id']]);
        ?>
        <article class="post <?= $post['is_pinned'] ? 'post-pinned' : '' ?>" id="post-<?= $postId ?>">

          <div class="d-flex justify-content-between align-items-start gap-2">
            <a href="user_profile.php?user_id=<?= (int)$post['user_id'] ?>"
               class="text-decoration-none d-flex align-items-start gap-2 flex-grow-1" style="color:inherit;">
              <img src="<?= htmlspecialchars($authorAv) ?>" alt="avatar" class="post-avatar">
              <div>
                <div class="post-author"><?= htmlspecialchars($authorName) ?></div>
                <div class="post-meta">
                  @<?= htmlspecialchars($post['username']) ?> •
                  <?= htmlspecialchars(date('M j, H:i', strtotime($post['created_at']))) ?>
                  <?php if ($post['is_pinned']): ?>
                    • <span style="color:var(--neon-cyan);"><i class="bi bi-pin-angle-fill"></i> Pinned</span>
                  <?php endif; ?>
                  <span class="badge-cat"><?= htmlspecialchars($catLabel) ?></span>
                </div>
              </div>
            </a>

            <div class="text-end">
         <?php if (!$isOwnerPost): ?>
  <button
    class="follow-pill follow-toggle <?= $authorFollowed ? 'following' : '' ?> mb-1"
    type="button"
    data-user-id="<?= (int)$post['user_id'] ?>"
    data-following="<?= $authorFollowed ? '1' : '0' ?>">
    <i class="bi <?= $authorFollowed ? 'bi-person-check-fill' : 'bi-person-plus' ?>"></i>
    <?= $authorFollowed ? 'Following' : 'Follow' ?>
  </button>
<?php endif; ?>


              <?php if ($isOwnerPost || $isStaff): ?>
               <div class="dropdown">
<button class="three-dots" data-bs-toggle="dropdown">
<i class="bi bi-three-dots-vertical"></i>
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
<input type="hidden" name="pin" value="<?= $post['is_pinned'] ? '0' : '1' ?>">
<button class="dropdown-item">
<i class="bi bi-pin-angle-fill me-1"></i>
<?= $post['is_pinned'] ? 'Unpin post' : 'Pin as post of the day' ?>
</button>
</form>
</li>
<?php endif; ?>


<li><hr class="dropdown-divider"></li>
<?php endif; ?>


<!-- NEW REPORT BUTTON (SHOWS ONLY IF NOT YOUR OWN POST) -->
<?php if (!$isOwnerPost): ?>
<li>
<form action="report_create.php" method="POST">
<input type="hidden" name="target_type" value="post">
<input type="hidden" name="target_id" value="<?= $postId ?>">
<input type="hidden" name="reason" value="Inappropriate content">
<input type="hidden" name="redirect" value="dashboard.php">
<button class="dropdown-item text-warning" type="submit">
<i class="bi bi-flag"></i> Report post
</button>
</form>
</li>
<?php endif; ?>


</ul>
</div>
              <?php endif; ?>
            </div>
          </div>

          <!-- BODY -->
          <div class="post-body">
            <?php if (!empty($post['title'])): ?>
              <div class="post-title"><?= htmlspecialchars($post['title']) ?></div>
            <?php endif; ?>
            <?= nl2br(htmlspecialchars($post['body'])) ?>
          </div>

          <?php if (!empty($mediaByPost[$postId])): ?>
            <div class="post-gallery">
              <?php foreach ($mediaByPost[$postId] as $m): ?>
                <img src="<?= htmlspecialchars($m['path']) ?>" alt="media">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- EDIT COLLAPSE -->
          <?php if ($isOwnerPost || $isStaff): ?>
            <div class="collapse mt-2" id="editPost<?= $postId ?>">
              <form action="post_update.php" method="POST">
                <input type="hidden" name="post_id" value="<?= $postId ?>">
                <textarea name="body" rows="3" class="form-control mb-2"><?= htmlspecialchars($post['body']) ?></textarea>
                <button class="btn btn-sm btn-outline-light" type="submit">
                  <i class="bi bi-check2-circle me-1"></i>Save edit
                </button>
              </form>
            </div>
          <?php endif; ?>

          <!-- ACTIONS -->
          <div class="post-actions">
            <span class="icon-btn">
              <i class="bi bi-chat-left-text"></i> <?= count($postComments) ?> comments
            </span>
            <form action="post_like_toggle.php" method="POST" class="d-inline">
              <input type="hidden" name="post_id" value="<?= $postId ?>">
              <button class="icon-btn <?= $userLiked ? 'icon-btn-liked' : '' ?>" type="submit">
                <i class="bi <?= $userLiked ? 'bi-heart-fill' : 'bi-heart' ?>"></i><?= $likeCount ?>
              </button>
            </form>
            <button class="icon-btn share-btn" type="button" data-post-id="<?= $postId ?>">
              <i class="bi bi-share-fill"></i> Share
            </button>
          </div>

          <!-- COMMENTS -->
          <div class="mt-2">
            <?php foreach ($postComments as $c): ?>
              <?php
                $cAuthorName = $c['full_name'] ?: $c['username'];
                $cAvatar = $c['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($cAuthorName) . '&background=111827&color=fff&rounded=true&size=64';
                $canDeleteComment = ($c['user_id'] == $userId) || $isStaff;
              ?>
              <div class="comment">
                <img src="<?= htmlspecialchars($cAvatar) ?>" class="comment-avatar" alt="">
                <div class="flex-grow-1">
                  <div class="d-flex justify-content-between">
                    <div class="comment-body-box">
                      <div class="comment-meta">
                        <?= htmlspecialchars($cAuthorName) ?> •
                        <?= htmlspecialchars(date('M j, H:i', strtotime($c['created_at']))) ?>
                      </div>
                      <div><?= nl2br(htmlspecialchars($c['body'])) ?></div>
                    </div>
                    <?php if ($canDeleteComment): ?>
                      <div class="dropdown ms-1">
                        <button class="three-dots" data-bs-toggle="dropdown">
                          <i class="bi bi-three-dots"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                          <li>
                            <form action="comment_delete.php" method="POST"
                                  onsubmit="return confirm('Delete this comment?');">
                              <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                              <button class="dropdown-item text-danger">
                                <i class="bi bi-trash3 me-1"></i>Delete
                              </button>
                            </form>
                          </li>
                        </ul>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- COMMENT FORM -->
          <div class="comment-form mt-2">
            <form action="comment_add.php" method="POST">
              <input type="hidden" name="post_id" value="<?= $postId ?>">
              <div class="row g-2 align-items-center">
                <div class="col-9 col-md-10">
                  <textarea name="body" rows="1" class="form-control" placeholder="Add a comment..." required></textarea>
                </div>
                <div class="col-3 col-md-2 d-flex justify-content-end">
                  <button type="submit" class="btn-comment">
                    <i class="bi bi-arrow-right-short"></i>
                  </button>
                </div>
              </div>
            </form>
          </div>

        </article>
      <?php endforeach; ?>
    <?php endif; ?>

  </div> <!-- /dash-main -->
</div> <!-- /dash-shell -->

<!-- floating + for mobile -->
<button class="fab-post" data-bs-toggle="modal" data-bs-target="#composeModal">
  <i class="bi bi-plus-lg"></i>
</button>

<!-- COMPOSE MODAL -->
<div class="modal fade" id="composeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content new-post-box">
      <div class="modal-header">
        <h5 class="modal-title">NEW POST</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form action="post_create.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <input type="hidden" name="cat"    value="<?= htmlspecialchars($cat) ?>">
        <input type="hidden" name="q"      value="<?= htmlspecialchars($search) ?>">
        <div class="modal-body">
          <div class="mb-2">
            <input type="text" name="title" class="form-control form-control-sm"
                   placeholder="Title (optional, e.g. 'Client win', 'Today mission')">
          </div>
          <div class="mb-2 d-flex flex-wrap gap-2 align-items-center">
            <label class="form-check-label me-2" style="font-size:.78rem;">
              <input class="form-check-input" type="radio" name="category" value="post" checked> Post
            </label>
            <label class="form-check-label me-2" style="font-size:.78rem;">
              <input class="form-check-input" type="radio" name="category" value="highlight"> Highlight
            </label>
            <label class="form-check-label" style="font-size:.78rem;">
              <input class="form-check-input" type="radio" name="category" value="project"> Project
            </label>
          </div>

          <div class="mb-2">
            <input id="imageInput" type="file" name="images[]" multiple accept="image/*" class="d-none">
            <button type="button" class="image-btn" id="imageBtn">
              <i class="bi bi-image"></i><span>Add images</span>
            </button>
            <div id="imageInfo" style="font-size:.72rem;color:var(--text-muted);" class="mt-1">
              Up to 4 images (JPG, PNG, GIF, WEBP, max 5MB each).
            </div>
          </div>

          <div class="mb-2">
            <textarea name="body" rows="4" class="form-control"
                      placeholder="Share an insight, lesson, or mission for today..." required></textarea>
          </div>
          <div style="font-size:.72rem;color:var(--text-muted);">
            Write like you're talking to serious people. This is your mini social network.
          </div>
        </div>
        <div class="modal-footer d-flex justify-content-between align-items-center">
          <div></div>
          <button type="submit" class="btn-post-primary">
            <i class="bi bi-send-fill me-1"></i>Post
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* SHARE */
document.querySelectorAll('.share-btn').forEach(btn=>{
  btn.addEventListener('click', async ()=>{
    const id = btn.dataset.postId;
    const url = window.location.origin + window.location.pathname + '#post-' + id;
    const text = "Check this post in the portal: " + url;

    if (navigator.share){
      try{ await navigator.share({title:"Portal post",text,url}); }catch(e){}
    }else if(navigator.clipboard){
      try{
        await navigator.clipboard.writeText(url);
        alert("Link copied – send it anywhere.");
      }catch(e){ prompt("Copy this link:",url); }
    }else{
      prompt("Copy this link:",url);
    }
  });
});

/* IMAGE BUTTON */
const imageBtn  = document.getElementById('imageBtn');
const imageInput= document.getElementById('imageInput');
const imageInfo = document.getElementById('imageInfo');

if(imageBtn && imageInput){
  imageBtn.addEventListener('click', ()=> imageInput.click());
  imageInput.addEventListener('change', ()=>{
    if(!imageInput.files.length){
      imageInfo.textContent = "Up to 4 images (JPG, PNG, GIF, WEBP, max 5MB each).";
      return;
    }
    const names = Array.from(imageInput.files).map(f=>f.name).slice(0,4);
    imageInfo.textContent = "Selected: " + names.join(', ');
  });
}

/* USERNAME AUTOCOMPLETE */
const searchInput = document.getElementById('globalSearch');
const suggestBox  = document.getElementById('userSuggest');

let suggestTimer = null;

function hideSuggest(){ suggestBox.style.display='none'; suggestBox.innerHTML=''; }

if(searchInput && suggestBox){
  document.addEventListener('click', (e)=>{
    if(!suggestBox.contains(e.target) && e.target !== searchInput){ hideSuggest(); }
  });

  searchInput.addEventListener('input', ()=>{
    const val = searchInput.value.trim();
    if(!val.startsWith('@') || val.length < 4){ hideSuggest(); return; }

    const query = val.substring(1);
    clearTimeout(suggestTimer);
    suggestTimer = setTimeout(async ()=>{
      try{
        const res = await fetch('user_search.php?q='+encodeURIComponent(query));
        if(!res.ok) return;
        const data = await res.json();
        suggestBox.innerHTML = '';
        if(!data.users || !data.users.length){ hideSuggest(); return; }

        data.users.forEach(u=>{
          const item = document.createElement('div');
          item.className = 'user-suggest-item';
          item.innerHTML = `
            <img src="${u.avatar}" alt="">
            <div>
              <div class="user-suggest-username">@${u.username}</div>
              <div class="user-suggest-muted">${u.full_name}</div>
            </div>
          `;
          item.addEventListener('click', ()=>{
            // go to profile directly
            window.location.href = 'user_profile.php?user_id='+u.id;
          });
          suggestBox.appendChild(item);
        });
        suggestBox.style.display='block';
      }catch(e){
        hideSuggest();
      }
    }, 200);
  });
}

// FOLLOW / UNFOLLOW – AJAX + smooth UI
document.querySelectorAll('.follow-toggle').forEach(btn => {
  btn.addEventListener('click', async () => {
    const userId = btn.dataset.userId;
    if (!userId) return;

    const formData = new FormData();
    formData.append('user_id', userId);
    formData.append('ajax', '1');

    btn.disabled = true;

    try {
      const res  = await fetch('follow_toggle.php', {
        method: 'POST',
        body:   formData
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

        // Update followers count on profile if element exists
        const counter = document.querySelector(
          '[data-followers-count-for="'+ userId +'"]'
        );
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
</body>
</html>
