$ErrorActionPreference = 'Stop'

function ReplaceRegex($Path, $Pattern, $Replacement) {
  $content = Get-Content -LiteralPath $Path -Raw
  $newContent = [regex]::Replace($content, $Pattern, $Replacement, [System.Text.RegularExpressions.RegexOptions]::Singleline)
  if ($newContent -eq $content) { throw "Pattern not found in $Path" }
  Set-Content -LiteralPath $Path -Value $newContent
}

$pages = @(
  'C:\xampp\htdocs\dsdd\dashboard.php',
  'C:\xampp\htdocs\dsdd\messages.php',
  'C:\xampp\htdocs\dsdd\groups.php',
  'C:\xampp\htdocs\dsdd\group_view.php',
  'C:\xampp\htdocs\dsdd\user_profile.php',
  'C:\xampp\htdocs\dsdd\settings.php'
)

foreach ($page in $pages) {
  $content = Get-Content -LiteralPath $page -Raw
  $content = $content.Replace('<link rel="stylesheet" href="assets/app-shell.css">`r`n<style>', "<link rel=\"stylesheet\" href=\"assets/app-shell.css\">`r`n<style>")
  Set-Content -LiteralPath $page -Value $content
}

$dashboardBlock = @"
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
"@
ReplaceRegex 'C:\xampp\htdocs\dsdd\dashboard.php' '\?>\s*= <<<HTML.*?<\?php require_once __DIR__ \. ''/includes/app_shell\.php''; \?>' $dashboardBlock

ReplaceRegex 'C:\xampp\htdocs\dsdd\messages.php' '<\?php render_app_shell_start\(\[.*?\]\); \?>\s*<div class=\\' "<?php render_app_shell_start(['active' => 'messages', 'context_label' => 'Inbox', 'context_title' => 'Messages', 'context_subtitle' => 'Private conversations, replies, and DMs in one shared workspace.', 'body_class' => (($currentConvId && $chatPartner) ? 'conversation-open' : '')]); ?>`r`n<div class=\"shell\">"
