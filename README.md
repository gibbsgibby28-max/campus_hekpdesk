# Campus Helpdesk

A self-contained campus complaint management application built with PHP 8+, MySQL, PDO, HTML, CSS and JavaScript. No Composer packages or front-end CDN are required for core functionality.

## Requirements

- XAMPP with PHP 8+ and MySQL/MariaDB
- PHP extensions `pdo_mysql`, `fileinfo`, and `mbstring`
- Apache and MySQL started in the XAMPP Control Panel

## Install locally

1. Copy the project into `C:\xampp\htdocs\campus_helpdesk`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. If your local MySQL credentials differ from XAMPP defaults, set these environment variables for Apache/PHP before starting it:
   - `CAMPUS_DB_HOST` (default `127.0.0.1`)
   - `CAMPUS_DB_PORT` (default `3306`)
   - `CAMPUS_DB_NAME` (default `campus_helpdesk`)
   - `CAMPUS_DB_USER` (default `root`)
   - `CAMPUS_DB_PASSWORD` (default empty for a standard local XAMPP installation)
4. Open `http://localhost/campus_helpdesk/setup.php`.
5. Enter the initial administrator's name and email and select **Initialize and create account**. The app creates the database and tables safely and seeds default campus categories and departments.
6. Save the generated temporary password shown once. Sign in at `http://localhost/campus_helpdesk/` with the administrator email; the system requires a password change on first sign-in. The initial administrator name defaults to Gibson and can be changed during setup.
7. Students can create accounts from the sign-in page. Setup creates the initial administrator; additional accounts created through the public form are students, and the Users page only manages student access.

Complaint attachments are limited to 5 MB. Private chat attachments and voice notes are limited to 10 MB; set PHP's `upload_max_filesize` to at least `10M` and `post_max_size` to at least `12M` in `C:\xampp\php\php.ini`, then restart Apache.

The setup script can be opened again to verify or complete initialization. It never replaces existing records or displays an existing administrator's password. The first administrator is created only when no administrator account exists.

## Included workflows

- Student registration, secure sign-in, session timeout, profile editing and password changes
- Student complaint submission with a named or anonymous-to-staff choice, category and priority selection, optional restricted attachments, complaint reference numbers and private complaint follow-up
- Administrator dashboard and complaint queue with search and filtering
- WhatsApp-inspired private administrator/student conversations with near-real-time message updates, safe clickable links, voice notes, mobile camera/photo capture and protected image/audio/document attachments
- Account-level dark mode, four dashboard color palettes, four private chat color themes, optional chat wallpaper photos and profile photos
- Campus status posts with text and optional photo, likes, private-to-author viewer names/timestamps, and automatic 24-hour expiry; all active users can see active posts
- Campus-wide, read-only announcements delivered to student notifications; administrators can also message students privately
- Complaint status history, staff assignment, threaded student/admin replies, notifications and reopening resolved/rejected requests
- Student account activation/deactivation, categories, departments, basic system settings and audit log; the Users page cannot create or promote administrator accounts
- Dashboard metrics based on database records, department report and date-range CSV export
- Responsive layouts and server-side authorization for protected pages, actions and attachment downloads

## Security notes

- Passwords use PHP's `password_hash()` and `password_verify()`.
- State-changing forms use session CSRF tokens. Database access uses PDO prepared statements, output is escaped, and user roles are checked server-side.
- Complaint attachments are limited to JPG, PNG, PDF and plain text, capped at 5 MB, MIME-checked, stored under random names, blocked from direct Apache access and served only after an ownership/admin authorization check.
- Private chat attachments are MIME-checked, limited to 10 MB, stored under random names outside direct web access and served only to the message participants. Voice notes require microphone permission; camera capture depends on browser/device support and a secure context (localhost or HTTPS).
- Profile photos, chat wallpapers and status photos are validated JPG, PNG, GIF or WebP images, limited to 5 MB and served through authenticated routes. Statuses are visible to all active users; only the author can see the viewer list and view times. Posts expire after 24 hours.
- Display mode, dashboard palette, chat theme and wallpaper are saved with the user account and apply across devices.
- Chat supports recorded voice notes, not live audio/video calling, and is not end-to-end encrypted. Message updates poll while the conversation is open.
- Anonymous complaints hide the student's profile from the administrator portal and reports while retaining an account link so the student can follow replies. The link remains accessible to database/server operators; report text and attachments may identify the sender, so this is not cryptographic or legally guaranteed anonymity.
- Do not use the empty-password XAMPP defaults on a production server. Set private database environment variables, use HTTPS, configure backups, and remove or restrict `setup.php` after initial provisioning in a deployed environment.

## Configuration and troubleshooting

Database defaults are in `config/database.php`; they can be overridden with the environment variables above. Initialization is in `database/initialize.php` and uses `CREATE TABLE IF NOT EXISTS` plus seed-on-empty behavior. To apply additive schema updates to an existing installation, open `setup.php` again; existing accounts and records are retained.

If setup reports a database error, verify that MySQL is running, the PHP `pdo_mysql` extension is enabled, and the database username/password are correct. PHP errors are written to the configured PHP error log; database credentials and exception details are not shown in public pages.
