<?php
// groups.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId   = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'member';

// load is_premium
$stmt = $pdo->prepare("SELECT is_premium FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$row = $stmt->fetch();
$isPremium = !empty($row['is_premium']);

$isStaff   = in_array($userRole, ['owner','admin','moderator'], true);
$canCreate = $isStaff || $isPremium;

/**
 * Helper to save cover image (optional)
 */
function save_group_cover(?array $file): ?string
{
    if (!$file || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif'
    ];

    $type = mime_content_type($file['tmp_name']) ?: '';
    if (!isset($allowed[$type])) {
        return null;
    }

    $ext  = $allowed[$type];
    $dir  = __DIR__ . '/uploads/group_covers';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $name = 'grp_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $path = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        return null;
    }

    return 'uploads/group_covers/' . $name;
}

function build_unique_group_slug(PDO $pdo, string $name): string
{
    $baseSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
    $baseSlug = trim($baseSlug, '-');

    if ($baseSlug === '') {
        $baseSlug = 'group';
    }

    $slug = $baseSlug;
    $suffix = 2;
    $stmt = $pdo->prepare("SELECT id FROM `groups` WHERE slug = :slug LIMIT 1");

    while (true) {
        $stmt->execute([':slug' => $slug]);
        if (!$stmt->fetch()) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }
}

/* CREATE GROUP (staff + premium only) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group']) && $canCreate) {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isPrivate   = isset($_POST['is_private']) ? 1 : 0;
    $isPaid      = isset($_POST['is_paid']) ? 1 : 0;
    $priceMonth  = $isPaid ? (float)($_POST['price_month'] ?? 0) : null;
    $isCodeOnly  = isset($_POST['is_code_only']) ? 1 : 0;
    $inviteToken = isset($_POST['enable_invite']) ? bin2hex(random_bytes(16)) : null;

    $coverImage = null;
    if (!empty($_FILES['cover_image']['name'])) {
        $coverImage = save_group_cover($_FILES['cover_image']);
    }

    if ($name !== '') {
        $slug = build_unique_group_slug($pdo, $name);

        $stmt = $pdo->prepare("
            INSERT INTO `groups` (
                name, slug, description, cover_image, is_code_only,
                created_by, is_private, is_paid, price_month, invite_token
            )
            VALUES (
                :n, :s, :d, :cover, :code,
                :cb, :priv, :paid, :price, :token
            )
        ");
        $stmt->execute([
            ':n'     => $name,
            ':s'     => $slug,
            ':d'     => $description,
            ':cover' => $coverImage,
            ':code'  => $isCodeOnly,
            ':cb'    => $userId,
            ':priv'  => $isPrivate,
            ':paid'  => $isPaid,
            ':price' => $priceMonth,
            ':token' => $inviteToken
        ]);

        $groupId = (int)$pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO group_members (group_id, user_id, role)
            VALUES (:gid, :uid, 'owner')
        ")->execute([
            ':gid' => $groupId,
            ':uid' => $userId
        ]);

        header("Location: group_view.php?id=" . $groupId);
        exit;
    }
}

/* JOIN / LEAVE GROUP */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['group_action'])) {
    $action  = $_POST['group_action'];
    $groupId = (int)($_POST['group_id'] ?? 0);

    if ($groupId > 0) {
        if ($action === 'join') {
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO group_members (group_id, user_id, role)
                VALUES (:gid, :uid, 'member')
            ");
            $stmt->execute([
                ':gid' => $groupId,
                ':uid' => $userId
            ]);
        } elseif ($action === 'leave') {
            $pdo->prepare("
                DELETE FROM group_members
                WHERE group_id = :gid
                  AND user_id = :uid
                  AND role != 'owner'
            ")->execute([
                ':gid' => $groupId,
                ':uid' => $userId
            ]);
        }
    }

    header("Location: groups.php");
    exit;
}

/* LOAD GROUPS + MEMBERSHIP INFO */
$groups = $pdo->query("
    SELECT
        g.*,
        u.username AS creator_username,
        (
            SELECT COUNT(*)
            FROM group_members gm
            WHERE gm.group_id = g.id
        ) AS member_count,
        EXISTS(
            SELECT 1
            FROM group_members gm2
            WHERE gm2.group_id = g.id
              AND gm2.user_id = {$userId}
        ) AS is_member
    FROM `groups` g
    JOIN users u ON u.id = g.created_by
    ORDER BY g.created_at DESC
")->fetchAll();

$totalGroups   = count($groups);
$joinedGroups  = 0;
$privateGroups = 0;
$paidGroups    = 0;
$codeGroups    = 0;

foreach ($groups as $groupItem) {
    if (!empty($groupItem['is_member'])) {
        $joinedGroups++;
    }
    if (!empty($groupItem['is_private'])) {
        $privateGroups++;
    }
    if (!empty($groupItem['is_paid'])) {
        $paidGroups++;
    }
    if (!empty($groupItem['is_code_only'])) {
        $codeGroups++;
    }
}

$topCommunity = null;
if (!empty($groups)) {
    usort($groups, function ($a, $b) {
        return ((int)$b['member_count']) <=> ((int)$a['member_count']);
    });
    $topCommunity = $groups[0];
    usort($groups, function ($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
}
?>
<?php require_once __DIR__ . '/includes/app_shell.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Groups | PortalFeed</title>
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
  --warning:#d97706;
  --danger:#dc2626;
  --shadow:0 20px 50px rgba(15,23,42,.07);
  --shadow-soft:0 12px 26px rgba(15,23,42,.05);
  --radius-xl:24px;
  --radius-lg:20px;
  --radius-md:16px;
}
*{box-sizing:border-box;}
body{
  margin:0;
  min-height:100vh;
  background:
    radial-gradient(circle at top left, rgba(37,99,235,.10), transparent 22%),
    radial-gradient(circle at bottom right, rgba(59,130,246,.07), transparent 20%),
    var(--bg);
  color:var(--text);
  font-family:"Plus Jakarta Sans","Segoe UI",sans-serif;
}
a{text-decoration:none;color:inherit;}
.groups-shell{
  max-width:1240px;
  margin:0 auto;
  padding:28px 22px 36px;
}
.page-top{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:18px;
  flex-wrap:wrap;
  margin-bottom:22px;
}
.page-title{
  margin:0;
  font-size:2rem;
  font-weight:800;
  letter-spacing:-.03em;
}
.page-subtitle{
  margin:8px 0 0;
  font-size:.98rem;
  color:var(--text-soft);
  max-width:720px;
  line-height:1.75;
}
.top-actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.soft-btn,
.primary-btn,
.secondary-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  padding:11px 16px;
  border-radius:12px;
  border:1px solid var(--border);
  background:var(--surface);
  color:var(--text-soft);
  font-size:.9rem;
  font-weight:700;
  box-shadow:var(--shadow-soft);
  transition:.18s ease;
}
.soft-btn:hover,
.secondary-btn:hover{
  border-color:rgba(37,99,235,.26);
  color:var(--accent);
  transform:translateY(-1px);
}
.primary-btn{
  background:linear-gradient(135deg, #2563eb, #60a5fa);
  color:#fff;
  border-color:transparent;
}
.primary-btn:hover{
  color:#fff;
  transform:translateY(-1px);
  background:linear-gradient(135deg, #1d4ed8, #3b82f6);
}
.hero-grid{
  display:grid;
  grid-template-columns:1.3fr .95fr;
  gap:20px;
  margin-bottom:20px;
}
.card-surface{
  background:rgba(255,255,255,.96);
  border:1px solid rgba(226,232,240,.95);
  border-radius:24px;
  box-shadow:var(--shadow);
}
.hero-main{
  padding:26px;
  background:
    radial-gradient(circle at top left, rgba(96,165,250,.16), transparent 22%),
    linear-gradient(180deg, #ffffff, #f8fbff);
}
.eyebrow{
  margin:0 0 10px;
  color:var(--text-muted);
  font-size:.76rem;
  font-weight:800;
  letter-spacing:.16em;
  text-transform:uppercase;
}
.hero-main h2{
  margin:0;
  font-size:1.95rem;
  font-weight:800;
  letter-spacing:-.04em;
  max-width:620px;
}
.hero-main p{
  margin:14px 0 0;
  color:var(--text-soft);
  line-height:1.75;
  max-width:650px;
}
.stats-grid{
  display:grid;
  grid-template-columns:repeat(5, minmax(0, 1fr));
  gap:12px;
  margin-top:22px;
}
.stat-card{
  background:var(--surface);
  border:1px solid var(--border);
  border-radius:18px;
  padding:16px;
  box-shadow:var(--shadow-soft);
}
.stat-label{
  color:var(--text-muted);
  font-size:.72rem;
  font-weight:800;
  letter-spacing:.14em;
  text-transform:uppercase;
}
.stat-value{
  margin-top:10px;
  font-size:1.5rem;
  font-weight:800;
  letter-spacing:-.03em;
}
.side-stack{
  display:flex;
  flex-direction:column;
  gap:20px;
}
.panel-card{
  padding:22px;
}
.panel-title{
  margin:0;
  font-size:1.15rem;
  font-weight:800;
  letter-spacing:-.02em;
}
.panel-desc{
  margin:8px 0 0;
  color:var(--text-soft);
  font-size:.92rem;
  line-height:1.7;
}
.panel-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:8px 13px;
  border-radius:999px;
  background:var(--surface-alt);
  border:1px solid rgba(37,99,235,.12);
  color:var(--accent);
  font-size:.8rem;
  font-weight:700;
}
.quick-list{
  display:grid;
  gap:10px;
  margin-top:16px;
}
.quick-item{
  display:flex;
  align-items:flex-start;
  gap:12px;
  padding:14px;
  border:1px solid var(--border);
  border-radius:16px;
  background:var(--surface-soft);
}
.quick-icon{
  width:38px;
  height:38px;
  border-radius:12px;
  display:grid;
  place-items:center;
  background:var(--surface);
  color:var(--accent);
  border:1px solid var(--border);
  flex-shrink:0;
}
.quick-item strong{
  display:block;
  font-size:.92rem;
}
.quick-item span{
  display:block;
  font-size:.82rem;
  color:var(--text-muted);
  margin-top:3px;
  line-height:1.55;
}
.form-label{
  margin-bottom:8px;
  color:var(--text);
  font-size:.86rem;
  font-weight:700;
}
.form-control,
.form-check-input,
.form-select{
  border-color:var(--border);
}
.form-control,
.form-select{
  background:var(--surface);
  border-radius:14px;
  min-height:50px;
  color:var(--text);
  padding:12px 14px;
  font-size:.94rem;
}
.form-control:focus,
.form-select:focus{
  border-color:rgba(37,99,235,.42);
  box-shadow:0 0 0 4px rgba(37,99,235,.12);
}
textarea.form-control{
  min-height:auto;
}
.option-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:12px;
}
.option-card{
  padding:14px 16px;
  border-radius:16px;
  border:1px solid var(--border);
  background:var(--surface-soft);
}
.option-note{
  margin-top:6px;
  color:var(--text-muted);
  font-size:.8rem;
  line-height:1.55;
}
.locked-note,
.empty-note{
  border:1px dashed var(--border-strong);
  border-radius:18px;
  padding:18px;
  background:rgba(248,250,252,.92);
  color:var(--text-soft);
  line-height:1.7;
}
.directory-card{
  padding:22px;
}
.directory-top{
  display:flex;
  justify-content:space-between;
  align-items:flex-end;
  gap:16px;
  flex-wrap:wrap;
  margin-bottom:18px;
}
.filters-row{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  align-items:center;
  margin-bottom:18px;
}
.search-wrap{
  flex:1 1 280px;
  position:relative;
}
.search-wrap i{
  position:absolute;
  left:14px;
  top:50%;
  transform:translateY(-50%);
  color:var(--text-muted);
}
.search-wrap input{
  width:100%;
  min-height:48px;
  border:1px solid var(--border);
  border-radius:14px;
  background:#fff;
  padding:12px 14px 12px 42px;
  font-size:.93rem;
}
.search-wrap input:focus{
  outline:none;
  border-color:rgba(37,99,235,.42);
  box-shadow:0 0 0 4px rgba(37,99,235,.12);
}
.filter-chip{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 14px;
  border-radius:999px;
  background:var(--surface-soft);
  border:1px solid var(--border);
  color:var(--text-soft);
  font-size:.82rem;
  font-weight:700;
  cursor:pointer;
  user-select:none;
}
.filter-chip.active{
  background:var(--accent-soft);
  color:var(--accent);
  border-color:rgba(37,99,235,.22);
}
.groups-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:18px;
}
.group-card{
  border:1px solid rgba(226,232,240,.94);
  border-radius:22px;
  overflow:hidden;
  background:linear-gradient(180deg, #ffffff, #fbfdff);
  box-shadow:var(--shadow-soft);
  display:flex;
  flex-direction:column;
}
.group-cover{
  height:190px;
  position:relative;
  background:linear-gradient(135deg, #dbeafe, #eff6ff 60%, #ffffff);
}
.group-cover.has-image{
  background-size:cover;
  background-position:center;
}
.group-cover::after{
  content:"";
  position:absolute;
  inset:0;
  background:linear-gradient(180deg, rgba(15,23,42,.04), rgba(15,23,42,.34));
}
.group-overlay{
  position:absolute;
  inset:auto 16px 16px 16px;
  z-index:1;
  display:flex;
  justify-content:space-between;
  align-items:flex-end;
  gap:12px;
}
.overlay-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:8px 12px;
  border-radius:999px;
  background:rgba(255,255,255,.93);
  color:var(--text);
  font-size:.8rem;
  font-weight:800;
  box-shadow:var(--shadow-soft);
}
.overlay-members{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:8px 12px;
  border-radius:999px;
  background:rgba(15,23,42,.68);
  color:#fff;
  font-size:.8rem;
  font-weight:700;
}
.group-content{
  padding:18px;
  display:flex;
  flex-direction:column;
  gap:14px;
  flex:1;
}
.group-head{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
}
.group-name{
  margin:0;
  font-size:1.12rem;
  font-weight:800;
  letter-spacing:-.02em;
}
.group-meta{
  margin:8px 0 0;
  color:var(--text-muted);
  font-size:.84rem;
  line-height:1.7;
}
.group-description{
  margin:0;
  color:var(--text-soft);
  line-height:1.72;
  font-size:.92rem;
}
.badges-wrap{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
}
.badge-pill{
  display:inline-flex;
  align-items:center;
  gap:7px;
  padding:7px 11px;
  border-radius:999px;
  font-size:.74rem;
  font-weight:800;
  letter-spacing:.08em;
  text-transform:uppercase;
  border:1px solid transparent;
}
.badge-free{background:#ecfdf5;color:var(--success);border-color:rgba(22,163,74,.14);}
.badge-paid{background:#fff7ed;color:var(--warning);border-color:rgba(217,119,6,.14);}
.badge-private{background:#fff1f2;color:var(--danger);border-color:rgba(220,38,38,.14);}
.badge-code{background:#eff6ff;color:var(--accent);border-color:rgba(37,99,235,.14);}
.invite-chip{
  padding:12px 14px;
  border-radius:16px;
  border:1px dashed var(--border-strong);
  background:var(--surface-soft);
  color:var(--text-soft);
  font-size:.84rem;
  line-height:1.65;
}
.group-footer{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  margin-top:auto;
}
.group-status{
  color:var(--text-muted);
  font-size:.84rem;
  font-weight:600;
}
.inline-form{margin:0;}
.feature-card{
  padding:16px;
  border:1px solid var(--border);
  border-radius:18px;
  background:linear-gradient(180deg, #ffffff, #f8fbff);
  margin-bottom:18px;
}
.feature-card strong{
  display:block;
  font-size:.95rem;
}
.feature-card span{
  display:block;
  margin-top:6px;
  color:var(--text-muted);
  font-size:.84rem;
  line-height:1.6;
}
.hidden-group{
  display:none !important;
}
@media (max-width: 1100px){
  .hero-grid{
    grid-template-columns:1fr;
  }
}
@media (max-width: 991.98px){
  .stats-grid{
    grid-template-columns:repeat(3, minmax(0, 1fr));
  }
  .groups-grid{
    grid-template-columns:1fr;
  }
}
@media (max-width: 767.98px){
  .groups-shell{
    padding:16px 12px 22px;
  }
  .page-title{
    font-size:1.6rem;
  }
  .card-surface,
  .directory-card{
    border-radius:18px;
  }
  .hero-main,
  .panel-card,
  .directory-card{
    padding:16px;
  }
  .stats-grid,
  .option-grid{
    grid-template-columns:1fr 1fr;
  }
  .group-cover{
    height:160px;
  }
  .group-overlay,
  .group-head,
  .directory-top{
    flex-direction:column;
    align-items:flex-start;
  }
}
@media (max-width: 575.98px){
  .stats-grid,
  .option-grid{
    grid-template-columns:1fr;
  }
}
</style>
</head>
<?php
render_app_shell_start([
    'active' => 'groups',
    'context_label' => 'Communities',
    'context_title' => 'Groups',
    'context_subtitle' => 'Discover focused communities, premium rooms, and code-first circles.'
]);
?>
<div class="groups-shell">
  <div class="page-top">
    <div>
      <h1 class="page-title">Groups & Communities</h1>
      <p class="page-subtitle">
        Discover focused communities for growth, collaboration, creator circles, premium rooms, and code-first spaces built around shared work.
      </p>
    </div>
    <div class="top-actions">
      <a href="dashboard.php" class="soft-btn">
        <i class="bi bi-house-door"></i>
        <span>Back to feed</span>
      </a>
      <?php if ($canCreate): ?>
        <a href="#create-group" class="primary-btn">
          <i class="bi bi-plus-circle"></i>
          <span>Create group</span>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <section class="hero-grid">
    <div class="card-surface hero-main">
      <p class="eyebrow">Community hub</p>
      <h2>Find serious spaces for growth, discussion, and collaboration.</h2>
      <p>
        Browse communities in one place, join the groups that match your goals, and launch private or premium spaces when your account allows it.
      </p>

      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-label">Total</div>
          <div class="stat-value"><?= $totalGroups ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Joined</div>
          <div class="stat-value"><?= $joinedGroups ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Private</div>
          <div class="stat-value"><?= $privateGroups ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Paid</div>
          <div class="stat-value"><?= $paidGroups ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Code</div>
          <div class="stat-value"><?= $codeGroups ?></div>
        </div>
      </div>
    </div>

    <div class="side-stack">
      <div class="card-surface panel-card">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
          <div>
            <p class="eyebrow">Featured</p>
            <h2 class="panel-title">Top community right now</h2>
            <p class="panel-desc">A quick spotlight to make the page feel more alive and curated.</p>
          </div>
          <div class="panel-badge">
            <i class="bi bi-stars"></i> Highlight
          </div>
        </div>

        <?php if ($topCommunity): ?>
          <div class="feature-card">
            <strong><?= htmlspecialchars($topCommunity['name']) ?></strong>
            <span>
              <?= (int)$topCommunity['member_count'] ?> members · by @<?= htmlspecialchars($topCommunity['creator_username']) ?>
            </span>
            <div class="d-flex flex-wrap gap-2 mt-3">
              <a href="group_view.php?id=<?= (int)$topCommunity['id'] ?>" class="secondary-btn">
                <i class="bi bi-arrow-up-right-circle"></i>
                <span>Open group</span>
              </a>
            </div>
          </div>
        <?php else: ?>
          <div class="empty-note">Once groups are created, a featured community will appear here.</div>
        <?php endif; ?>
      </div>

      <div class="card-surface panel-card" id="create-group">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
          <div>
            <p class="eyebrow">Create</p>
            <h2 class="panel-title">Launch a new group</h2>
            <p class="panel-desc">Set up a room for your audience, code circle, or private community.</p>
          </div>
          <div class="panel-badge">
            <i class="bi bi-people"></i> Community setup
          </div>
        </div>

        <?php if ($canCreate): ?>
          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="create_group" value="1">

            <div class="mb-3">
              <label class="form-label">Group name</label>
              <input type="text" name="name" class="form-control" placeholder="e.g. Balkan Web Dev Elite" required>
            </div>

            <div class="mb-3">
              <label class="form-label">Description</label>
              <textarea name="description" rows="3" class="form-control" placeholder="What happens inside this group?"></textarea>
            </div>

            <div class="mb-3">
              <label class="form-label">Cover image</label>
              <input type="file" name="cover_image" class="form-control" accept="image/*">
            </div>

            <div class="option-grid mb-3">
              <div class="option-card">
                <div class="form-check mb-0">
                  <input class="form-check-input" type="checkbox" name="is_private" id="is_private">
                  <label class="form-check-label" for="is_private">Private group</label>
                </div>
                <div class="option-note">Invite or approval-based access.</div>
              </div>

              <div class="option-card">
                <div class="form-check mb-0">
                  <input class="form-check-input" type="checkbox" name="is_code_only" id="is_code_only">
                  <label class="form-check-label" for="is_code_only">Code group</label>
                </div>
                <div class="option-note">Built for snippets, technical posts, and dev discussion.</div>
              </div>

              <div class="option-card">
                <div class="form-check mb-0">
                  <input class="form-check-input" type="checkbox" name="is_paid" id="is_paid">
                  <label class="form-check-label" for="is_paid">Paid group</label>
                </div>
                <div class="option-note">Charge monthly for access.</div>
              </div>

              <div class="option-card">
                <div class="form-check mb-0">
                  <input class="form-check-input" type="checkbox" name="enable_invite" id="enable_invite">
                  <label class="form-check-label" for="enable_invite">Generate invite token</label>
                </div>
                <div class="option-note">Useful for shareable onboarding links later.</div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Price per month</label>
              <input type="number" step="0.01" min="0" name="price_month" class="form-control" placeholder="EUR / month">
            </div>

            <button type="submit" class="primary-btn">
              <i class="bi bi-plus-circle"></i>
              <span>Create group</span>
            </button>
          </form>
        <?php else: ?>
          <div class="locked-note">
            Only staff and premium members can create groups. You can still browse communities and join the ones that fit your goals.
          </div>

          <div class="quick-list">
            <div class="quick-item">
              <div class="quick-icon"><i class="bi bi-stars"></i></div>
              <div>
                <strong>Join existing spaces</strong>
                <span>Find relevant communities and become active before launching your own.</span>
              </div>
            </div>
            <div class="quick-item">
              <div class="quick-icon"><i class="bi bi-gem"></i></div>
              <div>
                <strong>Unlock premium later</strong>
                <span>Premium can later be used as a monetization or creator access layer.</span>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="card-surface directory-card">
    <div class="directory-top">
      <div>
        <p class="eyebrow">Directory</p>
        <h2 class="panel-title">Browse communities</h2>
        <p class="panel-desc">Search, filter, and explore groups with clearer member info, access badges, and direct actions.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <span class="filter-chip"><i class="bi bi-grid"></i> <?= $totalGroups ?> listed</span>
        <span class="filter-chip"><i class="bi bi-person-check"></i> <?= $joinedGroups ?> joined</span>
      </div>
    </div>

    <div class="filters-row">
      <div class="search-wrap">
        <i class="bi bi-search"></i>
        <input type="text" id="groupSearch" placeholder="Search groups by name or description...">
      </div>

      <button type="button" class="filter-chip active" data-filter="all">All</button>
      <button type="button" class="filter-chip" data-filter="joined">Joined</button>
      <button type="button" class="filter-chip" data-filter="free">Free</button>
      <button type="button" class="filter-chip" data-filter="paid">Paid</button>
      <button type="button" class="filter-chip" data-filter="private">Private</button>
      <button type="button" class="filter-chip" data-filter="code">Code</button>

      <select id="groupSort" class="form-select" style="max-width:200px;">
        <option value="newest">Newest first</option>
        <option value="members">Most members</option>
        <option value="name">Name A-Z</option>
      </select>
    </div>

    <?php if (empty($groups)): ?>
      <div class="empty-note">
        No groups yet. Once staff or premium users create a group, it will appear here.
      </div>
    <?php else: ?>
      <div class="groups-grid" id="groupsGrid">
        <?php foreach ($groups as $g): ?>
          <?php
            $gid       = (int)$g['id'];
            $isMem     = (bool)$g['is_member'];
            $isCreator = ($userId === (int)$g['created_by']);
            $cover     = $g['cover_image'] ?? null;
            $invite    = $g['invite_token'] ?? null;
            $codeGroup = !empty($g['is_code_only']);

            $tags = [];
            $tags[] = $isMem ? 'joined' : '';
            $tags[] = !empty($g['is_paid']) ? 'paid' : 'free';
            $tags[] = !empty($g['is_private']) ? 'private' : '';
            $tags[] = $codeGroup ? 'code' : '';
            $tags[] = 'all';
          ?>
          <article
            class="group-card"
            data-name="<?= htmlspecialchars(strtolower($g['name'])) ?>"
            data-description="<?= htmlspecialchars(strtolower($g['description'] ?? '')) ?>"
            data-members="<?= (int)$g['member_count'] ?>"
            data-created="<?= htmlspecialchars(strtotime($g['created_at'])) ?>"
            data-tags="<?= htmlspecialchars(trim(implode(' ', array_filter($tags)))) ?>"
          >
            <div class="group-cover <?= $cover ? 'has-image' : '' ?>" <?= $cover ? 'style="background-image:url(' . htmlspecialchars($cover) . ');"' : '' ?>>
              <div class="group-overlay">
                <span class="overlay-badge">
                  <i class="bi bi-person-workspace"></i> @<?= htmlspecialchars($g['creator_username']) ?>
                </span>
                <span class="overlay-members">
                  <i class="bi bi-people"></i> <?= (int)$g['member_count'] ?> members
                </span>
              </div>
            </div>

            <div class="group-content">
              <div class="group-head">
                <div>
                  <h3 class="group-name">
                    <a href="group_view.php?id=<?= $gid ?>"><?= htmlspecialchars($g['name']) ?></a>
                  </h3>
                  <div class="group-meta">
                    Created <?= htmlspecialchars(date('M j, Y', strtotime($g['created_at']))) ?>
                    <?php if ($isMem): ?> · You're a member<?php endif; ?>
                  </div>
                </div>
              </div>

              <p class="group-description"><?= htmlspecialchars($g['description'] ?? '') ?></p>

              <div class="badges-wrap">
                <?php if (!empty($g['is_paid'])): ?>
                  <span class="badge-pill badge-paid">
                    <i class="bi bi-gem"></i>
                    Paid<?= $g['price_month'] ? ' · ' . htmlspecialchars($g['price_month']) . ' €/month' : '' ?>
                  </span>
                <?php else: ?>
                  <span class="badge-pill badge-free"><i class="bi bi-unlock"></i> Free</span>
                <?php endif; ?>

                <?php if (!empty($g['is_private'])): ?>
                  <span class="badge-pill badge-private"><i class="bi bi-lock-fill"></i> Private</span>
                <?php endif; ?>

                <?php if ($codeGroup): ?>
                  <span class="badge-pill badge-code"><i class="bi bi-code-slash"></i> Code</span>
                <?php endif; ?>
              </div>

              <?php if ($isCreator && $invite): ?>
                <div class="invite-chip">
                  <i class="bi bi-link-45deg"></i>
                  Invite token: <code><?= htmlspecialchars($invite) ?></code>
                </div>
              <?php endif; ?>

              <div class="group-footer">
                <div class="group-status">
                  <?= $isMem ? 'You can open the group immediately.' : 'Join to unlock the full group feed.' ?>
                </div>

                <div class="d-flex flex-wrap gap-2">
                  <?php if ($isMem): ?>
                    <form method="POST" class="inline-form">
                      <input type="hidden" name="group_id" value="<?= $gid ?>">
                      <input type="hidden" name="group_action" value="leave">
                      <button type="submit" class="secondary-btn">
                        <i class="bi bi-box-arrow-left"></i>
                        <span>Leave</span>
                      </button>
                    </form>

                    <a href="group_view.php?id=<?= $gid ?>" class="primary-btn">
                      <i class="bi bi-door-open"></i>
                      <span>Enter</span>
                    </a>
                  <?php else: ?>
                    <form method="POST" class="inline-form">
                      <input type="hidden" name="group_id" value="<?= $gid ?>">
                      <input type="hidden" name="group_action" value="join">
                      <button type="submit" class="primary-btn">
                        <i class="bi bi-plus-circle"></i>
                        <span>Join group</span>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<script>
(() => {
  const searchInput = document.getElementById('groupSearch');
  const sortSelect = document.getElementById('groupSort');
  const chips = document.querySelectorAll('.filter-chip[data-filter]');
  const grid = document.getElementById('groupsGrid');
  if (!grid) return;

  let activeFilter = 'all';

  function applyFilters() {
    const query = (searchInput?.value || '').trim().toLowerCase();
    const sort = sortSelect?.value || 'newest';
    const cards = Array.from(grid.querySelectorAll('.group-card'));

    cards.forEach(card => {
      const name = card.dataset.name || '';
      const description = card.dataset.description || '';
      const tags = (card.dataset.tags || '').split(/\s+/);

      const matchesSearch = !query || name.includes(query) || description.includes(query);
      const matchesFilter = activeFilter === 'all' || tags.includes(activeFilter);

      card.classList.toggle('hidden-group', !(matchesSearch && matchesFilter));
    });

    const visibleCards = cards.filter(card => !card.classList.contains('hidden-group'));

    visibleCards.sort((a, b) => {
      if (sort === 'members') {
        return Number(b.dataset.members) - Number(a.dataset.members);
      }
      if (sort === 'name') {
        return (a.dataset.name || '').localeCompare(b.dataset.name || '');
      }
      return Number(b.dataset.created) - Number(a.dataset.created);
    });

    visibleCards.forEach(card => grid.appendChild(card));
  }

  chips.forEach(chip => {
    chip.addEventListener('click', () => {
      chips.forEach(c => c.classList.remove('active'));
      chip.classList.add('active');
      activeFilter = chip.dataset.filter || 'all';
      applyFilters();
    });
  });

  searchInput?.addEventListener('input', applyFilters);
  sortSelect?.addEventListener('change', applyFilters);
})();
</script>

<?php render_app_shell_end(['active' => 'groups']); ?>
</body>
</html>