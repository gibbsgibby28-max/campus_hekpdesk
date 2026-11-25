<?php
declare(strict_types=1);

$user = current_user();
$isAdmin = $user && $user['role'] === 'admin';
$page = (string) ($_GET['page'] ?? 'dashboard');

if ($page === 'login' || $page === 'register') {
    ?>
    <div class="auth-wrap auth-wrap-single <?= $page === 'register' ? 'auth-register' : '' ?>">
        <section class="auth-card auth-card-clean">
            <a class="brand auth-brand" href="index.php"><span class="brand-mark">C</span><span><strong>Campus Helpdesk</strong><small>Student support, made simple</small></span></a>
            <div class="auth-heading"><span class="eyebrow"><?= $page === 'login' ? 'STUDENT & STAFF PORTAL' : 'STUDENT REGISTRATION' ?></span><h1><?= $page === 'login' ? 'Welcome back' : 'Create your account' ?></h1></div>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= e($page) === 'login' ? 'login' : 'register' ?>">
                <?php if ($page === 'register'): ?>
                    <label>Full name<input name="full_name" required maxlength="150" autocomplete="name" value="<?= e($_POST['full_name'] ?? '') ?>" placeholder="Your full name"></label>
                    <label>Student email<input type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="name@university.edu"></label>
                    <div class="form-row">
                        <label><span class="auth-label-title">Student ID <span class="optional">Optional</span></span><input name="student_id" maxlength="60" autocomplete="off" placeholder="Student ID"></label>
                        <label><span class="auth-label-title">Department <span class="optional">Optional</span></span>
                            <select name="department_id"><option value="">Select department</option>
                                <?php foreach (db()->query('SELECT id,name FROM departments WHERE is_active=1 ORDER BY name') as $department): ?><option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="form-row password-row">
                        <div class="auth-field"><label for="register-password">Password</label><span class="password-control"><input id="register-password" type="password" name="password" required minlength="10" autocomplete="new-password" placeholder="At least 10 characters"><button class="password-toggle" type="button" data-password-toggle="register-password" aria-controls="register-password" aria-label="Show password" aria-pressed="false">Show</button></span><small>At least 10 characters.</small></div>
                        <div class="auth-field"><label for="register-password-confirm">Confirm password</label><span class="password-control"><input id="register-password-confirm" type="password" name="password_confirm" required minlength="10" autocomplete="new-password" placeholder="Re-enter password"><button class="password-toggle" type="button" data-password-toggle="register-password-confirm" aria-controls="register-password-confirm" aria-label="Show confirmation password" aria-pressed="false">Show</button></span></div>
                    </div>
                <?php else: ?>
                    <label>Institutional email<input type="email" name="email" required autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>" placeholder="name@university.edu"></label>
                    <div class="auth-field"><label for="login-password">Password</label><span class="password-control"><input id="login-password" type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"><button class="password-toggle" type="button" data-password-toggle="login-password" aria-controls="login-password" aria-label="Show password" aria-pressed="false">Show</button></span></div>
                    <div class="auth-options"><span></span><a href="mailto:<?= e(setting('contact_email')) ?>?subject=Campus%20Helpdesk%20password%20help">Forgot password?</a></div>
                <?php endif; ?>
                <button class="button button-primary button-full auth-submit" type="submit"><?= $page === 'login' ? 'Sign in' : 'Create account' ?><span aria-hidden="true">→</span></button>
            </form>
            <div class="auth-switch"><?= $page === 'login' ? "New to Campus Helpdesk? <a href=\"" . e(url('register')) . '">Create an account</a>' : 'Already have an account? <a href="' . e(url('login')) . '">Sign in</a>' ?></div>
        </section>
    </div>
    <?php
    return;
}

switch ($page) {
    case 'dashboard':
        render_dashboard($user, $isAdmin);
        break;
    case 'complaints':
        render_complaints($user, $isAdmin);
        break;
    case 'submit':
        render_submit_form();
        break;
    case 'detail':
        render_complaint_detail($user, $isAdmin, (int) ($_GET['id'] ?? 0));
        break;
    case 'notifications':
        render_notifications($user);
        break;
    case 'messages':
        render_messages($user, $isAdmin);
        break;
    case 'status':
        render_status($user);
        break;
    case 'profile':
        render_profile($user);
        break;
    case 'users':
        render_users($user);
        break;
    case 'categories':
    case 'departments':
        render_catalog($page);
        break;
    case 'reports':
        render_reports();
        break;
    case 'settings':
        render_settings();
        break;
    case 'audit':
        render_audit();
        break;
    default:
        http_response_code(404);
        echo '<div class="empty-state"><span class="empty-icon">?</span><h2>Page not found</h2><p>The page you requested does not exist.</p><a class="button button-primary" href="' . e(url('dashboard')) . '">Back to dashboard</a></div>';
}

function render_dashboard(array $user, bool $isAdmin): void
{
    $pdo = db();
    if ($isAdmin) {
        $stats = [];
        $stats['students'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=1")->fetchColumn();
        $stats['total'] = (int) $pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn();
        $statusRows = $pdo->query('SELECT status, COUNT(*) AS total FROM complaints GROUP BY status')->fetchAll();
        $byStatus = [];
        foreach ($statusRows as $row) {
            $byStatus[$row['status']] = (int) $row['total'];
        }
        $resolved = $byStatus['Resolved'] ?? 0;
        $resolutionRate = $stats['total'] ? round($resolved * 100 / $stats['total']) : 0;
        $averageDays = $pdo->query('SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) / 24 FROM complaints WHERE resolved_at IS NOT NULL')->fetchColumn();
        $recent = $pdo->query("SELECT c.id,c.reference_no,c.title,c.status,c.priority,c.created_at,CASE WHEN c.is_anonymous=1 THEN 'Anonymous' ELSE u.full_name END AS full_name,cat.name AS category FROM complaints c JOIN users u ON u.id=c.student_id JOIN categories cat ON cat.id=c.category_id ORDER BY c.created_at DESC LIMIT 6")->fetchAll();
        $categories = $pdo->query('SELECT cat.name, COUNT(c.id) AS total FROM categories cat LEFT JOIN complaints c ON c.category_id=cat.id GROUP BY cat.id ORDER BY total DESC, cat.name LIMIT 5')->fetchAll();
        $months = $pdo->query("SELECT DATE_FORMAT(created_at, '%b') AS label, COUNT(*) AS total FROM complaints WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) GROUP BY YEAR(created_at), MONTH(created_at), DATE_FORMAT(created_at, '%b') ORDER BY YEAR(created_at), MONTH(created_at)")->fetchAll();
        ?>
        <div class="dashboard-page dashboard-admin">
        <div class="dashboard-intro"><div><span class="eyebrow">ADMIN OVERVIEW</span><h2>Welcome back, <?= e(explode(' ', $user['full_name'])[0]) ?></h2><p>Campus support at a glance. <a class="dashboard-customize-link" href="<?= e(url('profile')) ?>#appearance">Personalize your dashboard</a></p></div><a class="button button-primary" href="<?= e(url('complaints')) ?>">Review complaints <span aria-hidden="true">→</span></a></div>
        <div class="stats-grid dashboard-stats">
            <?php stat_card('Total complaints', $stats['total'], 'Requests received', '▤', 'blue'); ?>
            <?php stat_card('Awaiting review', $byStatus['Pending'] ?? 0, 'Needs staff attention', '◷', 'amber'); ?>
            <?php stat_card('In progress', ($byStatus['In Progress'] ?? 0) + ($byStatus['Under Review'] ?? 0), 'Being handled now', '↗', 'teal'); ?>
            <?php stat_card('Resolved', $resolved, $resolutionRate . '% resolution rate', '✓', 'green'); ?>
        </div>
        <div class="dashboard-insights">
            <div><span class="insight-icon tone-purple">♙</span><span><strong><?= $stats['students'] ?></strong> active students</span></div>
            <div><span class="insight-icon tone-amber">…</span><span><strong><?= $byStatus['Awaiting Student Response'] ?? 0 ?></strong> requests need a student reply</span></div>
            <div><span class="insight-icon tone-blue">◷</span><span><strong><?= $averageDays === null ? '—' : number_format((float) $averageDays, 1) . ' days' ?></strong> average resolution time</span></div>
        </div>
        <div class="dashboard-columns">
            <section class="panel panel-table"><div class="panel-heading"><div><h3>Recent complaints</h3><p>Latest requests from across campus</p></div><a class="text-link" href="<?= e(url('complaints')) ?>">View all →</a></div>
                <?php if ($recent): ?><div class="table-wrap"><table><thead><tr><th>Complaint</th><th>Reporter</th><th>Priority</th><th>Status</th><th></th></tr></thead><tbody>
                    <?php foreach ($recent as $item): ?><tr><td><a class="table-title" href="<?= e(url('detail', ['id' => $item['id']])) ?>"><?= e($item['title']) ?></a><small><?= e($item['reference_no']) ?> · <?= e($item['category']) ?></small></td><td><?= e($item['full_name']) ?></td><td><span class="priority priority-<?= strtolower(e($item['priority'])) ?>"><?= e($item['priority']) ?></span></td><td><span class="status <?= e(status_class($item['status'])) ?>"><?= e($item['status']) ?></span></td><td><a class="row-arrow" href="<?= e(url('detail', ['id' => $item['id']])) ?>">→</a></td></tr><?php endforeach; ?>
                </tbody></table></div><?php else: ?><div class="empty-state compact-empty"><span class="empty-icon">▤</span><h2>No complaints yet</h2><p>New campus requests will appear here.</p></div><?php endif; ?>
            </section>
            <div class="dashboard-side">
                <section class="panel chart-panel"><div class="panel-heading"><div><h3>By category</h3><p>Complaint distribution</p></div></div>
                    <?php $categoryMax = max([1, ...array_map(static fn($r) => (int) $r['total'], $categories)]); foreach ($categories as $i => $category): ?><div class="bar-item"><div><span><?= e($category['name']) ?></span><strong><?= (int) $category['total'] ?></strong></div><div class="bar-track"><span class="bar-fill bar-color-<?= $i % 5 ?>" style="width:<?= (int) round((int) $category['total'] * 100 / $categoryMax) ?>%"></span></div></div><?php endforeach; if (!$categories): ?><p class="muted">No category data yet.</p><?php endif; ?>
                </section>
                <section class="panel chart-panel"><div class="panel-heading"><div><h3>Monthly activity</h3><p>Complaints received recently</p></div></div>
                    <?php $monthMax = max([1, ...array_map(static fn($r) => (int) $r['total'], $months)]); ?><div class="month-bars"><?php foreach ($months as $month): ?><div class="month-bar"><span style="height:<?= max(4, (int) round((int) $month['total'] * 100 / $monthMax)) ?>%"></span><small><?= e($month['label']) ?></small></div><?php endforeach; if (!$months): ?><p class="muted">No recent complaint data.</p><?php endif; ?></div>
                </section>
            </div>
        </div>
        </div>
        <?php
        return;
    }

    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM complaints WHERE student_id=? GROUP BY status');
    $stmt->execute([$user['id']]);
    $byStatus = [];
    foreach ($stmt->fetchAll() as $row) {
        $byStatus[$row['status']] = (int) $row['total'];
    }
    $total = array_sum($byStatus);
    $stmt = $pdo->prepare('SELECT c.id,c.reference_no,c.title,c.status,c.priority,c.created_at,cat.name AS category FROM complaints c JOIN categories cat ON cat.id=c.category_id WHERE c.student_id=? ORDER BY c.created_at DESC LIMIT 5');
    $stmt->execute([$user['id']]);
    $recent = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT message,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 4');
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
    ?>
    <div class="dashboard-page dashboard-student">
    <div class="dashboard-intro"><div><span class="eyebrow">STUDENT OVERVIEW</span><h2>Welcome back, <?= e(explode(' ', $user['full_name'])[0]) ?></h2><p>Your support requests, all in one place. <a class="dashboard-customize-link" href="<?= e(url('profile')) ?>#appearance">Personalize your dashboard</a></p></div><a class="button button-primary" href="<?= e(url('submit')) ?>"><span aria-hidden="true">＋</span> New request</a></div>
    <div class="stats-grid dashboard-stats">
        <?php stat_card('Total requests', $total, 'Submitted by you', '▤', 'blue'); ?>
        <?php stat_card('Awaiting review', $byStatus['Pending'] ?? 0, 'Waiting for the team', '◷', 'amber'); ?>
        <?php stat_card('In progress', ($byStatus['In Progress'] ?? 0) + ($byStatus['Under Review'] ?? 0), 'Being handled by the team', '↗', 'teal'); ?>
        <?php stat_card('Resolved', $byStatus['Resolved'] ?? 0, 'Successfully completed', '✓', 'green'); ?>
    </div>
    <?php if (!empty($byStatus['Awaiting Student Response'])): ?>
        <a class="student-action-notice" href="<?= e(url('complaints', ['status' => 'Awaiting Student Response'])) ?>"><span class="notice-symbol">!</span><span><strong><?= (int) $byStatus['Awaiting Student Response'] ?> request<?= (int) $byStatus['Awaiting Student Response'] === 1 ? '' : 's' ?> need<?= (int) $byStatus['Awaiting Student Response'] === 1 ? 's' : '' ?> your reply</strong><small>Open the request and continue the conversation.</small></span><b aria-hidden="true">→</b></a>
    <?php endif; ?>
    <div class="dashboard-columns student-dashboard-columns">
        <section class="panel panel-table"><div class="panel-heading"><div><h3>Your recent complaints</h3><p>Track the latest updates to your requests</p></div><a class="text-link" href="<?= e(url('complaints')) ?>">View all →</a></div>
            <?php if ($recent): ?><div class="table-wrap"><table><thead><tr><th>Complaint</th><th>Submitted</th><th>Priority</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($recent as $item): ?><tr><td><a class="table-title" href="<?= e(url('detail', ['id' => $item['id']])) ?>"><?= e($item['title']) ?></a><small><?= e($item['reference_no']) ?> · <?= e($item['category']) ?></small></td><td><?= e(date('M j, Y', strtotime($item['created_at']))) ?></td><td><span class="priority priority-<?= strtolower(e($item['priority'])) ?>"><?= e($item['priority']) ?></span></td><td><span class="status <?= e(status_class($item['status'])) ?>"><?= e($item['status']) ?></span></td><td><a class="row-arrow" href="<?= e(url('detail', ['id' => $item['id']])) ?>">→</a></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state compact-empty"><span class="empty-icon">✎</span><h2>No complaints yet</h2><p>When you need campus support, submit a request to get started.</p><a class="button button-primary" href="<?= e(url('submit')) ?>">Submit your first complaint</a></div><?php endif; ?>
        </section>
        <div class="dashboard-side"><section class="panel quick-panel"><div class="panel-heading"><div><h3>Quick actions</h3><p>Get started with campus support</p></div></div><a class="quick-link" href="<?= e(url('submit')) ?>"><span class="quick-icon quick-blue">＋</span><span><strong>Submit a complaint</strong><small>Tell us what you need help with</small></span><b>→</b></a><a class="quick-link" href="<?= e(url('profile')) ?>"><span class="quick-icon quick-purple">♙</span><span><strong>Update your profile</strong><small>Keep your contact details current</small></span><b>→</b></a></section>
        <section class="panel"><div class="panel-heading"><div><h3>Recent updates</h3><p>Latest notifications</p></div><a class="text-link" href="<?= e(url('notifications')) ?>">See all</a></div><?php if ($notifications): ?><div class="notification-list compact-list"><?php foreach ($notifications as $notification): ?><div class="notification-item"><span class="notification-dot"></span><div><p><?= e($notification['message']) ?></p><small><?= e(date('M j · g:i a', strtotime($notification['created_at']))) ?></small></div></div><?php endforeach; ?></div><?php else: ?><p class="muted padded">You're all caught up. New updates will appear here.</p><?php endif; ?></section></div>
    </div>
    </div>
    <?php
}

function stat_card(string $label, int|string $value, string $note, string $icon, string $tone): void
{
    echo '<article class="stat-card"><div class="stat-top"><span>' . e($label) . '</span><span class="stat-icon tone-' . e($tone) . '">' . e($icon) . '</span></div><strong class="stat-value">' . e($value) . '</strong><small>' . e($note) . '</small></article>';
}

function render_complaints(array $user, bool $isAdmin): void
{
    $search = trim((string) ($_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $priority = trim((string) ($_GET['priority'] ?? ''));
    $category = (int) ($_GET['category'] ?? 0);
    $department = (int) ($_GET['department'] ?? 0);
    $dateFrom = trim((string) ($_GET['from'] ?? ''));
    $dateTo = trim((string) ($_GET['to'] ?? ''));
    $conditions = [];
    $params = [];
    if (!$isAdmin) {
        $conditions[] = 'c.student_id = ?';
        $params[] = $user['id'];
    }
    if ($search !== '') {
        if ($isAdmin) {
            $conditions[] = '(c.reference_no LIKE ? OR c.title LIKE ? OR (c.is_anonymous=0 AND u.full_name LIKE ?))';
            array_push($params, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');
        } else {
            $conditions[] = '(c.reference_no LIKE ? OR c.title LIKE ?)';
            array_push($params, '%' . $search . '%', '%' . $search . '%');
        }
    }
    if ($status !== '') {
        $conditions[] = 'c.status = ?';
        $params[] = $status;
    }
    if ($priority !== '') {
        $conditions[] = 'c.priority = ?';
        $params[] = $priority;
    }
    if ($category > 0) {
        $conditions[] = 'c.category_id = ?';
        $params[] = $category;
    }
    if ($department > 0) {
        $conditions[] = 'c.department_id = ?';
        $params[] = $department;
    }
    if (is_valid_date($dateFrom)) {
        $conditions[] = 'c.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if (is_valid_date($dateTo)) {
        $conditions[] = 'c.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $dateTo;
    }
    $sql = "SELECT c.id,c.reference_no,c.title,c.status,c.priority,c.created_at,CASE WHEN c.is_anonymous=1 THEN 'Anonymous' ELSE u.full_name END AS full_name,cat.name AS category,d.name AS department FROM complaints c JOIN users u ON u.id=c.student_id JOIN categories cat ON cat.id=c.category_id LEFT JOIN departments d ON d.id=c.department_id";
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY c.created_at DESC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    ?>
    <div class="list-page-heading"><div><span class="eyebrow"><?= $isAdmin ? 'SERVICE OVERVIEW' : 'YOUR SUPPORT REQUESTS' ?></span><h2><?= $isAdmin ? 'All complaints' : 'Your complaints' ?></h2><p><?= $isAdmin ? 'Review and manage requests submitted by students.' : 'Search your requests and check their latest status.' ?></p></div><?php if (!$isAdmin): ?><a class="button button-primary" href="<?= e(url('submit')) ?>">＋ New complaint</a><?php endif; ?></div>
    <section class="panel">
        <form class="filter-bar" method="get"><input type="hidden" name="page" value="complaints"><label class="search-input"><span>⌕</span><input name="q" value="<?= e($search) ?>" placeholder="<?= $isAdmin ? 'Search reference, title or named student…' : 'Search reference or title…' ?>"></label>
            <select name="status"><option value="">All statuses</option><?php foreach (['Pending','Under Review','In Progress','Awaiting Student Response','Resolved','Rejected','Reopened'] as $option): ?><option <?= $status === $option ? 'selected' : '' ?> value="<?= e($option) ?>"><?= e($option) ?></option><?php endforeach; ?></select>
            <select name="priority"><option value="">All priorities</option><?php foreach (['Urgent','High','Normal','Low'] as $option): ?><option <?= $priority === $option ? 'selected' : '' ?> value="<?= e($option) ?>"><?= e($option) ?></option><?php endforeach; ?></select>
            <select name="category"><option value="0">All categories</option><?php foreach (db()->query('SELECT id,name FROM categories ORDER BY name') as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $category === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select>
            <select name="department"><option value="0">All departments</option><?php foreach (db()->query('SELECT id,name FROM departments ORDER BY name') as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $department === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select>
            <label class="date-filter">From<input type="date" name="from" value="<?= e($dateFrom) ?>"></label><label class="date-filter">To<input type="date" name="to" value="<?= e($dateTo) ?>"></label>
            <button class="button button-light" type="submit">Filter</button>
        </form>
        <?php if ($rows): ?><div class="table-wrap"><table class="wide-table"><thead><tr><th>Complaint</th><?php if ($isAdmin): ?><th>Reporter</th><?php endif; ?><th>Category / department</th><th>Priority</th><th>Status</th><th>Submitted</th><th></th></tr></thead><tbody><?php foreach ($rows as $item): ?><tr><td><a class="table-title" href="<?= e(url('detail', ['id' => $item['id']])) ?>"><?= e($item['title']) ?></a><small><?= e($item['reference_no']) ?></small></td><?php if ($isAdmin): ?><td><?= e($item['full_name']) ?></td><?php endif; ?><td><?= e($item['category']) ?><small><?= e($item['department'] ?: 'Unassigned') ?></small></td><td><span class="priority priority-<?= strtolower(e($item['priority'])) ?>"><?= e($item['priority']) ?></span></td><td><span class="status <?= e(status_class($item['status'])) ?>"><?= e($item['status']) ?></span></td><td><?= e(date('M j, Y', strtotime($item['created_at']))) ?></td><td><a class="row-arrow" href="<?= e(url('detail', ['id' => $item['id']])) ?>">→</a></td></tr><?php endforeach; ?></tbody></table></div>
        <?php else: ?><div class="empty-state"><span class="empty-icon">⌕</span><h2>No matching complaints</h2><p>Try changing your filters<?= $isAdmin ? '.' : ' or submit a new complaint.' ?></p><?php if (!$isAdmin): ?><a class="button button-primary" href="<?= e(url('submit')) ?>">Submit a complaint</a><?php endif; ?></div><?php endif; ?>
    </section>
    <?php
}

function render_submit_form(): void
{
    $categories = db()->query('SELECT id,name,description FROM categories WHERE is_active=1 ORDER BY name')->fetchAll();
    ?>
    <div class="form-page"><div class="list-page-heading"><div><span class="eyebrow">STUDENT SUPPORT</span><h2>Tell us how we can help</h2><p>Share the details and we'll route your request to the right team.</p></div></div>
        <form class="panel form-panel" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="submit_complaint">
            <div class="form-section-title"><span>01</span><div><h3>Complaint details</h3><p>Give your request a clear title and description.</p></div></div>
            <label>Complaint title <span class="required">*</span><input name="title" required minlength="5" maxlength="180" placeholder="Briefly summarize the issue"></label>
            <div class="form-row"><label>Category <span class="required">*</span><select name="category_id" required><option value="">Select a category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
                <label>Priority<select name="priority"><option>Normal</option><option>Low</option><option>High</option><option>Urgent</option></select><small>Choose urgent only when immediate attention is needed.</small></label></div>
            <label>Description <span class="required">*</span><textarea name="description" rows="7" minlength="20" maxlength="20000" required placeholder="Describe what happened, where and when. Include any steps already taken."></textarea><small>At least 20 characters. Do not include passwords or sensitive financial details.</small></label>
            <fieldset class="privacy-choice">
                <legend>Choose how your identity is shared</legend>
                <label><input type="radio" name="identity_preference" value="named" checked><span><strong>Share my name</strong><small>The helpdesk team can see your student profile.</small></span></label>
                <label><input type="radio" name="identity_preference" value="anonymous"><span><strong>Submit anonymously</strong><small>Your identity is hidden from staff in the portal. The complaint stays linked to your account so you can follow replies.</small></span></label>
                <p>For honesty: the system stores that private account link. Database operators may access it, and details or files you include could identify you.</p>
            </fieldset>
            <div class="form-section-title section-spaced"><span>02</span><div><h3>Supporting document <span class="optional">(optional)</span></h3><p>Attach an image, PDF or plain text file if it helps explain the issue.</p></div></div>
            <label class="file-field"><input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.txt,image/jpeg,image/png,application/pdf,text/plain"><span class="file-icon">⇧</span><span><strong>Choose a file to upload</strong><small>JPG, PNG, PDF or TXT · Maximum 5 MB</small></span></label>
            <div class="form-actions"><a class="button button-light" href="<?= e(url('complaints')) ?>">Cancel</a><button type="submit" class="button button-primary">Submit complaint <span>→</span></button></div>
        </form>
    </div>
    <?php
}

function render_complaint_detail(array $user, bool $isAdmin, int $id): void
{
    $stmt = db()->prepare('SELECT c.*,u.full_name AS student_name,u.email AS student_email,u.student_id AS student_number,cat.name AS category,d.name AS department FROM complaints c JOIN users u ON u.id=c.student_id JOIN categories cat ON cat.id=c.category_id LEFT JOIN departments d ON d.id=c.department_id WHERE c.id=?');
    $stmt->execute([$id]);
    $complaint = $stmt->fetch();
    if (!$complaint || (!$isAdmin && (int) $complaint['student_id'] !== (int) $user['id'])) {
        http_response_code(404);
        echo '<div class="empty-state"><span class="empty-icon">?</span><h2>Complaint not found</h2><p>This complaint may not exist or you may not have permission to view it.</p><a class="button button-primary" href="' . e(url('complaints')) . '">Back to complaints</a></div>';
        return;
    }
    if ($isAdmin && (int) $complaint['is_anonymous'] === 1) {
        $complaint['student_name'] = 'Anonymous';
        $complaint['student_email'] = '';
        $complaint['student_number'] = '';
    }
    $stmt = db()->prepare('SELECT h.*,u.full_name,u.role FROM status_history h JOIN users u ON u.id=h.changed_by WHERE h.complaint_id=? ORDER BY h.created_at ASC');
    $stmt->execute([$id]);
    $history = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT r.*,u.full_name,u.role FROM responses r JOIN users u ON u.id=r.user_id WHERE r.complaint_id=? ORDER BY r.created_at ASC');
    $stmt->execute([$id]);
    $responses = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT a.admin_id,u.full_name FROM complaint_assignments a JOIN users u ON u.id=a.admin_id WHERE a.complaint_id=?');
    $stmt->execute([$id]);
    $assigned = $stmt->fetchAll();
    ?>
    <div class="detail-back"><a href="<?= e(url('complaints')) ?>">← Back to complaints</a><span class="status <?= e(status_class($complaint['status'])) ?>"><?= e($complaint['status']) ?></span></div>
    <div class="detail-layout"><div class="detail-main">
        <section class="panel detail-summary"><div class="detail-reference"><?= e($complaint['reference_no']) ?> <span>·</span> Submitted <?= e(date('M j, Y \a\t g:i a', strtotime($complaint['created_at']))) ?></div><h2><?= e($complaint['title']) ?></h2><p class="detail-description"><?= nl2br(e($complaint['description'])) ?></p>
            <div class="detail-tags"><span class="tag"><?= e($complaint['category']) ?></span><span class="priority priority-<?= strtolower(e($complaint['priority'])) ?>"><?= e($complaint['priority']) ?> priority</span><span class="tag"><?= e($complaint['department'] ?: 'Unassigned department') ?></span><?php if ((int) $complaint['is_anonymous'] === 1): ?><span class="privacy-status">Anonymous to staff</span><?php elseif (!$isAdmin): ?><span class="tag">Name shared with staff</span><?php endif; ?></div>
            <?php if ($complaint['attachment_path']): ?><a class="attachment-link" href="<?= e(url('attachment', ['id' => $id])) ?>">⇩ <?= e($complaint['attachment_name']) ?> <small>Download attachment</small></a><?php endif; ?>
        </section>
        <section class="panel conversation-panel"><div class="panel-heading"><div><h3>Conversation</h3><p>Messages about this complaint</p></div></div>
            <?php if (!$responses): ?><div class="empty-conversation"><span>◌</span><p>There are no replies yet. Add a message below to continue the conversation.</p></div><?php endif; ?>
            <?php foreach ($responses as $reply): $replyName = $isAdmin && (int) $complaint['is_anonymous'] === 1 && $reply['role'] === 'student' ? 'Anonymous' : $reply['full_name']; ?><article class="message <?= $reply['role'] === 'admin' ? 'message-admin' : '' ?>"><span class="avatar avatar-small"><?= e(strtoupper(substr($replyName, 0, 1))) ?></span><div class="message-content"><div class="message-meta"><strong><?= e($replyName) ?></strong><span class="role-pill"><?= $reply['role'] === 'admin' ? 'STAFF' : 'STUDENT' ?></span><small><?= e(date('M j, Y · g:i a', strtotime($reply['created_at']))) ?></small></div><p><?= linkify_text((string) $reply['message']) ?></p></div></article><?php endforeach; ?>
            <form method="post" class="reply-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="send_response"><input type="hidden" name="complaint_id" value="<?= $id ?>"><label for="message">Add a reply</label><textarea id="message" name="message" rows="4" maxlength="10000" required placeholder="Write a message…"></textarea><div class="reply-actions"><small>Keep your message respectful and relevant.</small><button class="button button-primary" type="submit">Send reply <span>→</span></button></div></form>
        </section>
    </div><aside class="detail-aside">
        <?php if ($isAdmin): ?><section class="panel"><div class="panel-heading"><div><h3>Manage request</h3><p>Update status and assignment</p></div></div>
            <form method="post" class="form-stack compact-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_status"><input type="hidden" name="complaint_id" value="<?= $id ?>"><label>Status<select name="status"><?php foreach (['Pending','Under Review','In Progress','Awaiting Student Response','Resolved','Rejected','Reopened'] as $option): ?><option <?= $complaint['status'] === $option ? 'selected' : '' ?> value="<?= e($option) ?>"><?= e($option) ?></option><?php endforeach; ?></select></label><button class="button button-primary button-full" type="submit">Save status</button></form>
            <form method="post" class="form-stack compact-form assignment-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="assign_complaint"><input type="hidden" name="complaint_id" value="<?= $id ?>"><label>Assign staff<select name="admin_id"><option value="">No assignment</option><?php foreach (db()->query("SELECT id,full_name FROM users WHERE role='admin' AND is_active=1 ORDER BY full_name") as $admin): ?><option value="<?= (int) $admin['id'] ?>" <?= $assigned && (int) $assigned[0]['admin_id'] === (int) $admin['id'] ? 'selected' : '' ?>><?= e($admin['full_name']) ?></option><?php endforeach; ?></select></label><button class="button button-light button-full" type="submit">Save assignment</button></form>
            <div class="assigned-list"><?php foreach ($assigned as $staff): ?><span class="tag"><?= e($staff['full_name']) ?></span><?php endforeach; ?></div>
        </section><?php else: ?><section class="panel"><div class="panel-heading"><div><h3>Request details</h3><p>Information about your complaint</p></div></div><dl class="detail-facts"><dt>Reference</dt><dd><?= e($complaint['reference_no']) ?></dd><dt>Category</dt><dd><?= e($complaint['category']) ?></dd><dt>Priority</dt><dd><?= e($complaint['priority']) ?></dd><dt>Department</dt><dd><?= e($complaint['department'] ?: 'To be assigned') ?></dd><dt>Last updated</dt><dd><?= e(date('M j, Y', strtotime($complaint['updated_at']))) ?></dd></dl>
                <?php if (in_array($complaint['status'], ['Resolved', 'Rejected'], true)): ?><form method="post" class="reopen-form" data-confirm="Reopen this complaint?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reopen"><input type="hidden" name="complaint_id" value="<?= $id ?>"><button class="button button-light button-full" type="submit">Reopen complaint</button></form><?php endif; ?>
            </section><?php endif; ?>
        <section class="panel timeline-panel"><div class="panel-heading"><div><h3>Activity timeline</h3><p>Status history</p></div></div><div class="timeline"><?php foreach ($history as $item): $actorName = $isAdmin && (int) $complaint['is_anonymous'] === 1 && $item['role'] === 'student' ? 'Anonymous' : $item['full_name']; ?><div class="timeline-item"><span class="timeline-marker"></span><div><strong><?= e($item['new_status']) ?></strong><p><?= e($item['note'] ?: 'Updated by ' . $actorName) ?></p><small><?= e(date('M j, Y · g:i a', strtotime($item['created_at']))) ?></small></div></div><?php endforeach; ?></div></section>
        <?php if ($isAdmin): ?><section class="panel student-mini"><div class="panel-heading"><div><h3>Submitted by</h3></div></div><strong><?= e($complaint['student_name']) ?></strong><?php if ((int) $complaint['is_anonymous'] === 1): ?><p>Identity hidden from staff for this complaint.</p><?php else: ?><p><?= e($complaint['student_email']) ?></p><?php if ($complaint['student_number']): ?><small>ID: <?= e($complaint['student_number']) ?></small><?php endif; ?><?php endif; ?></section><?php endif; ?>
    </aside></div>
    <?php
}

function render_notifications(array $user): void
{
    $stmt = db()->prepare('SELECT n.*,c.reference_no,c.title FROM notifications n LEFT JOIN complaints c ON c.id=n.complaint_id WHERE n.user_id=? ORDER BY n.created_at DESC LIMIT 100');
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
    ?>
    <div class="list-page-heading"><div><span class="eyebrow">UPDATES</span><h2>Your notifications</h2><p>Stay informed about your support requests.</p></div>
        <?php if ($notifications): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="mark_notifications"><button class="button button-light" type="submit">Mark all as read</button></form><?php endif; ?>
    </div>
    <section class="panel"><div class="notification-list"><?php if ($notifications): foreach ($notifications as $item): ?><div class="notification-item <?= !(int) $item['is_read'] ? 'notification-unread' : '' ?>"><span class="notification-dot"></span><div><p><?= linkify_text((string) $item['message']) ?></p><small><?= e(date('M j, Y · g:i a', strtotime($item['created_at']))) ?><?= $item['reference_no'] ? ' · ' . e($item['reference_no']) : '' ?></small><a class="notification-target" href="<?= e($item['complaint_id'] ? url('detail', ['id' => $item['complaint_id']]) : url('messages')) ?>"><?= $item['complaint_id'] ? 'Open complaint' : 'Open messages' ?> →</a></div><?php if (!(int) $item['is_read']): ?><span class="unread-label">NEW</span><?php endif; ?></div><?php endforeach; else: ?><div class="empty-state"><span class="empty-icon">◉</span><h2>You're all caught up</h2><p>Notifications about your complaints will appear here.</p></div><?php endif; ?></div></section>
    <?php
}

function render_messages(array $user, bool $isAdmin): void
{
    $contacts = db()->query($isAdmin
        ? "SELECT id,full_name,email,role,profile_image_path FROM users WHERE role='student' AND is_active=1 ORDER BY full_name"
        : "SELECT id,full_name,email,role,profile_image_path FROM users WHERE role='admin' AND is_active=1 ORDER BY full_name")->fetchAll();
    $contactId = (int) ($_GET['contact'] ?? 0);
    $contact = null;
    foreach ($contacts as $candidate) {
        if ((int) $candidate['id'] === $contactId) {
            $contact = $candidate;
            break;
        }
    }
    if (!$contact && $contacts) {
        $contact = $contacts[0];
    }
    $messages = [];
    if ($contact) {
        $stmt = db()->prepare('SELECT m.*,u.full_name AS sender_name,u.role AS sender_role FROM (SELECT * FROM messages WHERE (sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?) ORDER BY id DESC LIMIT 300) m JOIN users u ON u.id=m.sender_id ORDER BY m.created_at,m.id');
        $stmt->execute([(int) $user['id'], (int) $contact['id'], (int) $contact['id'], (int) $user['id']]);
        $messages = $stmt->fetchAll();
    }
    $lastMessageId = $messages ? max(array_map(static fn(array $message): int => (int) $message['id'], $messages)) : 0;
    ?>
    <div class="list-page-heading chat-page-heading">
        <div><span class="eyebrow">CAMPUS COMMUNICATION</span><h2>Messages</h2><p><?= $isAdmin ? 'Private conversations and campus-wide announcements, in one place.' : 'A private line to your campus helpdesk team.' ?></p></div>
    </div>
    <?php if ($isAdmin): ?>
        <section class="panel announcement-panel">
            <div class="panel-heading"><div><h3>Campus announcement</h3><p>Share a public update with every active student. Replies stay private.</p></div><span class="soft-badge">All students</span></div>
            <form method="post" class="announcement-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="send_announcement">
                <label class="sr-only" for="announcement-message">Announcement message</label>
                <textarea id="announcement-message" name="message" rows="2" maxlength="500" required placeholder="Write an important campus update or add a link…"></textarea>
                <button class="button button-primary" type="submit">Send announcement <span>→</span></button>
            </form>
        </section>
    <?php endif; ?>
    <div class="messages-layout whatsapp-layout">
        <aside class="panel contacts-panel" aria-label="<?= $isAdmin ? 'Student conversations' : 'Helpdesk contacts' ?>">
            <div class="panel-heading"><div><h3><?= $isAdmin ? 'Your students' : 'Helpdesk team' ?></h3><p><?= count($contacts) ?> available</p></div><span class="contacts-count"><?= count($contacts) ?></span></div>
            <div class="contact-list" role="list">
                <?php foreach ($contacts as $item): ?>
                    <a role="listitem" class="contact-item <?= $contact && (int) $contact['id'] === (int) $item['id'] ? 'contact-active' : '' ?>" href="<?= e(url('messages', ['contact' => $item['id']])) ?>">
                        <?= avatar_markup($item) ?>
                        <span><strong><?= e($item['full_name']) ?></strong><small><?= $isAdmin ? 'Student · Private chat' : 'Campus support' ?></small></span>
                    </a>
                <?php endforeach; ?>
                <?php if (!$contacts): ?><div class="empty-state compact-empty"><span class="empty-icon">✉</span><h2>No contacts yet</h2><p><?= $isAdmin ? 'Active student accounts will appear here.' : 'No active administrator is available.' ?></p></div><?php endif; ?>
            </div>
        </aside>
        <section class="panel conversation-panel direct-conversation chat-window" aria-label="Private message conversation">
            <?php if ($contact): ?>
                <div class="panel-heading conversation-heading chat-header">
                    <?= avatar_markup($contact) ?>
                    <div class="chat-contact-title"><h3><?= e($contact['full_name']) ?></h3><p><?= $isAdmin ? 'Student' : 'Campus helpdesk' ?> · Private conversation</p></div>
                    <span class="private-chat-badge"><span aria-hidden="true">●</span> Private</span>
                </div>
                <div class="direct-message-list chat-wallpaper" role="log" aria-label="Messages with <?= e($contact['full_name']) ?>" data-feed-url="<?= e(url('message_feed', ['contact' => $contact['id']])) ?>" data-last-message-id="<?= $lastMessageId ?>" data-current-user-id="<?= (int) $user['id'] ?>"<?= !empty($user['chat_wallpaper_path']) ? ' style="--chat-wallpaper-image:url(\'' . e(url('user_media', ['type' => 'wallpaper'])) . '\')"' : '' ?>>
                    <?php if ($messages): foreach ($messages as $message): $mine = (int) $message['sender_id'] === (int) $user['id']; ?>
                        <article class="direct-message <?= $mine ? 'direct-message-mine' : '' ?>" data-message-id="<?= (int) $message['id'] ?>">
                            <div class="message-bubble">
                                <?php if (!$mine): ?><span class="chat-sender"><?= e($message['sender_name']) ?></span><?php endif; ?>
                                <?php if ((string) $message['message'] !== ''): ?><p><?= linkify_text((string) $message['message']) ?></p><?php endif; ?>
                                <?php if ($message['media_path']): ?>
                                    <?php $mediaUrl = url('message_attachment', ['id' => $message['id']]); ?>
                                    <?php if (str_starts_with((string) $message['media_mime'], 'image/')): ?>
                                        <a class="chat-image-link" href="<?= e($mediaUrl) ?>"><img src="<?= e($mediaUrl) ?>" alt="<?= e($message['media_name']) ?>" loading="lazy"></a>
                                    <?php elseif (str_starts_with((string) $message['media_mime'], 'audio/') || str_starts_with((string) $message['media_mime'], 'video/') || $message['media_mime'] === 'application/ogg'): ?>
                                        <audio controls preload="metadata"><source src="<?= e($mediaUrl) ?>" type="<?= e($message['media_mime']) ?>">Your browser cannot play this voice note.</audio>
                                    <?php else: ?>
                                        <a class="chat-file-link" href="<?= e($mediaUrl) ?>"><span class="file-icon" aria-hidden="true">⇩</span><span><strong><?= e($message['media_name']) ?></strong><small>Download attachment</small></span></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <time datetime="<?= e(date(DATE_ATOM, strtotime($message['created_at']))) ?>"><?= e(date('M j · g:i a', strtotime($message['created_at']))) ?></time>
                            </div>
                        </article>
                    <?php endforeach; else: ?>
                        <div class="chat-empty"><span class="empty-icon">✦</span><h2>A private space to talk</h2><p>Start the conversation with a message, photo, file, or voice note.</p></div>
                    <?php endif; ?>
                </div>
                <p class="chat-feed-status" data-feed-status aria-live="polite"></p>
                <form method="post" class="direct-reply-form chat-composer" enctype="multipart/form-data" data-chat-composer>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="recipient_id" value="<?= (int) $contact['id'] ?>">
                    <div class="chat-compose-tools">
                        <input class="chat-hidden-input" id="chat-media" type="file" name="media" accept="image/jpeg,image/png,image/gif,image/webp,audio/*,application/pdf,text/plain,text/csv,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                        <input class="chat-hidden-input" id="chat-camera" type="file" name="camera_capture" accept="image/*" capture="environment">
                        <button class="chat-tool-button" type="button" data-open-chat-file="chat-media" aria-label="Attach a photo or file" title="Attach a photo or file">＋</button>
                        <button class="chat-tool-button" type="button" data-open-chat-file="chat-camera" aria-label="Take a photo" title="Take a photo">▧</button>
                        <button class="chat-tool-button chat-record-button" type="button" data-voice-record aria-label="Record a voice note" aria-pressed="false" title="Record a voice note">●</button>
                    </div>
                    <div class="chat-compose-body">
                        <label class="sr-only" for="direct-message">Write a message or paste a link</label>
                        <textarea id="direct-message" name="message" rows="1" maxlength="5000" data-chat-message placeholder="Write a message or paste a link…"></textarea>
                        <div class="chat-attachment-preview" data-attachment-preview hidden><span data-attachment-name></span><button type="button" data-remove-attachment aria-label="Remove attachment">×</button></div>
                        <audio class="voice-preview" data-voice-preview controls hidden></audio>
                        <small class="chat-compose-status" data-voice-status aria-live="polite"></small>
                        <small class="chat-privacy-note">Visible to you and this contact in the portal; not end-to-end encrypted. Voice notes are limited to 2 minutes.</small>
                    </div>
                    <button class="chat-send-button" type="submit" aria-label="Send message" title="Send message">➤</button>
                </form>
            <?php else: ?>
                <div class="empty-state"><span class="empty-icon">✉</span><h2>No conversation available</h2><p>Once an active contact is available, you can message them here.</p></div>
            <?php endif; ?>
        </section>
    </div>
    <?php
}

function render_profile(array $user): void
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id=?');
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
    ?>
    <div class="list-page-heading"><div><span class="eyebrow">ACCOUNT</span><h2>My profile</h2><p>Manage your account details, personal appearance, and password.</p></div></div>
    <?php if ($user['must_change_password']): ?><div class="alert alert-warning">For your security, choose a new password before using the system.</div><?php endif; ?>
    <form method="post" class="panel profile-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_profile">
        <div class="profile-identity"><?= avatar_markup($profile, 'avatar avatar-large') ?><span><strong><?= e($profile['full_name']) ?></strong><small><?= e(ucfirst($profile['role'])) ?> account · Member since <?= e(date('M Y', strtotime($profile['created_at']))) ?></small></span></div>
        <div class="form-row"><label>Full name<input name="full_name" value="<?= e($profile['full_name']) ?>" required maxlength="150"></label><label>Email address<input value="<?= e($profile['email']) ?>" disabled><small>Email address cannot be changed here.</small></label></div>
        <?php if ($profile['role'] === 'student'): ?><div class="form-row"><label>Student ID<input name="student_id" value="<?= e($profile['student_id']) ?>" maxlength="60"></label><label>Department<select name="department_id"><option value="">Select department</option><?php foreach (db()->query('SELECT id,name FROM departments WHERE is_active=1 ORDER BY name') as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (int) $profile['department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></label></div><?php endif; ?>
        <hr><div class="form-section-title"><span>⌑</span><div><h3>Change password</h3><p>Choose a strong password that you don't use elsewhere.</p></div></div>
        <?php if (!$user['must_change_password']): ?><label>Current password<input type="password" name="current_password" autocomplete="current-password"></label><?php endif; ?>
        <div class="form-row"><label>New password<input type="password" name="new_password" minlength="10" autocomplete="new-password"><small>Leave blank to keep your current password.</small></label><div class="form-note"><span class="secure-mark">✓</span><span>Use at least 10 characters. Your password is stored securely.</span></div></div>
        <div class="form-actions"><button class="button button-primary" type="submit">Save profile</button></div>
    </form>
    <form method="post" enctype="multipart/form-data" class="panel profile-form profile-photo-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_profile_picture">
        <div class="form-section-title"><span>◎</span><div><h3>Profile photo</h3><p>Help people recognize you in messages and status updates.</p></div></div>
        <label>Choose a photo<input type="file" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp"><small>JPG, PNG, GIF or WebP · maximum 5 MB</small></label>
        <?php if (!empty($profile['profile_image_path'])): ?><label class="inline-check"><input type="checkbox" name="remove_profile_image" value="1"> Remove my current photo</label><?php endif; ?>
        <div class="form-actions"><button class="button button-primary" type="submit">Update photo</button></div>
    </form>
    <form method="post" enctype="multipart/form-data" class="panel profile-form appearance-form" id="appearance">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_preferences">
        <div class="form-section-title"><span>✦</span><div><h3>Your look &amp; feel</h3><p>Preferences are saved to your account and follow you across devices.</p></div></div>
        <div class="form-row">
            <label>Display mode<select name="theme_mode"><option value="light" <?= $profile['theme_mode'] === 'light' ? 'selected' : '' ?>>Light</option><option value="dark" <?= $profile['theme_mode'] === 'dark' ? 'selected' : '' ?>>Dark</option></select><small>Choose a bright workspace or a comfortable dark appearance.</small></label>
            <label>Dashboard palette<select name="dashboard_theme"><option value="ocean" <?= $profile['dashboard_theme'] === 'ocean' ? 'selected' : '' ?>>Ocean</option><option value="forest" <?= $profile['dashboard_theme'] === 'forest' ? 'selected' : '' ?>>Forest</option><option value="sunset" <?= $profile['dashboard_theme'] === 'sunset' ? 'selected' : '' ?>>Sunset</option><option value="violet" <?= $profile['dashboard_theme'] === 'violet' ? 'selected' : '' ?>>Violet</option></select><small>Metric cards use four distinct coordinated colors.</small></label>
        </div>
        <div class="form-row">
            <label>Chat color theme<select name="chat_theme"><option value="classic" <?= $profile['chat_theme'] === 'classic' ? 'selected' : '' ?>>Classic</option><option value="ocean" <?= $profile['chat_theme'] === 'ocean' ? 'selected' : '' ?>>Ocean</option><option value="rose" <?= $profile['chat_theme'] === 'rose' ? 'selected' : '' ?>>Rose</option><option value="forest" <?= $profile['chat_theme'] === 'forest' ? 'selected' : '' ?>>Forest</option></select><small>Change the color accents in your private conversations.</small></label>
            <label>Chat background photo<input type="file" name="chat_wallpaper" accept="image/jpeg,image/png,image/gif,image/webp"><small>Optional JPG, PNG, GIF or WebP image · maximum 5 MB</small></label>
        </div>
        <?php if (!empty($profile['chat_wallpaper_path'])): ?><label class="inline-check"><input type="checkbox" name="remove_chat_wallpaper" value="1"> Remove my chat background photo</label><?php endif; ?>
        <div class="form-actions"><button class="button button-primary" type="submit">Save appearance</button></div>
    </form>
    <?php
}

function render_status(array $user): void
{
    $expiredStmt = db()->query('SELECT media_path FROM statuses WHERE expires_at <= NOW() AND media_path IS NOT NULL');
    foreach ($expiredStmt->fetchAll(PDO::FETCH_COLUMN) as $expiredPath) {
        delete_user_image(is_string($expiredPath) ? $expiredPath : null, 'statuses');
    }
    db()->exec('DELETE FROM statuses WHERE expires_at <= NOW()');
    $total = (int) db()->query("SELECT COUNT(*) FROM statuses s JOIN users u ON u.id=s.user_id WHERE s.expires_at>NOW() AND u.is_active=1")->fetchColumn();
    $perPage = 30;
    $pageCount = max(1, (int) ceil($total / $perPage));
    $statusPage = min(max(1, (int) ($_GET['status_page'] ?? 1)), $pageCount);
    $offset = ($statusPage - 1) * $perPage;
    $stmt = db()->prepare("SELECT s.id,s.user_id,s.message,s.media_path,s.media_mime,s.created_at,s.expires_at,u.full_name,u.profile_image_path,
        (SELECT COUNT(*) FROM status_likes l JOIN users liker ON liker.id=l.user_id WHERE l.status_id=s.id AND liker.is_active=1) AS like_count,
        (SELECT COUNT(*) FROM status_views v JOIN users viewer ON viewer.id=v.user_id WHERE v.status_id=s.id AND viewer.is_active=1) AS view_count,
        EXISTS(SELECT 1 FROM status_likes mine WHERE mine.status_id=s.id AND mine.user_id=?) AS viewer_liked,
        EXISTS(SELECT 1 FROM status_views watched WHERE watched.status_id=s.id AND watched.user_id=?) AS viewer_viewed
        FROM statuses s JOIN users u ON u.id=s.user_id
        WHERE s.expires_at>NOW() AND u.is_active=1
        ORDER BY s.created_at DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute([(int) $user['id'], (int) $user['id']]);
    $statuses = $stmt->fetchAll();
    $storyGroups = [];
    foreach ($statuses as $status) {
        $authorId = (int) $status['user_id'];
        if (!isset($storyGroups[$authorId])) {
            $storyGroups[$authorId] = [
                'id' => $authorId,
                'name' => $status['full_name'],
                'full_name' => $status['full_name'],
                'profile_image_path' => $status['profile_image_path'],
                'unseen' => false,
                'is_mine' => $authorId === (int) $user['id'],
            ];
        }
        if (!(int) $status['viewer_viewed'] && $authorId !== (int) $user['id']) {
            $storyGroups[$authorId]['unseen'] = true;
        }
    }
    ?>
    <div class="list-page-heading status-page-heading">
        <div><span class="eyebrow">CAMPUS COMMUNITY</span><h2>Campus status</h2><p>Share a photo or quick update with active campus members. Posts disappear after 24 hours.</p></div>
        <span class="soft-badge"><?= $total ?> active post<?= $total === 1 ? '' : 's' ?></span>
    </div>
    <section class="panel status-stories-panel">
        <div class="panel-heading"><div><h3>Recent updates</h3><p>Tap a circle to view a 24-hour story.</p></div><span class="status-expiry-pill">Photos · 30 sec</span></div>
        <div class="status-stories">
            <?php foreach ($storyGroups as $group): ?><button class="status-story-profile <?= $group['unseen'] ? 'story-unseen' : '' ?>" type="button" data-status-story-group="<?= (int) $group['id'] ?>">
                <span class="status-story-ring"><?= avatar_markup($group, 'avatar') ?></span><span><?= $group['is_mine'] ? 'Your status' : e($group['name']) ?></span>
            </button><?php endforeach; ?>
            <?php if (!$storyGroups): ?><p class="status-stories-empty">Active campus stories will appear here.</p><?php endif; ?>
        </div>
    </section>
    <section class="panel status-compose-panel">
        <div class="panel-heading"><div><h3>Share a moment</h3><p>Every active user can see your post, like it, and see when it expires.</p></div><span class="status-expiry-pill">24 hours</span></div>
        <form method="post" enctype="multipart/form-data" class="status-compose-form" data-status-compose>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_status">
            <label class="sr-only" for="status-message">Status text</label>
            <textarea id="status-message" name="message" rows="3" maxlength="1000" placeholder="What would you like to share?"></textarea>
            <div class="status-compose-tools"><label class="status-photo-picker">＋ Add media<input type="file" name="status_media" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime"><span data-status-file-name>No media selected</span></label><small>Photo up to 5 MB · MP4/MOV up to 2 minutes and 50 MB. Trim longer videos before posting.</small><span class="status-media-feedback" data-status-media-feedback aria-live="polite"></span><button class="button button-primary" type="submit">Post status <span aria-hidden="true">→</span></button></div>
        </form>
    </section>
    <div class="status-feed" id="status-feed" data-status-feed data-view-url="<?= e(url('status')) ?>" data-updates-url="<?= e(url('status_updates')) ?>">
        <?php if ($statuses): ?><input type="hidden" data-status-csrf value="<?= e(csrf_token()) ?>"><?php endif; ?>
        <?php foreach ($statuses as $status): ?>
            <?php $isMine = (int) $status['user_id'] === (int) $user['id']; ?>
            <article class="panel status-card <?= $isMine ? 'status-card-mine' : '' ?>" id="status-<?= (int) $status['id'] ?>" data-status-card="<?= (int) $status['id'] ?>" data-status-author="<?= $isMine ? 'true' : 'false' ?>" data-status-author-id="<?= (int) $status['user_id'] ?>" data-status-author-name="<?= e($status['full_name']) ?>" data-status-created="<?= e(date(DATE_ATOM, strtotime($status['created_at']))) ?>" data-status-viewed="<?= (int) $status['viewer_viewed'] ? 'true' : 'false' ?>">
                <header class="status-card-header">
                    <?= avatar_markup(['id' => $status['user_id'], 'full_name' => $status['full_name'], 'profile_image_path' => $status['profile_image_path']]) ?>
                    <div class="status-author"><strong><?= $isMine ? 'You' : e($status['full_name']) ?></strong><small>Posted <?= e(date('M j · g:i a', strtotime($status['created_at']))) ?> · expires <?= e(date('M j · g:i a', strtotime($status['expires_at']))) ?></small></div>
                    <?php if ($isMine): ?><span class="status-owner-pill">Your post</span><?php endif; ?>
                </header>
                <?php if ($status['message'] !== ''): ?><p class="status-copy"><?= nl2br(e($status['message']), false) ?></p><?php endif; ?>
                <?php if ($status['media_path'] && str_starts_with((string) $status['media_mime'], 'image/')): ?><img class="status-image" src="<?= e(url('user_media', ['type' => 'status', 'id' => $status['id']])) ?>" alt="Photo posted by <?= e($status['full_name']) ?>" loading="lazy"><?php elseif ($status['media_path']): ?><div class="status-video-preview"><video src="<?= e(url('user_media', ['type' => 'status', 'id' => $status['id']])) ?>" preload="metadata" muted playsinline></video><span aria-hidden="true">▶</span></div><?php endif; ?>
                <div class="status-card-actions">
                    <span class="status-like-count" data-status-like-count><?= (int) $status['like_count'] ?> like<?= (int) $status['like_count'] === 1 ? '' : 's' ?></span>
                    <?php if (!$isMine): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status_like"><input type="hidden" name="status_id" value="<?= (int) $status['id'] ?>"><input type="hidden" name="status_page" value="<?= $statusPage ?>"><button class="status-like-button <?= (int) $status['viewer_liked'] ? 'is-liked' : '' ?>" type="submit" aria-pressed="<?= (int) $status['viewer_liked'] ? 'true' : 'false' ?>"><?= (int) $status['viewer_liked'] ? '♥ Liked' : '♡ Like' ?></button></form><?php endif; ?>
                    <?php if ($isMine): ?><span class="status-view-count" data-status-view-count><?= (int) $status['view_count'] ?> view<?= (int) $status['view_count'] === 1 ? '' : 's' ?></span><form method="post" data-confirm="Delete this status now?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_status"><input type="hidden" name="status_id" value="<?= (int) $status['id'] ?>"><input type="hidden" name="status_page" value="<?= $statusPage ?>"><button class="status-delete-button" type="submit">Delete</button></form><?php endif; ?>
                    <button class="status-open-button" type="button" data-status-story-open="<?= (int) $status['id'] ?>">View story</button>
                </div>
                <?php if ($isMine): ?>
                    <?php $viewStmt = db()->prepare('SELECT u.id AS viewer_id,u.full_name,u.profile_image_path,v.viewed_at FROM status_views v JOIN users u ON u.id=v.user_id WHERE v.status_id=? AND u.is_active=1 ORDER BY v.viewed_at DESC'); $viewStmt->execute([(int) $status['id']]); $viewers = $viewStmt->fetchAll(); ?>
                    <details class="status-viewers" data-viewers-url="<?= e(url('status_viewers', ['id' => $status['id']])) ?>"><summary>Who viewed this <span data-viewer-total><?= count($viewers) ?></span></summary>
                        <div class="status-viewer-list" data-status-viewers-list><?php foreach ($viewers as $viewer): ?><div><?= avatar_markup(['id' => $viewer['viewer_id'], 'full_name' => $viewer['full_name'], 'profile_image_path' => $viewer['profile_image_path']], 'avatar avatar-tiny') ?><strong><?= e($viewer['full_name']) ?></strong><time><?= e(date('M j · g:i a', strtotime($viewer['viewed_at']))) ?></time></div><?php endforeach; ?></div>
                        <p class="status-no-viewers" data-status-no-viewers <?= $viewers ? 'hidden' : '' ?>>Viewers will appear here after they open your post.</p>
                    </details>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$statuses): ?><div class="panel empty-state status-empty"><span class="empty-icon">✦</span><h2>No campus statuses yet</h2><p>Share a quick update or photo to start the campus feed.</p></div><?php endif; ?>
    </div>
    <div class="status-story-viewer" data-status-story-viewer hidden role="dialog" aria-modal="true" aria-label="Campus status viewer">
        <div class="status-story-backdrop" data-story-close></div>
        <section class="status-story-stage">
            <div class="status-story-progress" data-story-progress></div>
            <header class="status-story-header"><span class="status-story-avatar" data-story-avatar></span><div><strong data-story-author></strong><time data-story-time></time></div><button type="button" data-story-close aria-label="Close status">×</button></header>
            <div class="status-story-content"><p data-story-message></p><img data-story-image alt="" hidden><video data-story-video playsinline controls hidden></video><span class="status-video-hint" data-story-video-hint hidden></span></div>
            <button type="button" class="status-story-nav status-story-previous" data-story-previous aria-label="Previous status">‹</button>
            <button type="button" class="status-story-nav status-story-next" data-story-next aria-label="Next status">›</button>
            <footer class="status-story-footer"><button type="button" class="status-story-like" data-story-like hidden>♡ Like</button><button type="button" data-story-close class="status-story-close">Close</button></footer>
        </section>
    </div>
    <?php if ($pageCount > 1): ?><nav class="status-pagination" aria-label="Status pages"><?php if ($statusPage > 1): ?><a href="<?= e(url('status', ['status_page' => $statusPage - 1])) ?>#status-feed">← Newer</a><?php endif; ?><span>Page <?= $statusPage ?> of <?= $pageCount ?></span><?php if ($statusPage < $pageCount): ?><a href="<?= e(url('status', ['status_page' => $statusPage + 1])) ?>#status-feed">Older →</a><?php endif; ?></nav><?php endif; ?>
    <?php
}

function render_users(array $user): void
{
    $q = trim((string) ($_GET['q'] ?? ''));
    $stmt = db()->prepare('SELECT u.id,u.full_name,u.email,u.student_id,u.role,u.is_active,u.created_at,d.name AS department FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.full_name LIKE ? OR u.email LIKE ? OR COALESCE(u.student_id, "") LIKE ? ORDER BY u.created_at DESC');
    $term = '%' . $q . '%';
    $stmt->execute([$term, $term, $term]);
    $accounts = $stmt->fetchAll();
    ?>
    <div class="list-page-heading"><div><span class="eyebrow">ACCESS CONTROL</span><h2>Users</h2><p>Review student details and manage student account access.</p></div><span class="soft-badge"><?= count($accounts) ?> accounts</span></div>
    <section class="panel"><form class="filter-bar compact-filter" method="get"><input type="hidden" name="page" value="users"><label class="search-input"><span>⌕</span><input name="q" value="<?= e($q) ?>" placeholder="Search name, email or student ID…"></label><button class="button button-light">Search</button></form>
        <div class="table-wrap"><table><thead><tr><th>User</th><th>Department / ID</th><th>Role</th><th>Status</th><th>Member since</th><th>Student access</th></tr></thead><tbody>
            <?php foreach ($accounts as $account): ?>
                <tr>
                    <td><strong class="table-title"><?= e($account['full_name']) ?></strong><small><?= e($account['email']) ?><?php if ($account['role'] === 'student' && (int) $account['is_active']): ?> · <a class="text-link" href="<?= e(url('messages', ['contact' => $account['id']])) ?>">Message</a><?php endif; ?></small></td>
                    <td><?= e($account['department'] ?: '—') ?><small><?= e($account['student_id'] ?: '') ?></small></td>
                    <td><span class="role-pill"><?= e(ucfirst($account['role'])) ?></span></td>
                    <td><span class="status <?= $account['is_active'] ? 'status-resolved' : 'status-rejected' ?>"><?= $account['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td><?= e(date('M j, Y', strtotime($account['created_at']))) ?></td>
                    <td>
                        <?php if ($account['role'] === 'student'): ?>
                            <form method="post" class="user-manage-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="manage_user">
                                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">
                                <label class="toggle-label"><input type="checkbox" name="is_active" value="1" <?= $account['is_active'] ? 'checked' : '' ?>><span>Active</span></label>
                                <button class="button button-light button-small" type="submit">Save</button>
                            </form>
                        <?php else: ?>
                            <span class="muted">Protected</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php
}

function render_catalog(string $table): void
{
    $items = db()->query($table === 'categories' ? 'SELECT c.*,d.name AS department FROM categories c LEFT JOIN departments d ON d.id=c.department_id ORDER BY c.name' : 'SELECT * FROM departments ORDER BY name')->fetchAll();
    $label = $table === 'categories' ? 'Category' : 'Department';
    ?>
    <div class="list-page-heading"><div><span class="eyebrow">CONFIGURATION</span><h2><?= e($table === 'categories' ? 'Complaint categories' : 'Departments') ?></h2><p>Organize requests and route them to the right campus team.</p></div></div>
    <div class="catalog-layout"><section class="panel"><div class="panel-heading"><div><h3>Current <?= e(strtolower($label)) ?> list</h3><p><?= count($items) ?> configured</p></div></div><div class="catalog-list"><?php foreach ($items as $item): ?><div class="catalog-item"><div><strong><?= e($item['name']) ?></strong><small><?= e($item['description'] ?: ($item['department'] ?? '')) ?></small></div><span class="status <?= $item['is_active'] ? 'status-resolved' : 'status-rejected' ?>"><?= $item['is_active'] ? 'Active' : 'Inactive' ?></span><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_catalog"><input type="hidden" name="table" value="<?= e($table) ?>"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="button button-light button-small"><?= $item['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></div><?php endforeach; ?></div></section>
        <section class="panel catalog-form"><div class="panel-heading"><div><h3>Add <?= e(strtolower($label)) ?></h3><p>Keep the request structure clear.</p></div></div><form method="post" class="form-stack"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $table === 'categories' ? 'add_category' : 'add_department' ?>"><label><?= e($label) ?> name<input name="name" required maxlength="120"></label><label>Description <span class="optional">(optional)</span><textarea name="description" rows="4" maxlength="500"></textarea></label><?php if ($table === 'categories'): ?><label>Default department<select name="department_id"><option value="">No default department</option><?php foreach (db()->query('SELECT id,name FROM departments WHERE is_active=1 ORDER BY name') as $department): ?><option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?><button class="button button-primary" type="submit">Add <?= e(strtolower($label)) ?></button></form></section></div>
    <?php
}

function render_reports(): void
{
    $period = (string) ($_GET['period'] ?? 'month');
    $today = new DateTimeImmutable('today');
    if ($period === 'day') {
        $from = $today->format('Y-m-d');
        $to = $from;
    } elseif ($period === 'week') {
        $from = $today->modify('monday this week')->format('Y-m-d');
        $to = $today->format('Y-m-d');
    } elseif ($period === 'custom') {
        $from = (string) ($_GET['from'] ?? $today->modify('first day of this month')->format('Y-m-d'));
        $to = (string) ($_GET['to'] ?? $today->format('Y-m-d'));
        if (!is_valid_date($from)) {
            $from = $today->modify('first day of this month')->format('Y-m-d');
        }
        if (!is_valid_date($to)) {
            $to = $today->format('Y-m-d');
        }
    } else {
        $period = 'month';
        $from = $today->modify('first day of this month')->format('Y-m-d');
        $to = $today->format('Y-m-d');
    }
    $categoryId = (int) ($_GET['category'] ?? 0);
    $departmentId = (int) ($_GET['department'] ?? 0);
    $conditions = ['c.created_at >= ?', 'c.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
    $params = [$from . ' 00:00:00', $to];
    if ($categoryId > 0) {
        $conditions[] = 'c.category_id = ?';
        $params[] = $categoryId;
    }
    if ($departmentId > 0) {
        $conditions[] = 'c.department_id = ?';
        $params[] = $departmentId;
    }
    $where = implode(' AND ', $conditions);
    $summaryStmt = db()->prepare("SELECT COUNT(*) AS total, SUM(c.status='Resolved') AS resolved, SUM(c.status='Pending') AS pending, SUM(c.status='Rejected') AS rejected FROM complaints c WHERE $where");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch();
    $departmentStmt = db()->prepare("SELECT COALESCE(d.name,'Unassigned') AS name,COUNT(c.id) AS total,SUM(c.status='Resolved') AS resolved FROM complaints c LEFT JOIN departments d ON d.id=c.department_id WHERE $where GROUP BY d.id,d.name ORDER BY total DESC");
    $departmentStmt->execute($params);
    $departments = $departmentStmt->fetchAll();
    $categoryStmt = db()->prepare("SELECT cat.name,COUNT(c.id) AS total,SUM(c.status='Resolved') AS resolved FROM complaints c JOIN categories cat ON cat.id=c.category_id WHERE $where GROUP BY cat.id,cat.name ORDER BY total DESC");
    $categoryStmt->execute($params);
    $categories = $categoryStmt->fetchAll();
    ?>
    <div class="list-page-heading"><div><span class="eyebrow">INSIGHTS</span><h2>Reports &amp; analytics</h2><p>Understand volume and outcomes across campus.</p></div></div>
    <form method="get" class="panel report-filters"><input type="hidden" name="page" value="reports"><label>Period<select name="period"><option value="day" <?= $period === 'day' ? 'selected' : '' ?>>Today</option><option value="week" <?= $period === 'week' ? 'selected' : '' ?>>This week</option><option value="month" <?= $period === 'month' ? 'selected' : '' ?>>This month</option><option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom dates</option></select></label><label>From<input type="date" name="from" value="<?= e($from) ?>"></label><label>To<input type="date" name="to" value="<?= e($to) ?>"></label><label>Category<select name="category"><option value="0">All categories</option><?php foreach (db()->query('SELECT id,name FROM categories ORDER BY name') as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $categoryId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></label><label>Department<select name="department"><option value="0">All departments</option><?php foreach (db()->query('SELECT id,name FROM departments ORDER BY name') as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $departmentId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option><?php endforeach; ?></select></label><button class="button button-primary" type="submit">Apply filters</button></form>
    <div class="stats-grid"><?php stat_card('Total complaints', (int) $summary['total'], $from . ' to ' . $to, '▤', 'blue'); ?><?php stat_card('Resolved', (int) $summary['resolved'], 'Closed successfully', '✓', 'green'); ?><?php stat_card('Pending', (int) $summary['pending'], 'Awaiting action', '◷', 'amber'); ?><?php stat_card('Rejected', (int) $summary['rejected'], 'Not approved', '–', 'red'); ?></div>
    <div class="report-grid"><section class="panel"><div class="panel-heading"><div><h3>Category performance</h3><p>Request volume and resolved counts for this report</p></div></div><?php if ($categories): ?><div class="table-wrap"><table><thead><tr><th>Category</th><th>Complaints</th><th>Resolved</th><th>Resolution rate</th></tr></thead><tbody><?php foreach ($categories as $category): $rate = (int) $category['total'] ? round((int) $category['resolved'] * 100 / (int) $category['total']) : 0; ?><tr><td><?= e($category['name']) ?></td><td><?= (int) $category['total'] ?></td><td><?= (int) $category['resolved'] ?></td><td><span class="report-rate"><span><i style="width:<?= $rate ?>%"></i></span><?= $rate ?>%</span></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state compact-empty"><p>No category data for this report period.</p></div><?php endif; ?></section>
        <section class="panel"><div class="panel-heading"><div><h3>Department performance</h3><p>Complaint volume and resolved counts</p></div></div><?php if ($departments): ?><div class="table-wrap"><table><thead><tr><th>Department</th><th>Complaints</th><th>Resolved</th><th>Resolution rate</th></tr></thead><tbody><?php foreach ($departments as $department): $rate = (int) $department['total'] ? round((int) $department['resolved'] * 100 / (int) $department['total']) : 0; ?><tr><td><?= e($department['name']) ?></td><td><?= (int) $department['total'] ?></td><td><?= (int) $department['resolved'] ?></td><td><span class="report-rate"><span><i style="width:<?= $rate ?>%"></i></span><?= $rate ?>%</span></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state compact-empty"><p>No department data for this report period.</p></div><?php endif; ?></section></div>
    <form method="get" action="index.php" class="export-inline"><input type="hidden" name="page" value="export"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><input type="hidden" name="category" value="<?= $categoryId ?>"><input type="hidden" name="department" value="<?= $departmentId ?>"><button class="button button-light" type="submit">⇩ Export this report as CSV</button></form>
    <?php
}

function render_settings(): void
{
    ?>
    <div class="list-page-heading"><div><span class="eyebrow">ADMINISTRATION</span><h2>System settings</h2><p>Customize your helpdesk identity and contact information.</p></div></div>
    <form method="post" class="panel settings-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_settings"><div class="form-section-title"><span>⚙</span><div><h3>Institution details</h3><p>These details are shown in the helpdesk interface.</p></div></div>
        <div class="form-row"><label>Application name<input name="app_name" value="<?= e(setting('app_name', APP_NAME)) ?>" maxlength="100" required></label><label>Institution name<input name="institution_name" value="<?= e(setting('institution_name', 'Campus Institution')) ?>" maxlength="150" required></label></div>
        <div class="form-row"><label>Contact email<input type="email" name="contact_email" value="<?= e(setting('contact_email')) ?>" maxlength="190"></label><label>Contact phone<input name="contact_phone" value="<?= e(setting('contact_phone')) ?>" maxlength="50"></label></div>
        <div class="form-actions"><button class="button button-primary">Save settings</button></div></form>
    <?php
}

function render_audit(): void
{
    $logs = db()->query('SELECT a.*,u.full_name,u.email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 250')->fetchAll();
    ?>
    <div class="list-page-heading"><div><span class="eyebrow">SECURITY &amp; ACCOUNTABILITY</span><h2>Audit log</h2><p>Recent account and complaint management activity.</p></div></div>
    <section class="panel"><?php if ($logs): ?><div class="table-wrap"><table><thead><tr><th>Action</th><th>Performed by</th><th>Details</th><th>IP address</th><th>Date &amp; time</th></tr></thead><tbody><?php foreach ($logs as $log): ?><tr><td><strong><?= e($log['action']) ?></strong></td><td><?= e($log['full_name'] ?: 'System') ?><small><?= e($log['email'] ?: '') ?></small></td><td><?= e($log['details'] ?: '—') ?></td><td><?= e($log['ip_address'] ?: '—') ?></td><td><?= e(date('M j, Y · g:i a', strtotime($log['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><p>Administrative activity will appear here.</p></div><?php endif; ?></section>
    <?php
}
