<?php
/**
 * Student profile editor.
 *
 * The student can edit their own full_name, email, department, and (optionally)
 * password. The identity comes from $_SESSION['user_id'] — there is no
 * hidden id input on the form, so this page cannot be used to edit anyone
 * else's account. Role / status / student_id / account id / joined date are
 * shown read-only.
 */

require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';

requireLogin();

// ============================================
// CONSTANTS (must match auth/register.php exactly)
// ============================================
$allowedDepartments = [
    'Computer Science', 'Software Engineering', 'Electrical Engineering',
    'Business Administration', 'Mathematics', 'Physics', 'Other',
];

/**
 * Tiny inline-SVG helper (Lucide paths). Used only on this page.
 * Kept local rather than promoting to a shared file — only 4 icons, single page.
 */
function profileIcon(string $name, int $size = 18): string {
    static $paths = [
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'pencil'     => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'check'      => '<path d="M20 6 9 17l-5-5"/>',
        'pause'      => '<rect x="14" y="3" width="5" height="18" rx="1"/><rect x="5" y="3" width="5" height="18" rx="1"/>',
    ];
    $body = $paths[$name] ?? '';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $size . '" height="' . (int) $size
         . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
         . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $body . '</svg>';
}

// Identity is fixed from the session. NEVER take this from the form.
$userId = (int) ($_SESSION['user_id'] ?? 0);

// ============================================
// LOAD CURRENT STUDENT (used both for read-only meta and for UPDATE base)
// ============================================
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, student_id, department, role, is_active, created_at
        FROM students
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $student = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Profile load failed: " . $e->getMessage());
    $student = null;
}

if (!$student) {
    // Session points to a row that no longer exists — force re-login.
    header('Location: /student-auth/auth/logout.php');
    exit;
}

$errors = flashGet('errors', []);
$old    = flashGet('old', null);

// Pre-populate display values — flashed old input on error reload, else DB.
$display = [
    'full_name'  => $old['full_name']  ?? $student['full_name'],
    'email'      => $old['email']      ?? $student['email'],
    'department' => $old['department'] ?? $student['department'],
];

// ============================================
// HANDLE POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- 0. CSRF ---
    if (!csrfVerify($_POST['_csrf'] ?? null)) {
        $errors['general'] = 'Security token mismatch. Please refresh and try again.';
        flashSet('errors', $errors);
        header('Location: /student-auth/profile.php');
        exit;
    }

    // --- 1. Collect input ---
    $fullName   = trim($_POST['full_name']  ?? '');
    $email      = trim($_POST['email']      ?? '');
    $department = trim($_POST['department'] ?? '');
    $password   = $_POST['password']         ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    $old = [
        'full_name'  => $fullName,
        'email'      => $email,
        'department' => $department,
    ];

    // --- 2. Validate each field (rules identical to auth/register.php) ---

    // Full name
    if ($fullName === '') {
        $errors['full_name'] = 'Full name is required.';
    } elseif (mb_strlen($fullName) < 3) {
        $errors['full_name'] = 'Full name must be at least 3 characters.';
    } elseif (mb_strlen($fullName) > 100) {
        $errors['full_name'] = 'Full name cannot exceed 100 characters.';
    } elseif (!preg_match("/^[a-zA-Z\s.'-]+$/u", $fullName)) {
        $errors['full_name'] = 'Full name can only contain letters, spaces, apostrophes, hyphens, and periods.';
    }

    // Email
    if ($email === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } elseif (mb_strlen($email) > 150) {
        $errors['email'] = 'Email cannot exceed 150 characters.';
    }

    // Department
    if ($department === '') {
        $errors['department'] = 'Please select a department.';
    } elseif (!in_array($department, $allowedDepartments, true)) {
        $errors['department'] = 'Invalid department selected.';
    }

    // Password — OPTIONAL. Validate only if either field has any input.
    $changePassword = ($password !== '' || $confirm !== '');
    if ($changePassword) {
        if ($password === '') {
            $errors['password'] = 'Password is required when changing.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (strlen($password) > 72) {
            $errors['password'] = 'Password cannot exceed 72 characters.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors['password'] = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $errors['password'] = 'Password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Password must contain at least one number.';
        }

        if ($confirm === '') {
            $errors['confirm_password'] = 'Please confirm the password.';
        } elseif ($password !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }
    }

    // --- 3. Email uniqueness (excluding own row) ---
    if (!isset($errors['email'])) {
        $stmt = $pdo->prepare("
            SELECT id FROM students
            WHERE email = :email AND id != :current_id
            LIMIT 1
        ");
        $stmt->execute([':email' => $email, ':current_id' => $userId]);
        if ($stmt->fetch()) {
            $errors['email'] = 'This email is already registered.';
        }
    }

    // --- 4. Update if clean ---
    if (empty($errors)) {
        try {
            if ($changePassword) {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    UPDATE students
                    SET full_name  = :full_name,
                        email      = :email,
                        department = :department,
                        password   = :password
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':full_name'  => $fullName,
                    ':email'      => $email,
                    ':department' => $department,
                    ':password'   => $hashed,
                    ':id'         => $userId,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE students
                    SET full_name  = :full_name,
                        email      = :email,
                        department = :department
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':full_name'  => $fullName,
                    ':email'      => $email,
                    ':department' => $department,
                    ':id'         => $userId,
                ]);
            }

            // Keep the session in lockstep so the new values reflect everywhere
            // (topbar greeting, dashboard email line, etc.) without a relogin.
            $_SESSION['full_name']  = $fullName;
            $_SESSION['email']      = $email;
            $_SESSION['department'] = $department;

            flashSet('profile_success', 'Profile updated successfully.');
            header('Location: /student-auth/dashboard.php');
            exit;
        } catch (PDOException $e) {
            error_log("Profile update failed: " . $e->getMessage());
            $errors['general'] = 'Something went wrong. Please try again.';
        }
    }

    // --- 5. Errors → flash and redirect (POST-Redirect-GET) ---
    if (!empty($errors)) {
        flashSet('errors', $errors);
        flashSet('old', $old);
        header('Location: /student-auth/profile.php');
        exit;
    }
}

// ============================================
// VIEW
// ============================================
$pageTitle = 'Edit Profile';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card card-wide profile-card">
    <a href="/student-auth/dashboard.php" class="profile-back-link">
        <?= profileIcon('arrow-left', 16) ?>
        <span>Back to dashboard</span>
    </a>

    <div class="card-header">
        <h1 class="card-title">
            <span class="profile-title-icon"><?= profileIcon('pencil', 22) ?></span>
            Edit Profile
        </h1>
        <p class="card-subtitle">Update your account information</p>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error">
            <span><?= htmlspecialchars($errors['general']) ?></span>
        </div>
    <?php endif; ?>

    <h2 class="profile-section-title">Account info</h2>
    <!-- Read-only meta. Reusing .admin-form-meta classes — they're a generic
         readonly-tile pattern, not admin-specific in behavior. -->
    <div class="admin-form-meta profile-meta-grid">
        <div class="admin-form-meta-item">
            <span class="admin-form-meta-label">Student ID</span>
            <span class="admin-form-meta-value mono"><?= htmlspecialchars($student['student_id']) ?></span>
        </div>
        <div class="admin-form-meta-item">
            <span class="admin-form-meta-label">Role</span>
            <span class="admin-form-meta-value"><?= htmlspecialchars(ucfirst($student['role'])) ?></span>
        </div>
        <div class="admin-form-meta-item">
            <span class="admin-form-meta-label">Status</span>
            <?php if ((int) $student['is_active'] === 1): ?>
                <span class="pill pill-active">
                    <?= profileIcon('check', 12) ?>
                    Active
                </span>
            <?php else: ?>
                <span class="pill pill-inactive">
                    <?= profileIcon('pause', 12) ?>
                    Inactive
                </span>
            <?php endif; ?>
        </div>
        <div class="admin-form-meta-item">
            <span class="admin-form-meta-label">Account ID</span>
            <span class="admin-form-meta-value mono">#<?= (int) $student['id'] ?></span>
        </div>
        <div class="admin-form-meta-item">
            <span class="admin-form-meta-label">Joined</span>
            <span class="admin-form-meta-value">
                <?= htmlspecialchars(date('M j, Y', strtotime($student['created_at']))) ?>
            </span>
        </div>
    </div>

    <form method="POST" novalidate>
        <?= csrfField() ?>

        <h2 class="profile-section-title">Profile details</h2>

        <div class="form-group">
            <label class="form-label" for="full_name">
                Full Name <span class="required">*</span>
            </label>
            <input
                type="text"
                id="full_name"
                name="full_name"
                class="form-input <?= isset($errors['full_name']) ? 'has-error' : '' ?>"
                value="<?= htmlspecialchars($display['full_name']) ?>"
                maxlength="100"
                autocomplete="name"
                autofocus
            >
            <?php if (isset($errors['full_name'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['full_name']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="email">
                Email Address <span class="required">*</span>
            </label>
            <input
                type="email"
                id="email"
                name="email"
                class="form-input <?= isset($errors['email']) ? 'has-error' : '' ?>"
                value="<?= htmlspecialchars($display['email']) ?>"
                maxlength="150"
                autocomplete="email"
            >
            <?php if (isset($errors['email'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['email']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label class="form-label" for="department">
                Department <span class="required">*</span>
            </label>
            <select
                id="department"
                name="department"
                class="form-input <?= isset($errors['department']) ? 'has-error' : '' ?>"
            >
                <option value="">Select...</option>
                <?php foreach ($allowedDepartments as $dept): ?>
                    <option value="<?= htmlspecialchars($dept) ?>"
                            <?= $display['department'] === $dept ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['department'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['department']) ?></span>
            <?php endif; ?>
        </div>

        <div class="admin-form-section">
            <div class="admin-form-section-title">Change Password</div>
            <p class="admin-form-section-hint">Leave both password fields blank to keep your current password.</p>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="password">New Password</label>
                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-input <?= isset($errors['password']) ? 'has-error' : '' ?>"
                            placeholder="Minimum 8 characters"
                            autocomplete="new-password"
                        >
                        <button type="button" class="password-toggle" data-target="password" aria-label="Show password">Show</button>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <span class="form-error"><?= htmlspecialchars($errors['password']) ?></span>
                    <?php else: ?>
                        <span class="form-hint">At least 8 characters, with uppercase, lowercase, and a number.</span>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm New Password</label>
                    <div class="password-wrapper">
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="form-input <?= isset($errors['confirm_password']) ? 'has-error' : '' ?>"
                            placeholder="Re-enter the new password"
                            autocomplete="new-password"
                        >
                        <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password">Show</button>
                    </div>
                    <?php if (isset($errors['confirm_password'])): ?>
                        <span class="form-error"><?= htmlspecialchars($errors['confirm_password']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="profile-form-actions">
            <button type="submit" class="btn">Save Changes</button>
            <a href="/student-auth/dashboard.php" class="profile-cancel">Cancel</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
