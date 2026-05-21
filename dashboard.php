<?php
/**
 * Student dashboard — the protected landing page after login.
 * Shows a greeting, profile snapshot, quick actions, and recent sign-in activity.
 */

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';

requireLogin();

// ============================================
// Session values + derived display data
// ============================================
$userId     = (int) ($_SESSION['user_id']    ?? 0);
$fullName   = (string) ($_SESSION['full_name']  ?? '');
$email      = (string) ($_SESSION['email']      ?? '');
$studentId  = (string) ($_SESSION['student_id'] ?? '');
$department = (string) ($_SESSION['department'] ?? '');

// Time-of-day greeting
$hour = (int) date('H');
if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

// First name only — strip trailing/leading whitespace, take first word
$firstName = trim(explode(' ', trim($fullName))[0] ?? '');
if ($firstName === '') {
    $firstName = 'there';
}

$today = date('l, F j, Y');

// Initials for avatar: first letter of first two name parts, uppercase
$parts = preg_split('/\s+/', trim($fullName)) ?: [];
$initials = '';
foreach ($parts as $p) {
    if ($p !== '') {
        $initials .= mb_substr($p, 0, 1);
    }
    if (mb_strlen($initials) >= 2) break;
}
$initials = mb_strtoupper($initials) ?: '?';

// ============================================
// DB lookups: created_at (for "days at portal") + last 5 login_attempts
// ============================================
$createdAt = null;
try {
    $stmt = $pdo->prepare("SELECT created_at FROM students WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if ($row) {
        $createdAt = $row['created_at'];
    }
} catch (PDOException $e) {
    error_log("Dashboard created_at fetch failed: " . $e->getMessage());
}

$daysAsStudent = 0;
if ($createdAt) {
    try {
        $start = new DateTime($createdAt);
        $now   = new DateTime();
        $daysAsStudent = (int) $start->diff($now)->days;
    } catch (Exception $e) {
        $daysAsStudent = 0;
    }
}

$activity = [];
try {
    $stmt = $pdo->prepare("
        SELECT successful, attempted_at, ip_address
        FROM login_attempts
        WHERE email = :email
        ORDER BY attempted_at DESC
        LIMIT 5
    ");
    $stmt->execute([':email' => $email]);
    $activity = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Dashboard activity fetch failed: " . $e->getMessage());
}

// ============================================
// Helpers (page-local — not worth promoting to session.php)
// ============================================

/**
 * Render a Lucide-style inline SVG icon. currentColor so it inherits text color.
 */
function dashIcon(string $name, int $size = 20): string {
    static $paths = [
        'pencil'         => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'key'            => '<path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
        'mail'           => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'book'           => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>',
        'graduation-cap' => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
        'check'          => '<path d="M20 6 9 17l-5-5"/>',
        'x'              => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    ];
    $body = $paths[$name] ?? '';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $size . '" height="' . (int) $size
         . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
         . ' stroke-linecap="round" stroke-linejoin="round" class="icon" aria-hidden="true">'
         . $body . '</svg>';
}

/**
 * Human-friendly relative time. Calendar-aware: "Yesterday" means
 * yesterday's date, not just 24h ago.
 */
function relativeTime(string $datetime): string {
    $time = strtotime($datetime);
    if ($time === false) return '';
    $now  = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600 && date('Y-m-d', $time) === date('Y-m-d', $now)) {
        $m = (int) ($diff / 60);
        return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    }
    if (date('Y-m-d', $time) === date('Y-m-d', $now)) {
        $h = (int) ($diff / 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    if (date('Y-m-d', $time) === date('Y-m-d', $now - 86400)) {
        return 'Yesterday at ' . date('g:i A', $time);
    }
    if ($diff < 86400 * 7) {
        return date('l', $time) . ' at ' . date('g:i A', $time);
    }
    return date('M j', $time) . ' at ' . date('g:i A', $time);
}

// Flash success banner — set by profile.php after a successful update.
$profileSuccess = flashGet('profile_success');

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="student-dash">

    <?php if ($profileSuccess): ?>
        <div class="alert alert-success">
            <span><?= htmlspecialchars($profileSuccess) ?></span>
        </div>
    <?php endif; ?>

    <?php if (isAdmin()): ?>
        <div class="alert alert-info">
            <span>You're logged in as an admin. <a href="/student-auth/admin/dashboard.php">Open the admin panel →</a></span>
        </div>
    <?php endif; ?>

    <!-- ============================================
         Section 1 — Hero greeting
         ============================================ -->
    <section class="dash-hero" aria-labelledby="dash-hero-greeting">
        <h1 id="dash-hero-greeting" class="dash-hero-greeting">
            <?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($firstName) ?>
            <span class="dash-hero-wave" aria-hidden="true">👋</span>
        </h1>
        <p class="dash-hero-date"><?= htmlspecialchars($today) ?></p>
    </section>

    <!-- ============================================
         Section 2 — Profile card + stat trio
         ============================================ -->
    <section class="dash-profile-row" aria-label="Your profile and stats">
        <!-- Profile card -->
        <div class="dash-profile-card">
            <div class="dash-avatar"><?= htmlspecialchars($initials) ?></div>
            <h2 class="dash-profile-name"><?= htmlspecialchars($fullName) ?></h2>
            <p class="dash-profile-email"><?= htmlspecialchars($email) ?></p>
            <span class="pill pill-positive">Student</span>
            <a href="/student-auth/profile.php" class="dash-profile-edit">Edit profile</a>
            <a href="/student-auth/auth/logout.php" class="btn btn-secondary btn-compact">Sign out</a>
        </div>

        <!-- 3-up stat grid -->
        <div class="dash-stat-grid">
            <div class="dash-stat-card">
                <div class="dash-stat-label">Days at the portal</div>
                <div class="dash-stat-value mono"><?= number_format($daysAsStudent) ?></div>
            </div>
            <div class="dash-stat-card">
                <div class="dash-stat-label">Student ID</div>
                <div class="dash-stat-value mono"><?= htmlspecialchars($studentId) ?></div>
            </div>
            <div class="dash-stat-card">
                <div class="dash-stat-label">Department</div>
                <div class="dash-stat-value dash-stat-value-textual">
                    <?= dashIcon('graduation-cap', 18) ?>
                    <span><?= htmlspecialchars($department) ?></span>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================
         Section 3 — Quick actions (2x2 grid on desktop)
         ============================================ -->
    <section class="dash-section" aria-labelledby="dash-quick-actions-title">
        <h2 id="dash-quick-actions-title" class="dash-section-title">Quick actions</h2>
        <div class="dash-quick-actions">
            <a href="/student-auth/profile.php" class="dash-quick-action">
                <?= dashIcon('pencil', 22) ?>
                <span>Edit Profile</span>
            </a>
            <a href="/student-auth/profile.php" class="dash-quick-action">
                <?= dashIcon('key', 22) ?>
                <span>Change Password</span>
            </a>
            <a href="mailto:admin@portal.com" class="dash-quick-action">
                <?= dashIcon('mail', 22) ?>
                <span>Contact Admin</span>
            </a>
            <a href="#" class="dash-quick-action">
                <?= dashIcon('book', 22) ?>
                <span>View Resources</span>
            </a>
        </div>
    </section>

    <!-- ============================================
         Section 4 — Recent activity (full width)
         ============================================ -->
    <section class="dash-section" aria-labelledby="dash-activity-title">
        <h2 id="dash-activity-title" class="dash-section-title">Recent activity</h2>
        <div class="dash-activity-card">
            <?php if (empty($activity)): ?>
                <div class="dash-activity-empty">No recent activity yet.</div>
            <?php else: ?>
                <ul class="dash-activity-list">
                    <?php foreach ($activity as $a):
                        $ok = (int) $a['successful'] === 1;
                    ?>
                        <li class="dash-activity-item">
                            <span class="dash-activity-icon dash-activity-icon-<?= $ok ? 'success' : 'error' ?>">
                                <?= $ok ? dashIcon('check', 16) : dashIcon('x', 16) ?>
                            </span>
                            <div class="dash-activity-text">
                                <span class="dash-activity-label">
                                    <?= $ok ? 'Signed in successfully' : 'Failed sign-in attempt' ?>
                                </span>
                                <span class="dash-activity-time">
                                    <?= htmlspecialchars(relativeTime($a['attempted_at'])) ?>
                                </span>
                            </div>
                            <span class="dash-activity-ip"><?= htmlspecialchars($a['ip_address']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
