<?php
/**
 * Session bootstrap — include this BEFORE any output on pages that use sessions.
 * Configures secure session settings and starts the session.
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    session_start();
}

/**
 * Helper: check if user is logged in.
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Helper: require login or redirect.
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /student-auth/auth/login.php');
        exit;
    }
}

/**
 * Helper: redirect away if already logged in (used on login/register pages).
 */
function redirectIfLoggedIn(): void {
    if (isLoggedIn()) {
        if (isAdmin()) {
            header('Location: /student-auth/admin/dashboard.php');
        } else {
            header('Location: /student-auth/dashboard.php');
        }
        exit;
    }
}

/**
 * Helper: check if logged-in user is an admin.
 */
function isAdmin(): bool {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Helper: require admin role or redirect.
 * Used at the top of every admin page.
 */
function requireAdmin(): void {
    if (!isLoggedIn()) {
        header('Location: /student-auth/auth/login.php');
        exit;
    }
    if (!isAdmin()) {
        // Logged in but not admin — send to student dashboard
        header('Location: /student-auth/dashboard.php');
        exit;
    }
}

/**
 * Flash data: store form errors and old input between redirects.
 * Used to implement POST-Redirect-GET pattern cleanly.
 */
function flashSet(string $key, $value): void {
    $_SESSION['_flash'][$key] = $value;
}

function flashGet(string $key, $default = null) {
    $value = $_SESSION['_flash'][$key] ?? $default;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function flashHas(string $key): bool {
    return isset($_SESSION['_flash'][$key]);
}

/**
 * CSRF protection: generate a token and store it in session.
 * Call this once per session — subsequent calls return the existing token.
 */
function csrfToken(): string {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Verify a submitted CSRF token against the one in session.
 * Uses hash_equals() for timing-safe comparison.
 */
function csrfVerify(?string $submittedToken): bool {
    if (empty($_SESSION['_csrf_token']) || empty($submittedToken)) {
        return false;
    }
    return hash_equals($_SESSION['_csrf_token'], $submittedToken);
}

/**
 * Convenience: render the hidden input for forms.
 */
function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfToken()) . '">';
}