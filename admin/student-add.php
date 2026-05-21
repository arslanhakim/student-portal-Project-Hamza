<?php
/**
 * Admin: create a new student or admin account.
 * Reuses every validation rule from auth/register.php and adds a role selector.
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

// Flash-restored state (POST-redirect-GET)
$errors = flashGet('errors', []);
$old    = flashGet('old', [
    'full_name'  => '',
    'email'      => '',
    'student_id' => '',
    'department' => '',
    'role'       => 'student',
    'is_active'  => 1,
]);

// ============================================
// CONTROLLER (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- 0. CSRF check ---
    if (!csrfVerify($_POST['_csrf'] ?? null)) {
        $errors['general'] = 'Security token mismatch. Please refresh and try again.';
        flashSet('errors', $errors);
        header('Location: /student-auth/admin/student-add.php');
        exit;
    }

    // --- 1. Collect input ---
    $fullName  = trim($_POST['full_name']  ?? '');
    $email     = trim($_POST['email']      ?? '');
    $studentId = trim($_POST['student_id'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $role      = $_POST['role']             ?? 'student';
    // Checkbox toggle: present (any value) = 1, absent = 0.
    $isActive  = isset($_POST['is_active']) ? 1 : 0;
    $password  = $_POST['password']         ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

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

    // Role (server-side whitelist — never trust the form value)
    if (!in_array($role, $allowedRoles, true)) {
        $errors['role'] = 'Invalid role selected.';
        $role = 'student';
    }

    // Password
    if ($password === '') {
        $errors['password'] = 'Password is required.';
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

    // Confirm password
    if ($confirm === '') {
        $errors['confirm_password'] = 'Please confirm the password.';
    } elseif ($password !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // --- 3. Uniqueness checks ---
    if (!isset($errors['email'])) {
        $stmt = $pdo->prepare("SELECT id FROM students WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            $errors['email'] = 'This email is already registered.';
        }
    }

    if (!isset($errors['student_id'])) {
        $stmt = $pdo->prepare("SELECT id FROM students WHERE student_id = :sid LIMIT 1");
        $stmt->execute([':sid' => $studentId]);
        if ($stmt->fetch()) {
            $errors['student_id'] = 'This Student ID is already registered.';
        }
    }

    // --- 4. Insert if clean ---
    if (empty($errors)) {
        try {
            $hashed = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare("
                INSERT INTO students (full_name, email, password, student_id, department, role, is_active)
                VALUES (:full_name, :email, :password, :student_id, :department, :role, :is_active)
            ");
            $stmt->execute([
                ':full_name'  => $fullName,
                ':email'      => $email,
                ':password'   => $hashed,
                ':student_id' => $studentId,
                ':department' => $department,
                ':role'       => $role,
                ':is_active'  => $isActive,
            ]);

            flashSet('admin_success', 'Account created successfully.');
            header('Location: /student-auth/admin/students.php');
            exit;
        } catch (PDOException $e) {
            error_log("Admin create student failed: " . $e->getMessage());
            $errors['general'] = 'Something went wrong. Please try again.';
        }
    }

    // --- 5. Errors → flash + redirect ---
    if (!empty($errors)) {
        flashSet('errors', $errors);
        flashSet('old', $old);
        header('Location: /student-auth/admin/student-add.php');
        exit;
    }
}

// ============================================
// VIEW
// ============================================
renderAdminHeader('Add Student', 'add');
?>

<div class="admin-page-header">
    <div>
        <h1 class="admin-page-title">Add Student</h1>
        <p class="admin-page-subtitle">Create a new student or admin account.</p>
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

        <div class="form-group">
            <label class="form-label" for="full_name">
                Full Name <span class="required">*</span>
            </label>
            <input
                type="text"
                id="full_name"
                name="full_name"
                class="form-input <?= isset($errors['full_name']) ? 'has-error' : '' ?>"
                value="<?= htmlspecialchars($old['full_name']) ?>"
                placeholder="Jane Doe"
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
                value="<?= htmlspecialchars($old['email']) ?>"
                placeholder="jane.doe@university.edu"
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
                    value="<?= htmlspecialchars($old['student_id']) ?>"
                    placeholder="BCS-2024-045"
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
                            <?= $old['department'] === $dept ? 'selected' : '' ?>>
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
                    <option value="student" <?= $old['role'] === 'student' ? 'selected' : '' ?>>Student</option>
                    <option value="admin"   <?= $old['role'] === 'admin'   ? 'selected' : '' ?>>Admin</option>
                </select>
                <?php if (isset($errors['role'])): ?>
                    <span class="form-error"><?= htmlspecialchars($errors['role']) ?></span>
                <?php else: ?>
                    <span class="form-hint">Admins can access the admin panel and manage all students.</span>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="is_active">
                    Account Status <span class="required">*</span>
                </label>
                <input type="checkbox"
                       name="is_active"
                       id="is_active"
                       value="1"
                       class="toggle-input"
                       aria-label="Account status"
                       <?= ((int) ($old['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                <label for="is_active" class="toggle">
                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    <span class="toggle-label"></span>
                </label>
                <?php if (isset($errors['is_active'])): ?>
                    <span class="form-error"><?= htmlspecialchars($errors['is_active']) ?></span>
                <?php else: ?>
                    <span class="form-hint">Inactive users cannot sign in but their data is preserved.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label" for="password">
                    Password <span class="required">*</span>
                </label>
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
                <label class="form-label" for="confirm_password">
                    Confirm Password <span class="required">*</span>
                </label>
                <div class="password-wrapper">
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        class="form-input <?= isset($errors['confirm_password']) ? 'has-error' : '' ?>"
                        placeholder="Re-enter the password"
                        autocomplete="new-password"
                    >
                    <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password">Show</button>
                </div>
                <?php if (isset($errors['confirm_password'])): ?>
                    <span class="form-error"><?= htmlspecialchars($errors['confirm_password']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-form-actions">
            <button type="submit" class="btn">Create Account</button>
            <a href="/student-auth/admin/students.php" class="admin-form-cancel">Cancel</a>
        </div>
    </form>
</div>

<?php renderAdminFooter(); ?>
