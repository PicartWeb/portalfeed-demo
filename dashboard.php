<?php
// dashboard.php — SPA-style mobile tabs + improved search

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId   = $_SESSION['user_id'] ?? 0;
$fullName = $_SESSION['full_name'] ?? $_SESSION['name'] ?? 'User';
$username = $_SESSION['username'] ?? 'account';
$role     = $_SESSION['role'] ?? 'member';
$avatar   = $_SESSION['avatar'] ?? null;

$isStaff        = in_array($role, ['owner','admin','moderator'], true);
$isAdminOrOwner = in_array($role, ['owner','admin'], true);

/* ========== Notifications ========== */
$notifUnreadCount = 0;
$dmUnreadCount = 0;
$notifications = [];

try {
    $notifUnreadCountStmt = $pdo->prepare("
      SELECT COUNT(*) FROM notifications
      WHERE to_user_id = :uid AND is_read = 0
    ");
    $notifUnreadCountStmt->execute([':uid' => $userId]);
    $notifUnreadCount = (int)$notifUnreadCountStmt->fetchColumn();

    $dmUnreadStmt = $pdo->prepare("
      SELECT COUNT(*) FROM notifications
      WHERE to_user_id = :uid AND type = 'message' AND is_read = 0
    ");
    $dmUnreadStmt->execute([':uid' => $userId]);
    $dmUnreadCount = (int)$dmUnreadStmt->fetchColumn();

    $notifStmt = $pdo->prepare("
      SELECT n.*, u.username, u.full_name, u.avatar
      FROM notifications n
      LEFT JOIN users u ON u.id = n.from_user_id
      WHERE n.to_user_id = :uid
      ORDER BY n.created_at DESC
      LIMIT 15
    ");
    $notifStmt->execute([':uid' => $userId]);
    $notifications = $notifStmt->fetchAll();
} catch (PDOException $e) {
    $notifUnreadCount = 0;
    $dmUnreadCount = 0;
    $notifications = [];
}

/* ========== Filters / Search ========== */
$filter = $_GET['filter'] ?? 'all';
$cat    = $_GET['cat'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$allowedFilters = ['all','following','staff','mine'];
$allowedCats    = ['all','post','highlight','project'];

if (!in_array($filter,$allowedFilters,true)) $filter='all';
if (!in_array($cat,$allowedCats,true))       $cat='all';

$searchMode = 'none';
$searchTerm = '';
if ($search !== '') {
    if ($search[0]==='@') {
        $searchMode='user';
        $searchTerm=substr($search,1);
    } elseif ($search[0]==='#') {
        $searchMode='tag';
        $searchTerm=substr($search,1);
    } else {
        $searchMode='text';
        $searchTerm=$search;
    }
}

$searchHint='';
if ($searchMode==='user' && $searchTerm) {
    $searchHint='Filtering by user @'.$searchTerm;
} elseif ($searchMode==='tag' && $searchTerm) {
    $searchHint='Filtering by hashtag #'.$searchTerm;
} elseif ($searchMode==='text' && $searchTerm) {
    $searchHint='Searching: “'.$searchTerm.'”';
}

/* ========== Feed query ========== */
$whereParts=[];
$params=[];

if ($cat!=='all') {
  $whereParts[]='p.category=:cat';
  $params[':cat']=$cat;
}
if ($filter==='following') {
  $whereParts[]='p.user_id IN (SELECT following_id FROM follows WHERE follower_id=:mef)';
  $params[':mef']=$userId;
} elseif ($filter==='staff') {
  $whereParts[]="u.role IN ('owner','admin','moderator')";
} elseif ($filter==='mine') {
  $whereParts[]='p.user_id=:mem';
  $params[':mem']=$userId;
}

if ($searchMode==='text' && $searchTerm) {
  $whereParts[]='(p.title LIKE :q OR p.body LIKE :q)';
  $params[':q']='%'.$searchTerm.'%';
} elseif ($searchMode==='tag' && $searchTerm) {
  $whereParts[]='(p.title LIKE :tq OR p.body LIKE :tq)';
  $params[':tq']='%#'.$searchTerm.'%';
} elseif ($searchMode==='user' && $searchTerm) {
  $whereParts[]='(u.username LIKE :uq OR u.full_name LIKE :uq)';
  $params[':uq']='%'.$searchTerm.'%';
}

$whereSQL = $whereParts ? 'WHERE '.implode(' AND ',$whereParts) : '';

$postSql = "
  SELECT p.id,p.title,p.body,p.is_pinned,p.user_id,p.created_at,p.category,
         u.username,u.full_name,u.avatar,u.role AS user_role,
         COALESCE(lc.like_count,0) AS like_count
  FROM posts p
  JOIN users u ON u.id=p.user_id
  LEFT JOIN (
    SELECT post_id,COUNT(*) AS like_count
    FROM post_likes GROUP BY post_id
  ) lc ON lc.post_id=p.id
  $whereSQL
  ORDER BY p.is_pinned DESC, p.created_at DESC
";
$stmt=$pdo->prepare($postSql);
$stmt->execute($params);
$posts=$stmt->fetchAll();

$postIds = array_column($posts,'id');
$inList  = $postIds ? implode(',',array_map('intval',$postIds)) : '0';

/* ========== Comments / Likes / Media ========== */
$commentsByPost=[];
if ($postIds) {
  $cStmt=$pdo->query("
    SELECT c.*,u.username,u.full_name,u.avatar
    FROM comments c
    JOIN users u ON u.id=c.user_id
    WHERE c.post_id IN ($inList)
    ORDER BY c.created_at ASC
  ");
  foreach($cStmt as $c){
    $commentsByPost[$c['post_id']][]=$c;
  }
}

$likedPosts=[];
if ($postIds) {
  $likeStmt=$pdo->prepare("
    SELECT post_id FROM post_likes
    WHERE user_id=:uid AND post_id IN ($inList)
  ");
  $likeStmt->execute([':uid'=>$userId]);
  foreach($likeStmt as $lp){
    $likedPosts[(int)$lp['post_id']]=true;
  }
}

$mediaByPost=[];
if ($postIds) {
  $mStmt=$pdo->query("
    SELECT id,post_id,path,type
    FROM post_media
    WHERE post_id IN ($inList)
    ORDER BY id ASC
  ");
  foreach($mStmt as $m){
    $mediaByPost[$m['post_id']][]=$m;
  }
}

/* ========== Following & counts ========== */
$followingMap=[];
$fs=$pdo->prepare("SELECT following_id FROM follows WHERE follower_id=:me");
$fs->execute([':me'=>$userId]);
foreach($fs as $f){
  $followingMap[(int)$f['following_id']]=true;
}

$followersCount=(int)$pdo->query("SELECT COUNT(*) FROM follows WHERE following_id=".(int)$userId)->fetchColumn();
$followingCount=(int)$pdo->query("SELECT COUNT(*) FROM follows WHERE follower_id=".(int)$userId)->fetchColumn();

/* ========== Preload discovery data (for Explore tab & right column) ========== */
$s = $pdo->prepare("
  SELECT id,full_name,username,avatar
  FROM users
  WHERE id!=:me
  ORDER BY RAND()
  LIMIT 6
");
$s->execute([':me'=>$userId]);
$suggestedUsers=$s->fetchAll();

$tags=$pdo->query("
  SELECT SUBSTRING_INDEX(SUBSTRING(body, LOCATE('#', body)), ' ', 1) AS tag
  FROM posts
  WHERE body LIKE '%#%'
  ORDER BY RAND()
  LIMIT 12
")->fetchAll();

try{
  $gStmt=$pdo->query("
    SELECT g.id,g.title,g.price_from,g.rating,u.full_name,u.avatar
    FROM gigs g
    JOIN users u ON u.id=g.user_id
    ORDER BY RAND()
    LIMIT 4
  ");
  $gigs=$gStmt->fetchAll();
}catch(Exception $e){ $gigs=[]; }

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
    LIMIT 5
  ");
  $groups=$grpStmt->fetchAll();
}catch(Exception $e){ $groups=[]; }
?>
<?php
$dashboardSearchHtml = <<<HTML
<form method="GET" autocomplete="off" class="app-shell-search-form">
  <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
  <input type="hidden" name="cat" value="<?= htmlspecialchars($cat) ?>">
  <div class="header-search-wrap">
    <i class="bi bi-search"></i>
    <input
      type="search"
      name="q"
      class="header-search-input global-search"
      placeholder="Search people, posts, or #tags"
      value="<?= htmlspecialchars($search) ?>">
    <div class="user-suggest-box" data-suggest-for="desktop"></div>
  </div>
</form>
HTML;

ob_start();
?>
<div class="dropdown">
  <button class="app-nav-link position-relative" data-bs-toggle="dropdown" type="button">
    <i class="bi <?= $notifUnreadCount ? 'bi-bell-fill' : 'bi-bell' ?>"></i>
    <span>Alerts</span>
    <?php if ($notifUnreadCount): ?>
      <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger rounded-circle"></span>
    <?php endif; ?>
  </button>
  <div class="dropdown-menu dropdown-menu-end app-dropdown">
    <div class="px-3 py-2 d-flex justify-content-between align-items-center">
      <span class="text-label">Notifications</span>
      <form action="notifications_mark_read.php" method="POST">
        <input type="hidden" name="redirect" value="dashboard.php">
        <button class="btn btn-link p-0 text-decoration-none" style="font-size:.72rem;color:var(--text-muted);">Mark all</button>
      </form>
    </div>
    <div style="max-height:260px;overflow-y:auto;">
      <?php if (empty($notifications)): ?>
        <div class="px-3 pb-3 small text-muted">
          Nothing yet. Likes, comments, follows and DMs will appear here.
        </div>
      <?php else: ?>
        <?php foreach($notifications as $n): ?>
          <?php
            $nName   = $n['full_name'] ?: $n['username'];
            $nAvatar = $n['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($nName).'&background=ffffff&color=0f172a';
            $isUnread = !$n['is_read'];
            $targetUrl='dashboard.php';
            if ($n['type']==='follow')   $targetUrl='user_profile.php?user_id='.(int)$n['from_user_id'];
            if ($n['type']==='message')  $targetUrl='messages.php?conversation_id='.(int)$n['ref_id'];
          ?>
          <a href="<?= htmlspecialchars($targetUrl) ?>" class="notification-row <?= $isUnread?'':'opacity-75' ?>">
            <img src="<?= htmlspecialchars($nAvatar) ?>" alt="" class="notification-avatar">
            <div class="flex-grow-1">
              <div style="font-size:.8rem;color:var(--text-soft);line-height:1.5;">
                <strong><?= htmlspecialchars($nName) ?></strong> <?= htmlspecialchars($n['message']) ?>
              </div>
              <div style="font-size:.72rem;color:var(--text-muted);">
                <?= htmlspecialchars(date('M j, H:i', strtotime($n['created_at']))) ?>
              </div>
            </div>
            <?php if ($isUnread): ?>
              <span class="notification-dot"></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$dashboardNotificationsHtml = ob_get_clean();

$dashboardExtraActionsHtml = '';
if ($isAdminOrOwner) {
    $dashboardExtraActionsHtml = '<a href="admin_dashboard.php" class="app-nav-link"><i class="bi bi-cpu-fill"></i><span>Admin</span></a>';
}
require_once __DIR__ . '/includes/app_shell.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard • Cyber Glass Portal</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app-shell.css">
<style>
:root{
  --bg:#f4f7fb;
  --bg-soft:#edf2f7;
  --panel:#ffffff;
  --panel-alt:#ffffff;
  --panel-muted:#f8fafc;
  --border:#e2e8f0;
  --border-soft:#e9eef6;
  --text:#0f172a;
  --text-soft:#334155;
  --text-muted:#64748b;
  --accent:#2563eb;
  --accent-strong:#1d4ed8;
  --accent-soft:#dbeafe;
  --accent-soft-2:#eff6ff;
  --danger:#dc2626;
  --shadow-sm:0 8px 24px rgba(15,23,42,.06);
  --shadow-md:0 18px 48px rgba(15,23,42,.08);
  --shadow-lg:0 24px 70px rgba(15,23,42,.12);
}

*{box-sizing:border-box;}
body{
  margin:0;
  background:var(--bg);
  color:var(--text);
  font-family:"Plus Jakarta Sans","Segoe UI",sans-serif;
  -webkit-font-smoothing:antialiased;
}
.app-shell{
  min-height:100vh;
  display:flex;
  flex-direction:column;
  background:
    radial-gradient(circle at top left, rgba(37,99,235,.08), transparent 28%),
    radial-gradient(circle at top right, rgba(59,130,246,.08), transparent 24%),
    linear-gradient(180deg, #f8fbff 0%, #f4f7fb 32%, #f4f7fb 100%);
}

/* -------- HEADER -------- */
.dash-top{
  position:sticky;top:0;z-index:60;
  padding:1rem 1.35rem;
  background:rgba(255,255,255,.92);
  border-bottom:1px solid rgba(226,232,240,.92);
  backdrop-filter:blur(18px);
}
.avatar-lg{
  width:44px;height:44px;border-radius:50%;object-fit:cover;
  border:2px solid #fff;
  box-shadow:0 0 0 1px rgba(37,99,235,.14);
}
.topbar-brand{
  display:flex;
  align-items:center;
  gap:.9rem;
  min-width:0;
}
.brand-mark{
  width:44px;
  height:44px;
  border-radius:14px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:linear-gradient(135deg,#2563eb,#1d4ed8);
  color:#fff;
  box-shadow:0 14px 28px rgba(37,99,235,.2);
  flex:0 0 auto;
}
.brand-copy-title{
  font-size:1rem;
  font-weight:800;
  letter-spacing:-.03em;
}
.brand-copy-sub{
  font-size:.76rem;
  color:var(--text-muted);
}
.btn-top{
  border-radius:999px;
  border:1px solid var(--border);
  background:#fff;
  color:var(--text-soft);
  font-size:.82rem;
  font-weight:600;
  padding:.58rem .95rem;
  display:inline-flex;
  align-items:center;
  gap:.42rem;
  text-decoration:none;
  transition:all .2s ease;
  box-shadow:0 2px 10px rgba(15,23,42,.03);
}
.btn-top:hover{
  border-color:#cbd5e1;
  background:#f8fbff;
  color:var(--text);
  transform:translateY(-1px);
}
.badge-role{
  display:inline-flex;align-items:center;gap:.25rem;
  font-size:.64rem;
  font-weight:700;
  padding:.2rem .75rem;
  border-radius:999px;
  border:1px solid #dbeafe;
  text-transform:uppercase;
  letter-spacing:.14em;
  color:var(--accent-strong);
  background:var(--accent-soft-2);
}

/* search desktop */
.header-search-desktop{max-width:500px;width:100%;flex:1;}
.header-search-wrap{position:relative;}
.header-search-wrap i{
  position:absolute;left:.82rem;top:50%;transform:translateY(-50%);
  color:var(--text-muted);font-size:.9rem;
}
.header-search-input{
  width:100%;
  border-radius:999px;
  border:1px solid var(--border);
  background:#f8fafc;
  color:var(--text-soft);
  font-size:.84rem;
  padding:.74rem 1rem .74rem 2.3rem;
  transition:all .2s ease;
}
.header-search-input:focus{
  outline:none;
  border-color:#bfdbfe;
  background:#fff;
  box-shadow:0 0 0 .18rem rgba(37,99,235,.1);
}

/* mobile search bar */
.mobile-search-bar{
  display:none;
  padding:.2rem 1rem .9rem;
  background:rgba(255,255,255,.92);
  border-bottom:1px solid rgba(226,232,240,.92);
}
.mobile-search-inner{position:relative;}
.mobile-search-inner i{
  position:absolute;left:.82rem;top:50%;transform:translateY(-50%);
  color:var(--text-muted);font-size:.9rem;
}
.mobile-search-input{
  width:100%;
  border-radius:999px;
  border:1px solid var(--border);
  background:#f8fafc;
  color:var(--text-soft);
  font-size:.85rem;
  padding:.78rem 1rem .78rem 2.2rem;
}

/* suggestions */
.user-suggest-box{
  position:absolute;left:0;right:0;top:100%;margin-top:.25rem;
  background:#fff;
  border-radius:18px;
  border:1px solid var(--border);
  box-shadow:var(--shadow-lg);
  max-height:260px;
  overflow-y:auto;
  z-index:90;
  display:none;
}
.user-suggest-item{
  display:flex;align-items:center;gap:.5rem;
  padding:.55rem .85rem;
  font-size:.8rem;
  cursor:pointer;
}
.user-suggest-item:hover{background:#f8fafc;}
.user-suggest-item img{
  width:30px;height:30px;border-radius:50%;object-fit:cover;
  border:1px solid #dbeafe;
}
.user-suggest-username{font-weight:700;color:var(--text);}
.user-suggest-muted{color:var(--text-muted);font-size:.74rem;}

/* -------- MAIN GRID (desktop) -------- */
.layout-grid{
  flex:1;
  width:100%;
  max-width:1360px;
  margin:0 auto;
  padding:1.35rem 1.25rem 5.5rem;
  display:grid;
  grid-template-columns:260px minmax(0,1fr) 300px;
  gap:1.1rem;
}

.left-column,
.center-column,
.right-column,
.card-glass,
.post-card,
.compose-card{
  min-width:0;
}

@media(max-width:1200px){
  .layout-grid{
    max-width:1100px;
    grid-template-columns:240px minmax(0,1fr);
    gap:1rem;
  }

  .right-column{
    grid-column:1 / -1;
    position:static;
  }
}

@media(max-width:992px){
  .layout-grid{
    max-width:100%;
    margin:0;
    padding:.95rem .8rem 5.8rem;
    display:block;
  }

  .left-column,
  .right-column{
    position:static;
  }
}

.card-glass{
  background:var(--panel-alt);
  border-radius:22px;
  border:1px solid var(--border-soft);
  padding:1.15rem;
  box-shadow:var(--shadow-sm);
}
.text-label{
  font-size:.72rem;
  font-weight:700;
  text-transform:uppercase;
  letter-spacing:.16em;
  color:var(--text-muted);
}
.section-title{
  font-size:1.06rem;
  font-weight:800;
  color:var(--text);
}
.section-subtitle{
  font-size:.86rem;
  color:var(--text-muted);
}

.left-column,
.right-column{
  position:sticky;
  top:96px;
  height:min-content;
}

@media(max-width:1200px){
  .right-column{
    position:static;
  }
}

@media(max-width:992px){
  .left-column,
  .right-column{
    position:static;
  }
}

/* -------- Stories -------- */
.stories-strip{
  display:flex;
  gap:.95rem;
  overflow-x:auto;
  padding:.15rem 0 .35rem;
  scrollbar-width:none;
}
.stories-strip::-webkit-scrollbar{display:none;}
.story-item{width:84px;flex:0 0 auto;text-align:center;}
.story-ring{
  padding:3px;
  border-radius:999px;
  background:linear-gradient(135deg,#60a5fa,#2563eb 55%,#93c5fd);
  box-shadow:0 12px 22px rgba(37,99,235,.18);
}
.story-inner{
  border-radius:999px;
  background:#fff;
  padding:3px;
}
.story-inner img{
  width:66px;height:66px;border-radius:50%;object-fit:cover;
}
.story-item .small{color:var(--text-soft);font-size:.75rem;font-weight:600;}

/* -------- Filters -------- */
.feed-controls{display:grid;gap:1rem;}
.filter-row-title{
  font-size:.82rem;
  font-weight:700;
  color:var(--text-soft);
  margin-bottom:.55rem;
}
.filter-pill{
  border-radius:999px;
  border:1px solid var(--border);
  background:#fff;
  color:var(--text-soft);
  font-size:.8rem;
  font-weight:600;
  padding:.52rem .95rem;
  text-decoration:none;
  transition:all .2s ease;
}
.filter-pill.active{
  background:var(--accent-soft-2);
  border-color:#bfdbfe;
  color:var(--accent-strong);
}
.filter-pill:hover{background:#f8fafc;color:var(--text);}

/* -------- Composer -------- */
.compose-card{padding:1.2rem;}
.compose-topline{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:1rem;
  margin-bottom:1rem;
}
.compose-trigger{display:flex;align-items:center;gap:.85rem;}
.compose-avatar{
  width:48px;
  height:48px;
  border-radius:50%;
  object-fit:cover;
  border:2px solid #fff;
  box-shadow:0 0 0 1px rgba(37,99,235,.14);
}
.compose-input-fake{
  flex:1;
  border-radius:16px;
  border:1px solid var(--border);
  background:var(--panel-muted);
  color:var(--text-muted);
  padding:.95rem 1rem;
  font-size:.92rem;
}
.compose-actions{
  display:flex;
  flex-wrap:wrap;
  gap:.65rem;
  padding-top:.9rem;
  border-top:1px solid var(--border-soft);
}
.compose-chip{
  border:none;
  border-radius:999px;
  background:#eff6ff;
  color:var(--accent-strong);
  padding:.58rem .9rem;
  font-size:.79rem;
  font-weight:700;
  display:inline-flex;
  align-items:center;
  gap:.42rem;
}

/* -------- Posts -------- */
.post-card{
  background:var(--panel-alt);
  border-radius:24px;
  border:1px solid var(--border-soft);
  padding:1.2rem 1.2rem 1rem;
  margin-bottom:1rem;
  box-shadow:var(--shadow-sm);
}
.post-header{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:.8rem;
  margin-bottom:.8rem;
}
.post-author-row{
  display:flex;
  gap:.8rem;
  align-items:flex-start;
}
.post-author-avatar{
  width:46px;
  height:46px;
  border-radius:50%;
  object-fit:cover;
  border:2px solid #fff;
  box-shadow:0 0 0 1px rgba(37,99,235,.12);
}
.post-author-name{
  font-size:.98rem;
  font-weight:700;
  color:var(--text);
}
.post-header-meta{
  font-size:.8rem;
  color:var(--text-muted);
}
.post-badge{
  border-radius:999px;
  border:1px solid #dbeafe;
  background:var(--accent-soft-2);
  padding:.22rem .58rem;
  font-size:.68rem;
  font-weight:700;
  color:var(--accent-strong);
}
.post-title{font-weight:700;font-size:1.06rem;line-height:1.4;color:var(--text);}
.post-body-text{font-size:.93rem;color:var(--text-soft);line-height:1.75;white-space:pre-wrap;}
.post-media img{
  max-width:100%;
  max-height:320px;
  border-radius:18px;
  border:1px solid var(--border);
  object-fit:cover;
  box-shadow:var(--shadow-sm);
}

/* actions */
.post-stats{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:1rem;
  padding:.95rem 0 .7rem;
  font-size:.82rem;
  color:var(--text-muted);
}
.post-actions{
  border-top:1px solid var(--border-soft);
  padding-top:.7rem;
  display:flex;
  justify-content:space-between;
  gap:.6rem;
}
.post-actions form{flex:1;}
.post-action-btn{
  width:100%;
  border:none;background:none;color:var(--text-soft);
  font-size:.84rem;
  font-weight:700;
  display:inline-flex;align-items:center;justify-content:center;gap:.42rem;
  border-radius:12px;
  padding:.65rem .75rem;
  transition:all .2s ease;
}
.post-action-btn:hover{background:#f8fafc;}
.post-action-btn.liked{color:var(--accent-strong);background:var(--accent-soft-2);}

/* comments */
.comments-stack{margin-top:.95rem;display:grid;gap:.7rem;}
.comment-row{display:flex;gap:.65rem;align-items:flex-start;}
.comment-avatar{
  width:32px;
  height:32px;
  border-radius:50%;
  object-fit:cover;
  border:1px solid #dbeafe;
}
.comment-bubble{
  flex:1;
  background:var(--panel-muted);
  border-radius:18px;
  border:1px solid var(--border);
  padding:.72rem .85rem;
  font-size:.82rem;
  color:var(--text-soft);
}
.comment-meta{font-size:.74rem;color:var(--text-muted);margin-bottom:.2rem;font-weight:600;}

/* comment form */
.comment-form-row{margin-top:.9rem;display:flex;gap:.65rem;}
.comment-form{
  background:var(--panel-muted);
  border-radius:16px;
  border:1px solid var(--border);
  color:var(--text-soft);
  font-size:.83rem;
  resize:none;
  min-height:46px;
  padding:.72rem .9rem;
}
.comment-form:focus{
  border-color:#bfdbfe;
  box-shadow:0 0 0 .18rem rgba(37,99,235,.1);
  background:#fff;
}
.profile-summary{padding:1.25rem;}
.profile-cover{
  height:88px;
  border-radius:18px;
  background:linear-gradient(135deg,#dbeafe,#eff6ff 55%,#ffffff);
  border:1px solid #e0ecff;
  margin-bottom:-2.25rem;
}
.profile-summary-head{position:relative;}
.profile-summary-avatar{
  width:72px;height:72px;border-radius:50%;object-fit:cover;
  border:4px solid #fff;
  box-shadow:var(--shadow-md);
}
.stat-grid{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:.7rem;
  margin:1.15rem 0;
}
.stat-box{
  background:var(--panel-muted);
  border:1px solid var(--border);
  border-radius:16px;
  padding:.9rem .55rem;
  text-align:center;
}
.stat-box strong{
  display:block;
  font-size:1.05rem;
  color:var(--text);
}
.stat-box span{
  font-size:.72rem;
  color:var(--text-muted);
  text-transform:uppercase;
  letter-spacing:.08em;
}
.nav-list{display:grid;gap:.45rem;}
.nav-link-soft{
  display:flex;
  align-items:center;
  gap:.75rem;
  padding:.82rem .92rem;
  border-radius:14px;
  text-decoration:none;
  color:var(--text-soft);
  font-size:.86rem;
  font-weight:600;
  border:1px solid transparent;
}
.nav-link-soft:hover{
  background:#f8fafc;
  border-color:var(--border);
  color:var(--text);
}
.completion-bar{
  height:8px;
  border-radius:999px;
  background:#e2e8f0;
  overflow:hidden;
}
.completion-bar span{
  display:block;
  width:60%;
  height:100%;
  border-radius:999px;
  background:linear-gradient(90deg,#60a5fa,#2563eb);
}
.widget-list{display:grid;gap:.9rem;}
.widget-item{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:.85rem;
}
.widget-person{
  display:flex;
  align-items:center;
  gap:.75rem;
  min-width:0;
}
.widget-avatar{
  width:42px;
  height:42px;
  border-radius:50%;
  object-fit:cover;
  border:1px solid #dbeafe;
}
.widget-name{
  font-size:.86rem;
  font-weight:700;
  color:var(--text);
}
.widget-meta{
  font-size:.75rem;
  color:var(--text-muted);
}
.tag-chip{
  display:inline-flex;
  align-items:center;
  padding:.48rem .75rem;
  border-radius:999px;
  background:var(--panel-muted);
  border:1px solid var(--border);
  color:var(--accent-strong);
  text-decoration:none;
  font-size:.78rem;
  font-weight:700;
}
.group-suggestion{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:.8rem;
  padding:.9rem 0;
  border-top:1px solid var(--border-soft);
}
.group-suggestion:first-child{
  border-top:none;
  padding-top:.1rem;
}
.soft-button{
  border-radius:12px;
  border:1px solid var(--border);
  background:#fff;
  color:var(--text-soft);
  font-size:.8rem;
  font-weight:700;
  padding:.55rem .8rem;
}
.soft-button:hover{background:#f8fafc;}
.primary-button{
  border:none;
  border-radius:14px;
  background:linear-gradient(135deg,#2563eb,#1d4ed8);
  color:#fff;
  font-size:.84rem;
  font-weight:700;
  padding:.78rem 1rem;
  box-shadow:0 14px 28px rgba(37,99,235,.18);
}
.primary-button:hover{background:linear-gradient(135deg,#1d4ed8,#1e40af);}
.dropdown-menu.app-dropdown{
  min-width:300px;
  background:#fff;
  border-radius:20px;
  border:1px solid var(--border);
  box-shadow:var(--shadow-lg);
  padding:.45rem;
}
.notification-row{
  display:flex;
  align-items:flex-start;
  gap:.75rem;
  padding:.7rem;
  border-radius:16px;
  text-decoration:none;
  color:inherit;
}
.notification-row:hover{background:#f8fafc;}
.notification-avatar{
  width:34px;
  height:34px;
  border-radius:50%;
  object-fit:cover;
  border:1px solid #dbeafe;
}
.notification-dot{
  width:8px;
  height:8px;
  border-radius:50%;
  background:var(--accent);
  margin-top:.35rem;
}
.modal-content{
  background:#fff;
  border-radius:24px;
  border:1px solid var(--border);
  box-shadow:var(--shadow-lg);
}
.modal .form-control{
  border-radius:16px;
  border:1px solid var(--border);
  background:#f8fafc;
  color:var(--text-soft);
  padding:.85rem .95rem;
}
.modal .form-control:focus{
  border-color:#bfdbfe;
  box-shadow:0 0 0 .18rem rgba(37,99,235,.1);
  background:#fff;
}
.modal .btn-outline-info{
  color:var(--accent-strong);
  border-color:#bfdbfe;
}
.modal .btn-outline-info:hover{
  background:#eff6ff;
  color:var(--accent-strong);
  border-color:#bfdbfe;
}

/* ------- MOBILE TABS ------- */
.mobile-tab{display:block;}
@media(max-width:992px){
  .mobile-tab{display:none;}
  .mobile-tab.active{display:block;}
  .mobile-tab.slide-in-right{animation:slideInRight .25s ease-out;}
  .mobile-tab.slide-in-left{animation:slideInLeft .25s ease-out;}
}
@keyframes slideInRight{
  from{opacity:0;transform:translateX(20px);}
  to{opacity:1;transform:translateX(0);}
}
@keyframes slideInLeft{
  from{opacity:0;transform:translateX(-20px);}
  to{opacity:1;transform:translateX(0);}
}

/* bottom nav */
.bottom-nav{
  position:fixed;
  left:0;right:0;bottom:0;
  height:72px;
  background:rgba(255,255,255,.96);
  border-top:1px solid rgba(226,232,240,.96);
  display:none;
  z-index:70;
  backdrop-filter:blur(18px);
  box-shadow:0 -10px 30px rgba(15,23,42,.06);
}
.bottom-nav button{
  flex:1;
  border:none;
  background:none;
  color:var(--text-soft);
  font-size:.75rem;
  font-weight:700;
  padding-top:.35rem;
}
.bottom-nav i{display:block;font-size:1.2rem;}
.bottom-nav button.active{color:var(--accent-strong);}
@media(max-width:992px){
  .bottom-nav{display:flex;}
}

/* FAB */
.fab-post{
  position:fixed;
  right:1rem;
  bottom:5.3rem;
  width:58px;height:58px;
  border-radius:999px;
  border:none;
  background:linear-gradient(135deg,#2563eb,#1d4ed8);
  color:#fff;
  font-size:1.4rem;
  display:none;
  align-items:center;
  justify-content:center;
  box-shadow:0 18px 34px rgba(37,99,235,.24);
  z-index:70;
}
@media(max-width:992px){
  .fab-post{display:flex;}
}

/* utilities */
@media(max-width:576px){
  .dash-top{padding:.82rem .8rem;}
  .card-glass,.post-card,.compose-card{padding:1rem;}
  .brand-copy{display:none;}
}
@media(max-width:992px){
  .post-actions{gap:.4rem;}
  .post-action-btn{
    font-size:.78rem;
    padding:.6rem .45rem;
  }
}
.stat-grid{
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.stat-box{
  min-width: 0;
  padding: .85rem .35rem;
}

.stat-box strong{
  font-size: .95rem;
}

.stat-box span{
  font-size: .58rem;
  letter-spacing: .05em;
  white-space: nowrap;
}
</style>
</head>
<?php render_app_shell_start(['active' => 'dashboard', 'context_label' => 'Feed', 'context_title' => 'Portal Feed', 'context_subtitle' => 'A clean social workspace for your network and work.', 'search_html' => $dashboardSearchHtml, 'notifications_html' => $dashboardNotificationsHtml, 'extra_actions_html' => $dashboardExtraActionsHtml, 'messages_badge' => $dmUnreadCount]); ?>
<div class=\

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
          placeholder="Search people, posts, or #tags"
          value="<?= htmlspecialchars($search) ?>">
        <div class="user-suggest-box" data-suggest-for="mobile"></div>
      </div>
    </form>
  </div>

  <!-- ============ MAIN GRID ============ -->
  <main class="layout-grid">

    <!-- LEFT COLUMN (desktop only) -->
    <aside class="left-column d-none d-lg-block">
      <div class="card-glass profile-summary mb-3">
        <div class="profile-cover"></div>
        <div class="profile-summary-head">
          <img
            src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name='.urlencode($fullName).'&background=ffffff&color=0f172a&rounded=true' ?>"
            class="profile-summary-avatar">
        </div>
        <div class="mt-2">
          <div style="font-size:1.08rem;font-weight:800;"><?= htmlspecialchars($fullName) ?></div>
          <div style="font-size:.84rem;color:var(--text-muted);">@<?= htmlspecialchars($username) ?></div>
          <div class="mt-2"><span class="badge-role"><?= htmlspecialchars($role) ?></span></div>
        </div>
        <div class="stat-grid">
          <div class="stat-box"><strong><?= count($posts) ?></strong><span>Posts</span></div>
          <div class="stat-box"><strong><?= $followersCount ?></strong><span>Followers</span></div>
          <div class="stat-box"><strong><?= $followingCount ?></strong><span>Following</span></div>
        </div>
        <div class="d-grid gap-2">
          <button class="primary-button" data-bs-toggle="modal" data-bs-target="#composeModal">
            <i class="bi bi-pencil-square me-1"></i>Create post
          </button>
          <a href="create_gig.php" class="soft-button text-decoration-none text-center">
            <i class="bi bi-briefcase me-1"></i>Create gig
          </a>
        </div>
        <div class="nav-list mt-3">
          <a href="dashboard.php" class="nav-link-soft"><i class="bi bi-house-door-fill"></i>Home feed</a>
          <a href="messages.php" class="nav-link-soft"><i class="bi bi-chat-dots-fill"></i>Messages</a>
          <a href="groups.php" class="nav-link-soft"><i class="bi bi-people-fill"></i>Groups</a>
          <a href="settings.php" class="nav-link-soft"><i class="bi bi-gear-fill"></i>Settings</a>
        </div>
        <div class="mt-4">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span style="font-size:.8rem;font-weight:700;color:var(--text-soft);">Profile completion</span>
            <span style="font-size:.74rem;color:var(--text-muted);">60%</span>
          </div>
          <div class="completion-bar"><span></span></div>
        </div>
      </div>
    </aside>

    <!-- CENTER COLUMN (mobile tabs live here) -->
    <section class="center-column">

      <!-- FEED TAB (tab-feed) -->
      <div id="tab-feed" class="mobile-tab active">

        <!-- stories -->
        <div class="card-glass mb-3">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
              <div class="section-title">Today&apos;s feed</div>
              <div class="section-subtitle">Follow updates, highlights, and projects from your network.</div>
            </div>
            <?php if ($searchHint): ?>
              <div style="font-size:.8rem;color:var(--accent-strong);background:var(--accent-soft-2);border:1px solid #dbeafe;border-radius:999px;padding:.45rem .8rem;">
                <?= htmlspecialchars($searchHint) ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="stories-strip">
            <div class="story-item">
              <div class="story-ring">
                <div class="story-inner">
                  <img src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name='.urlencode($fullName).'&background=ffffff&color=0f172a&rounded=true' ?>">
                </div>
              </div>
              <div class="small mt-1">Your update</div>
            </div>
            <?php for($i=1;$i<=7;$i++): ?>
              <div class="story-item">
                <div class="story-ring">
                  <div class="story-inner">
                    <img src="https://i.pravatar.cc/80?img=<?= $i ?>">
                  </div>
                </div>
                <div class="small mt-1">Creator <?= $i ?></div>
              </div>
            <?php endfor; ?>
          </div>
        </div>

        <!-- filters -->
        <div class="card-glass mb-3">
          <div class="feed-controls">
            <div>
              <div class="filter-row-title">Feed view</div>
              <div class="d-flex flex-wrap gap-2">
                <?php
                $filters=['all'=>'All','following'=>'Following','staff'=>'Staff','mine'=>'My posts'];
                foreach($filters as $key=>$label):
                ?>
                  <a href="dashboard.php?filter=<?= $key ?>&cat=<?= $cat ?>&q=<?= urlencode($search) ?>"
                     class="filter-pill <?= $filter===$key?'active':'' ?>">
                    <?= htmlspecialchars($label) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
            <div>
              <div class="filter-row-title">Content type</div>
              <div class="d-flex flex-wrap gap-2">
                <?php
                $cats=['all'=>'All types','post'=>'Posts','highlight'=>'Highlights','project'=>'Projects'];
                foreach($cats as $key=>$label):
                ?>
                  <a href="dashboard.php?filter=<?= $filter ?>&cat=<?= $key ?>&q=<?= urlencode($search) ?>"
                     class="filter-pill <?= $cat===$key?'active':'' ?>">
                    <?= htmlspecialchars($label) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- composer -->
        <div class="card-glass compose-card mb-3" data-bs-toggle="modal" data-bs-target="#composeModal" style="cursor:pointer;">
          <div class="compose-topline">
            <div>
              <div class="section-title">Create a post</div>
              <div class="section-subtitle">Share an update, highlight, or project progress.</div>
            </div>
            <div class="badge-role">New</div>
          </div>
          <div class="compose-trigger">
            <img
              src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name='.urlencode($fullName).'&background=ffffff&color=0f172a&rounded=true' ?>"
              class="compose-avatar">
            <div class="compose-input-fake">What’s on your mind?</div>
          </div>
          <div class="compose-actions">
            <span class="compose-chip"><i class="bi bi-image"></i>Add media</span>
            <span class="compose-chip"><i class="bi bi-stars"></i>Highlight work</span>
            <span class="compose-chip"><i class="bi bi-kanban"></i>Share a project</span>
          </div>
        </div>

        <!-- FEED AREA -->
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
              $authorAvatar = $post['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($authorName).'&background=ffffff&color=0f172a&rounded=true';
              $isOwnerPost  = ($post['user_id']==$userId);
              $postComments = $commentsByPost[$postId] ?? [];
              $likeCount    = (int)$post['like_count'];
              $userLiked    = isset($likedPosts[$postId]);
              $authorFollowed = isset($followingMap[(int)$post['user_id']]);
            ?>
            <article class="post-card" id="post-<?= $postId ?>">

              <div class="post-header">
                <div class="post-author-row">
                  <a href="user_profile.php?user_id=<?= (int)$post['user_id'] ?>" class="text-decoration-none">
                    <img src="<?= htmlspecialchars($authorAvatar) ?>" alt=""
                         class="post-author-avatar">
                  </a>
                  <div>
                    <a href="user_profile.php?user_id=<?= (int)$post['user_id'] ?>"
                       class="text-decoration-none post-author-name">
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

                <div class="d-flex align-items-start gap-2">
                  <?php if (!$isOwnerPost): ?>
                    <button
                      class="btn btn-sm <?= $authorFollowed?'btn-info text-dark':'btn-outline-info' ?> follow-toggle"
                      data-user-id="<?= (int)$post['user_id'] ?>"
                      data-following="<?= $authorFollowed?'1':'0' ?>">
                      <i class="bi <?= $authorFollowed?'bi-person-check-fill':'bi-person-plus' ?>"></i>
                    </button>
                  <?php endif; ?>

                  <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-toggle="dropdown">
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
                              <input type="hidden" name="pin" value="<?= $post['is_pinned']?'0':'1' ?>">
                              <button class="dropdown-item">
                                <i class="bi bi-pin-angle-fill me-1"></i>
                                <?= $post['is_pinned']?'Unpin post':'Pin to top' ?>
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

              <?php if ($isOwnerPost || $isStaff): ?>
                <div class="collapse mt-2" id="editPost<?= $postId ?>">
                  <form action="post_update.php" method="POST">
                    <input type="hidden" name="post_id" value="<?= $postId ?>">
                    <textarea name="body" rows="3" class="form-control mb-2"
                              style="background:#f8fafc;color:var(--text-soft);border:1px solid var(--border);">
<?= htmlspecialchars($post['body']) ?></textarea>
                    <button class="btn btn-sm btn-primary rounded-pill px-3">
                      <i class="bi bi-check2-circle me-1"></i>Save
                    </button>
                  </form>
                </div>
              <?php endif; ?>

              <div class="post-stats">
                <span><?= $likeCount ?> likes</span>
                <span><?= count($postComments) ?> comments</span>
              </div>

              <div class="post-actions">
                <form action="post_like_toggle.php" method="POST">
                  <input type="hidden" name="post_id" value="<?= $postId ?>">
                  <button class="post-action-btn <?= $userLiked?'liked':'' ?>" type="submit">
                    <i class="bi <?= $userLiked?'bi-heart-fill':'bi-heart' ?>"></i>
                    <span><?= $likeCount ?></span>
                  </button>
                </form>
                <button class="post-action-btn" type="button">
                  <i class="bi bi-chat-left-text"></i><span><?= count($postComments) ?></span>
                </button>
                <button class="post-action-btn share-btn" type="button" data-post-id="<?= $postId ?>">
                  <i class="bi bi-send"></i><span>Share</span>
                </button>
              </div>

              <div class="comments-stack">
                <?php foreach($postComments as $c): ?>
                  <?php
                    $cName=$c['full_name'] ?: $c['username'];
                    $cAv  =$c['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($cName).'&background=ffffff&color=0f172a&rounded=true';
                    $canDeleteComment = ($c['user_id']==$userId) || $isStaff;
                  ?>
                  <div class="comment-row">
                    <img src="<?= htmlspecialchars($cAv) ?>" alt=""
                         class="comment-avatar">
                    <div class="comment-bubble">
                      <div class="comment-meta">
                        <?= htmlspecialchars($cName) ?> • <?= date('M j, H:i', strtotime($c['created_at'])) ?>
                      </div>
                      <div><?= nl2br(htmlspecialchars($c['body'])) ?></div>
                    </div>
                    <?php if ($canDeleteComment): ?>
                      <form action="comment_delete.php" method="POST"
                            onsubmit="return confirm('Delete this comment?');">
                        <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-sm text-danger" type="submit"><i class="bi bi-trash"></i></button>
                      </form>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>

              <form action="comment_add.php" method="POST" class="mt-2">
                <input type="hidden" name="post_id" value="<?= $postId ?>">
                <div class="comment-form-row">
                  <textarea name="body" rows="1" class="form-control comment-form"
                            placeholder="Write a comment..." required></textarea>
                  <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3">
                    <i class="bi bi-arrow-right-short"></i>
                  </button>
                </div>
              </form>

            </article>
          <?php endforeach; ?>
        <?php endif; ?>

        </div><!-- /feedArea -->
      </div><!-- /tab-feed -->

      <!-- EXPLORE TAB (mobile only) -->
      <div id="tab-explore" class="mobile-tab">
        <div class="card-glass mb-3">
          <div class="section-title mb-1">Explore</div>
          <div class="section-subtitle">Discover people, tags, and gigs worth following.</div>
        </div>

        <div class="card-glass mb-3">
          <div class="text-label mb-3">People to follow</div>
          <div class="widget-list">
          <?php foreach($suggestedUsers as $u): ?>
            <?php $alreadyFollow = isset($followingMap[(int)$u['id']]); ?>
            <div class="widget-item">
              <div class="widget-person">
                <img src="<?= $u['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($u['full_name']).'&background=ffffff&color=0f172a&rounded=true' ?>"
                     class="widget-avatar">
                <div>
                  <div class="widget-name"><?= htmlspecialchars($u['full_name']) ?></div>
                  <div class="widget-meta">@<?= htmlspecialchars($u['username']) ?></div>
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
        </div>

        <div class="card-glass mb-3">
          <div class="text-label mb-3">Trending tags</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach($tags as $tg):
              $tag=ltrim($tg['tag'],'#'); if(!$tag) continue; ?>
              <a href="dashboard.php?filter=all&cat=all&q=%23<?= urlencode($tag) ?>"
                 class="tag-chip">
                #<?= htmlspecialchars($tag) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card-glass mb-3">
          <div class="text-label mb-2">Featured gigs</div>
          <?php foreach($gigs as $gig): ?>
            <div class="mb-3 small">
              <div class="d-flex gap-2">
                <img src="<?= $gig['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($gig['full_name']).'&background=ffffff&color=0f172a&rounded=true' ?>"
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

      </div><!-- /tab-explore -->

      <!-- GROUPS TAB (mobile) -->
      <div id="tab-groups" class="mobile-tab">
        <div class="card-glass mb-3">
          <span class="text-label">Groups</span>
        </div>
        <div class="card-glass mb-3">
          <?php foreach($groups as $gr): ?>
            <div class="d-flex justify-content-between align-items-center mb-2 small">
              <div>
                <div class="fw-semibold"><?= htmlspecialchars($gr['name']) ?></div>
                <div class="text-muted"><?= (int)$gr['members_count'] ?> members</div>
              </div>
              <a href="group_join.php?id=<?= (int)$gr['id'] ?>" class="btn btn-sm btn-info text-dark">Join</a>
            </div>
          <?php endforeach; ?>
          <a href="groups.php" class="btn btn-outline-info btn-sm w-100 mt-1">
            Open full groups page
          </a>
        </div>
      </div>

      <!-- PROFILE TAB (mobile) -->
      <div id="tab-profile" class="mobile-tab">
        <div class="card-glass mb-3">
          <span class="text-label">Your profile</span>
        </div>
        <div class="card-glass mb-3">
          <div class="d-flex align-items-center gap-2">
            <img
              src="<?= $avatar ? htmlspecialchars($avatar) : 'https://ui-avatars.com/api/?name='.urlencode($fullName).'&background=ffffff&color=0f172a&rounded=true' ?>"
              style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--accent-strong);">
            <div>
              <div style="font-size:1rem;font-weight:600;"><?= htmlspecialchars($fullName) ?></div>
              <div style="font-size:.82rem;color:var(--text-muted);">@<?= htmlspecialchars($username) ?></div>
              <div class="mt-1">
                <span class="badge-role"><?= htmlspecialchars($role) ?></span>
              </div>
            </div>
          </div>
          <div class="d-flex justify-content-between text-center mt-3 small">
            <div><strong><?= count($posts) ?></strong><br><span class="text-muted">Posts</span></div>
            <div><strong><?= $followersCount ?></strong><br><span class="text-muted">Followers</span></div>
            <div><strong><?= $followingCount ?></strong><br><span class="text-muted">Following</span></div>
          </div>
          <div class="mt-3 d-grid gap-2">
            <button class="btn btn-sm btn-info text-dark" data-bs-toggle="modal" data-bs-target="#composeModal">
              <i class="bi bi-plus-circle me-1"></i>New post
            </button>
            <a href="settings.php" class="btn btn-sm btn-outline-info">Profile settings</a>
          </div>
        </div>
      </div>

    </section><!-- /center-column -->
    <!-- RIGHT COLUMN (desktop only, same data as explore tab) -->
    <aside class="right-column d-none d-lg-block">

      <div class="card-glass mb-3">
        <div class="text-label mb-2">People to follow</div>
        <?php foreach($suggestedUsers as $u): ?>
          <?php $alreadyFollow = isset($followingMap[(int)$u['id']]); ?>
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="d-flex align-items-center gap-2">
              <img src="<?= $u['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($u['full_name']).'&background=ffffff&color=0f172a&rounded=true' ?>"
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

      <div class="card-glass mb-3">
        <div class="text-label mb-2">Trending tags</div>
        <div class="d-flex flex-wrap gap-2 small">
          <?php foreach($tags as $tg):
            $tag=ltrim($tg['tag'],'#'); if(!$tag) continue; ?>
            <a href="dashboard.php?filter=all&cat=all&q=%23<?= urlencode($tag) ?>"
               class="tag-chip">
              #<?= htmlspecialchars($tag) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card-glass mb-3">
        <div class="text-label mb-2">Featured gigs</div>
        <?php foreach($gigs as $gig): ?>
          <div class="mb-3 small">
            <div class="d-flex gap-2">
              <img src="<?= $gig['avatar'] ?: 'https://ui-avatars.com/api/?name='.urlencode($gig['full_name']).'&background=ffffff&color=0f172a&rounded=true' ?>"
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

      <div class="card-glass">
        <div class="text-label mb-2">Groups you may like</div>
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

  </main><!-- /layout-grid -->

  <!-- MOBILE BOTTOM NAV (tabs) -->
  <nav class="bottom-nav d-lg-none" id="bottomNav">
    <button data-tab="feed" class="active">
      <i class="bi bi-house-fill"></i>Feed
    </button>
    <button data-tab="explore">
      <i class="bi bi-compass-fill"></i>Explore
    </button>
    <button data-tab="groups">
      <i class="bi bi-people-fill"></i>Groups
    </button>
    <button data-tab="profile">
      <i class="bi bi-person-circle"></i>Profile
    </button>
  </nav>

  <!-- FAB -->
  <button class="fab-post d-lg-none" data-bs-toggle="modal" data-bs-target="#composeModal">
    <i class="bi bi-plus-lg"></i>
  </button>

  <!-- COMPOSE MODAL -->
  <div class="modal fade" id="composeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header border-0">
          <h5 class="modal-title text-label">Create post</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
              <input type="file" id="imageInput" name="images[]" multiple accept="image/*" class="d-none">
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
            <button type="submit" class="btn btn-primary rounded-pill px-4">
              <i class="bi bi-send-fill me-1"></i>Post
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div><!-- /app-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// mobile search toggle
const mobileSearchToggle=document.getElementById('mobileSearchToggle');
const mobileSearchBar=document.getElementById('mobileSearchBar');
if(mobileSearchToggle && mobileSearchBar){
  mobileSearchToggle.addEventListener('click',()=>{
    mobileSearchBar.style.display = mobileSearchBar.style.display==='block' ? 'none':'block';
  });
}

// share
document.querySelectorAll('.share-btn').forEach(btn=>{
  btn.addEventListener('click',async()=>{
    const id=btn.dataset.postId;
    const url=location.origin+location.pathname+'#post-'+id;
    if(navigator.share){
      try{await navigator.share({title:'Portal post',url});return;}catch(e){}
    }
    try{
      await navigator.clipboard.writeText(url);
      alert('Link copied to clipboard.');
    }catch(e){
      prompt('Copy this link:',url);
    }
  });
});

// follow ajax
document.querySelectorAll('.follow-toggle').forEach(btn=>{
  btn.addEventListener('click',async()=>{
    const userId=btn.dataset.userId;
    const fd=new FormData();
    fd.append('user_id',userId);
    fd.append('ajax','1');
    btn.disabled=true;
    try{
      const res=await fetch('follow_toggle.php',{method:'POST',body:fd});
      const data=await res.json();
      if(data.status==='ok'){
        if(data.following){
          btn.classList.remove('btn-outline-info');
          btn.classList.add('btn-info','text-dark');
          btn.dataset.following='1';
          btn.innerHTML='<i class="bi bi-person-check-fill"></i>';
        }else{
          btn.classList.remove('btn-info','text-dark');
          btn.classList.add('btn-outline-info');
          btn.dataset.following='0';
          btn.innerHTML='<i class="bi bi-person-plus"></i>';
        }
      }
    }catch(e){console.error(e);}
    btn.disabled=false;
  });
});

// image picker
const imageBtn=document.getElementById('imageBtn');
const imageInput=document.getElementById('imageInput');
const imageInfo=document.getElementById('imageInfo');
if(imageBtn && imageInput){
  imageBtn.addEventListener('click',()=>imageInput.click());
  imageInput.addEventListener('change',()=>{
    if(!imageInput.files.length){
      imageInfo.textContent='Up to 4 images (JPG, PNG, WEBP, max 5MB each).';
      return;
    }
    const names=Array.from(imageInput.files).slice(0,4).map(f=>f.name);
    imageInfo.textContent='Selected: '+names.join(', ');
  });
}

// search autocomplete (name or @username)
const searchInputs=document.querySelectorAll('.global-search');
let suggestTimer=null;

function attachSuggest(input){
  const form=input.closest('form');
  const wrapper=form.querySelector('.user-suggest-box');
  if(!wrapper)return;

  function hide(){
    wrapper.style.display='none';
    wrapper.innerHTML='';
  }

  document.addEventListener('click',e=>{
    if(!wrapper.contains(e.target) && e.target!==input) hide();
  });

  input.addEventListener('input',()=>{
    const val=input.value.trim();
    if(val.length<2){hide();return;}
    const q=val[0]==='@' ? val.substring(1) : val;

    clearTimeout(suggestTimer);
    suggestTimer=setTimeout(async()=>{
      try{
        const res=await fetch('user_search.php?q='+encodeURIComponent(q));
        if(!res.ok)return;
        const data=await res.json();
        wrapper.innerHTML='';
        if(!data.users || !data.users.length){hide();return;}

        data.users.forEach(u=>{
          const item=document.createElement('div');
          item.className='user-suggest-item';
          item.innerHTML=`
            <img src="${u.avatar}" alt="">
            <div>
              <div class="user-suggest-username">@${u.username}</div>
              <div class="user-suggest-muted">${u.full_name}</div>
            </div>`;
          item.addEventListener('click',()=>{
            window.location.href='user_profile.php?user_id='+u.id;
          });
          wrapper.appendChild(item);
        });
        wrapper.style.display='block';
      }catch(e){hide();}
    },200);
  });
}
searchInputs.forEach(attachSuggest);

// mobile tabs + swipe
const tabIds=['feed','explore','groups','profile'];
const bottomNav=document.getElementById('bottomNav');
const tabButtons=bottomNav ? bottomNav.querySelectorAll('button[data-tab]') : [];
function showTab(name,dir){
  tabIds.forEach(id=>{
    const el=document.getElementById('tab-'+id);
    if(!el)return;
    el.classList.remove('active','slide-in-left','slide-in-right');
    if(id===name){
      el.classList.add('active');
      if(dir==='left')  el.classList.add('slide-in-left');
      if(dir==='right') el.classList.add('slide-in-right');
    }
  });
  tabButtons.forEach(btn=>{
    btn.classList.toggle('active', btn.dataset.tab===name);
  });
}
tabButtons.forEach(btn=>{
  btn.addEventListener('click',()=>{
    const name=btn.dataset.tab;
    showTab(name);  // default no direction
  });
});

// swipe left / right on mobile center column
const center=document.querySelector('.center-column');
let startX=null;
if(center){
  center.addEventListener('touchstart',e=>{
    startX=e.touches[0].clientX;
  });
  center.addEventListener('touchend',e=>{
    if(startX===null)return;
    const dx=e.changedTouches[0].clientX-startX;
    startX=null;
    if(Math.abs(dx)<50)return;
    const current = tabIds.find(id=>document.getElementById('tab-'+id)?.classList.contains('active')) || 'feed';
    let idx=tabIds.indexOf(current);
    if(dx<0 && idx<tabIds.length-1){ // swipe left -> next
      const next=tabIds[idx+1];
      showTab(next,'right');
    }else if(dx>0 && idx>0){ // swipe right -> prev
      const prev=tabIds[idx-1];
      showTab(prev,'left');
    }
  });
}
</script>
<?php render_app_shell_end(['active' => 'dashboard']); ?>
</body>
</html>






