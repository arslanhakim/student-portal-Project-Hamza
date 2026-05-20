# Student Authentication System

A production-grade student login and registration system built with vanilla PHP, PDO, and MySQL. No frameworks, no dependencies.

## Features

- Registration with full server-side validation
- Bcrypt password hashing
- Session-based login with secure cookie settings
- CSRF protection on all forms
- Brute-force rate limiting (5 attempts per 15 minutes)
- Protected dashboard route with session guards
- Clean logout with full session destruction
- Responsive UI with focus states, error feedback, and password visibility toggle

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

## Database Schema

```sql
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    student_id VARCHAR(20) NOT NULL UNIQUE,
    department VARCHAR(100) NOT NULL,
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
├── assets/
│   ├── css/style.css       # Design system + components
│   └── js/auth.js          # Password toggle
├── auth/
│   ├── login.php           # Login + rate limiting
│   ├── logout.php          # Full session destruction
│   └── register.php        # Registration + validation
├── config/
│   ├── database.php        # PDO connection
│   └── session.php         # Session config, auth helpers, CSRF, flash
├── includes/
│   ├── header.php          # Shared <head> + brand
│   └── footer.php          # Shared closing tags
├── dashboard.php           # Protected page (post-login)
├── index.php               # Root router
└── README.md
```

## Validation Rules

- **Full name**: 3-100 characters, letters/spaces/apostrophes/hyphens/periods only.
- **Email**: valid format, max 150 chars, unique.
- **Student ID**: 3-20 chars (alphanumeric + hyphens), unique.
- **Department**: must match one of the whitelisted options.
- **Password**: 8-72 chars, requires uppercase, lowercase, and a number.

## Security

Built-in protections:

- Prepared statements throughout (no SQL injection)
- Bcrypt password hashing
- CSRF tokens with timing-safe verification
- Brute-force rate limiting (5 attempts per 15 minutes per email)
- Secure session cookie settings (HttpOnly, SameSite=Lax)
- Session ID regeneration on login (defeats session fixation)
- XSS protection via consistent output escaping
- Generic auth errors (no user enumeration)
- Whitelist validation for enum-like fields
- Server-side validation on every field

## Production Checklist

Before deploying to a real server:

1. Move DB credentials to environment variables.
2. Set `session.cookie_secure = '1'` (requires HTTPS).
3. Create a non-root MySQL user with minimal privileges (SELECT, INSERT, UPDATE only).
4. Disable PHP error display in `php.ini`.
5. Move `config/` outside the web root if possible.
6. Add security headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options).
7. Set up periodic cleanup of `login_attempts`.
8. Configure daily database backups.

## License

Free to use for learning, modify as needed.
