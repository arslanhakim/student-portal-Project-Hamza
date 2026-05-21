<?php
/**
 * Admin: paginated student list with search, sort, and delete.
 */

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

// Identity of the currently-logged-in admin. Hoisted to file scope so both the
// POST handlers (self-delete / self-deactivate guards) and the row renderer
// (which disables the toggle on the admin's own row) can use it.
$selfId = (int) ($_SESSION['user_id'] ?? 0);

// ============================================
// HELPERS (defined before any use)
// ============================================

/**
 * Build a students.php URL preserving the current query string,
 * with optional overrides. Pass '' or null to drop a key.
 */
function adminStudentsUrl(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return '/student-auth/admin/students.php' . ($qs !== '' ? '?' . $qs : '');
}

/**
 * Render a sortable column header link. Clicking toggles direction.
 */
function sortLink(string $column, string $label, string $currentSort, string $currentDir): string {
    $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($currentSort === $column) {
        $arrow = $currentDir === 'asc' ? ' <span class="sort-arrow">▲</span>' : ' <span class="sort-arrow">▼</span>';
    }
    $href = adminStudentsUrl(['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
    return '<a href="' . htmlspecialchars($href) . '" class="sort-link">' . htmlspecialchars($label) . $arrow . '</a>';
}

/**
 * Render pagination links (Prev, numbered with elision, Next).
 * Preserves current ?q, ?sort, ?dir via adminStudentsUrl().
 */
function renderPagination(int $current, int $total): string {
    if ($total <= 1) {
        return '';
    }

    $out = '';

    $prevIcon = adminIcon('chevron-left', 16);
    $nextIcon = adminIcon('chevron-right', 16);

    // Prev
    if ($current > 1) {
        $out .= '<a href="' . htmlspecialchars(adminStudentsUrl(['page' => $current - 1])) . '" class="pagination-link icon-only" aria-label="Previous page">' . $prevIcon . '</a>';
    } else {
        $out .= '<span class="pagination-link icon-only disabled" aria-hidden="true">' . $prevIcon . '</span>';
    }

    // Numbered (max ~7 visible + ellipses)
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
            $out .= '<a href="' . htmlspecialchars(adminStudentsUrl(['page' => $item])) . '" class="' . $cls . '">' . (int) $item . '</a>';
        }
    }

    // Next
    if ($current < $total) {
        $out .= '<a href="' . htmlspecialchars(adminStudentsUrl(['page' => $current + 1])) . '" class="pagination-link icon-only" aria-label="Next page">' . $nextIcon . '</a>';
    } else {
        $out .= '<span class="pagination-link icon-only disabled" aria-hidden="true">' . $nextIcon . '</span>';
    }

    return $out;
}

// ============================================
// POST: delete action
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify($_POST['_csrf'] ?? null)) {
        flashSet('admin_error', 'Security token mismatch. Please try again.');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                flashSet('admin_error', 'Invalid student ID.');
            } elseif ($id === $selfId) {
                flashSet('admin_error', 'You cannot delete your own account.');
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM students WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    if ($stmt->rowCount() > 0) {
                        flashSet('admin_success', 'Student deleted successfully.');
                    } else {
                        flashSet('admin_error', 'Student not found.');
                    }
                } catch (PDOException $e) {
                    error_log("Delete student failed: " . $e->getMessage());
                    flashSet('admin_error', 'Failed to delete student.');
                }
            }
        } elseif ($action === 'toggle_status') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                flashSet('admin_error', 'Invalid student ID.');
            } else {
                try {
                    // Read current state so we can enforce self-deactivate guard
                    // and pick the right flash message.
                    $stmt = $pdo->prepare("SELECT is_active FROM students WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => $id]);
                    $row = $stmt->fetch();

                    if (!$row) {
                        flashSet('admin_error', 'Student not found.');
                    } elseif ($id === $selfId && (int) $row['is_active'] === 1) {
                        // Toggling self while active would deactivate — block.
                        flashSet('admin_error', 'You cannot deactivate your own account.');
                    } else {
                        // Atomic flip — safe against tab-racing.
                        $upd = $pdo->prepare("UPDATE students SET is_active = NOT is_active WHERE id = :id");
                        $upd->execute([':id' => $id]);
                        $nowActive = ((int) $row['is_active']) !== 1;
                        flashSet('admin_success', $nowActive ? 'Student activated.' : 'Student deactivated.');
                    }
                } catch (PDOException $e) {
                    error_log("Toggle student status failed: " . $e->getMessage());
                    flashSet('admin_error', 'Failed to update status.');
                }
            }
        }
    }

    // Preserve q/sort/dir/page in the redirect (action is POST-only, drop it)
    $passthrough = $_GET;
    $qs = http_build_query($passthrough);
    header('Location: /student-auth/admin/students.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

// ============================================
// GET: list students
// ============================================

// Sort whitelist — column names ONLY come from this list, never user input.
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

$perPage = 15;
$page = max(1, (int) ($_GET['page'] ?? 1));

// Build WHERE.
//
// PDO with EMULATE_PREPARES=false (see config/database.php) sends queries to
// MySQL as native prepared statements with positional ?-placeholders. A named
// parameter used more than once must therefore be bound as multiple distinct
// names — otherwise PDO throws SQLSTATE[HY093] "Invalid parameter number"
// and the catch below silently turns it into an empty result set.
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

try {
    // Count total
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM students $where");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    // Fetch page — $sort and $dir are whitelisted; $perPage and $offset are ints
    $sql = "SELECT id, full_name, email, student_id, department, role, is_active, created_at
            FROM students
            $where
            ORDER BY $sort $dir
            LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Student list query failed: " . $e->getMessage());
    $students   = [];
    $totalRows  = 0;
    $totalPages = 1;
    $offset     = 0;
}

$flashSuccess = flashGet('admin_success');
$flashError   = flashGet('admin_error');

renderAdminHeader('Students', 'students');
?>

<?php
// Build the export URL preserving the current filter + sort (drop page —
// the export covers all matching rows across pages).
$exportQuery = $_GET;
unset($exportQuery['page']);
$exportUrl = '/student-auth/admin/students-export.php'
           . ($exportQuery ? '?' . http_build_query($exportQuery) : '');
?>
<div class="admin-page-header">
    <h1 class="admin-page-title">Students</h1>
    <div class="admin-page-header-actions">
        <a href="<?= htmlspecialchars($exportUrl) ?>"
           class="btn btn-outline btn-compact"
           title="Download all matching students as CSV">
            <?= adminIcon('download', 16) ?>
            <span>Export CSV</span>
        </a>
        <a href="/student-auth/admin/student-add.php" class="btn btn-compact">
            <?= adminIcon('user-plus', 16) ?>
            <span>Add Student</span>
        </a>
    </div>
</div>

<?php if ($flashSuccess): ?>
    <div class="alert alert-success"><span><?= htmlspecialchars($flashSuccess) ?></span></div>
<?php endif; ?>
<?php if ($flashError): ?>
    <div class="alert alert-error"><span><?= htmlspecialchars($flashError) ?></span></div>
<?php endif; ?>

<div class="admin-panel">
    <form method="GET" class="admin-search">
        <div class="admin-search-field">
            <span class="icon-leading"><?= adminIcon('search', 18) ?></span>
            <input
                type="text"
                name="q"
                value="<?= htmlspecialchars($q) ?>"
                placeholder="Search by name, email, or student ID..."
                class="form-input"
                autofocus
            >
            <?php if ($q !== ''): ?>
                <a href="<?= htmlspecialchars(adminStudentsUrl(['q' => '', 'page' => 1])) ?>"
                   class="admin-search-clear-btn"
                   title="Clear search"
                   aria-label="Clear search">
                    <?= adminIcon('x', 16) ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
        // Preserve sort/dir so a search doesn't reset the current ordering
        if ($sort !== 'created_at' || $dir !== 'desc'):
        ?>
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
            <input type="hidden" name="dir"  value="<?= htmlspecialchars($dir) ?>">
        <?php endif; ?>
        <button type="submit" class="btn btn-compact">
            <?= adminIcon('search', 16) ?>
            <span>Search</span>
        </button>
    </form>

    <?php if (empty($students)): ?>
        <?php if ($q !== ''): ?>
            <div class="empty-state">
                <span class="empty-state-icon"><?= adminIcon('search-x', 28) ?></span>
                <div class="empty-state-title">No students found</div>
                <p>Nothing matched "<?= htmlspecialchars($q) ?>".</p>
                <a href="<?= htmlspecialchars(adminStudentsUrl(['q' => '', 'page' => 1])) ?>" class="empty-state-action">
                    <?= adminIcon('x', 14) ?>
                    <span>Clear search</span>
                </a>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <span class="empty-state-icon"><?= adminIcon('inbox', 28) ?></span>
                <div class="empty-state-title">No students yet</div>
                <p>The roster is empty — add your first student to get started.</p>
                <a href="/student-auth/admin/student-add.php" class="empty-state-action">
                    <?= adminIcon('user-plus', 14) ?>
                    <span>Add the first student</span>
                </a>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table sortable">
                <thead>
                    <tr>
                        <th><?= sortLink('id',         'ID',         $sort, $dir) ?></th>
                        <th><?= sortLink('full_name',  'Full Name',  $sort, $dir) ?></th>
                        <th><?= sortLink('email',      'Email',      $sort, $dir) ?></th>
                        <th><?= sortLink('student_id', 'Student ID', $sort, $dir) ?></th>
                        <th><?= sortLink('department', 'Department', $sort, $dir) ?></th>
                        <th><?= sortLink('role',       'Role',       $sort, $dir) ?></th>
                        <th>Status</th>
                        <th><?= sortLink('created_at', 'Joined',     $sort, $dir) ?></th>
                        <th class="actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $s): ?>
                        <?php $isAdminRow = $s['role'] === 'admin'; ?>
                        <tr>
                            <td class="mono"><?= (int) $s['id'] ?></td>
                            <td><?= htmlspecialchars($s['full_name']) ?></td>
                            <td><?= htmlspecialchars($s['email']) ?></td>
                            <td class="mono"><?= htmlspecialchars($s['student_id']) ?></td>
                            <td><?= htmlspecialchars($s['department']) ?></td>
                            <td>
                                <span class="pill <?= $isAdminRow ? 'pill-admin' : 'pill-student' ?>">
                                    <?= $isAdminRow ? 'Admin' : 'Student' ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $isActive = (int) $s['is_active'] === 1;
                                $isSelfRow = (int) $s['id'] === $selfId;
                                ?>
                                <div class="status-cell" data-confirm-cell>
                                    <div class="status-default" data-confirm-default>
                                        <button type="button"
                                                class="pill pill-button <?= $isActive ? 'pill-active' : 'pill-inactive' ?>"
                                                <?php if ($isSelfRow): ?>
                                                    disabled
                                                    title="You cannot deactivate your own account"
                                                <?php else: ?>
                                                    data-action="show-confirm"
                                                    title="<?= $isActive ? 'Click to deactivate' : 'Click to activate' ?>"
                                                <?php endif; ?>
                                                aria-label="<?= $isActive ? 'Active' : 'Inactive' ?>">
                                            <?= $isActive ? adminIcon('check', 12) : adminIcon('pause', 12) ?>
                                            <?= $isActive ? 'Active' : 'Inactive' ?>
                                        </button>
                                    </div>
                                    <?php if (!$isSelfRow): ?>
                                        <form method="POST"
                                              action="<?= htmlspecialchars(adminStudentsUrl()) ?>"
                                              class="status-confirm"
                                              data-confirm-form
                                              hidden>
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id"     value="<?= (int) $s['id'] ?>">
                                            <span class="confirm-prompt"><?= $isActive ? 'Deactivate?' : 'Activate?' ?></span>
                                            <button type="submit" class="btn-confirm-yes">Yes</button>
                                            <button type="button" class="btn-confirm-no" data-action="hide-confirm">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= htmlspecialchars(date('M j, Y', strtotime($s['created_at']))) ?></td>
                            <td>
                                <div class="action-buttons" data-confirm-cell>
                                    <div class="actions-default" data-confirm-default>
                                        <a href="/student-auth/admin/student-edit.php?id=<?= (int) $s['id'] ?>"
                                           class="btn-icon"
                                           title="Edit student"
                                           aria-label="Edit student">
                                            <?= adminIcon('pencil', 16) ?>
                                        </a>
                                        <button type="button"
                                                class="btn-icon btn-icon-danger"
                                                data-action="show-confirm"
                                                title="Delete student"
                                                aria-label="Delete student">
                                            <?= adminIcon('trash', 16) ?>
                                        </button>
                                    </div>
                                    <form method="POST"
                                          action="<?= htmlspecialchars(adminStudentsUrl()) ?>"
                                          class="actions-confirm"
                                          data-confirm-form
                                          hidden>
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id"     value="<?= (int) $s['id'] ?>">
                                        <span class="confirm-prompt">Delete?</span>
                                        <button type="submit" class="btn-confirm-yes">Yes, delete</button>
                                        <button type="button" class="btn-confirm-no" data-action="hide-confirm">Cancel</button>
                                    </form>
                                </div>
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
                Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> of <?= $totalRows ?> total
            </div>
            <div class="pagination">
                <?= renderPagination($page, $totalPages) ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
/* Inline confirmation handler — drives BOTH the delete and the status-toggle
   confirm UIs in this table. Each row cell that needs a confirmation step
   contains:
     <div data-confirm-cell>
       <... data-confirm-default>         ← default visible state
       <form data-confirm-form hidden>     ← confirmation state (CSRF-protected POST)
     </div>
   The trigger buttons declare data-action="show-confirm" or "hide-confirm".
   The actual POST stays a real form submission — JS only toggles visibility. */
(function () {
    "use strict";

    function open(cell) {
        var def  = cell.querySelector("[data-confirm-default]");
        var conf = cell.querySelector("[data-confirm-form]");
        if (def)  def.hidden = true;
        if (conf) {
            conf.hidden = false;
            var yes = conf.querySelector(".btn-confirm-yes");
            if (yes) yes.focus();
        }
    }

    function close(cell) {
        var def  = cell.querySelector("[data-confirm-default]");
        var conf = cell.querySelector("[data-confirm-form]");
        if (conf) conf.hidden = true;
        if (def)  def.hidden = false;
    }

    document.addEventListener("click", function (e) {
        var trigger = e.target.closest("[data-action]");
        if (!trigger) return;
        var cell = trigger.closest("[data-confirm-cell]");
        if (!cell) return;
        if (trigger.dataset.action === "show-confirm")      open(cell);
        else if (trigger.dataset.action === "hide-confirm") close(cell);
    });

    // Esc anywhere on the page closes every open confirmation.
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        var openConfirms = document.querySelectorAll("[data-confirm-form]:not([hidden])");
        openConfirms.forEach(function (conf) {
            close(conf.closest("[data-confirm-cell]"));
        });
    });
})();
</script>

<?php renderAdminFooter(); ?>
