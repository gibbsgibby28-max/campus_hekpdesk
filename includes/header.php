<?php
$user = current_user();
$page = $_GET['page'] ?? 'dashboard';
$title = page_title((string) $page);
$flashMessage = take_flash();
$unreadCount = 0;
if ($user) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([(int) $user['id']]);
    $unreadCount = (int) $stmt->fetchColumn();
}
$isAdmin = $user && $user['role'] === 'admin';
$themeMode = in_array($user['theme_mode'] ?? '', ['light', 'dark'], true) ? $user['theme_mode'] : 'light';
$dashboardTheme = in_array($user['dashboard_theme'] ?? '', ['ocean', 'forest', 'sunset', 'violet'], true) ? $user['dashboard_theme'] : 'ocean';
$chatTheme = in_array($user['chat_theme'] ?? '', ['classic', 'ocean', 'rose', 'forest'], true) ? $user['chat_theme'] : 'classic';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#142d4e">
    <title><?= e($title) ?> · <?= e(setting('app_name', APP_NAME)) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="theme-<?= e($themeMode) ?> dashboard-theme-<?= e($dashboardTheme) ?> chat-theme-<?= e($chatTheme) ?>">
<?php if ($user): ?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= e(url('dashboard')) ?>">
            <span class="brand-mark">C</span>
            <span><strong><?= e(setting('app_name', APP_NAME)) ?></strong><small>Student services</small></span>
        </a>
        <div class="nav-label">WORKSPACE</div>
        <nav class="side-nav">
            <a class="<?= $page === 'dashboard' ? 'active' : '' ?>" href="<?= e(url('dashboard')) ?>"><span class="nav-icon">⌂</span>Dashboard</a>
            <a class="<?= in_array($page, ['complaints', 'detail'], true) ? 'active' : '' ?>" href="<?= e(url('complaints')) ?>"><span class="nav-icon">▤</span><?= $isAdmin ? 'All complaints' : 'My complaints' ?></a>
            <?php if (!$isAdmin): ?>
                <a class="<?= $page === 'submit' ? 'active' : '' ?>" href="<?= e(url('submit')) ?>"><span class="nav-icon">＋</span>Submit a complaint</a>
            <?php endif; ?>
            <a class="<?= $page === 'notifications' ? 'active' : '' ?>" href="<?= e(url('notifications')) ?>"><span class="nav-icon">◉</span>Notifications <?php if ($unreadCount): ?><span class="nav-count"><?= $unreadCount ?></span><?php endif; ?></a>
            <a class="<?= $page === 'messages' ? 'active' : '' ?>" href="<?= e(url('messages')) ?>"><span class="nav-icon">✉</span>Messages</a>
            <a class="<?= $page === 'status' ? 'active' : '' ?>" href="<?= e(url('status')) ?>"><span class="nav-icon">◉</span>Campus status</a>
            <?php if ($isAdmin): ?>
                <div class="nav-label nav-label-spaced">MANAGEMENT</div>
                <a class="<?= $page === 'users' ? 'active' : '' ?>" href="<?= e(url('users')) ?>"><span class="nav-icon">♙</span>Users</a>
                <a class="<?= $page === 'categories' ? 'active' : '' ?>" href="<?= e(url('categories')) ?>"><span class="nav-icon">◈</span>Categories</a>
                <a class="<?= $page === 'departments' ? 'active' : '' ?>" href="<?= e(url('departments')) ?>"><span class="nav-icon">▦</span>Departments</a>
                <a class="<?= $page === 'reports' ? 'active' : '' ?>" href="<?= e(url('reports')) ?>"><span class="nav-icon">▥</span>Reports</a>
                <a class="<?= $page === 'audit' ? 'active' : '' ?>" href="<?= e(url('audit')) ?>"><span class="nav-icon">◷</span>Audit log</a>
                <a class="<?= $page === 'settings' ? 'active' : '' ?>" href="<?= e(url('settings')) ?>"><span class="nav-icon">⚙</span>Settings</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-bottom">
            <a class="side-user" href="<?= e(url('profile')) ?>">
                <?= avatar_markup($user) ?>
                <span class="side-user-name"><strong><?= e($user['full_name']) ?></strong><small><?= $isAdmin ? 'Administrator' : 'Student' ?></small></span>
                <span class="side-user-more">···</span>
            </a>
        </div>
    </aside>
    <main class="main-area">
        <header class="topbar">
            <button type="button" class="mobile-menu" aria-label="Toggle navigation" aria-expanded="false">☰</button>
            <div><h1><?= e($title) ?></h1></div>
            <div class="topbar-actions">
                <a class="icon-button appearance-button" aria-label="Personalize dashboard and chat" title="Personalize appearance" href="<?= e(url('profile')) ?>#appearance">✦</a>
                <a class="icon-button notification-button" aria-label="Notifications" href="<?= e(url('notifications')) ?>">♧<?php if ($unreadCount): ?><i></i><?php endif; ?></a>
                <a class="top-user" href="<?= e(url('profile')) ?>"><?= avatar_markup($user, 'avatar avatar-small') ?><span><strong><?= e($user['full_name']) ?></strong><small><?= $isAdmin ? 'Admin' : 'Student' ?></small></span></a>
                <form method="post" action="index.php" class="topbar-logout">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="button button-light topbar-signout" type="submit"><span aria-hidden="true">↪</span> Sign out</button>
                </form>
            </div>
        </header>
        <section class="content">
            <?php if ($flashMessage): ?><div class="alert alert-<?= e($flashMessage['type']) ?>" role="status"><?= e($flashMessage['message']) ?><button type="button" class="alert-close" aria-label="Dismiss">×</button></div><?php endif; ?>
<?php else: ?>
<main class="public-shell">
    <?php if ($flashMessage): ?><div class="alert alert-<?= e($flashMessage['type']) ?>" role="status"><?= e($flashMessage['message']) ?><button type="button" class="alert-close" aria-label="Dismiss">×</button></div><?php endif; ?>
<?php endif; ?>
