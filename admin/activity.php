<?php
/**
 * Admin: login activity log.
 * Read-only view of all rows in login_attempts, filterable by email substring,
 * status (success/fail), and date range. 15-row pagination preserving filters.
 */

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

// ============================================
// HELPERS (file-local)
// ============================================

/**
 * Human-friendly relative time for activity rows.
 * < 1h → "X minutes ago" · same day → "Today at h:mm AM" ·
 * yesterday → "Yesterday at h:mm AM" · older → "May 19, 2026 at 3:24 PM".
 */
function relativeTime(string $datetime): string {
    $time = strtotime($datetime);
    if ($time === false) return '';
    $now  = time();
    $diff = $now - $time;

    if ($diff < 60) return 'Just now';
    if ($diff < 3600) {
        $m = (int) ($diff / 60);
        return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    }
    if (date('Y-m-d', $time) === date('Y-m-d', $now)) {
        return 'Today at ' . date('g:i A', $time);
    }
    if (date('Y-m-d', $time) === date('Y-m-d', $now - 86400)) {
        return 'Yesterday at ' . date('g:i A', $time);
    }
    return date('M j, Y', $time) . ' at ' . date('g:i A', $time);
}

/** Build an activity.php URL that preserves the current GET params, with optional overrides. */
function adminActivityUrl(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return '/student-auth/admin/activity.php' . ($qs !== '' ? '?' . $qs : '');
}

/** Pagination (mirrors students.php — chevron prev/next, numbered window + ellipses). */
function renderActivityPagination(int $current, int $total): string {
    if ($total <= 1) return '';

    $out      = '';
    $prevIcon = adminIcon('chevron-left', 16);
    $nextIcon = adminIcon('chevron-right', 16);

    if ($current > 1) {
        $out .= '<a href="' . htmlspecialchars(adminActivityUrl(['page' => $current - 1])) . '" class="pagination-link icon-only" aria-label="Previous page">' . $prevIcon . '</a>';
    } else {
        $out .= '<span class="pagination-link icon-only disabled" aria-hidden="true">' . $prevIcon . '</span>';
    }

    $items = [];
    if ($total <= 7) {
        for ($i = 1; $i <= $total; $i++) $items[] = $i;
    } else {
        $items[] = 1;
        $start = max(2, $current - 2);
        $end   = min($total - 1, $current + 2);
        if ($start > 2)         $items[] = '…';
        for ($i = $start; $i <= $end; $i++) $items[] = $i;
        if ($end   < $total - 1) $items[] = '…';
        $items[] = $total;
    }

    foreach ($items as $item) {
        if ($item === '…') {
            $out .= '<span class="pagination-ellipsis">…</span>';
        } else {
            $isActive = $item === $current;
            $cls = 'pagination-link' . ($isActive ? ' active' : '');
            $out .= '<a href="' . htmlspecialchars(adminActivityUrl(['page' => $item])) . '" class="' . $cls . '">' . (int) $item . '</a>';
        }
    }

    if ($current < $total) {
        $out .= '<a href="' . htmlspecialchars(adminActivityUrl(['page' => $current + 1])) . '" class="pagination-link icon-only" aria-label="Next page">' . $nextIcon . '</a>';
    } else {
        $out .= '<span class="pagination-link icon-only disabled" aria-hidden="true">' . $nextIcon . '</span>';
    }

    return $out;
}

// ============================================
// PARSE + VALIDATE FILTERS
// ============================================

$q            = trim($_GET['q']      ?? '');
$statusFilter = $_GET['status']      ?? 'all';
if (!in_array($statusFilter, ['all', 'success', 'fail'], true)) {
    $statusFilter = 'all';
}

// Dates: must look like YYYY-MM-DD or are dropped entirely (per spec).
// Any value that fails the regex never reaches the SQL.
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
if ($to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = '';

$hasFilter = ($q !== '' || $statusFilter !== 'all' || $from !== '' || $to !== '');

// ============================================
// BUILD WHERE — named placeholders, never concatenating user input
// ============================================
$where  = "WHERE 1=1";
$params = [];

if ($q !== '') {
    $where .= " AND email LIKE :email";
    $params[':email'] = '%' . $q . '%';
}
if ($statusFilter === 'success') {
    $where .= " AND successful = 1";
} elseif ($statusFilter === 'fail') {
    $where .= " AND successful = 0";
}
if ($from !== '') {
    $where .= " AND attempted_at >= :from";
    $params[':from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where .= " AND attempted_at <= :to";
    $params[':to'] = $to . ' 23:59:59';
}

// ============================================
// STATS (single combined query for the filter scope)
// ============================================
$totalRows        = 0;
$successfulCount  = 0;
$failedCount      = 0;
try {
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*)                                            AS total,
            SUM(CASE WHEN successful = 1 THEN 1 ELSE 0 END)     AS successful_count,
            SUM(CASE WHEN successful = 0 THEN 1 ELSE 0 END)     AS failed_count
        FROM login_attempts
        $where
    ");
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch();
    $totalRows       = (int) ($stats['total']           ?? 0);
    $successfulCount = (int) ($stats['successful_count'] ?? 0);
    $failedCount     = (int) ($stats['failed_count']     ?? 0);
} catch (PDOException $e) {
    error_log("Activity stats query failed: " . $e->getMessage());
}

// ============================================
// PAGINATION
// ============================================
$perPage = 15;
$page    = max(1, (int) ($_GET['page'] ?? 1));

$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ============================================
// FETCH PAGE
// ============================================
$rows = [];
try {
    $sql = "SELECT id, email, ip_address, successful, attempted_at
            FROM login_attempts
            $where
            ORDER BY attempted_at DESC
            LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Activity list query failed: " . $e->getMessage());
}

renderAdminHeader('Login Activity', 'activity');
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Login Activity</h1>
        <p class="admin-page-subtitle">All sign-in attempts across the platform</p>
    </div>
</div>

<div class="admin-stat-grid activity-stat-grid">
    <div class="admin-stat-card">
        <div class="admin-stat-label">Total Attempts</div>
        <div class="admin-stat-value"><?= number_format($totalRows) ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-label">Successful</div>
        <div class="admin-stat-value"><?= number_format($successfulCount) ?></div>
    </div>
    <div class="admin-stat-card">
        <div class="admin-stat-label">Failed</div>
        <div class="admin-stat-value"><?= number_format($failedCount) ?></div>
    </div>
</div>

<div class="admin-panel">
    <form method="GET" class="activity-filters">
        <div class="activity-filter-group">
            <label for="filter-email" class="form-label">Email</label>
            <input
                type="text"
                id="filter-email"
                name="q"
                value="<?= htmlspecialchars($q) ?>"
                placeholder="Filter by email..."
                class="form-input"
                autocomplete="off"
            >
        </div>

        <div class="activity-filter-group">
            <label for="filter-status" class="form-label">Result</label>
            <select id="filter-status" name="status" class="form-input">
                <option value="all"     <?= $statusFilter === 'all'     ? 'selected' : '' ?>>All</option>
                <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>Successful only</option>
                <option value="fail"    <?= $statusFilter === 'fail'    ? 'selected' : '' ?>>Failed only</option>
            </select>
        </div>

        <div class="activity-filter-group">
            <label for="filter-from" class="form-label">From</label>
            <input
                type="date"
                id="filter-from"
                name="from"
                value="<?= htmlspecialchars($from) ?>"
                class="form-input"
            >
        </div>

        <div class="activity-filter-group">
            <label for="filter-to" class="form-label">To</label>
            <input
                type="date"
                id="filter-to"
                name="to"
                value="<?= htmlspecialchars($to) ?>"
                class="form-input"
            >
        </div>

        <div class="activity-filter-actions">
            <button type="submit" class="btn btn-compact">
                <?= adminIcon('search', 16) ?>
                <span>Apply filters</span>
            </button>
            <?php if ($hasFilter): ?>
                <a href="/student-auth/admin/activity.php" class="admin-search-clear">Clear filters</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (empty($rows)): ?>
        <?php if ($hasFilter): ?>
            <div class="empty-state">
                <span class="empty-state-icon"><?= adminIcon('search-x', 28) ?></span>
                <div class="empty-state-title">No activity matches your filters</div>
                <p>Try widening the date range or clearing the email filter.</p>
                <a href="/student-auth/admin/activity.php" class="empty-state-action">
                    <?= adminIcon('x', 14) ?>
                    <span>Clear filters</span>
                </a>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <span class="empty-state-icon"><?= adminIcon('inbox', 28) ?></span>
                <div class="empty-state-title">No login activity yet</div>
                <p>Sign-in attempts will appear here as soon as anyone tries to log in.</p>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Result</th>
                        <th>IP Address</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $ok = (int) $r['successful'] === 1;
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($r['email']) ?></td>
                            <td>
                                <span class="pill <?= $ok ? 'pill-success' : 'pill-failed' ?>">
                                    <?= $ok ? adminIcon('check', 12) : adminIcon('x', 12) ?>
                                    <?= $ok ? 'Success' : 'Failed' ?>
                                </span>
                            </td>
                            <td class="activity-ip"><?= htmlspecialchars($r['ip_address']) ?></td>
                            <td title="<?= htmlspecialchars($r['attempted_at']) ?>">
                                <?= htmlspecialchars(relativeTime($r['attempted_at'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination-bar">
            <div class="pagination-info">
                Page <?= $page ?> of <?= $totalPages ?>
                <span class="pagination-sep">·</span>
                Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalRows)) ?> of <?= number_format($totalRows) ?> total
            </div>
            <div class="pagination">
                <?= renderActivityPagination($page, $totalPages) ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php renderAdminFooter(); ?>
