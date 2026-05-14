<?php
// messages.php
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';

$userId   = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'];
$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? 'member';
$avatar   = $_SESSION['avatar'] ?? null;

/* ====== CONVERSATION LIST (all people you DMed / DMed you) ====== */
$convStmt = $pdo->prepare("
  SELECT c.id,
         CASE WHEN c.user1_id = :me1 THEN c.user2_id ELSE c.user1_id END AS other_id,
         u.username, u.full_name, u.avatar,
         (
           SELECT body FROM messages m2
           WHERE m2.conversation_id = c.id
           ORDER BY m2.created_at DESC
           LIMIT 1
         ) AS last_body,
         (
           SELECT created_at FROM messages m3
           WHERE m3.conversation_id = c.id
           ORDER BY m3.created_at DESC
           LIMIT 1
         ) AS last_time,
         (
           SELECT COUNT(*)
           FROM messages m4
           WHERE m4.conversation_id = c.id
             AND m4.sender_id <> :me2
             AND m4.read_at IS NULL
         ) AS unread_count
  FROM conversations c
  JOIN users u
    ON u.id = CASE WHEN c.user1_id = :me3 THEN c.user2_id ELSE c.user1_id END
  WHERE c.user1_id = :me4 OR c.user2_id = :me5
  ORDER BY last_time DESC
");
$convStmt->execute([
    ':me1' => $userId,
    ':me2' => $userId,
    ':me3' => $userId,
    ':me4' => $userId,
    ':me5' => $userId,
]);
$conversations = $convStmt->fetchAll();

/* ====== WHICH CONVERSATION IS OPEN ====== */
$currentConvId = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
$targetUserId  = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($currentConvId === 0 && $targetUserId > 0 && $targetUserId !== $userId) {
    // find or create conversation with that user
    $u1 = min($userId, $targetUserId);
    $u2 = max($userId, $targetUserId);

    $findStmt = $pdo->prepare("
      SELECT id FROM conversations
      WHERE user1_id = :u1 AND user2_id = :u2
      LIMIT 1
    ");
    $findStmt->execute([':u1' => $u1, ':u2' => $u2]);
    $row = $findStmt->fetch();

    if ($row) {
        $currentConvId = (int)$row['id'];
    } else {
        $ins = $pdo->prepare("
          INSERT INTO conversations (user1_id, user2_id)
          VALUES (:u1, :u2)
        ");
        $ins->execute([':u1' => $u1, ':u2' => $u2]);
        $currentConvId = (int)$pdo->lastInsertId();
    }

    header('Location: messages.php?conversation_id=' . $currentConvId);
    exit;
}

/* ====== LOAD CURRENT CONVERSATION ====== */
$messages = [];
$chatPartner = null;

if ($currentConvId) {
    // verify user is participant + load partner
    $checkStmt = $pdo->prepare("
      SELECT c.*,
             CASE WHEN c.user1_id = :me1 THEN c.user2_id ELSE c.user1_id END AS other_id,
             u.username, u.full_name, u.avatar
      FROM conversations c
      JOIN users u
        ON u.id = CASE WHEN c.user1_id = :me2 THEN c.user2_id ELSE c.user1_id END
      WHERE c.id = :cid
        AND (c.user1_id = :me3 OR c.user2_id = :me4)
      LIMIT 1
    ");
    $checkStmt->execute([
        ':cid' => $currentConvId,
        ':me1' => $userId,
        ':me2' => $userId,
        ':me3' => $userId,
        ':me4' => $userId,
    ]);
    $chatPartner = $checkStmt->fetch();

    if ($chatPartner) {
        // messages
        $msgStmt = $pdo->prepare("
          SELECT m.*, u.username, u.full_name, u.avatar
          FROM messages m
          JOIN users u ON u.id = m.sender_id
          WHERE m.conversation_id = :cid
          ORDER BY m.created_at ASC
        ");
        $msgStmt->execute([':cid' => $currentConvId]);
        $messages = $msgStmt->fetchAll();

        // mark their messages as read
        $markStmt = $pdo->prepare("
          UPDATE messages
          SET read_at = NOW()
          WHERE conversation_id = :cid AND sender_id <> :me AND read_at IS NULL
        ");
        $markStmt->execute([':cid' => $currentConvId, ':me' => $userId]);

        // also mark 'message' notifications for this convo as read
        $notifStmt = $pdo->prepare("
          UPDATE notifications
          SET is_read = 1, read_at = NOW()
          WHERE to_user_id = :uid
            AND type = 'message'
            AND ref_id = :cid
            AND is_read = 0
        ");
        $notifStmt->execute([':uid' => $userId, ':cid' => $currentConvId]);
    } else {
        $currentConvId = 0;
    }
}
?>
<?php require_once __DIR__ . '/includes/app_shell.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Messages | Social Platform</title>
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
      --surface-muted:#eef2f7;
      --border:#e2e8f0;
      --border-strong:#d7dfeb;
      --text:#0f172a;
      --text-soft:#475569;
      --text-muted:#64748b;
      --accent:#2563eb;
      --accent-soft:#dbeafe;
      --accent-strong:#1d4ed8;
      --shadow:0 20px 50px rgba(15, 23, 42, 0.08);
      --shadow-soft:0 10px 24px rgba(15, 23, 42, 0.06);
      --radius-xl:28px;
      --radius-lg:20px;
      --radius-md:16px;
      --radius-sm:12px;
    }

    *{box-sizing:border-box;}

    body{
      margin:0;
      min-height:100vh;
      background:
        radial-gradient(circle at top left, rgba(37,99,235,.10), transparent 24%),
        radial-gradient(circle at bottom right, rgba(59,130,246,.09), transparent 22%),
        var(--bg);
      color:var(--text);
      font-family:"Plus Jakarta Sans","Segoe UI",sans-serif;
    }

    a{
      color:inherit;
      text-decoration:none;
    }

    .shell{
      min-height:100vh;
      display:flex;
      flex-direction:column;
    }

    .topbar{
      position:sticky;
      top:0;
      z-index:30;
      padding:18px 24px;
      background:rgba(255,255,255,.88);
      backdrop-filter:blur(18px);
      border-bottom:1px solid rgba(226,232,240,.9);
    }

    .topbar-inner{
      max-width:1280px;
      margin:0 auto;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
    }

    .brand-wrap{
      display:flex;
      align-items:center;
      gap:14px;
      min-width:0;
    }

    .brand-mark{
      width:44px;
      height:44px;
      border-radius:14px;
      display:grid;
      place-items:center;
      background:linear-gradient(135deg, #2563eb, #60a5fa);
      color:#fff;
      box-shadow:0 12px 24px rgba(37,99,235,.22);
      font-size:1.15rem;
    }

    .brand-copy{
      min-width:0;
    }

    .eyebrow{
      margin:0;
      font-size:.72rem;
      letter-spacing:.16em;
      text-transform:uppercase;
      color:var(--text-muted);
      font-weight:700;
    }

    .brand-title{
      margin:2px 0 0;
      font-size:1.15rem;
      font-weight:800;
      color:var(--text);
    }

    .brand-subtitle{
      margin:4px 0 0;
      font-size:.88rem;
      color:var(--text-soft);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }

    .top-actions{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
      justify-content:flex-end;
    }

    .pill-link{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:11px 16px;
      border-radius:999px;
      background:var(--surface);
      border:1px solid var(--border);
      box-shadow:var(--shadow-soft);
      font-size:.88rem;
      font-weight:600;
      color:var(--text-soft);
      transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease, color .18s ease;
    }

    .pill-link:hover{
      transform:translateY(-1px);
      border-color:rgba(37,99,235,.28);
      color:var(--accent);
      box-shadow:0 14px 26px rgba(37,99,235,.10);
    }

    .workspace{
      width:100%;
      max-width:1280px;
      margin:0 auto;
      padding:28px 24px 34px;
    }

    .messenger-shell{
      background:rgba(255,255,255,.84);
      border:1px solid rgba(226,232,240,.9);
      border-radius:var(--radius-xl);
      box-shadow:var(--shadow);
      overflow:hidden;
    }

    .messenger-grid{
      display:grid;
      grid-template-columns:340px minmax(0, 1fr);
      min-height:calc(100vh - 172px);
      background:linear-gradient(180deg, rgba(248,250,252,.92), rgba(255,255,255,.95));
    }

    .sidebar{
      display:flex;
      flex-direction:column;
      border-right:1px solid var(--border);
      background:rgba(248,250,252,.88);
      min-width:0;
    }

    .sidebar-head{
      padding:24px 22px 18px;
      border-bottom:1px solid var(--border);
      display:flex;
      flex-direction:column;
      gap:16px;
    }

    .sidebar-summary{
      display:flex;
      align-items:center;
      gap:14px;
      min-width:0;
    }

    .avatar{
      width:48px;
      height:48px;
      border-radius:16px;
      object-fit:cover;
      flex:0 0 auto;
      box-shadow:0 10px 22px rgba(15,23,42,.10);
      background:#dbeafe;
    }

    .avatar-lg{
      width:54px;
      height:54px;
      border-radius:18px;
    }

    .avatar-sm{
      width:44px;
      height:44px;
      border-radius:14px;
    }

    .sidebar-name,
    .chat-name{
      margin:0;
      font-size:1rem;
      font-weight:700;
      color:var(--text);
    }

    .sidebar-meta,
    .chat-meta{
      margin:4px 0 0;
      font-size:.86rem;
      color:var(--text-muted);
    }

    .search-card{
      position:relative;
    }

    .search-card i{
      position:absolute;
      left:14px;
      top:50%;
      transform:translateY(-50%);
      color:#94a3b8;
      font-size:.92rem;
      pointer-events:none;
    }

    .search-input{
      width:100%;
      border:1px solid var(--border);
      background:var(--surface);
      border-radius:14px;
      padding:13px 14px 13px 40px;
      font-size:.9rem;
      color:var(--text);
      outline:none;
      transition:border-color .18s ease, box-shadow .18s ease;
    }

    .search-input:focus{
      border-color:rgba(37,99,235,.42);
      box-shadow:0 0 0 4px rgba(37,99,235,.12);
    }

    .conversation-list{
      flex:1;
      overflow-y:auto;
      padding:14px 14px 18px;
    }

    .conversation-empty,
    .chat-empty{
      border:1px dashed var(--border-strong);
      background:rgba(255,255,255,.78);
      color:var(--text-soft);
      border-radius:18px;
      padding:22px 18px;
      font-size:.92rem;
      line-height:1.6;
    }

    .conversation-item{
      position:relative;
      display:flex;
      align-items:center;
      gap:14px;
      padding:14px;
      border-radius:18px;
      border:1px solid transparent;
      transition:background .18s ease, border-color .18s ease, transform .18s ease, box-shadow .18s ease;
      margin-bottom:8px;
      background:transparent;
    }

    .conversation-item:hover{
      background:rgba(255,255,255,.92);
      border-color:rgba(226,232,240,.92);
      transform:translateY(-1px);
      box-shadow:0 12px 24px rgba(15,23,42,.05);
    }

    .conversation-item.active{
      background:linear-gradient(180deg, #ffffff, #f8fbff);
      border-color:rgba(37,99,235,.22);
      box-shadow:0 16px 30px rgba(37,99,235,.10);
    }

    .conversation-copy{
      min-width:0;
      flex:1;
    }

    .conversation-row{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      margin-bottom:4px;
    }

    .conversation-name{
      font-size:.92rem;
      font-weight:700;
      color:var(--text);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }

    .conversation-time{
      flex:0 0 auto;
      font-size:.74rem;
      font-weight:600;
      color:var(--text-muted);
    }

    .conversation-preview{
      margin:0;
      font-size:.84rem;
      color:var(--text-soft);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      padding-right:40px;
    }

    .conversation-unread{
      position:absolute;
      right:14px;
      bottom:15px;
      min-width:22px;
      height:22px;
      border-radius:999px;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:0 6px;
      background:var(--accent);
      color:#fff;
      font-size:.72rem;
      font-weight:700;
      box-shadow:0 10px 18px rgba(37,99,235,.24);
    }

    .chat-panel{
      display:flex;
      flex-direction:column;
      min-width:0;
      background:
        linear-gradient(180deg, rgba(255,255,255,.92), rgba(249,250,251,.98)),
        repeating-linear-gradient(135deg, rgba(148,163,184,.05) 0 1px, transparent 1px 14px);
    }

    .chat-header{
      padding:22px 24px;
      border-bottom:1px solid var(--border);
      background:rgba(255,255,255,.92);
      display:flex;
      align-items:center;
      gap:16px;
      flex-wrap:wrap;
    }

    .mobile-back{
      display:none;
      width:42px;
      height:42px;
      border-radius:14px;
      align-items:center;
      justify-content:center;
      border:1px solid var(--border);
      background:var(--surface);
      color:var(--text-soft);
      box-shadow:var(--shadow-soft);
      flex:0 0 auto;
    }

    .chat-copy{
      min-width:0;
      flex:1;
    }

    .chat-actions{
      display:flex;
      align-items:center;
      gap:10px;
      margin-left:auto;
      flex-wrap:wrap;
    }

    .chat-stat{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:10px 14px;
      border-radius:999px;
      background:var(--surface-soft);
      border:1px solid var(--border);
      font-size:.82rem;
      color:var(--text-soft);
      font-weight:600;
    }

    .chat-stat i{
      color:var(--accent);
    }

    .profile-button{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:11px 16px;
      border-radius:999px;
      background:var(--accent);
      border:1px solid var(--accent);
      color:#fff;
      font-size:.86rem;
      font-weight:700;
      box-shadow:0 12px 22px rgba(37,99,235,.20);
      transition:transform .18s ease, background .18s ease, box-shadow .18s ease;
    }

    .profile-button:hover{
      transform:translateY(-1px);
      background:var(--accent-strong);
      box-shadow:0 16px 26px rgba(37,99,235,.24);
      color:#fff;
    }

    .chat-empty-wrap{
      flex:1;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:36px 24px;
    }

    .chat-empty{
      max-width:420px;
      text-align:center;
      padding:34px 28px;
      background:rgba(255,255,255,.9);
    }

    .chat-empty-icon{
      width:72px;
      height:72px;
      border-radius:24px;
      display:grid;
      place-items:center;
      margin:0 auto 18px;
      background:linear-gradient(135deg, #dbeafe, #eff6ff);
      color:var(--accent);
      font-size:1.8rem;
    }

    .chat-empty h2{
      margin:0 0 10px;
      font-size:1.35rem;
      font-weight:800;
    }

    .chat-empty p{
      margin:0;
      color:var(--text-soft);
      line-height:1.65;
    }

    .message-stream{
      flex:1;
      overflow-y:auto;
      padding:24px 24px 12px;
      scroll-behavior:smooth;
    }

    .message-day{
      text-align:center;
      margin:8px 0 20px;
    }

    .message-day span{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 14px;
      border-radius:999px;
      background:rgba(255,255,255,.86);
      border:1px solid var(--border);
      color:var(--text-muted);
      font-size:.76rem;
      font-weight:700;
      letter-spacing:.08em;
      text-transform:uppercase;
    }

    .message-row{
      display:flex;
      gap:12px;
      margin-bottom:16px;
      align-items:flex-end;
    }

    .message-row.mine{
      justify-content:flex-end;
    }

    .message-row.them{
      justify-content:flex-start;
    }

    .message-author{
      width:36px;
      height:36px;
      border-radius:12px;
      object-fit:cover;
      flex:0 0 auto;
      box-shadow:0 8px 16px rgba(15,23,42,.08);
    }

    .message-cluster{
      max-width:min(72%, 580px);
      display:flex;
      flex-direction:column;
      gap:6px;
    }

    .bubble{
      padding:14px 16px;
      border-radius:20px;
      font-size:.92rem;
      line-height:1.6;
      word-break:break-word;
      box-shadow:var(--shadow-soft);
      white-space:normal;
    }

    .bubble.them{
      background:#ffffff;
      border:1px solid rgba(226,232,240,.95);
      color:var(--text);
      border-bottom-left-radius:8px;
    }

    .bubble.mine{
      background:linear-gradient(135deg, #2563eb, #3b82f6);
      color:#ffffff;
      border-bottom-right-radius:8px;
    }

    .message-meta{
      font-size:.76rem;
      color:var(--text-muted);
      padding:0 4px;
    }

    .message-row.mine .message-meta{
      text-align:right;
    }

    .composer-wrap{
      padding:18px 24px 24px;
      border-top:1px solid var(--border);
      background:rgba(255,255,255,.95);
      position:sticky;
      bottom:0;
    }

    .composer{
      display:flex;
      align-items:flex-end;
      gap:12px;
      padding:12px;
      border-radius:22px;
      border:1px solid rgba(226,232,240,.96);
      background:linear-gradient(180deg, #ffffff, #f8fafc);
      box-shadow:0 12px 28px rgba(15,23,42,.06);
    }

    .composer textarea{
      flex:1;
      border:none;
      background:transparent;
      resize:none;
      min-height:26px;
      max-height:180px;
      padding:10px 12px;
      font-size:.94rem;
      line-height:1.55;
      color:var(--text);
      outline:none;
    }

    .composer textarea::placeholder{
      color:#94a3b8;
    }

    .send-button{
      width:52px;
      height:52px;
      border:none;
      border-radius:18px;
      display:grid;
      place-items:center;
      background:linear-gradient(135deg, #2563eb, #60a5fa);
      color:#fff;
      font-size:1.05rem;
      box-shadow:0 14px 28px rgba(37,99,235,.22);
      transition:transform .18s ease, box-shadow .18s ease, filter .18s ease;
      flex:0 0 auto;
    }

    .send-button:hover{
      transform:translateY(-1px);
      filter:brightness(.98);
      box-shadow:0 18px 30px rgba(37,99,235,.26);
    }

    .send-button:focus-visible,
    .mobile-back:focus-visible,
    .pill-link:focus-visible,
    .profile-button:focus-visible{
      outline:3px solid rgba(37,99,235,.18);
      outline-offset:2px;
    }

    .no-match{
      display:none;
      margin-top:10px;
      padding:14px 16px;
      border-radius:14px;
      background:rgba(255,255,255,.88);
      border:1px dashed var(--border-strong);
      color:var(--text-soft);
      font-size:.86rem;
    }

    @media (max-width: 991.98px){
      .workspace{
        padding:20px 16px 24px;
      }

      .messenger-grid{
        grid-template-columns:300px minmax(0, 1fr);
        min-height:calc(100vh - 150px);
      }

      .chat-actions{
        width:100%;
        margin-left:0;
      }
    }

    @media (max-width: 767.98px){
      .topbar{
        padding:14px 16px;
      }

      .topbar-inner{
        align-items:flex-start;
      }

      .brand-subtitle{
        white-space:normal;
      }

      .workspace{
        padding:14px 10px 18px;
      }

      .messenger-shell{
        border-radius:22px;
      }

      .messenger-grid{
        display:block;
        min-height:calc(100vh - 138px);
      }

      .sidebar{
        border-right:none;
        min-height:calc(100vh - 138px);
      }

      .chat-panel{
        min-height:calc(100vh - 138px);
      }

      body.conversation-open .sidebar{
        display:none;
      }

      body:not(.conversation-open) .chat-panel{
        display:none;
      }

      .mobile-back{
        display:inline-flex;
      }

      .chat-header{
        padding:16px;
      }

      .sidebar-head{
        padding:18px 16px 16px;
      }

      .conversation-list{
        padding:12px;
      }

      .message-stream{
        padding:18px 14px 10px;
      }

      .composer-wrap{
        padding:14px;
      }

      .composer{
        padding:10px;
        border-radius:18px;
      }

      .message-cluster{
        max-width:84%;
      }

      .chat-stat{
        display:none;
      }
    }
  </style>
</head>
<?php render_app_shell_start(['active' => 'messages', 'context_label' => 'Inbox', 'context_title' => 'Messages', 'context_subtitle' => 'Private conversations, replies, and DMs in one shared workspace.', 'body_class' => (($currentConvId && $chatPartner) ? 'conversation-open' : '')]); ?>
<div class="shell">

  <main class="workspace">
    <section class="messenger-shell">
      <div class="messenger-grid">
        <aside class="sidebar">
          <div class="sidebar-head">
            <div class="sidebar-summary">
              <?php
                $selfAvatar = $avatar ?: 'https://ui-avatars.com/api/?name=' . urlencode($fullName ?: $username ?: 'You') . '&background=dbeafe&color=1d4ed8&rounded=true&size=96';
              ?>
              <img src="<?= htmlspecialchars($selfAvatar) ?>" class="avatar avatar-lg" alt="">
              <div class="flex-grow-1 min-w-0">
                <p class="sidebar-name"><?= htmlspecialchars($fullName) ?></p>
                <p class="sidebar-meta">@<?= htmlspecialchars($username) ?> · <?= count($conversations) ?> conversation<?= count($conversations) === 1 ? '' : 's' ?></p>
              </div>
            </div>
            <div class="search-card">
              <i class="bi bi-search"></i>
              <input type="text" class="search-input" id="conversationSearch" placeholder="Search conversations">
            </div>
          </div>

          <div class="conversation-list" id="conversationList">
            <?php if (empty($conversations)): ?>
              <div class="conversation-empty">
                No chats yet. Open a profile and hit <strong>Message</strong> to start your first conversation.
              </div>
            <?php else: ?>
              <?php foreach ($conversations as $c): ?>
                <?php
                  $otherName   = $c['full_name'] ?: $c['username'];
                  $convAvatar  = $c['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($otherName) . '&background=dbeafe&color=1d4ed8&rounded=true&size=96';
                  $active      = ($currentConvId == $c['id']);
                  $preview     = $c['last_body'] ? mb_strimwidth($c['last_body'], 0, 58, '...', 'UTF-8') : 'No messages yet';
                  $unreadCount = (int)$c['unread_count'];
                  $timeLabel   = $c['last_time'] ? date('M j', strtotime($c['last_time'])) : '';
                ?>
                <a
                  href="messages.php?conversation_id=<?= (int)$c['id'] ?>"
                  class="conversation-item <?= $active ? 'active' : '' ?>"
                  data-conversation-search="<?= htmlspecialchars(strtolower(trim($otherName . ' ' . $c['username'] . ' ' . $preview)), ENT_QUOTES) ?>"
                >
                  <img src="<?= htmlspecialchars($convAvatar) ?>" class="avatar avatar-sm" alt="">
                  <div class="conversation-copy">
                    <div class="conversation-row">
                      <div class="conversation-name"><?= htmlspecialchars($otherName) ?></div>
                      <?php if ($timeLabel): ?>
                        <div class="conversation-time"><?= htmlspecialchars($timeLabel) ?></div>
                      <?php endif; ?>
                    </div>
                    <p class="conversation-preview"><?= htmlspecialchars($preview) ?></p>
                  </div>
                  <?php if ($unreadCount > 0): ?>
                    <div class="conversation-unread"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></div>
                  <?php endif; ?>
                </a>
              <?php endforeach; ?>
              <div class="no-match" id="conversationNoMatch">No conversations match your search yet.</div>
            <?php endif; ?>
          </div>
        </aside>

        <section class="chat-panel">
          <?php if (!$currentConvId || !$chatPartner): ?>
            <div class="chat-empty-wrap">
              <div class="chat-empty">
                <div class="chat-empty-icon">
                  <i class="bi bi-chat-square-text"></i>
                </div>
                <h2>Select a conversation</h2>
                <p>Choose a chat from the left to read messages, reply quickly, and keep your inbox organized.</p>
              </div>
            </div>
          <?php else: ?>
            <?php
              $otherName   = $chatPartner['full_name'] ?: $chatPartner['username'];
              $otherAvatar = $chatPartner['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($otherName) . '&background=dbeafe&color=1d4ed8&rounded=true&size=96';
            ?>
            <div class="chat-header">
              <a href="messages.php" class="mobile-back" aria-label="Back to conversations">
                <i class="bi bi-arrow-left"></i>
              </a>
              <img src="<?= htmlspecialchars($otherAvatar) ?>" class="avatar avatar-lg" alt="">
              <div class="chat-copy">
                <p class="chat-name"><?= htmlspecialchars($otherName) ?></p>
                <p class="chat-meta">@<?= htmlspecialchars($chatPartner['username']) ?></p>
              </div>
              <div class="chat-actions">
                <div class="chat-stat">
                  <i class="bi bi-shield-check"></i>
                  <span>Private conversation</span>
                </div>
                <a href="user_profile.php?user_id=<?= (int)$chatPartner['other_id'] ?>" class="profile-button">
                  <i class="bi bi-person"></i>
                  <span>View profile</span>
                </a>
              </div>
            </div>

            <div class="message-stream" id="msgStream">
              <?php
                $lastDateLabel = null;
              ?>
              <?php foreach ($messages as $m): ?>
                <?php
                  $mine = ($m['sender_id'] == $userId);
                  $messageDateLabel = date('M j, Y', strtotime($m['created_at']));
                  $senderName = $m['full_name'] ?: $m['username'];
                  $senderAvatar = $m['avatar'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($senderName) . '&background=dbeafe&color=1d4ed8&rounded=true&size=72';
                ?>
                <?php if ($messageDateLabel !== $lastDateLabel): ?>
                  <div class="message-day">
                    <span><?= htmlspecialchars($messageDateLabel) ?></span>
                  </div>
                  <?php $lastDateLabel = $messageDateLabel; ?>
                <?php endif; ?>
                <div class="message-row <?= $mine ? 'mine' : 'them' ?>">
                  <?php if (!$mine): ?>
                    <img src="<?= htmlspecialchars($senderAvatar) ?>" class="message-author" alt="">
                  <?php endif; ?>
                  <div class="message-cluster">
                    <div class="bubble <?= $mine ? 'mine' : 'them' ?>">
                      <?= nl2br(htmlspecialchars($m['body'])) ?>
                    </div>
                    <div class="message-meta"><?= htmlspecialchars(date('g:i A', strtotime($m['created_at']))) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="composer-wrap">
              <form action="message_send.php" method="POST" class="composer">
                <input type="hidden" name="conversation_id" value="<?= (int)$currentConvId ?>">
                <textarea name="body" id="messageBody" rows="1" placeholder="Write a message..." required></textarea>
                <button type="submit" class="send-button" aria-label="Send message">
                  <i class="bi bi-send-fill"></i>
                </button>
              </form>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </section>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const stream = document.getElementById('msgStream');
if (stream) {
  stream.scrollTop = stream.scrollHeight;
}

const textarea = document.getElementById('messageBody');
if (textarea) {
  const resizeComposer = () => {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 180) + 'px';
  };
  textarea.addEventListener('input', resizeComposer);
  resizeComposer();
}

const searchInput = document.getElementById('conversationSearch');
const conversationItems = Array.from(document.querySelectorAll('.conversation-item'));
const noMatch = document.getElementById('conversationNoMatch');

if (searchInput && conversationItems.length) {
  searchInput.addEventListener('input', () => {
    const term = searchInput.value.trim().toLowerCase();
    let visibleCount = 0;

    conversationItems.forEach((item) => {
      const haystack = item.getAttribute('data-conversation-search') || '';
      const match = !term || haystack.includes(term);
      item.style.display = match ? '' : 'none';
      if (match) visibleCount += 1;
    });

    if (noMatch) {
      noMatch.style.display = visibleCount === 0 ? 'block' : 'none';
    }
  });
}
</script>
<?php render_app_shell_end(['active' => 'messages']); ?>
</body>
</html>






