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
  if ($content -notmatch 'includes/app_shell.php') {
    $content = $content -replace "\?>\r?\n<!DOCTYPE html>", "?>`r`n<?php require_once __DIR__ . '/includes/app_shell.php'; ?>`r`n<!DOCTYPE html>"
  }
  if ($content -notmatch 'assets/app-shell.css') {
    $content = $content -replace '<style>', '<link rel="stylesheet" href="assets/app-shell.css">`r`n<style>'
  }
  Set-Content -LiteralPath $page -Value $content
}

ReplaceRegex 'C:\xampp\htdocs\dsdd\messages.php' '<body class="<\?= \(\$currentConvId && \$chatPartner\) \? ''conversation-open'' : '''' \?>">\s*<div class="shell">\s*<header class="topbar">.*?</header>' "<?php render_app_shell_start(['active' => 'messages', 'context_label' => 'Inbox', 'context_title' => 'Messages', 'context_subtitle' => 'Private conversations, replies, and DMs in one shared workspace.', 'body_class' => (($currentConvId && `$chatPartner) ? 'conversation-open' : '')]); ?>`r`n<div class=\"shell\">"
ReplaceRegex 'C:\xampp\htdocs\dsdd\groups.php' '<body>\s*<div class="shell">\s*<div class="topbar">.*?</div>\s*</div>\s*\s*<section class="hero-panel">' "<?php render_app_shell_start(['active' => 'groups', 'context_label' => 'Communities', 'context_title' => 'Groups', 'context_subtitle' => 'Discover focused communities, premium rooms, and code-first circles.']); ?>`r`n<div class=\"shell\">`r`n  <section class=\"hero-panel\">"
ReplaceRegex 'C:\xampp\htdocs\dsdd\group_view.php' '<body>\s*<div class="shell">\s*<div class="topbar">.*?</div>' "<?php render_app_shell_start(['active' => 'groups', 'context_label' => 'Community', 'context_title' => `$group['name'], 'context_subtitle' => 'Group posts, discussion, code sharing, and member collaboration.']); ?>`r`n<div class=\"shell\">"
ReplaceRegex 'C:\xampp\htdocs\dsdd\user_profile.php' '<body>\s*<div class="shell">\s*  <div class="page-wrap">\s*    <div class="topbar">.*?</div>\s*\s*    <section class="profile-hero">' "<?php render_app_shell_start(['active' => 'profile', 'context_label' => 'Profile', 'context_title' => `$fullName, 'context_subtitle' => 'Identity, activity, and social proof in one creator-style profile.']); ?>`r`n<div class=\"shell\">`r`n  <div class=\"page-wrap\">`r`n    <section class=\"profile-hero\">"
ReplaceRegex 'C:\xampp\htdocs\dsdd\settings.php' '<body>\s*<div class="shell">\s*  <div class="page-wrap">\s*    <div class="topbar">.*?</div>\s*\s*    <div class="settings-shell">' "<?php render_app_shell_start(['active' => 'settings', 'context_label' => 'Settings', 'context_title' => 'Profile Settings', 'context_subtitle' => 'Manage your public profile, identity, and creator links.']); ?>`r`n<div class=\"shell\">`r`n  <div class=\"page-wrap\">`r`n    <div class=\"settings-shell\">"

$map = @{
  'C:\xampp\htdocs\dsdd\messages.php'='messages'
  'C:\xampp\htdocs\dsdd\groups.php'='groups'
  'C:\xampp\htdocs\dsdd\group_view.php'='groups'
  'C:\xampp\htdocs\dsdd\user_profile.php'='profile'
  'C:\xampp\htdocs\dsdd\settings.php'='settings'
}

foreach ($path in $map.Keys) {
  $content = Get-Content -LiteralPath $path -Raw
  if ($content -notmatch 'render_app_shell_end') {
    $content = $content -replace '</body>', "<?php render_app_shell_end(['active' => '$($map[$path])']); ?>`r`n</body>"
    Set-Content -LiteralPath $path -Value $content
  }
}
