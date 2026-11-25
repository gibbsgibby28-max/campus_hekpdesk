<?php
declare(strict_types=1);

function handle_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $publicActions = ['login', 'register'];
    if (!in_array($action, $publicActions, true)) {
        require_login();
    }

    switch ($action) {
        case 'login':
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $account = $stmt->fetch();
            if (!$account || !password_verify($password, $account['password_hash']) || !(int) $account['is_active']) {
                flash('error', 'The email or password is incorrect, or this account is inactive.');
                redirect(url('login'));
            }
            session_regenerate_id(true);
            unset($account['password_hash']);
            $_SESSION['user'] = $account;
            $_SESSION['last_activity'] = time();
            audit('User signed in', null, (int) $account['id']);
            redirect(url('dashboard'));

        case 'register':
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $studentId = trim((string) ($_POST['student_id'] ?? ''));
            $departmentId = (int) ($_POST['department_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || $password !== (string) ($_POST['password_confirm'] ?? '')) {
                flash('error', 'Check your details. Use a valid email and a matching password with at least 10 characters.');
                redirect(url('register'));
            }
            if ($departmentId > 0) {
                $departmentStmt = db()->prepare('SELECT id FROM departments WHERE id = ? AND is_active = 1');
                $departmentStmt->execute([$departmentId]);
                if (!$departmentStmt->fetchColumn()) {
                    flash('error', 'Select an available department.');
                    redirect(url('register'));
                }
            }
            $stmt = db()->prepare('INSERT INTO users (full_name, email, student_id, department_id, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)');
            try {
                $stmt->execute([$name, $email, $studentId !== '' ? $studentId : null, $departmentId ?: null, password_hash($password, PASSWORD_DEFAULT), 'student']);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    flash('error', 'That email or student ID is already registered.');
                    redirect(url('register'));
                }
                throw $exception;
            }
            audit('Student account registered', 'Self-service student registration', (int) db()->lastInsertId());
            flash('success', 'Your account is ready. Sign in to continue.');
            redirect(url('login'));

        case 'logout':
            if (current_user()) {
                audit('User signed out');
            }
            $_SESSION = [];
            session_regenerate_id(true);
            flash('success', 'You have been signed out.');
            redirect(url('login'));

        case 'submit_complaint':
            $user = require_role('student');
            create_complaint($user);

        case 'update_status':
            $admin = require_role('admin');
            $complaintId = (int) ($_POST['complaint_id'] ?? 0);
            $newStatus = (string) ($_POST['status'] ?? '');
            $validStatuses = ['Pending', 'Under Review', 'In Progress', 'Awaiting Student Response', 'Resolved', 'Rejected', 'Reopened'];
            if (!in_array($newStatus, $validStatuses, true)) {
                flash('error', 'Choose a valid complaint status.');
                redirect(url('detail', ['id' => $complaintId]));
            }
            $pdo = db();
            $stmt = $pdo->prepare('SELECT status, student_id, reference_no FROM complaints WHERE id = ?');
            $stmt->execute([$complaintId]);
            $complaint = $stmt->fetch();
            if (!$complaint) {
                flash('error', 'Complaint not found.');
                redirect(url('complaints'));
            }
            $pdo->beginTransaction();
            $resolvedAt = $newStatus === 'Resolved' ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('UPDATE complaints SET status = ?, resolved_at = ? WHERE id = ?')->execute([$newStatus, $resolvedAt, $complaintId]);
            $pdo->prepare('INSERT INTO status_history (complaint_id, changed_by, old_status, new_status) VALUES (?, ?, ?, ?)')->execute([$complaintId, $admin['id'], $complaint['status'], $newStatus]);
            $pdo->commit();
            notify_user((int) $complaint['student_id'], 'Complaint ' . $complaint['reference_no'] . ' status changed to ' . $newStatus . '.', $complaintId);
            audit('Complaint status changed', $complaint['reference_no'] . ': ' . $complaint['status'] . ' → ' . $newStatus);
            flash('success', 'Complaint status updated and the student was notified.');
            redirect(url('detail', ['id' => $complaintId]));

        case 'send_response':
            $user = require_login();
            $complaintId = (int) ($_POST['complaint_id'] ?? 0);
            $message = trim((string) ($_POST['message'] ?? ''));
            if ($message === '' || mb_strlen($message) > 10000) {
                flash('error', 'Enter a response of up to 10,000 characters.');
                redirect(url('detail', ['id' => $complaintId]));
            }
            $stmt = db()->prepare('SELECT * FROM complaints WHERE id = ?');
            $stmt->execute([$complaintId]);
            $complaint = $stmt->fetch();
            if (!$complaint || ($user['role'] !== 'admin' && (int) $complaint['student_id'] !== (int) $user['id'])) {
                http_response_code(404);
                exit('Complaint not found.');
            }
            db()->prepare('INSERT INTO responses (complaint_id, user_id, message) VALUES (?, ?, ?)')->execute([$complaintId, $user['id'], $message]);
            if ($user['role'] === 'admin') {
                notify_user((int) $complaint['student_id'], 'An administrator replied to complaint ' . $complaint['reference_no'] . '.', $complaintId);
            } else {
                notify_admins('A student replied to complaint ' . $complaint['reference_no'] . '.', $complaintId);
            }
            audit(
                'Complaint response sent',
                $complaint['reference_no'],
                null,
                $user['role'] === 'student' && (int) $complaint['is_anonymous'] === 1
            );
            flash('success', 'Your reply has been added to the conversation.');
            redirect(url('detail', ['id' => $complaintId]));

        case 'reopen':
            $user = require_role('student');
            $complaintId = (int) ($_POST['complaint_id'] ?? 0);
            $stmt = db()->prepare("SELECT * FROM complaints WHERE id = ? AND student_id = ? AND status IN ('Resolved','Rejected')");
            $stmt->execute([$complaintId, $user['id']]);
            $complaint = $stmt->fetch();
            if (!$complaint) {
                flash('error', 'Only your resolved or rejected complaints can be reopened.');
                redirect(url('complaints'));
            }
            db()->beginTransaction();
            db()->prepare("UPDATE complaints SET status = 'Reopened', resolved_at = NULL WHERE id = ?")->execute([$complaintId]);
            db()->prepare("INSERT INTO status_history (complaint_id, changed_by, old_status, new_status, note) VALUES (?, ?, ?, 'Reopened', 'Student requested the complaint be reopened')")->execute([$complaintId, $user['id'], $complaint['status']]);
            db()->commit();
            notify_admins('Complaint ' . $complaint['reference_no'] . ' has been reopened by the student.', $complaintId);
            audit('Complaint reopened', $complaint['reference_no'], null, (int) $complaint['is_anonymous'] === 1);
            flash('success', 'Your complaint has been reopened.');
            redirect(url('detail', ['id' => $complaintId]));

        case 'mark_notifications':
            $user = require_login();
            db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
            flash('success', 'All notifications marked as read.');
            redirect(url('notifications'));

        case 'send_message':
            $user = require_login();
            $recipientId = (int) ($_POST['recipient_id'] ?? 0);
            $message = trim((string) ($_POST['message'] ?? ''));
            $recipientStmt = db()->prepare('SELECT id,full_name,role FROM users WHERE id=? AND is_active=1');
            $recipientStmt->execute([$recipientId]);
            $recipient = $recipientStmt->fetch();
            $expectedRole = $user['role'] === 'admin' ? 'student' : 'admin';
            if (!$recipient || $recipient['role'] !== $expectedRole || $recipientId === (int) $user['id']) {
                flash('error', 'That contact is no longer available.');
                redirect(url('messages'));
            }
            if (mb_strlen($message) > 5000) {
                flash('error', 'Messages can contain up to 5,000 characters.');
                redirect(url('messages', ['contact' => $recipientId]));
            }
            $mediaUpload = $_FILES['media'] ?? null;
            if (!is_array($mediaUpload) || ($mediaUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $mediaUpload = $_FILES['camera_capture'] ?? null;
            }
            $media = store_message_media(is_array($mediaUpload) ? $mediaUpload : null, $recipientId);
            if ($message === '' && $media === null) {
                flash('error', 'Write a message or attach a voice note, photo, or file.');
                redirect(url('messages', ['contact' => $recipientId]));
            }
            try {
                db()->prepare('INSERT INTO messages (sender_id,recipient_id,message,media_path,media_name,media_mime) VALUES (?,?,?,?,?,?)')
                    ->execute([
                        (int) $user['id'],
                        $recipientId,
                        $message,
                        $media['path'] ?? null,
                        $media['name'] ?? null,
                        $media['mime'] ?? null,
                    ]);
            } catch (Throwable $exception) {
                if ($media !== null && is_file($media['absolute_path']) && !unlink($media['absolute_path'])) {
                    error_log('Campus Helpdesk could not remove an unsaved message upload: ' . $media['absolute_path']);
                }
                throw $exception;
            }
            notify_user($recipientId, 'You have a new private message from ' . $user['full_name'] . '.');
            audit('Direct message sent', 'Message sent to user #' . $recipientId);
            flash('success', $media !== null ? 'Your message and attachment were sent.' : 'Your message was sent.');
            redirect(url('messages', ['contact' => $recipientId]));

        case 'send_announcement':
            $admin = require_role('admin');
            $message = trim((string) ($_POST['message'] ?? ''));
            if ($message === '' || mb_strlen($message) > 500) {
                flash('error', 'Enter an announcement of up to 500 characters.');
                redirect(url('messages'));
            }
            $stmt = db()->prepare("INSERT INTO notifications (user_id,message) SELECT id,? FROM users WHERE role='student' AND is_active=1");
            $stmt->execute([$message]);
            $recipientCount = $stmt->rowCount();
            audit('Student announcement sent', 'Delivered to ' . $recipientCount . ' active student account(s)');
            flash('success', 'Announcement sent to ' . $recipientCount . ' active student account(s).');
            redirect(url('messages'));

        case 'save_profile':
            save_profile(require_login());

        case 'update_profile_picture':
            update_profile_picture(require_login());

        case 'save_preferences':
            save_preferences(require_login());

        case 'create_status':
            create_status(require_login());

        case 'toggle_status_like':
            toggle_status_like(require_login());

        case 'delete_status':
            delete_status(require_login());

        case 'record_status_view':
            record_status_view(require_login());

        case 'manage_user':
            manage_user(require_role('admin'));

        case 'assign_complaint':
            assign_complaint(require_role('admin'));

        case 'add_category':
            manage_catalog('categories');

        case 'add_department':
            manage_catalog('departments');

        case 'toggle_catalog':
            toggle_catalog(require_role('admin'));

        case 'save_settings':
            save_settings(require_role('admin'));

        case 'export_csv':
            export_csv(require_role('admin'));
    }
}

function create_complaint(array $user): never
{
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $priority = (string) ($_POST['priority'] ?? 'Normal');
    $isAnonymous = (string) ($_POST['identity_preference'] ?? 'named') === 'anonymous';
    if (mb_strlen($title) < 5 || mb_strlen($title) > 180 || mb_strlen($description) < 20 || mb_strlen($description) > 20000 || !in_array($priority, ['Low', 'Normal', 'High', 'Urgent'], true)) {
        flash('error', 'Enter a title of 5–180 characters and a description of at least 20 characters.');
        redirect(url('submit'));
    }
    $categoryStmt = db()->prepare('SELECT id, department_id FROM categories WHERE id = ? AND is_active = 1');
    $categoryStmt->execute([$categoryId]);
    $category = $categoryStmt->fetch();
    if (!$category) {
        flash('error', 'Please select an available complaint category.');
        redirect(url('submit'));
    }

    $attachmentPath = null;
    $attachmentName = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['attachment'];
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf', 'text/plain' => 'txt'];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
            flash('error', 'The attachment must be a valid file smaller than 5 MB.');
            redirect(url('submit'));
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            flash('error', 'Allowed attachment types are JPG, PNG, PDF and plain text.');
            redirect(url('submit'));
        }
        $safeName = bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
        $target = dirname(__DIR__) . '/uploads/attachments/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException('Unable to store uploaded attachment.');
        }
        $attachmentPath = 'uploads/attachments/' . $safeName;
        $attachmentName = basename((string) $file['name']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO complaints (student_id, is_anonymous, category_id, department_id, title, description, priority, attachment_path, attachment_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$user['id'], $isAnonymous ? 1 : 0, $categoryId, $category['department_id'], $title, $description, $priority, $attachmentPath, $attachmentName]);
    $id = (int) $pdo->lastInsertId();
    $reference = sprintf('CH-%s-%04d', date('Y'), $id);
    $pdo->prepare('UPDATE complaints SET reference_no = ? WHERE id = ?')->execute([$reference, $id]);
    $pdo->prepare('INSERT INTO status_history (complaint_id, changed_by, new_status, note) VALUES (?, ?, ?, ?)')->execute([$id, $user['id'], 'Pending', 'Complaint submitted']);
    $pdo->commit();
    notify_admins('New complaint submitted: ' . $reference . '.', $id);
    audit('Complaint submitted', $reference, null, $isAnonymous);
    flash('success', ($isAnonymous ? 'Your anonymous complaint was submitted.' : 'Your complaint was submitted.') . ' Reference: ' . $reference);
    redirect(url('detail', ['id' => $id]));
}

function store_message_media(?array $file, int $recipientId): ?array
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['tmp_name'], $file['size']) || (int) $file['size'] > 10 * 1024 * 1024 || !is_uploaded_file((string) $file['tmp_name'])) {
        flash('error', 'The attachment could not be uploaded. Choose a valid file up to 10 MB.');
        redirect(url('messages', ['contact' => $recipientId]));
    }

    $allowed = [
        'image/jpeg' => ['jpg', 'image'],
        'image/png' => ['png', 'image'],
        'image/gif' => ['gif', 'image'],
        'image/webp' => ['webp', 'image'],
        'audio/webm' => ['webm', 'audio'],
        'video/webm' => ['webm', 'audio'],
        'audio/ogg' => ['ogg', 'audio'],
        'application/ogg' => ['ogg', 'audio'],
        'audio/mpeg' => ['mp3', 'audio'],
        'audio/mp4' => ['m4a', 'audio'],
        'audio/wav' => ['wav', 'audio'],
        'audio/x-wav' => ['wav', 'audio'],
        'application/pdf' => ['pdf', 'file'],
        'text/plain' => ['txt', 'file'],
        'text/csv' => ['csv', 'file'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'file'],
    ];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    if (!is_string($mime) || !isset($allowed[$mime])) {
        flash('error', 'Use a JPG, PNG, GIF, WebP, voice recording, PDF, TXT, CSV, or DOCX file.');
        redirect(url('messages', ['contact' => $recipientId]));
    }

    $uploadDirectory = dirname(__DIR__) . '/uploads/messages';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('Unable to prepare the private message upload directory.');
    }
    $filename = bin2hex(random_bytes(24)) . '.' . $allowed[$mime][0];
    $absolutePath = $uploadDirectory . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to store a private message attachment.');
    }

    $originalName = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'attachment')));
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'attachment';

    return [
        'path' => 'uploads/messages/' . $filename,
        'name' => mb_substr($originalName, 0, 180),
        'mime' => $mime,
        'kind' => $allowed[$mime][1],
        'absolute_path' => $absolutePath,
    ];
}

function store_user_image(?array $file, string $folder, string $returnPage): ?array
{
    $allowedFolders = ['profiles', 'wallpapers'];
    if (!in_array($folder, $allowedFolders, true)) {
        throw new InvalidArgumentException('Invalid image upload destination.');
    }
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['tmp_name'], $file['size']) || (int) $file['size'] > 5 * 1024 * 1024 || !is_uploaded_file((string) $file['tmp_name'])) {
        flash('error', 'Choose a valid image no larger than 5 MB.');
        redirect(url($returnPage));
    }

    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $imageInfo = @getimagesize((string) $file['tmp_name']);
    if (!is_string($mime) || !isset($extensions[$mime]) || !$imageInfo || ($imageInfo['mime'] ?? '') !== $mime) {
        flash('error', 'Use a valid JPG, PNG, GIF or WebP image.');
        redirect(url($returnPage));
    }

    $directory = dirname(__DIR__) . '/uploads/' . $folder;
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare the private image upload directory.');
    }
    $filename = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
    $absolutePath = $directory . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to store the uploaded image.');
    }
    return ['path' => 'uploads/' . $folder . '/' . $filename, 'absolute_path' => $absolutePath];
}

function status_video_duration(string $path): ?float
{
    $data = file_get_contents($path);
    if ($data === false) {
        return null;
    }
    $fileSize = strlen($data);
    $offset = 0;
    $moovStart = null;
    $moovEnd = null;
    while ($offset + 8 <= $fileSize) {
        $boxSize = unpack('N', substr($data, $offset, 4))[1];
        $boxType = substr($data, $offset + 4, 4);
        $headerSize = 8;
        if ($boxSize === 1) {
            if ($offset + 16 > $fileSize) {
                return null;
            }
            $largeSize = unpack('Nhigh/Nlow', substr($data, $offset + 8, 8));
            if ($largeSize['high'] !== 0) {
                return null;
            }
            $boxSize = (int) $largeSize['low'];
            $headerSize = 16;
        } elseif ($boxSize === 0) {
            $boxSize = $fileSize - $offset;
        }
        if ($boxSize < $headerSize || $offset + $boxSize > $fileSize) {
            return null;
        }
        if ($boxType === 'moov') {
            $moovStart = $offset + $headerSize;
            $moovEnd = $offset + $boxSize;
            break;
        }
        $offset += $boxSize;
    }
    if ($moovStart === null || $moovEnd === null) {
        return null;
    }

    $offset = $moovStart;
    while ($offset + 8 <= $moovEnd) {
        $boxSize = unpack('N', substr($data, $offset, 4))[1];
        $boxType = substr($data, $offset + 4, 4);
        $headerSize = 8;
        if ($boxSize === 1) {
            if ($offset + 16 > $moovEnd) {
                return null;
            }
            $largeSize = unpack('Nhigh/Nlow', substr($data, $offset + 8, 8));
            if ($largeSize['high'] !== 0) {
                return null;
            }
            $boxSize = (int) $largeSize['low'];
            $headerSize = 16;
        } elseif ($boxSize === 0) {
            $boxSize = $moovEnd - $offset;
        }
        if ($boxSize < $headerSize || $offset + $boxSize > $moovEnd) {
            return null;
        }
        if ($boxType === 'mvhd') {
            $payload = $offset + $headerSize;
            $version = ord($data[$payload]);
            if ($version === 0 && $boxSize >= $headerSize + 20) {
                $timescale = unpack('N', substr($data, $payload + 12, 4))[1];
                $duration = unpack('N', substr($data, $payload + 16, 4))[1];
            } elseif ($version === 1 && $boxSize >= $headerSize + 32) {
                $timescale = unpack('N', substr($data, $payload + 20, 4))[1];
                $parts = unpack('Nhigh/Nlow', substr($data, $payload + 24, 8));
                if ($parts['high'] !== 0) {
                    return null;
                }
                $duration = (int) $parts['low'];
            } else {
                return null;
            }
            return $timescale > 0 && $duration > 0 ? $duration / $timescale : null;
        }
        $offset += $boxSize;
    }
    return null;
}

function store_status_media(?array $file): ?array
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !isset($file['tmp_name'], $file['size']) || !is_uploaded_file((string) $file['tmp_name'])) {
        flash('error', 'Choose a valid photo or video to post.');
        redirect(url('status'));
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $images = ['image/jpeg' => ['jpg', 5 * 1024 * 1024], 'image/png' => ['png', 5 * 1024 * 1024], 'image/gif' => ['gif', 5 * 1024 * 1024], 'image/webp' => ['webp', 5 * 1024 * 1024]];
    $videos = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov'];
    if (is_string($mime) && isset($images[$mime])) {
        if ((int) $file['size'] > $images[$mime][1]) {
            flash('error', 'Status photos must be no larger than 5 MB.');
            redirect(url('status'));
        }
        $imageInfo = @getimagesize((string) $file['tmp_name']);
        if (!$imageInfo || ($imageInfo['mime'] ?? '') !== $mime) {
            flash('error', 'Choose a valid JPG, PNG, GIF or WebP photo.');
            redirect(url('status'));
        }
        $extension = $images[$mime][0];
    } elseif (is_string($mime) && isset($videos[$mime])) {
        if ((int) $file['size'] > 50 * 1024 * 1024) {
            flash('error', 'Status videos must be no larger than 50 MB.');
            redirect(url('status'));
        }
        $duration = status_video_duration((string) $file['tmp_name']);
        if ($duration === null) {
            flash('error', 'That video could not be verified. Choose a valid MP4 or MOV file.');
            redirect(url('status'));
        }
        if ($duration > 120) {
            flash('error', 'Status videos can be up to 2 minutes. Trim the video on your device and choose it again.');
            redirect(url('status'));
        }
        $extension = $videos[$mime];
    } else {
        flash('error', 'Use a JPG, PNG, GIF or WebP photo, or an MP4/MOV video up to 2 minutes.');
        redirect(url('status'));
    }

    $directory = dirname(__DIR__) . '/uploads/statuses';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare the private status upload directory.');
    }
    $filename = bin2hex(random_bytes(24)) . '.' . $extension;
    $absolutePath = $directory . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
        throw new RuntimeException('Unable to store the status media.');
    }
    return ['path' => 'uploads/statuses/' . $filename, 'mime' => $mime, 'absolute_path' => $absolutePath];
}

function update_profile_picture(array $user): never
{
    $stmt = db()->prepare('SELECT profile_image_path FROM users WHERE id=?');
    $stmt->execute([(int) $user['id']]);
    $oldPath = $stmt->fetchColumn();
    $remove = isset($_POST['remove_profile_image']);
    $image = store_user_image($_FILES['profile_image'] ?? null, 'profiles', 'profile');
    if ($image && $remove) {
        delete_user_image($image['path'], 'profiles');
        flash('error', 'Choose a new photo or remove the current one, not both.');
        redirect(url('profile'));
    }
    if (!$image && !$remove) {
        flash('error', 'Choose a new profile photo or select the option to remove your current photo.');
        redirect(url('profile'));
    }
    $newPath = $image['path'] ?? ($remove ? null : $oldPath);
    db()->prepare('UPDATE users SET profile_image_path=? WHERE id=?')->execute([$newPath, (int) $user['id']]);
    $_SESSION['user']['profile_image_path'] = $newPath;
    if ($image || $remove) {
        delete_user_image(is_string($oldPath) ? $oldPath : null, 'profiles');
    }
    audit('Profile photo updated');
    flash('success', $newPath ? 'Your profile photo was updated.' : 'Your profile photo was removed.');
    redirect(url('profile'));
}

function save_preferences(array $user): never
{
    $themeMode = (string) ($_POST['theme_mode'] ?? '');
    $dashboardTheme = (string) ($_POST['dashboard_theme'] ?? '');
    $chatTheme = (string) ($_POST['chat_theme'] ?? '');
    if (!in_array($themeMode, ['light', 'dark'], true) || !in_array($dashboardTheme, ['ocean', 'forest', 'sunset', 'violet'], true) || !in_array($chatTheme, ['classic', 'ocean', 'rose', 'forest'], true)) {
        flash('error', 'Choose a valid appearance option.');
        redirect(url('profile'));
    }

    $stmt = db()->prepare('SELECT chat_wallpaper_path FROM users WHERE id=?');
    $stmt->execute([(int) $user['id']]);
    $oldPath = $stmt->fetchColumn();
    $remove = isset($_POST['remove_chat_wallpaper']);
    $image = store_user_image($_FILES['chat_wallpaper'] ?? null, 'wallpapers', 'profile');
    if ($image && $remove) {
        delete_user_image($image['path'], 'wallpapers');
        flash('error', 'Choose a new wallpaper or remove the current one, not both.');
        redirect(url('profile'));
    }
    $newPath = $image['path'] ?? ($remove ? null : $oldPath);
    db()->prepare('UPDATE users SET theme_mode=?,dashboard_theme=?,chat_theme=?,chat_wallpaper_path=? WHERE id=?')
        ->execute([$themeMode, $dashboardTheme, $chatTheme, $newPath, (int) $user['id']]);
    $_SESSION['user']['theme_mode'] = $themeMode;
    $_SESSION['user']['dashboard_theme'] = $dashboardTheme;
    $_SESSION['user']['chat_theme'] = $chatTheme;
    $_SESSION['user']['chat_wallpaper_path'] = $newPath;
    if ($image || $remove) {
        delete_user_image(is_string($oldPath) ? $oldPath : null, 'wallpapers');
    }
    audit('Personal appearance updated');
    flash('success', 'Your appearance preferences were saved.');
    redirect(url('profile') . '#appearance');
}

function create_status(array $user): never
{
    $message = trim((string) ($_POST['message'] ?? ''));
    $upload = $_FILES['status_media'] ?? null;
    $hasUpload = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (mb_strlen($message) > 1000 || ($message === '' && !$hasUpload)) {
        flash('error', 'Write a status of up to 1,000 characters or add a photo.');
        redirect(url('status'));
    }
    $image = store_status_media(is_array($upload) ? $upload : null);
    try {
        db()->prepare('INSERT INTO statuses (user_id,message,media_path,media_mime,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))')
            ->execute([(int) $user['id'], $message, $image['path'] ?? null, $image['mime'] ?? null]);
    } catch (Throwable $exception) {
        if ($image && is_file($image['absolute_path']) && !unlink($image['absolute_path'])) {
            error_log('Campus Helpdesk could not remove an unsaved status photo: ' . $image['absolute_path']);
        }
        throw $exception;
    }
    $statusId = (int) db()->lastInsertId();
    audit('Campus status posted', 'Status #' . $statusId);
    flash('success', 'Your status is live for 24 hours.');
    redirect(url('status', ['status_page' => max(1, (int) ($_POST['status_page'] ?? 1))]) . '#status-' . $statusId);
}

function toggle_status_like(array $user): never
{
    $statusId = (int) ($_POST['status_id'] ?? 0);
    $pdo = db();
    $pdo->beginTransaction();
    $statusStmt = $pdo->prepare('SELECT user_id FROM statuses WHERE id=? AND expires_at>NOW() FOR UPDATE');
    $statusStmt->execute([$statusId]);
    $authorId = $statusStmt->fetchColumn();
    if ($authorId === false || (int) $authorId === (int) $user['id']) {
        $pdo->rollBack();
        if (($_POST['ajax'] ?? '') === '1') {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"error":"Status not found."}';
            exit;
        }
        flash('error', 'That status is no longer available to like.');
        redirect(url('status', ['status_page' => max(1, (int) ($_POST['status_page'] ?? 1))]));
    }
    $likeStmt = $pdo->prepare('SELECT 1 FROM status_likes WHERE status_id=? AND user_id=?');
    $likeStmt->execute([$statusId, (int) $user['id']]);
    $liked = (bool) $likeStmt->fetchColumn();
    if ($liked) {
        $pdo->prepare('DELETE FROM status_likes WHERE status_id=? AND user_id=?')->execute([$statusId, (int) $user['id']]);
    } else {
        $pdo->prepare('INSERT INTO status_likes (status_id,user_id) VALUES (?,?)')->execute([$statusId, (int) $user['id']]);
    }
    $pdo->commit();
    if (($_POST['ajax'] ?? '') === '1') {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM status_likes l JOIN users u ON u.id=l.user_id WHERE l.status_id=? AND u.is_active=1');
        $countStmt->execute([$statusId]);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode(['liked' => !$liked, 'count' => (int) $countStmt->fetchColumn()]);
        exit;
    }
    redirect(url('status', ['status_page' => max(1, (int) ($_POST['status_page'] ?? 1))]) . '#status-' . $statusId);
}

function delete_status(array $user): never
{
    $statusId = (int) ($_POST['status_id'] ?? 0);
    $stmt = db()->prepare('SELECT media_path FROM statuses WHERE id=? AND user_id=?');
    $stmt->execute([$statusId, (int) $user['id']]);
    $mediaPath = $stmt->fetchColumn();
    if ($mediaPath === false) {
        flash('error', 'That status is no longer available to delete.');
        redirect(url('status', ['status_page' => max(1, (int) ($_POST['status_page'] ?? 1))]));
    }
    db()->prepare('DELETE FROM statuses WHERE id=? AND user_id=?')->execute([$statusId, (int) $user['id']]);
    delete_user_image(is_string($mediaPath) ? $mediaPath : null, 'statuses');
    audit('Campus status deleted', 'Status #' . $statusId);
    flash('success', 'Your status was deleted.');
    redirect(url('status', ['status_page' => max(1, (int) ($_POST['status_page'] ?? 1))]));
}

function record_status_view(array $user): never
{
    $statusId = (int) ($_POST['status_id'] ?? 0);
    $statusStmt = db()->prepare('SELECT s.id FROM statuses s JOIN users author ON author.id=s.user_id WHERE s.id=? AND s.user_id<>? AND s.expires_at>NOW() AND author.is_active=1');
    $statusStmt->execute([$statusId, (int) $user['id']]);
    if (!$statusStmt->fetchColumn()) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"Status not found."}';
        exit;
    }
    $viewStmt = db()->prepare('INSERT IGNORE INTO status_views (status_id,user_id) VALUES (?,?)');
    $viewStmt->execute([$statusId, (int) $user['id']]);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode(['recorded' => $viewStmt->rowCount() > 0]);
    exit;
}

function save_profile(array $user): never
{
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    if (mb_strlen($name) < 2) {
        flash('error', 'Enter your full name.');
        redirect(url('profile'));
    }
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = (string) $stmt->fetchColumn();
    if ($newPassword !== '') {
        if ((!$user['must_change_password'] && !password_verify($currentPassword, $hash)) || strlen($newPassword) < 10) {
            flash('error', 'Enter your current password and choose a new password with at least 10 characters.');
            redirect(url('profile'));
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    } elseif ($user['must_change_password']) {
        flash('error', 'Set a new password before continuing.');
        redirect(url('profile'));
    }
    try {
        db()->prepare('UPDATE users SET full_name = ?, student_id = ?, department_id = ?, password_hash = ?, must_change_password = 0 WHERE id = ?')
            ->execute([$name, $studentId !== '' ? $studentId : null, $departmentId ?: null, $hash, $user['id']]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            flash('error', 'That student ID is already in use.');
            redirect(url('profile'));
        }
        throw $exception;
    }
    $_SESSION['user']['full_name'] = $name;
    $_SESSION['user']['must_change_password'] = 0;
    audit('Profile updated');
    flash('success', 'Your profile has been updated.');
    redirect(url('profile'));
}

function manage_user(array $admin): never
{
    $id = (int) ($_POST['user_id'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;
    if ($id === (int) $admin['id'] && !$active) {
        flash('error', 'You cannot deactivate your own account.');
        redirect(url('users'));
    }
    $current = db()->prepare('SELECT role FROM users WHERE id = ?');
    $current->execute([$id]);
    $currentUser = $current->fetch();
    if (!$currentUser) {
        flash('error', 'User account not found.');
        redirect(url('users'));
    }
    if ($currentUser['role'] !== 'student') {
        flash('error', 'Administrator access is protected and cannot be changed here.');
        redirect(url('users'));
    }
    db()->prepare('UPDATE users SET is_active = ? WHERE id = ? AND role = \'student\'')->execute([$active, $id]);
    audit('Student account status changed', 'Student #' . $id . ($active ? ' activated' : ' deactivated'));
    flash('success', $active ? 'Student account activated.' : 'Student account deactivated.');
    redirect(url('users'));
}

function assign_complaint(array $admin): never
{
    $complaintId = (int) ($_POST['complaint_id'] ?? 0);
    $staffId = (int) ($_POST['admin_id'] ?? 0);
    $check = db()->prepare('SELECT id, reference_no FROM complaints WHERE id = ?');
    $check->execute([$complaintId]);
    $complaint = $check->fetch();
    if (!$complaint) {
        flash('error', 'Complaint not found.');
        redirect(url('complaints'));
    }
    if ($staffId > 0) {
        $staffCheck = db()->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1");
        $staffCheck->execute([$staffId]);
        if (!$staffCheck->fetchColumn()) {
            flash('error', 'Select an active administrator.');
            redirect(url('detail', ['id' => $complaintId]));
        }
    }
    db()->prepare('DELETE FROM complaint_assignments WHERE complaint_id = ?')->execute([$complaintId]);
    if ($staffId > 0) {
        db()->prepare('INSERT INTO complaint_assignments (complaint_id, admin_id, assigned_by) VALUES (?, ?, ?)')->execute([$complaintId, $staffId, $admin['id']]);
    }
    audit('Complaint assigned', $complaint['reference_no'] . ($staffId ? ' to user #' . $staffId : ''));
    flash('success', $staffId ? 'Complaint assigned.' : 'No staff member selected.');
    redirect(url('detail', ['id' => $complaintId]));
}

function manage_catalog(string $table): never
{
    require_role('admin');
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        flash('error', 'Enter a name between 2 and 120 characters.');
        redirect(url($table));
    }
    try {
        if ($table === 'categories') {
            db()->prepare('INSERT INTO categories (name, description, department_id) VALUES (?, ?, ?)')->execute([$name, $description ?: null, $departmentId ?: null]);
        } else {
            db()->prepare('INSERT INTO departments (name, description) VALUES (?, ?)')->execute([$name, $description ?: null]);
        }
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            flash('error', 'An item with that name already exists.');
            redirect(url($table));
        }
        throw $exception;
    }
    audit(ucfirst(rtrim($table, 's')) . ' created', $name);
    flash('success', ucfirst(rtrim($table, 's')) . ' added.');
    redirect(url($table));
}

function toggle_catalog(array $admin): never
{
    $table = (string) ($_POST['table'] ?? '');
    if (!in_array($table, ['categories', 'departments'], true)) {
        http_response_code(400);
        exit('Invalid catalog.');
    }
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare("UPDATE `$table` SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
    audit(ucfirst(rtrim($table, 's')) . ' availability changed', 'Record #' . $id);
    flash('success', 'Availability updated.');
    redirect(url($table));
}

function save_settings(array $admin): never
{
    $settings = [
        'app_name' => trim((string) ($_POST['app_name'] ?? '')),
        'institution_name' => trim((string) ($_POST['institution_name'] ?? '')),
        'contact_email' => trim((string) ($_POST['contact_email'] ?? '')),
        'contact_phone' => trim((string) ($_POST['contact_phone'] ?? '')),
    ];
    if ($settings['app_name'] === '' || $settings['institution_name'] === '' || ($settings['contact_email'] !== '' && !filter_var($settings['contact_email'], FILTER_VALIDATE_EMAIL))) {
        flash('error', 'Enter an application name, institution name and a valid contact email.');
        redirect(url('settings'));
    }
    $stmt = db()->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach ($settings as $key => $value) {
        $stmt->execute([$key, $value]);
    }
    audit('System settings updated');
    flash('success', 'Settings saved.');
    redirect(url('settings'));
}

function export_csv(array $admin): never
{
    $from = (string) ($_GET['from'] ?? date('Y-m-01'));
    $to = (string) ($_GET['to'] ?? date('Y-m-d'));
    if (!is_valid_date($from) || !is_valid_date($to) || $from > $to) {
        http_response_code(400);
        exit('Invalid date range.');
    }
    $conditions = ['c.created_at >= ?', 'c.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
    $params = [$from . ' 00:00:00', $to];
    $categoryId = (int) ($_GET['category'] ?? 0);
    $departmentId = (int) ($_GET['department'] ?? 0);
    if ($categoryId > 0) {
        $conditions[] = 'c.category_id = ?';
        $params[] = $categoryId;
    }
    if ($departmentId > 0) {
        $conditions[] = 'c.department_id = ?';
        $params[] = $departmentId;
    }
    $sql = "SELECT c.reference_no, c.title, c.status, c.priority, cat.name AS category, d.name AS department, CASE WHEN c.is_anonymous=1 THEN 'Anonymous' ELSE u.full_name END AS student, CASE WHEN c.is_anonymous=1 THEN '' ELSE u.email END AS email, c.created_at, c.resolved_at FROM complaints c JOIN users u ON u.id=c.student_id JOIN categories cat ON cat.id=c.category_id LEFT JOIN departments d ON d.id=c.department_id WHERE " . implode(' AND ', $conditions) . ' ORDER BY c.created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campus-helpdesk-report-' . $from . '-to-' . $to . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Reference', 'Title', 'Status', 'Priority', 'Category', 'Department', 'Student', 'Student email', 'Submitted at', 'Resolved at']);
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $row = array_map(static function ($value) {
            $value = (string) ($value ?? '');
            return preg_match('/^[\s]*[=+\-@]/', $value) ? "'" . $value : $value;
        }, $row);
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
