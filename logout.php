<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/actions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post();
}

$user = require_login();
require __DIR__ . '/includes/header.php';
?>
<section class="panel logout-confirm">
    <span class="empty-icon">↪</span>
    <h2>Sign out of your account?</h2>
    <p>You are signed in as <?= e($user['full_name']) ?>.</p>
    <form method="post" action="logout.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="logout">
        <button class="button button-primary" type="submit">Sign out securely</button>
        <a class="button button-light" href="<?= e(url('dashboard')) ?>">Stay signed in</a>
    </form>
</section>
<?php
require __DIR__ . '/includes/footer.php';
