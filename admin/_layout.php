<?php
/**
 * Shared admin layout.
 *
 * Usage in an admin page:
 *   require_once __DIR__ . '/_layout.php';
 *   requireAdmin();                          // auth gate — every admin file
 *   // ... page logic + DB queries ...
 *   renderAdminHeader('Students', 'students');
 *   // ... page markup ...
 *   renderAdminFooter();
 */

require_once __DIR__ . '/../config/session.php';

/**
 * Bump this when style.css changes to force browsers to re-fetch.
 * Cleaner than telling every visitor to Ctrl+F5.
 */
const ADMIN_ASSET_VERSION = '9';

/**
 * Inline SVG icon (Lucide, MIT-licensed paths). Returns HTML.
 * Uses currentColor so icons pick up text color from the surrounding context.
 *
 * Supported names: grid, users, user-plus, search, x, pencil, trash,
 * chevron-left, chevron-right, search-x, inbox, shield, alert-triangle, trending-up.
 */
function adminIcon(string $name, int $size = 18): string {
    static $paths = [
        'grid'           => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'users'          => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user-plus'      => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" x2="20" y1="8" y2="14"/><line x1="23" x2="17" y1="11" y2="11"/>',
        'search'         => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'x'              => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'pencil'         => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'trash'          => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
        'chevron-left'   => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right'  => '<path d="m9 18 6-6-6-6"/>',
        'search-x'       => '<path d="m13.5 8.5-5 5"/><path d="m8.5 8.5 5 5"/><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'inbox'          => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'shield'         => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.46 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'trending-up'    => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
        'menu'           => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'check'          => '<path d="M20 6 9 17l-5-5"/>',
        'pause'          => '<rect x="14" y="3" width="5" height="18" rx="1"/><rect x="5" y="3" width="5" height="18" rx="1"/>',
        'download'       => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
        'history'        => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
    ];
    $body = $paths[$name] ?? '';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $size . '" height="' . (int) $size
         . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
         . ' stroke-linecap="round" stroke-linejoin="round"'
         . ' class="icon icon-' . htmlspecialchars($name) . '" aria-hidden="true">'
         . $body . '</svg>';
}

/**
 * Emit the opening HTML, top bar, and sidebar.
 * $activeNav: 'dashboard' | 'students' | 'add'
 */
function renderAdminHeader(string $pageTitle, string $activeNav): void {
    $nav = [
        'dashboard' => ['label' => 'Dashboard',   'href' => '/student-auth/admin/dashboard.php',    'icon' => 'grid'],
        'students'  => ['label' => 'Students',    'href' => '/student-auth/admin/students.php',     'icon' => 'users'],
        'activity'  => ['label' => 'Activity',    'href' => '/student-auth/admin/activity.php',     'icon' => 'history'],
        'add'       => ['label' => 'Add Student', 'href' => '/student-auth/admin/student-add.php',  'icon' => 'user-plus'],
    ];
    $adminName = $_SESSION['full_name'] ?? 'Admin';
    $cssHref   = '/student-auth/assets/css/style.css?v=' . ADMIN_ASSET_VERSION;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle) ?> | Admin · Student Portal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="<?= htmlspecialchars($cssHref) ?>">
    <script src="/student-auth/assets/js/auth.js?v=<?= ADMIN_ASSET_VERSION ?>" defer></script>
</head>
<body class="admin-body">
    <nav class="admin-topbar">
        <button class="admin-menu-toggle"
                type="button"
                data-drawer-open
                aria-label="Open navigation menu"
                aria-controls="admin-drawer"
                aria-expanded="false">
            <?= adminIcon('menu', 22) ?>
        </button>
        <div class="admin-topbar-brand">
            <div class="brand-logo">S</div>
            <div class="admin-topbar-title">Student Portal · Admin</div>
        </div>
        <div class="admin-topbar-user">
            <span class="admin-topbar-name"><?= htmlspecialchars($adminName) ?></span>
            <span class="admin-topbar-role">Admin</span>
            <a href="/student-auth/auth/logout.php" class="btn btn-secondary btn-compact">Sign out</a>
        </div>
    </nav>

    <div class="admin-shell">
        <aside class="admin-sidebar" id="admin-drawer">
            <div class="admin-drawer-header">
                <div class="admin-drawer-title">Menu</div>
                <button class="admin-drawer-close"
                        type="button"
                        data-drawer-close
                        aria-label="Close navigation menu">
                    <?= adminIcon('x', 20) ?>
                </button>
            </div>
            <?php foreach ($nav as $key => $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>"
                   class="admin-sidebar-link<?= $activeNav === $key ? ' active' : '' ?>">
                    <?= adminIcon($item['icon'], 18) ?>
                    <span><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </aside>

        <main class="admin-main">
    <?php
}

/**
 * Close the admin shell and document.
 */
function renderAdminFooter(): void {
    ?>
        </main>
    </div>

    <div class="admin-drawer-backdrop" data-drawer-backdrop aria-hidden="true"></div>

    <script>
    /* Mobile drawer — hamburger toggles the off-canvas sidebar on <768px.
       Vanilla JS, event-delegated, no framework. */
    (function () {
        "use strict";
        var body = document.body;

        function setOpen(open) {
            body.classList.toggle("drawer-open", open);
            var toggle = document.querySelector("[data-drawer-open]");
            if (toggle) toggle.setAttribute("aria-expanded", open ? "true" : "false");
        }

        document.addEventListener("click", function (e) {
            if (e.target.closest("[data-drawer-open]")) {
                e.preventDefault();
                setOpen(true);
                return;
            }
            if (e.target.closest("[data-drawer-close]") || e.target.closest("[data-drawer-backdrop]")) {
                setOpen(false);
                return;
            }
            // Auto-close after navigating from a sidebar link
            if (body.classList.contains("drawer-open") && e.target.closest(".admin-sidebar a")) {
                setOpen(false);
            }
        });

        document.addEventListener("keydown", function (e) {
            if (e.key === "Escape" && body.classList.contains("drawer-open")) {
                setOpen(false);
            }
        });
    })();
    </script>
</body>
</html>
    <?php
}
