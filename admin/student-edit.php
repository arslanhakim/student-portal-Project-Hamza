<?php
/**
 * Admin: edit an existing student. Accepts ?id=N.
 * Passwords are OPTIONAL here — leave blank to preserve the current hash.
 */

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

// ============================================
// CONSTANTS (must match auth/register.php exactly)
// ============================================
$allowedDepartments = [
    'Computer Science', 'Software Engineering', 'Electrical Engineering',
    'Business Administration', 'Mathematics', 'Physics', 'Other',
];
$allowedRoles = ['student', 'admin'];

// ============================================
// RESOLVE TARGET STUDENT
// Accept ?id on GET and hidden id on POST so the form survives redirects.
// ============================================
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    flashSet('admin_error', 'Student not found.');
    header('Location: /student-auth/admin/students.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, student_id, department, role, is_active, created_at
        FROM students
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $student = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Load student for edit failed: " . $e->getMessage());
    $student = null;
}

if (!$student) {
    flashSet('admin_error', 'Student not found.');
    header('Location: /student-auth/admin/students.php');
    exit;
}

$selfId = (int) ($_SESSION['user_id'] ?? 0);

// Flash-restored state (POST-redirect-GET)
$errors = flashGet('errors', []);
$old    = flashGet('old', null);

// Pre-populate display values — old input on validation-error reload, else DB
$display = [
    'full_name'  => $old['full_name']  ?? $student['full_name'],
    'email'      => $old['email']      ?? $student['email'],
    'student_id' => $old['student_id'] ?? $student['student_id'],
    'department' => $old['department'] ?? $student['department'],
    'role'       => $old['role']       ?? $student['role'],
    'is_active'  => isset($old['is_active']) ? (int) $old['is_active'] : (int) $student['is_active'],
];

// ============================================
// CONTROLLER (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- 0. CSRF check ---
    if (!csrfVerify($_POST['_csrf'] ?? null)) {
        $errors['general'] = 'Security token mismatch. Please refresh and try again.';
        flashSet('errors', $errors);
        header('Location: /student-auth/admin/student-edit.php?id=' . $id);
        exit;
    }

    // --- 1. Collect input ---
    $fullName    = trim($_POST['full_name']  ?? '');
    $email       = trim($_POST['email']      ?? '');
    $studentId   = trim($_POST['student_id'] ?? '');
    $department  = trim($_POST['department'] ?? '');
    $role        = $_POST['role']             ?? $student['role'];
    // Checkbox toggle: present (any value) = 1, absent = 0. Native browsers
    // omit unchecked checkboxes from form submissions entirely.
    $isActive    = isset($_POST['is_active']) ? 1 : 0;
    $password    = $_POST['password']         ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';

    $old = [
        'full_name'  => $fullName,
        'email'      => $email,
        'student_id' => $studentId,
        'department' => $department,
        'role'       => $role,
        'is_active'  => $isActive,
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

    // Student ID
    if ($studentId === '') {
        $errors['student_id'] = 'Student ID is required.';
    } elseif (!preg_match('/^[A-Z0-9-]{3,20}$/i', $studentId)) {
        $errors['student_id'] = 'Student ID must be 3–20 characters (letters, numbers, hyphens).';
    }

    // Department
    if ($department === '') {
        $errors['department'] = 'Please select a department.';
    } elseif (!in_array($department, $allowedDepartments, true)) {
        $errors['department'] = 'Invalid department selected.';
    }

    // Role (server-side whitelist)
    if (!in_array($role, $allowedRoles, true)) {
        $errors['role'] = 'Invalid role selected.';
        $role = $student['role'];
    }

    // Self-demote protection — server-side, not just UI
    if ($id === $selfId && $student['role'] === 'admin' && $role !== 'admin') {
        $errors['role'] = 'You cannot remove your own admin role.';
    }

    // Self-deactivate protection — server-side, never trust the form.
    // (No format check needed — checkbox semantics constrain the value: any
    // present `is_active` field becomes 1, absent becomes 0.)
    if ($id === $selfId && $isActive === 0) {
        $errors['is_active'] = 'You cannot deactivate your own account.';
    }

    // Password — OPTIONAL on edit. Validate only if either field has input.
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

    // --- 3. Uniqueness checks (excluding the current row) ---
    if (!isset($errors['email'])) {
        $stmt = $pdo->prepare("
            SELECT id FROM students
            WHERE email = :email AND id != :current_id
            LIMIT 1
        ");
        $stmt->execute([':email' => $email, ':current_id' => $id]);
        if ($stmt->fetch()) {
            $errors['email'] = 'This email is already registered.';
        }
    }

    if (!isset($errors['student_id'])) {
        $stmt = $pdo->prepare("
            SELECT id FROM students
            WHERE student_id = :sid AND id != :current_id
            LIMIT 1
        ");
        $stmt->execute([':sid' => $studentId, ':current_id' => $id]);
        if ($stmt->fetch()) {
            $errors['student_id'] = 'This Student ID is already registered.';
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
                        student_id = :sid,
                        department = :department,
                        role       = :role,
                        is_active  = :is_active,
                        password   = :password
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':full_name'  => $fullName,
                    ':email'      => $email,
                    ':sid'        => $studentId,
                    ':department' => $department,
                    ':role'       => $role,
                    ':is_active'  => $isActive,
                    ':password'   => $hashed,
                    ':id'         => $id,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE students
                    SET full_name  = :full_name,
                        email      = :email,
                        student_id = :sid,
                        department = :department,
                        role       = :role,
                        is_active  = :is_active
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':full_name'  => $fullName,
                    ':email'      => $email,
                    ':sid'        => $studentId,
                    ':department' => $department,
                    ':role'       => $role,
                    ':is_active'  => $isActive,
                    ':id'         => $id,
                ]);
            }

            // If the admin edited their own row, keep the live session in sync.
            if ($id === $selfId) {
                $_SESSION['full_name']  = $fullName;
                $_SESSION['email']      = $email;
                $_SESSION['student_id'] = $studentId;
                $_SESSION['department'] = $department;
                $_SESSION['role']       = $role;
            }

            flashSet('admin_success', 'Student updated successfully.');
            header('Location: /student-auth/admin/students.php');
            exit;
        } catch (PDOException $e) {
            error_log("Admin update student failed: " . $e->getMessage());
            $errors['general'] = 'Something went wrong. Please try again.';
        }
    }

    // --- 5. Errors → flash + redirect ---
    if (!empty($errors)) {
        flashSet('errors', $errors);
        flashSet('old', $old);
        header('Location: /student-auth/admin/student-edit.php?id=' . $id);
        exit;
    }
}

// ============================================
// VIEW
// ============================================
renderAdminHeader('Edit Student', 'students');
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Edit Student</h1>
        <p class="admin-page-subtitle">Editing: <?= htmlspecialchars($student['full_name']) ?></p>
    </div>
</div>

<?php if (!empty($errors['general'])): ?>
    <div class="alert alert-error">
        <span><?= htmlspecialchars($errors['general']) ?></span>
    </div>
<?php endif; ?>

<div class="admin-panel">
    <form method="POST" class="admin-form" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">

        <div class="admin-form-meta">
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
                autocomplete="off"
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
                autocomplete="off"
            >
            <?php if (isset($errors['email'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['email']) ?></span>
            <?php endif; ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="student_id">
                    Student ID <span class="required">*</span>
                </label>
                <input
                    type="text"
                    id="student_id"
                    name="student_id"
                    class="form-input <?= isset($errors['student_id']) ? 'has-error' : '' ?>"
                    value="<?= htmlspecialchars($display['student_id']) ?>"
                    maxlength="20"
                    autocomplete="off"
                >
                <?php if (isset($errors['student_id'])): ?>
                    <span class="form-error"><?= htmlspecialchars($errors['student_id']) ?></span>
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
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="role">
                    Role <span class="required">*</span>
                </label>
                <select
                    id="role"
                    name="role"
                    class="form-input <?= isset($errors['role']) ? 'has-error' : '' ?>"
                >
                    <option value="student" <?= $display['role'] === 'student' ? 'selected' : '' ?>>Student</option>
                    <option value="admin"   <?= $display['role'] === 'admin'   ? 'selected' : '' ?>>Admin</option>
                </select>
                <?php if (isset($errors['role'])): ?>
                    <span class="form-error"><?= htmlspecialchars($errors['role']) ?></span>
                <?php elseif ($id === $selfId): ?>
                    <span class="form-hint">You're editing your own account — you can't remove your own admin role.</span>
                <?php else: ?>
                    <span class="form-hint">Admins can access the admin panel and manage all students.</span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="is_active">
                    Account Status <span class="required">*</span>
                </label>
                <?php if ($id === $selfId): ?>
                    <input type="hidden" name="is_active" id="is_active" value="1">
                    <div class="toggle toggle-readonly is-on" aria-hidden="true">
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                        <span class="toggle-label"></span>
                    </div>
                    <span class="form-hint">You cannot deactivate your own account.</span>
                <?php else: ?>
                    <input type="checkbox"
                           name="is_active"
                           id="is_active"
                           value="1"
                           class="toggle-input"
                           aria-label="Account status"
                           <?= $display['is_active'] === 1 ? 'checked' : '' ?>>
                    <label for="is_active" class="toggle">
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                        <span class="toggle-label"></span>
                    </label>
                    <?php if (isset($errors['is_active'])): ?>
                        <span class="form-error"><?= htmlspecialchars($errors['is_active']) ?></span>
                    <?php else: ?>
                        <span class="form-hint">Inactive users cannot sign in but their data is preserved.</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-form-section">
            <div class="admin-form-section-title">Change Password</div>
            <p class="admin-form-section-hint">Leave blank to keep the current password.</p>

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

        <div class="admin-form-actions">
            <button type="submit" class="btn">Save Changes</button>
            <a href="/student-auth/admin/students.php" class="admin-form-cancel">Cancel</a>
        </div>
    </form>
</div>

<?php renderAdminFooter(); ?>
