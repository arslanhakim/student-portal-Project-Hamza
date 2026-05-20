<?php
/**
 * Logout — destroys the session completely and redirects to login.
 */

require_once __DIR__ . '/../config/session.php';

// 1. Empty the $_SESSION array
$_SESSION = [];

// 2. Delete the session cookie from the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// 3. Destroy the session file on the server
session_destroy();

// 4. Redirect to login with a logout flag (optional UX touch)
header('Location: /student-auth/auth/login.php?logged_out=1');
exit;