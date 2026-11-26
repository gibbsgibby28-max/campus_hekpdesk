<?php
declare(strict_types=1);
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';

$error = null;
$createdCredentials = null;
try {
    require_once __DIR__ . '/database/initialize.php';
    initialize_database();
    $adminExists = (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$adminExists) {
        verify_csrf();
        $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $setupToken = (string) (getenv('CAMPUS_SETUP_TOKEN') ?: '');
        $isLocal = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
        $tokenOk = $setupToken !== '' && hash_equals($setupToken, (string) ($_POST['setup_token'] ?? ''));
        if (!$isLocal && !$tokenOk) {
            throw new InvalidArgumentException('Initial administrator setup is only allowed from this computer, or with the correct setup token.');
        }
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Enter a name and a valid administrator email address.');
        }
        $temporaryPassword = bin2hex(random_bytes(8));
        $stmt = db()->prepare("INSERT INTO users (full_name, email, password_hash, role, must_change_password) VALUES (?, ?, ?, 'admin', 1)");
        $stmt->execute([$name, $email, password_hash($temporaryPassword, PASSWORD_DEFAULT)]);
        $createdCredentials = ['email' => $email, 'password' => $temporaryPassword];
        audit('Initial administrator created', 'Account created during local setup', (int) db()->lastInsertId());
        $adminExists = true;
    }
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Campus Helpdesk setup error: ' . $exception->getMessage());
    $error = 'The database could not be initialized. Check that MySQL is running and the database settings are correct.';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Setup · Campus Helpdesk</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="auth-page">
<main class="auth-card setup-card">
    <a class="brand auth-brand" href="index.php"><span class="brand-mark">C</span><span><strong>Campus Helpdesk</strong><small>Initial setup</small></span></a>
    <div class="auth-heading"><span class="eyebrow">GET STARTED</span><h1>Set up your helpdesk</h1><p>Create the database and your first administrator account.</p></div>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($createdCredentials): ?>
        <div class="alert alert-success">Setup is complete. Save these temporary credentials now; the password will not be shown again.</div>
        <div class="credential-box"><div><small>ADMINISTRATOR EMAIL</small><strong><?= e($createdCredentials['email']) ?></strong></div><div><small>TEMPORARY PASSWORD</small><strong><?= e($createdCredentials['password']) ?></strong></div></div>
        <p class="form-hint">You will be required to set a new password at your first sign in.</p>
        <a class="button button-primary button-full" href="index.php?page=login">Continue to sign in</a>
    <?php elseif (!empty($adminExists)): ?>
        <div class="empty-state compact-empty"><span class="empty-icon">✓</span><h2>System is ready</h2><p>The database is initialized and an administrator account already exists.</p><a class="button button-primary" href="index.php?page=login">Go to sign in</a></div>
    <?php else: ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label>Administrator name<input name="full_name" required maxlength="150" autocomplete="name" value="<?= e($_POST['full_name'] ?? 'Gibson') ?>"></label>
            <?php if (getenv('CAMPUS_SETUP_TOKEN')): ?><label>Setup token<input type="password" name="setup_token" autocomplete="off"></label><?php endif; ?>
            <label>Administrator email<input type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
            <button class="button button-primary button-full" type="submit">Initialize and create account</button>
        </form>
        <p class="form-hint">Sign in with the administrator email. A unique temporary password will be generated and displayed once; change it immediately after signing in.</p>
    <?php endif; ?>
</main>
</body></html>
