<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function linkify_text(string $text): string
{
    $parts = preg_split('~(https?://[^\s<>"\']+)~iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return nl2br(e($text), false);
    }

    $output = '';
    foreach ($parts as $part) {
        if (preg_match('~^https?://~i', $part) !== 1) {
            $output .= e($part);
            continue;
        }

        $link = rtrim($part, '.,!?;:)]}');
        $trailing = substr($part, strlen($link));
        $host = parse_url($link, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $output .= e($part);
            continue;
        }
        $output .= '<a href="' . e($link) . '" target="_blank" rel="noopener noreferrer">' . e($link) . '</a>' . e($trailing);
    }

    return nl2br($output, false);
}

function url(string $page = 'dashboard', array $params = []): string
{
    $params = ['page' => $page] + $params;
    return 'index.php?' . http_build_query($params);
}

function redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $sent = (string) ($_POST['csrf_token'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session token expired. Please go back, refresh the page, and try again.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please sign in to continue.');
        redirect(url('login'));
    }
    $stmt = db()->prepare('SELECT id, full_name, email, student_id, department_id, profile_image_path, theme_mode, dashboard_theme, chat_theme, chat_wallpaper_path, role, is_active, must_change_password FROM users WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $freshUser = $stmt->fetch();
    if (!$freshUser || !(int) $freshUser['is_active']) {
        unset($_SESSION['user']);
        session_regenerate_id(true);
        flash('error', 'Your account is unavailable. Contact a system administrator.');
        redirect(url('login'));
    }
    $_SESSION['user'] = $freshUser;
    $user = $freshUser;
    if (!empty($user['must_change_password']) && ($_GET['page'] ?? '') !== 'profile') {
        flash('warning', 'Please change your temporary password before continuing.');
        redirect(url('profile'));
    }
    return $user;
}

function avatar_markup(array $user, string $class = 'avatar'): string
{
    $image = !empty($user['profile_image_path'])
        ? '<img src="' . e(url('user_media', ['type' => 'profile', 'id' => (int) $user['id']])) . '" alt="" loading="lazy">'
        : e(strtoupper(mb_substr((string) ($user['full_name'] ?? '?'), 0, 1)));
    return '<span class="' . e($class) . '">' . $image . '</span>';
}

function delete_user_image(?string $relativePath, string $folder): void
{
    if (!$relativePath || !in_array($folder, ['profiles', 'wallpapers', 'statuses'], true)) {
        return;
    }
    $root = realpath(dirname(__DIR__) . '/uploads/' . $folder);
    $path = realpath(dirname(__DIR__) . '/' . $relativePath);
    if ($root && $path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) && !unlink($path)) {
        error_log('Campus Helpdesk could not remove an old private image: ' . $path);
    }
}

function require_role(string $role): array
{
    $user = require_login();
    if ($user['role'] !== $role) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
    return $user;
}

function audit(string $action, ?string $details = null, ?int $userId = null, bool $anonymousActor = false): void
{
    if (!$anonymousActor) {
        $userId = $userId ?? (int) (current_user()['id'] ?? 0);
    }
    $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId ?: null, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function notify_user(int $userId, string $message, ?int $complaintId = null): void
{
    $stmt = db()->prepare('INSERT INTO notifications (user_id, complaint_id, message) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $complaintId, $message]);
}

function notify_admins(string $message, int $complaintId): void
{
    $stmt = db()->prepare("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
    foreach ($stmt->fetchAll() as $admin) {
        notify_user((int) $admin['id'], $message, $complaintId);
    }
}

function status_class(string $status): string
{
    return 'status-' . strtolower(str_replace(' ', '-', $status));
}

function page_title(string $page): string
{
    $titles = [
        'dashboard' => 'Dashboard', 'complaints' => 'Complaints',
        'submit' => 'New complaint', 'detail' => 'Complaint details',
        'notifications' => 'Notifications', 'messages' => 'Messages', 'status' => 'Campus status', 'profile' => 'My profile',
        'users' => 'User management', 'categories' => 'Categories',
        'departments' => 'Departments', 'reports' => 'Reports & analytics',
        'settings' => 'System settings', 'audit' => 'Audit log',
    ];
    return $titles[$page] ?? 'Campus Helpdesk';
}

function setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function is_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
