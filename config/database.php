<?php
/**
 * Database Connection
 * Uses PDO with prepared statements for security against SQL injection.
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'student_auth_db');
define('DB_USER', 'root');        // XAMPP default
define('DB_PASS', '');             // XAMPP default (empty password)
define('DB_CHARSET', 'utf8mb4');

// Data Source Name
$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

// PDO options for security and proper error handling
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // Return associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                     // Use real prepared statements (security)
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // In production, log this instead of showing it
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please check your configuration or contact support.");
}