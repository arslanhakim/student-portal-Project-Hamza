<?php
/**
 * Admin dashboard — at-a-glance stats and recent registrations.
 */

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

// ============================================
// STATS
// ============================================
try {
    $totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE role = 'student'")->fetchColumn();
    $totalAdmins   = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE role = 'admin'")->fetchColumn();
    $newThisWeek   = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $failedLogins  = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE successful = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

    $recent = $pdo->query("
        SELECT full_name, email, department, created_at
        FROM students
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) {
    error_log("Admin dashboard query failed: " . $e->getMessage());
    $totalStudents = $totalAdmins = $newThisWeek = $failedLogins = 0;
    $recent = [];
}

renderAdminHeader('Dashboard', 'dashboard');
?>

<div class="admin-page-header">
    <h1 class="admin-page-title">Dashboard</h1>
</div>

<div class="admin-stat-grid">
    <div class="admin-stat-card">
        <span class="admin-stat-icon"><?= adminIcon('users', 18) ?></span>
        <div class="admin-stat-label">Total Students</div>
        <div class="admin-stat-value"><?= number_format($totalStudents) ?></div>
    </div>
    <div class="admin-stat-card">
        <span class="admin-stat-icon"><?= adminIcon('shield', 18) ?></span>
        <div class="admin-stat-label">Total Admins</div>
        <div class="admin-stat-value"><?= number_format($totalAdmins) ?></div>
    </div>
    <div class="admin-stat-card">
        <span class="admin-stat-icon"><?= adminIcon('trending-up', 18) ?></span>
        <div class="admin-stat-label">New This Week</div>
        <div class="admin-stat-value"><?= number_format($newThisWeek) ?></div>
    </div>
    <div class="admin-stat-card">
        <span class="admin-stat-icon"><?= adminIcon('alert-triangle', 18) ?></span>
        <div class="admin-stat-label">Failed Logins (24h)</div>
        <div class="admin-stat-value"><?= number_format($failedLogins) ?></div>
    </div>
</div>

<div class="admin-panel">
    <div class="admin-panel-header">
        <h2 class="admin-panel-title">Recent Registrations</h2>
        <a href="/student-auth/admin/students.php" class="admin-panel-link">View all students →</a>
    </div>

    <?php if (empty($recent)): ?>
        <div class="empty-state">
            <p>No registrations yet.</p>
        </div>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Department</th>
                    <th>Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                        <td><?= htmlspecialchars($r['email']) ?></td>
                        <td><?= htmlspecialchars($r['department']) ?></td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime($r['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php renderAdminFooter(); ?>
