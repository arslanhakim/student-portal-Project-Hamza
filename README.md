# Student Portal

A production-grade student portal with a built-in admin panel for account management. Built with vanilla PHP, PDO, and MySQL — no frameworks, no dependencies.

## Features

- Student registration and login with full server-side validation
- Bcrypt password hashing
- Session-based authentication with secure cookie settings and ID regeneration on login
- CSRF protection on all forms
- Brute-force rate limiting (5 attempts per 15 minutes per email)
- Clean logout with full session destruction
- Student dashboard with profile card, stat tiles, quick-action shortcuts, and a recent login activity feed
- Role-based routing: admins are sent to `/admin/dashboard.php` after login, students to `/dashboard.php`
- Admin dashboard with at-a-glance stats (total students, total admins, new this week, failed logins in the last 24 hours) and recent registrations
- Paginated student management with search across name/email/Student ID, sortable columns, and inline delete confirmation
- Create new student or admin accounts directly from the admin panel
- Edit any student's details, including role assignment
- Self-protection: admins cannot delete or demote their own account
- Responsive UI with focus states, error feedback, and password visibility toggle
- Mobile-responsive admin panel with slide-out drawer navigation under 768px

## Requirements

- PHP 8.0 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Apache (or any web server that supports PHP)

## Setup

1. Place the project folder inside `htdocs/` (XAMPP) or your web root.
2. Start Apache and MySQL.
3. Open phpMyAdmin and create a database named `student_auth_db` with `utf8mb4_unicode_ci` collation.
4. Run the SQL from the Database Schema section below to create the tables.
5. Edit `config/database.php` if your DB credentials differ from XAMPP defaults.
6. Visit `http://localhost/student-auth/` in your browser.

### Create the first admin account

The system ships with no admin accounts. After completing the steps above, register a regular account through the public registration form, then promote it to admin by running this SQL in phpMyAdmin or HeidiSQL:

```sql
UPDATE students SET role = 'admin' WHERE email = 'your-email@example.com';
```

Log out and log back in. You should now be redirected to `/admin/dashboard.php` instead of the student dashboard.

## Database Schema

```sql
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    student_id VARCHAR(20) NOT NULL UNIQUE,
    department VARCHAR(100) NOT NULL,
    role ENUM('student', 'admin') NOT NULL DEFAULT 'student',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    successful TINYINT(1) DEFAULT 0,
    INDEX idx_email_time (email, attempted_at),
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Project Structure

```
student-auth/
├── admin/
│   ├── _layout.php          # Shared topbar, sidebar, drawer
│   ├── dashboard.php        # Stats + recent registrations
│   ├── students.php         # List, search, sort, delete
│   ├── student-add.php      # Create student or admin
│   └── student-edit.php     # Edit existing student
├── assets/
│   ├── css/style.css        # Design system + components
│   └── js/auth.js           # Password toggle + drawer toggle
├── auth/
│   ├── login.php            # Login + rate limiting
│   ├── logout.php           # Full session destruction
│   └── register.php         # Registration + validation
├── config/
│   ├── database.php         # PDO connection
│   └── session.php          # Sessions, auth + role guards, CSRF, flash
├── includes/
│   ├── header.php           # Shared <head> + brand
│   └── footer.php           # Shared closing tags
├── dashboard.php            # Student dashboard (post-login for students)
├── index.php                # Role-based router
└── README.md
```

## Validation Rules

- **Full name**: 3–100 characters, letters/spaces/apostrophes/hyphens/periods only.
- **Email**: valid format, max 150 chars, unique.
- **Student ID**: 3–20 chars (alphanumeric + hyphens), unique.
- **Department**: must match one of the whitelisted options.
- **Role** (admin-set only): must be `'student'` or `'admin'`. Not exposed to public registration.
- **Password**: 8–72 chars, requires uppercase, lowercase, and a number.

## Admin Panel

The admin panel is accessible only to users with `role='admin'`. Admins are automatically redirected to `/admin/dashboard.php` after login. Non-admin users attempting to visit any `/admin/` URL are redirected away.

### Admin capabilities

- View dashboard with student/admin counts, new signups this week, and failed login attempts in the last 24 hours
- View all students in a paginated list with search and sortable columns
- Add new student or admin accounts directly (without requiring self-registration)
- Edit any student's details, including their role
- Delete students (with confirmation and self-delete protection)

### Bootstrapping the first admin

The system ships with no admin accounts by default. To create the first admin, register a regular account through the public registration form, then run this SQL on the database:

```sql
UPDATE students SET role = 'admin' WHERE email = 'your-email@example.com';
```

## Security

Built-in protections:

- Prepared statements throughout (no SQL injection)
- Bcrypt password hashing
- CSRF tokens with timing-safe verification on every POST form
- Brute-force rate limiting (5 attempts per 15 minutes per email)
- Secure session cookie settings (HttpOnly, SameSite=Lax)
- Session ID regeneration on login (defeats session fixation)
- XSS protection via consistent output escaping
- Generic auth errors (no user enumeration)
- Server-side validation on every field, including whitelist enums for `department` and `role`
- Role-based route guards via the `requireAdmin()` helper
- Self-delete and self-demote protections enforced server-side, not just hidden in UI
- Sortable columns in the admin student list use a whitelisted `ORDER BY` — user input is never concatenated into SQL

## Production Checklist

Before deploying to a real server:

1. Move DB credentials to environment variables.
2. Set `session.cookie_secure = '1'` (requires HTTPS).
3. Create a non-root MySQL user with minimal privileges (SELECT, INSERT, UPDATE, DELETE only — no DDL).
4. Disable PHP error display in `php.ini`.
5. Move `config/` outside the web root if possible.
6. Add security headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options).
7. Set up periodic cleanup of `login_attempts`.
8. Configure daily database backups.

## Troubleshooting

**Logged-in admin redirects in a loop** — Clear browser cookies for `localhost`. Stale session data from before the `role` column was added can conflict with the new role-based routing.

**Visiting an admin URL bounces back to the student dashboard** — The user's `role` is still `'student'` in the database. Promote the account using the SQL in [Create the first admin account](#create-the-first-admin-account).

**Sidebar doesn't appear on mobile** — That's expected. Tap the hamburger icon in the top-left of the admin topbar to open the drawer navigation.

## License

Free to use for learning, modify as needed.
