<?php
/**
 * CSV export of the students list.
 *
 * Honors the current ?q search filter and ?sort/?dir order from the admin
 * students.php view — same SQL shape, same column whitelist for ORDER BY,
 * but no LIMIT (exports every matching row across all pages).
 *
 * Sends a Content-Disposition: attachment header with a timestamped filename,
 * then streams CSV rows directly to php://output via fputcsv() — built-in
 * quoting handles commas, quotes, and newlines inside field values.
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

// ============================================
// Same sort whitelist as students.php — column names ONLY come from this
// list, never directly from $_GET, so ORDER BY interpolation is safe.
// ============================================
$allowedSortColumns = ['id', 'full_name', 'email', 'student_id', 'department', 'role', 'created_at'];
$sort = $_GET['sort'] ?? 'created_at';
if (!in_array($sort, $allowedSortColumns, true)) {
    $sort = 'created_at';
}

$dir = strtolower($_GET['dir'] ?? 'desc');
if ($dir !== 'asc' && $dir !== 'desc') {
    $dir = 'desc';
}

$q = trim($_GET['q'] ?? '');

// Build WHERE — three distinct named placeholders to avoid PDO's HY093
// error when EMULATE_PREPARES=false. Wildcards live in PHP, never SQL.
$where  = '';
$params = [];
if ($q !== '') {
    $where = "WHERE full_name LIKE :q_name OR email LIKE :q_email OR student_id LIKE :q_sid";
    $like  = '%' . $q . '%';
    $params = [
        ':q_name'  => $like,
        ':q_email' => $like,
        ':q_sid'   => $like,
    ];
}

// ============================================
// Fetch all matching rows (no LIMIT)
// ============================================
$sql = "SELECT id, full_name, email, student_id, department, role, is_active, created_at, updated_at
        FROM students
        $where
        ORDER BY $sort $dir";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
} catch (PDOException $e) {
    error_log("CSV export query failed: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Export failed. Please try again.';
    exit;
}

// ============================================
// CSV download headers + stream
// ============================================
$filename = 'students_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel recognizes non-ASCII characters (accented names etc.).
fwrite($out, "\xEF\xBB\xBF");

// Header row
fputcsv($out, [
    'ID',
    'Full Name',
    'Email',
    'Student ID',
    'Department',
    'Role',
    'Status',
    'Joined',
    'Updated',
]);

// Data rows — humanized values (Active/Inactive, capitalized Role, ISO-ish dates)
while ($row = $stmt->fetch()) {
    fputcsv($out, [
        (int) $row['id'],
        $row['full_name'],
        $row['email'],
        $row['student_id'],
        $row['department'],
        ucfirst($row['role']),
        (int) $row['is_active'] === 1 ? 'Active' : 'Inactive',
        $row['created_at'] ? date('Y-m-d H:i:s', strtotime($row['created_at'])) : '',
        $row['updated_at'] ? date('Y-m-d H:i:s', strtotime($row['updated_at'])) : '',
    ]);
}

fclose($out);
exit;
