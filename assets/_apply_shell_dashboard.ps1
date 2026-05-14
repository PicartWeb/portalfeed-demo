$ErrorActionPreference = 'Stop'
function ReplaceRegex($Path, $Pattern, $Replacement) {
  $content = Get-Content -LiteralPath $Path -Raw
  $newContent = [regex]::Replace($content, $Pattern, $Replacement, [System.Text.RegularExpressions.RegexOptions]::Singleline)
  if ($newContent -eq $content) { throw "Pattern not found in $Path" }
  Set-Content -LiteralPath $Path -Value $newContent
}

$path = 'C:\xampp\htdocs\dsdd\dashboard.php'
$content = Get-Content -LiteralPath $path -Raw
if ($content -notmatch '\$dashboardSearchHtml') {
  $insert = @"
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

"@
  $content = $content -replace "\?>\r?\n<\?php require_once __DIR__ \. '/includes/app_shell\.php'; \?>", "?>`r`n$insert<?php require_once __DIR__ . '/includes/app_shell.php'; ?>"
  Set-Content -LiteralPath $path -Value $content
}

ReplaceRegex $path '<body>\s*<div class="app-shell">\s*<!-- ============ HEADER ============ -->\s*<header class="dash-top">.*?</header>' "<?php render_app_shell_start(['active' => 'dashboard', 'context_label' => 'Feed', 'context_title' => 'Portal Feed', 'context_subtitle' => 'A clean social workspace for your network and work.', 'search_html' => `$dashboardSearchHtml, 'notifications_html' => `$dashboardNotificationsHtml, 'extra_actions_html' => `$dashboardExtraActionsHtml, 'messages_badge' => `$dmUnreadCount]); ?>`r`n<div class=\"app-shell\">"

$content = Get-Content -LiteralPath $path -Raw
if ($content -notmatch 'render_app_shell_end') {
  $content = $content -replace '</body>', "<?php render_app_shell_end(['active' => 'dashboard']); ?>`r`n</body>"
  Set-Content -LiteralPath $path -Value $content
}
