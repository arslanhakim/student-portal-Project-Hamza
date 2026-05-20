<?php
/**
 * Dashboard — protected page, only accessible to authenticated users.
 */

require_once __DIR__ . '/config/session.php';

// Guard: redirect to login if not authenticated
requireLogin();

// Optional: re-fetch fresh data from DB instead of relying purely on session.
// For this app, session data is fine, but here's how you'd do it if needed:
//
// require_once __DIR__ . '/config/database.php';
// $stmt = $pdo->prepare("SELECT full_name, email, student_id, department, created_at FROM students WHERE id = :id");
// $stmt->execute([':id' => $_SESSION['user_id']]);
// $user = $stmt->fetch();

// Format login time nicely
$loginTime = $_SESSION['login_time'] ?? time();
$loginTimeFormatted = date('M j, Y · g:i A', $loginTime);

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card dashboard-card">
    <div class="dashboard-header">
        <div class="dashboard-greeting">
            <div class="dashboard-greeting-label">Welcome back</div>
            <div class="dashboard-greeting-name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
        </div>
        <a href="/student-auth/auth/logout.php" class="btn btn-secondary">Sign out</a>
    </div>

    <div class="info-grid">
        <div class="info-tile">
            <div class="info-tile-label">Email</div>
            <div class="info-tile-value"><?= htmlspecialchars($_SESSION['email']) ?></div>
        </div>

        <div class="info-tile">
            <div class="info-tile-label">Student ID</div>
            <div class="info-tile-value mono"><?= htmlspecialchars($_SESSION['student_id']) ?></div>
        </div>

        <div class="info-tile">
            <div class="info-tile-label">Department</div>
            <div class="info-tile-value"><?= htmlspecialchars($_SESSION['department']) ?></div>
        </div>

        <div class="info-tile">
            <div class="info-tile-label">Account ID</div>
            <div class="info-tile-value mono">#<?= htmlspecialchars($_SESSION['user_id']) ?></div>
        </div>
    </div>

    <div class="session-meta">
        Logged in <strong><?= htmlspecialchars($loginTimeFormatted) ?></strong>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>