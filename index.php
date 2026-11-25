<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/actions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        handle_post();
    } catch (Throwable $exception) {
        try {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
        } catch (Throwable $rollbackException) {
            error_log('Campus Helpdesk rollback failed: ' . $rollbackException->getMessage());
        }
        error_log('Campus Helpdesk request failed: ' . $exception->getMessage());
        flash('error', 'Something went wrong while processing your request. Please try again.');
        redirect(url(current_user() ? 'dashboard' : 'login'));
    }
}

$page = (string) ($_GET['page'] ?? 'dashboard');
try {
    db()->query('SELECT 1 FROM users LIMIT 1');
} catch (Throwable $exception) {
    redirect('setup.php');
}

if (in_array($page, ['login', 'register'], true) && current_user()) {
    redirect(url('dashboard'));
}
if ($page === 'export') {
    export_csv(require_role('admin'));
}
if ($page === 'attachment') {
    $viewer = require_login();
    $stmt = db()->prepare('SELECT c.student_id,c.attachment_path,c.attachment_name FROM complaints c WHERE c.id=?');
    $stmt->execute([(int) ($_GET['id'] ?? 0)]);
    $attachment = $stmt->fetch();
    if (!$attachment || (!$viewer || ($viewer['role'] !== 'admin' && (int) $attachment['student_id'] !== (int) $viewer['id']))) {
        http_response_code(404);
        exit('Attachment not found.');
    }
    $uploadRoot = realpath(__DIR__ . '/uploads/attachments');
    $filePath = $attachment['attachment_path'] ? realpath(__DIR__ . '/' . $attachment['attachment_path']) : false;
    if (!$uploadRoot || !$filePath || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
        http_response_code(404);
        exit('Attachment not found.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath) ?: 'application/octet-stream';
    $filename = basename((string) $attachment['attachment_name']);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    readfile($filePath);
    exit;
}
if ($page === 'message_attachment') {
    $viewer = require_login();
    $stmt = db()->prepare('SELECT media_path,media_name,media_mime FROM messages WHERE id=? AND (sender_id=? OR recipient_id=?)');
    $stmt->execute([(int) ($_GET['id'] ?? 0), (int) $viewer['id'], (int) $viewer['id']]);
    $attachment = $stmt->fetch();
    $uploadRoot = realpath(__DIR__ . '/uploads/messages');
    $filePath = $attachment && $attachment['media_path'] ? realpath(__DIR__ . '/' . $attachment['media_path']) : false;
    if (!$attachment || !$uploadRoot || !$filePath || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
        http_response_code(404);
        exit('Attachment not found.');
    }

    $mime = (string) $attachment['media_mime'];
    if (preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*\z/i', $mime) !== 1) {
        http_response_code(404);
        exit('Attachment not found.');
    }
    $inlineTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'audio/webm', 'video/webm', 'audio/ogg', 'application/ogg',
        'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav',
    ];
    $filename = basename(str_replace('\\', '/', (string) $attachment['media_name']));
    $disposition = in_array($mime, $inlineTypes, true) ? 'inline' : 'attachment';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: private, no-store');
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    readfile($filePath);
    exit;
}
if ($page === 'user_media') {
    $viewer = require_login();
    $type = (string) ($_GET['type'] ?? '');
    $mediaPath = null;
    $uploadFolder = null;
    if ($type === 'profile') {
        $profileId = (int) ($_GET['id'] ?? 0);
        $stmt = db()->prepare('SELECT profile_image_path FROM users WHERE id=? AND is_active=1');
        $stmt->execute([$profileId]);
        $mediaPath = $stmt->fetchColumn();
        $uploadFolder = 'profiles';
    } elseif ($type === 'wallpaper') {
        $mediaPath = $viewer['chat_wallpaper_path'] ?? null;
        $uploadFolder = 'wallpapers';
    } elseif ($type === 'status') {
        $statusId = (int) ($_GET['id'] ?? 0);
        $stmt = db()->prepare('SELECT s.media_path FROM statuses s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.expires_at>NOW() AND u.is_active=1');
        $stmt->execute([$statusId]);
        $mediaPath = $stmt->fetchColumn();
        $uploadFolder = 'statuses';
    }

    $uploadRoot = $uploadFolder ? realpath(__DIR__ . '/uploads/' . $uploadFolder) : false;
    $filePath = is_string($mediaPath) && $mediaPath !== '' ? realpath(__DIR__ . '/' . $mediaPath) : false;
    if (!$uploadRoot || !$filePath || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
        http_response_code(404);
        exit('Image not found.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath) ?: '';
    $allowedMediaTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if ($type === 'status') {
        $allowedMediaTypes = array_merge($allowedMediaTypes, ['video/mp4', 'video/quicktime']);
    }
    if (!in_array($mime, $allowedMediaTypes, true)) {
        http_response_code(404);
        exit('Image not found.');
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline');
    $fileSize = (int) filesize($filePath);
    if (str_starts_with($mime, 'video/')) {
        header('Accept-Ranges: bytes');
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '') {
            if (preg_match('/\Abytes=(\d*)-(\d*)\z/', $range, $matches) !== 1 || ($matches[1] === '' && $matches[2] === '')) {
                http_response_code(416);
                header('Content-Range: bytes */' . $fileSize);
                exit;
            }
            if ($matches[1] === '') {
                $suffixLength = (int) $matches[2];
                $start = max(0, $fileSize - $suffixLength);
                $end = $fileSize - 1;
            } else {
                $start = (int) $matches[1];
                $end = $matches[2] === '' ? $fileSize - 1 : min((int) $matches[2], $fileSize - 1);
            }
            if ($start >= $fileSize || $start > $end) {
                http_response_code(416);
                header('Content-Range: bytes */' . $fileSize);
                exit;
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            header('Content-Length: ' . ($end - $start + 1));
            $handle = fopen($filePath, 'rb');
            if ($handle === false || fseek($handle, $start) !== 0) {
                http_response_code(500);
                exit;
            }
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($handle);
            exit;
        }
    }
    header('Content-Length: ' . $fileSize);
    readfile($filePath);
    exit;
}
if ($page === 'status_updates') {
    $viewer = require_login();
    $stmt = db()->prepare("SELECT s.id,(SELECT COUNT(*) FROM status_views v JOIN users viewer ON viewer.id=v.user_id WHERE v.status_id=s.id AND viewer.is_active=1) AS view_count
        FROM statuses s WHERE s.user_id=? AND s.expires_at>NOW() ORDER BY s.created_at DESC LIMIT 100");
    $stmt->execute([(int) $viewer['id']]);
    $updates = array_map(static function (array $status): array {
        return ['id' => (int) $status['id'], 'view_count' => (int) $status['view_count']];
    }, $stmt->fetchAll());
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($updates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}
if ($page === 'status_viewers') {
    $viewer = require_login();
    $statusId = (int) ($_GET['id'] ?? 0);
    $check = db()->prepare('SELECT id FROM statuses WHERE id=? AND user_id=? AND expires_at>NOW()');
    $check->execute([$statusId, (int) $viewer['id']]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        exit;
    }
    $stmt = db()->prepare('SELECT u.id,u.full_name,v.viewed_at FROM status_views v JOIN users u ON u.id=v.user_id WHERE v.status_id=? AND u.is_active=1 ORDER BY v.viewed_at DESC LIMIT 100');
    $stmt->execute([$statusId]);
    $viewers = array_map(static function (array $person): array {
        return [
            'id' => (int) $person['id'],
            'name' => (string) $person['full_name'],
            'avatar_url' => url('user_media', ['type' => 'profile', 'id' => (int) $person['id']]),
            'viewed_at' => date(DATE_ATOM, strtotime($person['viewed_at'])),
            'label' => date('M j · g:i a', strtotime($person['viewed_at'])),
        ];
    }, $stmt->fetchAll());
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($viewers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if ($page === 'message_feed') {
    $viewer = require_login();
    $contactId = (int) ($_GET['contact'] ?? 0);
    $afterId = max(0, (int) ($_GET['after'] ?? 0));
    $expectedRole = $viewer['role'] === 'admin' ? 'student' : 'admin';
    $contactStmt = db()->prepare('SELECT id FROM users WHERE id=? AND role=? AND is_active=1');
    $contactStmt->execute([$contactId, $expectedRole]);
    if (!$contactStmt->fetchColumn()) {
        http_response_code(404);
        exit;
    }

    $messageStmt = db()->prepare('SELECT m.id,m.sender_id,m.message,m.media_name,m.media_mime,m.created_at,u.full_name AS sender_name FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.id>? AND ((m.sender_id=? AND m.recipient_id=?) OR (m.sender_id=? AND m.recipient_id=?)) ORDER BY m.id ASC LIMIT 50');
    $messageStmt->execute([$afterId, (int) $viewer['id'], $contactId, $contactId, (int) $viewer['id']]);
    $messages = array_map(static function (array $message): array {
        $message['id'] = (int) $message['id'];
        $message['sender_id'] = (int) $message['sender_id'];
        $message['created_at'] = date(DATE_ATOM, strtotime($message['created_at']));
        return $message;
    }, $messageStmt->fetchAll());
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if ($page === 'login' || $page === 'register') {
    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/pages.php';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$user = require_login();
$adminPages = ['users', 'categories', 'departments', 'reports', 'settings', 'audit'];
if (in_array($page, $adminPages, true) && $user['role'] !== 'admin') {
    http_response_code(403);
    exit('You do not have permission to access this page.');
}
if ($page === 'submit' && $user['role'] !== 'student') {
    redirect(url('complaints'));
}

require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/pages.php';
require __DIR__ . '/includes/footer.php';
