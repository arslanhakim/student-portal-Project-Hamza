<?php
/**
 * Root router — sends authenticated users to dashboard,
 * unauthenticated users to login.
 */

require_once __DIR__ . '/config/session.php';

if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: /student-auth/admin/dashboard.php');
    } else {
        header('Location: /student-auth/dashboard.php');
    }
} else {
    header('Location: /student-auth/auth/login.php');
}


exit;