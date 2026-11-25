<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

function initialize_database(): void
{
    $config = database_config();
    $server = db(true);
    $databaseName = str_replace('`', '``', $config['name']);
    $server->exec("CREATE DATABASE IF NOT EXISTS `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = db();

    $statements = [
        "CREATE TABLE IF NOT EXISTS departments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            description VARCHAR(500) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            student_id VARCHAR(60) NULL UNIQUE,
            department_id INT UNSIGNED NULL,
            password_hash VARCHAR(255) NOT NULL,
            profile_image_path VARCHAR(255) NULL,
            theme_mode VARCHAR(20) NOT NULL DEFAULT 'light',
            dashboard_theme VARCHAR(20) NOT NULL DEFAULT 'ocean',
            chat_theme VARCHAR(20) NOT NULL DEFAULT 'classic',
            chat_wallpaper_path VARCHAR(255) NULL,
            role ENUM('student','admin') NOT NULL DEFAULT 'student',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
            INDEX idx_users_role_active (role, is_active)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            description VARCHAR(500) NULL,
            department_id INT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_categories_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS complaints (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference_no VARCHAR(32) NULL UNIQUE,
            student_id BIGINT UNSIGNED NOT NULL,
            is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
            category_id INT UNSIGNED NOT NULL,
            department_id INT UNSIGNED NULL,
            title VARCHAR(180) NOT NULL,
            description TEXT NOT NULL,
            priority ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
            status ENUM('Pending','Under Review','In Progress','Awaiting Student Response','Resolved','Rejected','Reopened') NOT NULL DEFAULT 'Pending',
            attachment_path VARCHAR(255) NULL,
            attachment_name VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            CONSTRAINT fk_complaints_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT fk_complaints_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
            CONSTRAINT fk_complaints_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
            INDEX idx_complaints_status_date (status, created_at),
            INDEX idx_complaints_student_date (student_id, created_at),
            INDEX idx_complaints_department (department_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS complaint_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            complaint_id BIGINT UNSIGNED NOT NULL,
            admin_id BIGINT UNSIGNED NOT NULL,
            assigned_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_complaint_assignment (complaint_id, admin_id),
            CONSTRAINT fk_assign_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
            CONSTRAINT fk_assign_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT fk_assign_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS responses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            complaint_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_responses_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
            CONSTRAINT fk_responses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
            INDEX idx_responses_complaint_date (complaint_id, created_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            complaint_id BIGINT UNSIGNED NULL,
            message VARCHAR(500) NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_notifications_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE SET NULL,
            INDEX idx_notifications_user_read (user_id, is_read, created_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_id BIGINT UNSIGNED NOT NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            media_path VARCHAR(255) NULL,
            media_name VARCHAR(255) NULL,
            media_mime VARCHAR(100) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_messages_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_messages_sender_recipient_date (sender_id, recipient_id, created_at),
            INDEX idx_messages_recipient_sender_date (recipient_id, sender_id, created_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS statuses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            message VARCHAR(1000) NOT NULL DEFAULT '',
            media_path VARCHAR(255) NULL,
            media_mime VARCHAR(100) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            CONSTRAINT fk_status_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_status_expiry (expires_at, created_at),
            INDEX idx_status_user_date (user_id, created_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS status_views (
            status_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (status_id, user_id),
            CONSTRAINT fk_status_views_status FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE CASCADE,
            CONSTRAINT fk_status_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_status_views_user (user_id, viewed_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS status_likes (
            status_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (status_id, user_id),
            CONSTRAINT fk_status_likes_status FOREIGN KEY (status_id) REFERENCES statuses(id) ON DELETE CASCADE,
            CONSTRAINT fk_status_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_status_likes_user (user_id, created_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS status_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            complaint_id BIGINT UNSIGNED NOT NULL,
            changed_by BIGINT UNSIGNED NOT NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NOT NULL,
            note VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_history_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
            CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE RESTRICT,
            INDEX idx_history_complaint_date (complaint_id, created_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(120) NOT NULL,
            details VARCHAR(1000) NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_audit_created (created_at)
        ) ENGINE=InnoDB",
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $schemaUpdates = [
        'complaints' => [
            'is_anonymous' => 'ADD COLUMN is_anonymous TINYINT(1) NOT NULL DEFAULT 0 AFTER student_id',
        ],
        'messages' => [
            'media_path' => 'ADD COLUMN media_path VARCHAR(255) NULL AFTER message',
            'media_name' => 'ADD COLUMN media_name VARCHAR(255) NULL AFTER media_path',
            'media_mime' => 'ADD COLUMN media_mime VARCHAR(100) NULL AFTER media_name',
        ],
        'users' => [
            'profile_image_path' => 'ADD COLUMN profile_image_path VARCHAR(255) NULL AFTER password_hash',
            'theme_mode' => "ADD COLUMN theme_mode VARCHAR(20) NOT NULL DEFAULT 'light' AFTER profile_image_path",
            'dashboard_theme' => "ADD COLUMN dashboard_theme VARCHAR(20) NOT NULL DEFAULT 'ocean' AFTER theme_mode",
            'chat_theme' => "ADD COLUMN chat_theme VARCHAR(20) NOT NULL DEFAULT 'classic' AFTER dashboard_theme",
            'chat_wallpaper_path' => 'ADD COLUMN chat_wallpaper_path VARCHAR(255) NULL AFTER chat_theme',
        ],
        'statuses' => [
            'media_mime' => 'ADD COLUMN media_mime VARCHAR(100) NULL AFTER media_path',
        ],
    ];
    foreach ($schemaUpdates as $table => $columns) {
        $existingColumns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $column => $definition) {
            if (!in_array($column, $existingColumns, true)) {
                $pdo->exec("ALTER TABLE `$table` $definition");
            }
        }
    }

    $departmentCount = (int) $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();
    if ($departmentCount === 0) {
        $pdo->exec("INSERT INTO departments (name, description) VALUES
            ('Student Affairs', 'Student support and welfare'),
            ('Academic Affairs', 'Teaching, learning and examinations'),
            ('Information Technology', 'Campus technology and connectivity'),
            ('Finance', 'Fees, payments and financial services'),
            ('Facilities', 'Campus buildings, utilities and maintenance')");
    }

    $categoryCount = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($categoryCount === 0) {
        $pdo->exec("INSERT INTO categories (name, description) VALUES
            ('Academics', 'Courses, teaching and academic records'),
            ('Accommodation', 'Housing and residence concerns'),
            ('Examinations', 'Examination scheduling and results'),
            ('Finance', 'Fees, payments and financial aid'),
            ('IT & Internet', 'Accounts, devices and campus connectivity'),
            ('Facilities', 'Buildings, utilities and maintenance'),
            ('Other', 'Other campus concerns')");
    }

    $pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
        ('institution_name', 'Campus Institution'),
        ('contact_email', ''),
        ('contact_phone', '')");
}
