<?php
if (!function_exists('app_shell_avatar_url')) {
    function app_shell_avatar_url(string $name, ?string $avatar): string
    {
        if (!empty($avatar)) {
            return $avatar;
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=dbeafe&color=1d4ed8&rounded=true&size=96';
    }
}

if (!function_exists('render_app_shell_start')) {
    function render_app_shell_start(array $config = []): void
    {
        $active = $config['active'] ?? 'dashboard';
        $bodyClass = trim($config['body_class'] ?? '');
        $contextLabel = $config['context_label'] ?? 'Workspace';
        $contextTitle = $config['context_title'] ?? 'Social Platform';
        $contextSubtitle = $config['context_subtitle'] ?? 'A connected social product experience.';
        $searchHtml = $config['search_html'] ?? '';
        $extraActionsHtml = $config['extra_actions_html'] ?? '';
        $notificationsHtml = $config['notifications_html'] ?? '';
        $messagesBadge = (int)($config['messages_badge'] ?? 0);

        $fullName = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'You');
        $username = $_SESSION['username'] ?? 'account';
        $avatar = $_SESSION['avatar'] ?? null;
        $avatarUrl = app_shell_avatar_url($fullName, $avatar);

        $logoPath = $config['logo_path'] ?? 'assets/images/logo.png';
        $logoDiskPath = __DIR__ . '/../' . ltrim(str_replace('\\', '/', $logoPath), '/');
        $hasLogo = is_file($logoDiskPath);

      
   $profileHref = 'user_profile.php?user_id=' . (int)($_SESSION['user_id'] ?? 0);

$nav = [
    'dashboard' => [
        'label' => 'Feed',
        'href' => 'dashboard.php',
        'icon' => 'bi-house-door-fill',
    ],
    'messages' => [
        'label' => 'Messages',
        'href' => 'messages.php',
        'icon' => 'bi-chat-dots-fill',
    ],
    'groups' => [
        'label' => 'Groups',
        'href' => 'groups.php',
        'icon' => 'bi-people-fill',
    ],
    'profile' => [
        'label' => 'Profile',
        'href' => $profileHref,
        'icon' => 'bi-person-badge-fill',
    ],
    'settings' => [
        'label' => 'Settings',
        'href' => 'settings.php',
        'icon' => 'bi-gear-fill',
    ],
];

        echo '<body' . ($bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES) . '"' : '') . '>';
        echo '<header class="app-header">';
        echo '<div class="app-header-inner">';

        echo '<div class="app-branding">';
        echo '<a href="dashboard.php" class="app-brand-mark" aria-label="Go to dashboard">';
        if ($hasLogo) {
            echo '<img src="' . htmlspecialchars($logoPath) . '" alt="Logo" class="app-brand-logo">';
        } else {
            echo '<i class="bi bi-collection-fill"></i>';
        }
        echo '</a>';

        echo '<div class="app-brand-copy">';
        echo '<div class="app-context-label">' . htmlspecialchars($contextLabel) . '</div>';
        echo '<div class="app-context-title">' . htmlspecialchars($contextTitle) . '</div>';
        echo '<div class="app-context-subtitle">' . htmlspecialchars($contextSubtitle) . '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="app-header-center">';
        if ($searchHtml !== '') {
            echo $searchHtml;
        }
        echo '</div>';

        echo '<div class="app-header-actions">';
        echo '<nav class="app-primary-nav" aria-label="Primary">';
        foreach ($nav as $key => $item) {
            $isActive = $active === $key;
            echo '<a href="' . htmlspecialchars($item['href']) . '" class="app-nav-link' . ($isActive ? ' active' : '') . '">';
            echo '<i class="bi ' . htmlspecialchars($item['icon']) . '"></i>';
            echo '<span>' . htmlspecialchars($item['label']) . '</span>';
            if ($key === 'messages' && $messagesBadge > 0) {
                echo '<span class="app-nav-badge">' . ($messagesBadge > 9 ? '9+' : $messagesBadge) . '</span>';
            }
            echo '</a>';
        }
        echo '</nav>';

        if ($notificationsHtml !== '') {
            echo $notificationsHtml;
        }

        if ($extraActionsHtml !== '') {
            echo $extraActionsHtml;
        }

        echo '<a href="' . htmlspecialchars($profileHref) . '" class="app-account-chip">';
        echo '<img src="' . htmlspecialchars($avatarUrl) . '" alt="avatar" class="app-account-avatar">';
        echo '<span class="app-account-copy">';
        echo '<strong>' . htmlspecialchars($fullName) . '</strong>';
        echo '<small>@' . htmlspecialchars($username) . '</small>';
        echo '</span>';
        echo '</a>';

        echo '<form action="logout.php" method="POST" class="app-logout-form">';
        echo '<button type="submit" class="app-logout-button" aria-label="Logout"><i class="bi bi-box-arrow-right"></i><span class="d-none d-xl-inline">Logout</span></button>';
        echo '</form>';

        echo '</div>';
        echo '</div>';
        echo '</header>';
    }
}

if (!function_exists('render_app_shell_end')) {
    function render_app_shell_end(array $config = []): void
    {
        $active = $config['active'] ?? 'dashboard';
        $profileHref = 'user_profile.php?user_id=' . (int)($_SESSION['user_id'] ?? 0);

        $items = [
            ['key' => 'dashboard', 'label' => 'Feed', 'href' => 'dashboard.php', 'icon' => 'bi-house-door-fill'],
            ['key' => 'messages', 'label' => 'Messages', 'href' => 'messages.php', 'icon' => 'bi-chat-dots-fill'],
            ['key' => 'groups', 'label' => 'Groups', 'href' => 'groups.php', 'icon' => 'bi-people-fill'],
            ['key' => 'profile', 'label' => 'Profile', 'href' => $profileHref, 'icon' => 'bi-person-circle'],
        ];

        echo '<nav class="app-mobile-nav">';
        foreach ($items as $item) {
            $isActive = $active === $item['key'] || ($active === 'settings' && $item['key'] === 'profile');
            echo '<a href="' . htmlspecialchars($item['href']) . '" class="app-mobile-link' . ($isActive ? ' active' : '') . '">';
            echo '<i class="bi ' . htmlspecialchars($item['icon']) . '"></i>';
            echo '<span>' . htmlspecialchars($item['label']) . '</span>';
            echo '</a>';
        }
        echo '</nav>';
    }
}