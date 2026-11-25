<?php
declare(strict_types=1);

define('APP_NAME', 'Campus Helpdesk');
define('APP_ROOT', dirname(__DIR__));
define('APP_BASE_URL', rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\'));
define('SESSION_TIMEOUT', 1800);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

if (!isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
} elseif (time() - (int) $_SESSION['last_activity'] > SESSION_TIMEOUT) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
}
$_SESSION['last_activity'] = time();
