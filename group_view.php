<?php
// group_view.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId   = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'member';

$groupId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($groupId <= 0) {
    header('Location: groups.php');
    exit;
}

/* LOAD GROUP INFO */
$stmt = $pdo->prepare("
  SELECT g.*, u.username AS creator_username, u.full_name AS creator_name
  FROM groups g
  JOIN users u ON u.id = g.created_by
  WHERE g.id = :gid
  LIMIT 1
");
$stmt->execute([':gid' => $groupId]);
$group = $stmt->fetch();

if (!$group) {
    echo "Group not found.";
    exit;
}

$isCodeGroup  = !empty($group['is_code_only']);
$coverImage   = $group['cover_image'] ?: null;
$inviteToken  = $group['invite_token'] ?: null;
$inviteParam  = $_GET['invite'] ?? null;

/* MEMBERSHIP INFO */
$memStmt = $pdo->prepare("
  SELECT role
  FROM group_members
  WHERE group_id = :gid AND user_id = :uid
  LIMIT 1
");
$memStmt->execute([':gid' => $groupId, ':uid' => $userId]);
$membership = $memStmt->fetch();
$isMember   = (bool) $membership;
$memberRole = $membership['role'] ?? null;

$isOwnerOrMod = in_array($memberRole, ['owner','moderator'], true);
$isStaff      = in_array($userRole, ['owner','admin','moderator'], true);

/* AUTO-JOIN VIA INVITE TOKEN (if present and valid) */
if (!$isMember && $inviteToken && $inviteParam && hash_equals($inviteToken, $inviteParam)) {
    $stmt = $pdo->prepare("
      INSERT IGNORE INTO group_members (group_id, user_id, role)
      VALUES (:gid, :uid, 'member')
    ");
    $stmt->execute([':gid' => $groupId, ':uid' => $userId]);

    $isMember   = true;
    $memberRole = 'member';
    $isOwnerOrMod = false;
}

/**
 * Helper for cover upload (owner / staff tool)
 */
function save_group_cover(?array $file): ?string
{
    if (!$file || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $type    = mime_content_type($file['tmp_name']) ?: '';
    if (!isset($allowed[$type])) {
        return null;
    }

    $ext  = $allowed[$type];
    $dir  = __DIR__ . '/uploads/group_covers';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $name = 'grp_' . $GLOBALS['groupId'] . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $path = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        return null;
    }

    return 'uploads/group_covers/' . $name;
}

/* HANDLE POST ACTIONS (join/leave, cover update, role changes) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* JOIN / LEAVE */
    if (isset($_POST['group_action'])) {
        $action = $_POST['group_action'];
        $gid    = (int)($_POST['group_id'] ?? 0);

        if ($gid === $groupId && $gid > 0) {
            if ($action === 'join') {
                $stmt = $pdo->prepare("
                  INSERT IGNORE INTO group_members (group_id, user_id, role)
                  VALUES (:gid, :uid, 'member')
                ");
                $stmt->execute([':gid' => $gid, ':uid' => $userId]);
            } elseif ($action === 'leave') {
                $pdo->prepare("
                  DELETE FROM group_members
                  WHERE group_id = :gid AND user_id = :uid AND role != 'owner'
                ")->execute([':gid' => $gid, ':uid' => $userId]);
            }
        }

        header("Location: group_view.php?id=".$groupId);
        exit;
    }

    /* UPDATE COVER (owner or portal staff) */
    if (isset($_POST['update_cover']) && ($isOwnerOrMod || $isStaff)) {
        $newCover = save_group_cover($_FILES['cover_image'] ?? null);
        if ($newCover) {
            $pdo->prepare("UPDATE groups SET cover_image = :c WHERE id = :gid")
                ->execute([':c' => $newCover, ':gid' => $groupId]);
        }
        header("Location: group_view.php?id=".$groupId);
        exit;
    }

    /* ROLE MANAGEMENT (owner or portal staff) */
    if (isset($_POST['role_action']) && ($memberRole === 'owner' || $isStaff)) {
        $tUserId = (int)($_POST['target_user_id'] ?? 0);
        $action  = $_POST['role_action'];
        if ($tUserId > 0) {
            $newRole = null;
            if ($action === 'set_mod')    $newRole = 'moderator';
            if ($action === 'set_member') $newRole = 'member';

            if ($newRole) {
                $stmt = $pdo->prepare("
                  UPDATE group_members
                  SET role = :r
                  WHERE group_id = :gid AND user_id = :uid AND role != 'owner'
                ");
                $stmt->execute([
                  ':r'   => $newRole,
                  ':gid' => $groupId,
                  ':uid' => $tUserId
                ]);
            }
        }
        header("Location: group_view.php?id=".$groupId);
        exit;
    }
}

/* LOAD POSTS INSIDE THIS GROUP (pinned first) */
$postSql = "
  SELECT p.id, p.title, p.body, p.user_id, p.created_at, p.category, p.is_pinned,
         u.username, u.full_name, u.avatar, u.role AS user_role,
         COALESCE(lc.like_count,0) AS like_count
  FROM posts p
  JOIN users u ON p.user_id = u.id
  LEFT JOIN (
    SELECT post_id, COUNT(*) AS like_count
    FROM post_likes
    GROUP BY post_id
  ) lc ON lc.post_id = p.id
  WHERE p.group_id = :gid
  ORDER BY p.is_pinned DESC, p.created_at DESC
";
$stmt = $pdo->prepare($postSql);
$stmt->execute([':gid' => $groupId]);
$posts = $stmt->fetchAll();

$postIds = array_column($posts, 'id');

/* COMMENTS & LIKES */
$commentsByPost = [];
$likedPosts     = [];

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

    $likeStmt = $pdo->prepare("
      SELECT post_id FROM post_likes
      WHERE user_id = :uid AND post_id IN ($in)
    ");
    $likeStmt->execute([':uid' => $userId]);
    foreach ($likeStmt as $lp) {
        $likedPosts[(int)$lp['post_id']] = true;
    }
}

/* LOAD MEMBERS FOR MANAGEMENT (bottom panel) */
$members = [];
if ($isOwnerOrMod || $isStaff) {
    $mStmt = $pdo->prepare("
      SELECT gm.user_id, gm.role, u.username, u.full_name, u.avatar
      FROM group_members gm
      JOIN users u ON u.id = gm.user_id
      WHERE gm.group_id = :gid
      ORDER BY gm.role DESC, u.username ASC
    ");
    $mStmt->execute([':gid' => $groupId]);
    $members = $mStmt->fetchAll();
}

$memberCount = 0;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = :gid");
$countStmt->execute([':gid' => $groupId]);
$memberCount = (int)$countStmt->fetchColumn();
$postsCount = count($posts);

?>
<?php require_once __DIR__ . '/includes/app_shell.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($group['name']) ?> | Group</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  if (window.hljs) hljs.highlightAll();
});
</script>

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
  --warning:#d97706;
  --danger:#dc2626;
  --shadow:0 24px 54px rgba(15,23,42,.08);
  --shadow-soft:0 14px 28px rgba(15,23,42,.06);
  --radius-xl:30px;
  --radius-lg:24px;
  --radius-md:18px;
}
*{box-sizing:border-box;}
body{
  margin:0;
  min-height:100vh;
  background:
    radial-gradient(circle at top left, rgba(37,99,235,.10), transparent 22%),
    radial-gradient(circle at bottom right, rgba(59,130,246,.08), transparent 20%),
    var(--bg);
  color:var(--text);
  font-family:"Plus Jakarta Sans","Segoe UI",sans-serif;
}
a{text-decoration:none;color:inherit;}
.shell{
  max-width:1240px;
  margin:0 auto;
  padding:28px 22px 34px;
}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:16px;
  flex-wrap:wrap;
  margin-bottom:18px;
}
.page-title{
  margin:0;
  font-size:2rem;
  font-weight:800;
  letter-spacing:-.03em;
}
.page-subtitle{
  margin:8px 0 0;
  color:var(--text-soft);
  font-size:.98rem;
  max-width:720px;
}
.topbar-actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.nav-button,
.action-button,
.tab-button,
.inline-button,
.icon-button{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  border:1px solid var(--border);
  background:var(--surface);
  color:var(--text-soft);
  font-weight:700;
  box-shadow:var(--shadow-soft);
  transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease, color .18s ease, background .18s ease;
}
.nav-button,
.action-button,
.inline-button{
  padding:12px 18px;
  border-radius:999px;
  font-size:.9rem;
}
.nav-button:hover,
.action-button:hover,
.inline-button:hover,
.icon-button:hover,
.tab-button:hover{
  transform:translateY(-1px);
  border-color:rgba(37,99,235,.26);
  color:var(--accent);
  box-shadow:0 16px 28px rgba(37,99,235,.10);
}
.action-button.primary,
.inline-button.primary,
.tab-button.active{
  background:linear-gradient(135deg, #2563eb, #60a5fa);
  border-color:transparent;
  color:#fff;
}
.action-button.primary:hover,
.inline-button.primary:hover,
.tab-button.active:hover{
  color:#fff;
  background:linear-gradient(135deg, #1d4ed8, #3b82f6);
}
.hero-card,
.section-card,
.feed-card,
.members-card,
.code-lab-card{
  background:rgba(255,255,255,.96);
  border:1px solid rgba(226,232,240,.94);
  border-radius:var(--radius-xl);
  box-shadow:var(--shadow);
}
.hero-card{overflow:hidden;margin-bottom:22px;}
.hero-cover{height:260px;position:relative;background:linear-gradient(135deg, #dbeafe, #eff6ff 58%, #ffffff);}
.hero-cover.has-image{background-size:cover;background-position:center;}
.hero-cover::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg, rgba(15,23,42,.08), rgba(15,23,42,.52));}
.hero-body{position:relative;z-index:1;margin-top:-72px;padding:0 26px 26px;}
.hero-panel{background:rgba(255,255,255,.98);border:1px solid rgba(226,232,240,.94);border-radius:28px;box-shadow:var(--shadow);padding:22px;}
.hero-top{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap;}
.avatar-badge{width:90px;height:90px;border-radius:28px;display:grid;place-items:center;background:linear-gradient(135deg, #2563eb, #60a5fa);color:#fff;font-size:2rem;box-shadow:0 20px 34px rgba(37,99,235,.22);margin-top:-58px;border:5px solid rgba(255,255,255,.96);}
.hero-main{flex:1;min-width:260px;}
.hero-title-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.hero-title{margin:0;font-size:2rem;font-weight:800;letter-spacing:-.04em;}
.badges-wrap{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
.badge-pill{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:999px;font-size:.76rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;border:1px solid transparent;}
.badge-free{background:#ecfdf5;color:var(--success);border-color:rgba(22,163,74,.14);}
.badge-paid{background:#fff7ed;color:var(--warning);border-color:rgba(217,119,6,.14);}
.badge-private{background:#fff1f2;color:var(--danger);border-color:rgba(220,38,38,.14);}
.badge-code{background:#eff6ff;color:var(--accent);border-color:rgba(37,99,235,.14);}
.hero-meta{margin:12px 0 0;display:flex;flex-wrap:wrap;gap:16px;color:var(--text-muted);font-size:.9rem;}
.hero-description{margin:14px 0 0;color:var(--text-soft);line-height:1.75;font-size:.94rem;white-space:pre-wrap;}
.hero-actions{display:flex;gap:10px;flex-wrap:wrap;}
.stats-grid{display:grid;grid-template-columns:repeat(4, minmax(0, 1fr));gap:12px;margin-top:20px;}
.stat-card{background:var(--surface-soft);border:1px solid var(--border);border-radius:20px;padding:16px;}
.stat-label{color:var(--text-muted);font-size:.74rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;}
.stat-value{margin-top:10px;font-size:1.5rem;font-weight:800;letter-spacing:-.03em;}
.owner-box,.join-note,.invite-note{margin-top:16px;padding:16px 18px;border-radius:20px;border:1px solid var(--border);background:var(--surface-soft);color:var(--text-soft);}
.owner-box form .form-control,.feed-card .form-control,.code-lab-card .form-control,.code-lab-card textarea,.feed-card textarea{border-color:var(--border);border-radius:16px;}
.form-control{background:var(--surface);min-height:48px;padding:12px 14px;color:var(--text);}
.form-control:focus{border-color:rgba(37,99,235,.42);box-shadow:0 0 0 4px rgba(37,99,235,.12);}
.tabs-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;}
.tab-button{padding:11px 16px;border-radius:999px;font-size:.88rem;}
.tab-pane{display:none;}
.tab-pane.active{display:block;}
.layout-grid{display:grid;grid-template-columns:minmax(0, 1fr) 320px;gap:22px;}
.feed-stack{display:flex;flex-direction:column;gap:18px;}
.section-card,.feed-card,.members-card,.code-lab-card{padding:22px;}
.section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px;}
.eyebrow{margin:0 0 8px;color:var(--text-muted);font-size:.76rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase;}
.section-title{margin:0;font-size:1.18rem;font-weight:800;letter-spacing:-.02em;}
.section-desc{margin:8px 0 0;color:var(--text-soft);line-height:1.7;font-size:.92rem;}
.section-badge{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:999px;background:var(--surface-alt);border:1px solid rgba(37,99,235,.12);color:var(--accent);font-size:.8rem;font-weight:700;}
.post-card{border:1px solid rgba(226,232,240,.94);border-radius:24px;background:linear-gradient(180deg, #ffffff, #fbfdff);padding:20px;box-shadow:var(--shadow-soft);}
.post-card + .post-card{margin-top:16px;}
.post-card.post-pinned{border-color:rgba(37,99,235,.28);box-shadow:0 18px 36px rgba(37,99,235,.12);}
.post-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;}
.author-row{display:flex;gap:12px;align-items:flex-start;}
.author-avatar{width:44px;height:44px;border-radius:16px;object-fit:cover;background:#dbeafe;box-shadow:var(--shadow-soft);}
.author-name{margin:0;font-size:.96rem;font-weight:800;}
.author-meta{margin:6px 0 0;color:var(--text-muted);font-size:.82rem;line-height:1.6;}
.pin-note{color:var(--accent);font-weight:700;}
.post-title{margin:18px 0 8px;font-size:1rem;font-weight:800;}
.post-body{margin:0;color:var(--text-soft);line-height:1.8;font-size:.93rem;white-space:pre-wrap;}
.post-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;}
.icon-button{padding:10px 14px;border-radius:999px;font-size:.84rem;}
.icon-button.icon-button-liked{background:rgba(219,234,254,.88);color:var(--accent);border-color:rgba(37,99,235,.22);}
.comments-block{margin-top:18px;padding-top:16px;border-top:1px solid var(--border);}
.comment{display:flex;gap:10px;margin-bottom:12px;}
.comment-avatar{width:34px;height:34px;border-radius:12px;object-fit:cover;background:#dbeafe;}
.comment-box{flex:1;border-radius:18px;border:1px solid var(--border);background:var(--surface-soft);padding:12px 14px;}
.comment-meta{color:var(--text-muted);font-size:.78rem;margin-bottom:6px;}
.reply-form{margin-top:14px;}
.reply-form .row{align-items:end;}
.code-lab-card textarea{min-height:180px;font-family:monospace;font-size:.88rem;}
.code-preview{margin-top:14px;border-radius:20px;overflow:hidden;border:1px solid rgba(226,232,240,.94);box-shadow:var(--shadow-soft);}
.empty-note{border:1px dashed var(--border-strong);border-radius:20px;padding:20px 18px;background:rgba(248,250,252,.92);color:var(--text-soft);line-height:1.7;}
.members-card{position:sticky;top:24px;}
.member-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-top:1px solid var(--border);}
.member-row:first-of-type{border-top:none;padding-top:0;}
.member-main{display:flex;align-items:center;gap:12px;min-width:0;}
.member-avatar{width:40px;height:40px;border-radius:14px;object-fit:cover;background:#dbeafe;}
.member-name{font-weight:700;font-size:.9rem;}
.member-handle{color:var(--text-muted);font-size:.8rem;}
.role-chip{display:inline-flex;align-items:center;justify-content:center;padding:7px 11px;border-radius:999px;background:var(--surface-alt);color:var(--accent);border:1px solid rgba(37,99,235,.12);font-size:.74rem;font-weight:800;text-transform:capitalize;}
@media (max-width: 991.98px){.layout-grid{grid-template-columns:1fr;}.members-card{position:static;}.stats-grid{grid-template-columns:repeat(2, minmax(0, 1fr));}}
@media (max-width: 767.98px){.shell{padding:16px 12px 22px;}.page-title{font-size:1.65rem;}.hero-cover{height:210px;}.hero-body{padding:0 16px 16px;margin-top:-58px;}.hero-panel,.section-card,.feed-card,.members-card,.code-lab-card,.post-card{padding:16px;}.hero-top,.post-head,.section-head,.topbar,.topbar-actions{flex-direction:column;align-items:flex-start;}.avatar-badge{width:76px;height:76px;border-radius:22px;font-size:1.7rem;}.hero-title{font-size:1.55rem;}.stats-grid{grid-template-columns:repeat(2, minmax(0, 1fr));}.nav-button,.action-button,.inline-button,.tab-button{width:100%;}}
</style>
</head>
<?php render_app_shell_start(['active' => 'groups', 'context_label' => 'Community', 'context_title' => $group['name'], 'context_subtitle' => 'Group posts, discussion, code sharing, and member collaboration.']); ?>
<div class=\
    <div class="topbar-actions">
      <a href="groups.php" class="nav-button"><i class="bi bi-arrow-left"></i><span>All groups</span></a>
    </div>
  </div>

  <section class="hero-card">
    <div class="hero-cover <?= $coverImage ? 'has-image' : '' ?>" <?= $coverImage ? 'style="background-image:url(' . htmlspecialchars($coverImage) . ');"' : '' ?>></div>
    <div class="hero-body">
      <div class="hero-panel">
        <div class="hero-top">
          <div class="hero-main">
            <div class="avatar-badge"><i class="bi bi-people-fill"></i></div>
            <div class="hero-title-row"><h2 class="hero-title"><?= htmlspecialchars($group['name']) ?></h2></div>
            <div class="badges-wrap">
              <?php if ($group['is_paid']): ?>
                <span class="badge-pill badge-paid"><i class="bi bi-gem"></i>Paid<?php if ($group['price_month']): ?> · <?= htmlspecialchars($group['price_month']) ?> €/month<?php endif; ?></span>
              <?php else: ?>
                <span class="badge-pill badge-free"><i class="bi bi-unlock"></i> Free</span>
              <?php endif; ?>
              <?php if ($group['is_private']): ?><span class="badge-pill badge-private"><i class="bi bi-lock-fill"></i> Private</span><?php endif; ?>
              <?php if ($isCodeGroup): ?><span class="badge-pill badge-code"><i class="bi bi-code-slash"></i> Code group</span><?php endif; ?>
            </div>
            <div class="hero-meta">
              <span><i class="bi bi-person-workspace"></i> By @<?= htmlspecialchars($group['creator_username']) ?></span>
              <span><i class="bi bi-calendar3"></i> <?= htmlspecialchars(date('M j, Y', strtotime($group['created_at']))) ?></span>
              <span><i class="bi bi-people"></i> <?= $memberCount ?> members</span>
            </div>
            <p class="hero-description"><?= nl2br(htmlspecialchars($group['description'] ?? '')) ?></p>
            <?php if (!$isMember && $inviteToken && $inviteParam && hash_equals($inviteToken, $inviteParam)): ?>
              <div class="invite-note"><i class="bi bi-link-45deg"></i> You joined via invite link.</div>
            <?php endif; ?>
          </div>
          <div class="hero-actions">
            <?php if ($isMember): ?>
              <form method="POST"><input type="hidden" name="group_id" value="<?= $groupId ?>"><input type="hidden" name="group_action" value="leave"><button type="submit" class="action-button"><i class="bi bi-box-arrow-left"></i><span>Leave</span></button></form>
            <?php else: ?>
              <form method="POST"><input type="hidden" name="group_id" value="<?= $groupId ?>"><input type="hidden" name="group_action" value="join"><button type="submit" class="action-button primary"><i class="bi bi-plus-circle"></i><span>Join group</span></button></form>
            <?php endif; ?>
          </div>
        </div>
        <div class="stats-grid">
          <div class="stat-card"><div class="stat-label">Members</div><div class="stat-value"><?= $memberCount ?></div></div>
          <div class="stat-card"><div class="stat-label">Posts</div><div class="stat-value"><?= $postsCount ?></div></div>
          <div class="stat-card"><div class="stat-label">Access</div><div class="stat-value"><?= $group['is_private'] ? 'Private' : 'Open' ?></div></div>
          <div class="stat-card"><div class="stat-label">Type</div><div class="stat-value"><?= $isCodeGroup ? 'Code' : 'Social' ?></div></div>
        </div>
        <?php if ($isOwnerOrMod || $isStaff): ?>
          <div class="owner-box">
            <div class="section-head" style="margin-bottom:12px;"><div><p class="eyebrow">Owner Tools</p><h3 class="section-title">Cover management</h3><p class="section-desc">Update the group’s cover without changing any of the underlying owner or staff permissions.</p></div></div>
            <form method="POST" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
              <input type="hidden" name="update_cover" value="1">
              <input type="file" name="cover_image" accept="image/*" class="form-control" style="max-width:260px;">
              <button class="inline-button" type="submit"><i class="bi bi-image-fill"></i><span>Update cover</span></button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <div class="tabs-bar">
    <button class="tab-button active" data-tab="feed"><i class="bi bi-grid-3x3-gap"></i><span>Feed</span></button>
    <?php if ($isCodeGroup): ?><button class="tab-button" data-tab="codelab"><i class="bi bi-code-square"></i><span>Code Lab</span></button><?php endif; ?>
  </div>
  <div id="tab-feed" class="tab-pane active">
    <div class="layout-grid">
      <div class="feed-stack">
        <section class="feed-card">
          <div class="section-head">
            <div>
              <p class="eyebrow">Composer</p>
              <h3 class="section-title">Share with the group</h3>
              <p class="section-desc">Create a post that stays inside this group and follows the same backend flow as before.</p>
            </div>
            <div class="section-badge"><i class="bi bi-send"></i> Group post</div>
          </div>

          <?php if ($isMember): ?>
            <form action="post_create.php" method="POST">
              <input type="hidden" name="group_id" value="<?= $groupId ?>">
              <input type="hidden" name="category" value="post">
              <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control form-control-sm" placeholder="Title (optional, e.g. 'JS tip', 'PHP help')">
              </div>
              <div class="mb-3">
                <label class="form-label">Post</label>
                <textarea name="body" rows="4" class="form-control" placeholder="<?= $isCodeGroup ? 'Paste your snippet or explanation...' : 'Share code, ask a question, or drop a lesson...' ?>" required></textarea>
              </div>
              <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                <span class="section-desc" style="margin:0;">Posts here stay inside this group.</span>
                <button type="submit" class="inline-button primary"><i class="bi bi-send-fill"></i><span>Post</span></button>
              </div>
            </form>
          <?php else: ?>
            <div class="join-note">Join this group to see and post content.</div>
          <?php endif; ?>
        </section>

        <section class="feed-card">
          <div class="section-head">
            <div>
              <p class="eyebrow">Feed</p>
              <h3 class="section-title">Group activity</h3>
              <p class="section-desc">Pinned threads, community posts, replies, and reactions styled to match the newer dashboard feel.</p>
            </div>
            <div class="section-badge"><i class="bi bi-chat-square-text"></i> <?= $postsCount ?> post<?= $postsCount === 1 ? '' : 's' ?></div>
          </div>

          <?php if ($isMember): ?>
            <?php if (empty($posts)): ?>
              <div class="empty-note">No posts yet. Start the first thread.</div>
            <?php else: ?>
              <?php foreach ($posts as $post): ?>
                <?php
                  $pid         = (int)$post['id'];
                  $authorName  = $post['full_name'] ?: $post['username'];
                  $authorAv    = $post['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($authorName) . '&background=111827&color=fff&rounded=true&size=64';
                  $postComments = $commentsByPost[$pid] ?? [];
                  $likeCount   = (int)$post['like_count'];
                  $userLiked   = isset($likedPosts[$pid]);
                ?>
                <article class="post-card <?= $post['is_pinned'] ? 'post-pinned' : '' ?>" id="post-<?= $pid ?>">
                  <div class="post-head">
                    <div class="author-row">
                      <img src="<?= htmlspecialchars($authorAv) ?>" alt="" class="author-avatar">
                      <div>
                        <p class="author-name"><?= htmlspecialchars($authorName) ?></p>
                        <p class="author-meta">
                          @<?= htmlspecialchars($post['username']) ?> · <?= htmlspecialchars(date('M j, H:i', strtotime($post['created_at']))) ?>
                          <?php if ($post['is_pinned']): ?> · <span class="pin-note"><i class="bi bi-pin-angle-fill"></i> Pinned</span><?php endif; ?>
                        </p>
                      </div>
                    </div>

                    <?php if ($isOwnerOrMod || $isStaff): ?>
                      <form action="post_pin.php" method="POST">
                        <input type="hidden" name="post_id" value="<?= $pid ?>">
                        <input type="hidden" name="pin" value="<?= $post['is_pinned'] ? '0' : '1' ?>">
                        <button class="inline-button" type="submit"><i class="bi bi-pin-angle-fill"></i><span><?= $post['is_pinned'] ? 'Unpin' : 'Pin' ?></span></button>
                      </form>
                    <?php endif; ?>
                  </div>

                  <?php if (!empty($post['title'])): ?><h4 class="post-title"><?= htmlspecialchars($post['title']) ?></h4><?php endif; ?>

                  <div class="post-body">
                    <?php if ($isCodeGroup): ?>
                      <pre><code class="language-php"><?= htmlspecialchars($post['body']) ?></code></pre>
                    <?php else: ?>
                      <?= nl2br(htmlspecialchars($post['body'])) ?>
                    <?php endif; ?>
                  </div>

                  <div class="post-actions">
                    <span class="icon-button"><i class="bi bi-chat-left-text"></i><span><?= count($postComments) ?></span></span>
                    <form action="post_like_toggle.php" method="POST" class="d-inline">
                      <input type="hidden" name="post_id" value="<?= $pid ?>">
                      <button class="icon-button <?= $userLiked ? 'icon-button-liked' : '' ?>" type="submit"><i class="bi <?= $userLiked ? 'bi-heart-fill' : 'bi-heart' ?>"></i><span><?= $likeCount ?></span></button>
                    </form>
                  </div>

                  <div class="comments-block">
                    <?php foreach ($postComments as $c): ?>
                      <?php
                        $cName = $c['full_name'] ?: $c['username'];
                        $cAv   = $c['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($cName) . '&background=111827&color=fff&rounded=true&size=64';
                      ?>
                      <div class="comment">
                        <img src="<?= htmlspecialchars($cAv) ?>" alt="" class="comment-avatar">
                        <div class="comment-box">
                          <div class="comment-meta"><?= htmlspecialchars($cName) ?> · <?= htmlspecialchars(date('M j, H:i', strtotime($c['created_at']))) ?></div>
                          <div><?= nl2br(htmlspecialchars($c['body'])) ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>

                    <form action="comment_add.php" method="POST" class="reply-form">
                      <input type="hidden" name="post_id" value="<?= $pid ?>">
                      <div class="row g-2 align-items-center">
                        <div class="col-9 col-md-10">
                          <textarea name="body" rows="1" class="form-control" placeholder="Reply..." required></textarea>
                        </div>
                        <div class="col-3 col-md-2 d-flex justify-content-end">
                          <button type="submit" class="inline-button"><i class="bi bi-arrow-right-short"></i><span>Send</span></button>
                        </div>
                      </div>
                    </form>
                  </div>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      </div>

      <?php if (!empty($members)): ?>
        <aside class="members-card">
          <div class="section-head">
            <div>
              <p class="eyebrow">Management</p>
              <h3 class="section-title">Members &amp; roles</h3>
              <p class="section-desc">Owner and moderator tools remain unchanged, presented in a cleaner side panel.</p>
            </div>
          </div>

          <?php foreach ($members as $mem): ?>
            <?php
              $mName = $mem['full_name'] ?: $mem['username'];
              $mAv   = $mem['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($mName) . '&background=111827&color=fff&rounded=true&size=64';
              $mRole = $mem['role'];
            ?>
            <div class="member-row">
              <div class="member-main">
                <img src="<?= htmlspecialchars($mAv) ?>" alt="" class="member-avatar">
                <div>
                  <div class="member-name"><?= htmlspecialchars($mName) ?></div>
                  <div class="member-handle">@<?= htmlspecialchars($mem['username']) ?></div>
                </div>
              </div>
              <div class="d-flex flex-wrap gap-1 justify-content-end">
                <span class="role-chip"><?= htmlspecialchars($mRole) ?></span>
                <?php if (($memberRole === 'owner' || $isStaff) && $mem['user_id'] != $userId && $mRole !== 'owner'): ?>
                  <form method="POST">
                    <input type="hidden" name="role_action" value="set_mod">
                    <input type="hidden" name="target_user_id" value="<?= (int)$mem['user_id'] ?>">
                    <button class="inline-button" type="submit"><i class="bi bi-shield-half"></i><span>Mod</span></button>
                  </form>
                  <form method="POST">
                    <input type="hidden" name="role_action" value="set_member">
                    <input type="hidden" name="target_user_id" value="<?= (int)$mem['user_id'] ?>">
                    <button class="inline-button" type="submit"><i class="bi bi-person"></i><span>Member</span></button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </aside>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isCodeGroup): ?>
    <div id="tab-codelab" class="tab-pane">
      <section class="code-lab-card">
        <div class="section-head">
          <div>
            <p class="eyebrow">Code Lab</p>
            <h3 class="section-title">Write and preview snippets</h3>
            <p class="section-desc">The live editor and preview keep the same posting endpoint and hidden fields while presenting a more polished workspace.</p>
          </div>
          <div class="section-badge"><i class="bi bi-code-square"></i> Live preview</div>
        </div>

        <?php if ($isMember): ?>
          <form action="post_create.php" method="POST" id="codeLabForm">
            <input type="hidden" name="group_id" value="<?= $groupId ?>">
            <input type="hidden" name="category" value="post">
            <div class="mb-3">
              <label class="form-label">Snippet title</label>
              <input type="text" name="title" class="form-control form-control-sm" placeholder="Snippet title (optional)">
            </div>
            <div class="mb-3">
              <label class="form-label">Code</label>
              <textarea name="body" id="code-input" placeholder="// Drop your PHP / JS / HTML here..." required></textarea>
            </div>
            <div class="code-preview">
              <pre><code id="code-preview" class="language-php"></code></pre>
            </div>
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mt-3">
              <span class="section-desc" style="margin:0;">This will also appear in the group feed as a post.</span>
              <button type="submit" class="inline-button primary"><i class="bi bi-upload"></i><span>Post snippet</span></button>
            </div>
          </form>
        <?php else: ?>
          <div class="empty-note">Join this group to use the code lab.</div>
        <?php endif; ?>
      </section>
    </div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.tab-button').forEach(btn => {
  btn.addEventListener('click', () => {
    const tab = btn.dataset.tab;
    document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
  });
});

const codeInput   = document.getElementById('code-input');
const codePreview = document.getElementById('code-preview');

if (codeInput && codePreview && window.hljs) {
  const updatePreview = () => {
    codePreview.textContent = codeInput.value;
    hljs.highlightElement(codePreview);
  };
  codeInput.addEventListener('input', updatePreview);
  updatePreview();
}
</script>

<?php render_app_shell_end(['active' => 'groups']); ?>
</body>
</html>




