<?php
/**
 * Registration page — handles GET (show form) and POST (process submission).
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

// Redirect to dashboard if already logged in
redirectIfLoggedIn();

// ============================================
// CONTROLLER LOGIC (runs only on POST)
// ============================================
// Pull any flashed errors/old input from a previous failed submission
$errors = flashGet('errors', []);
$old    = flashGet('old', [
    'full_name'  => '',
    'email'      => '',
    'student_id' => '',
    'department' => '',
]);



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
     // --- 0. CSRF check (must come first) ---
    if (!csrfVerify($_POST['_csrf'] ?? null)) {
        $errors['general'] = 'Security token mismatch. Please refresh the page and try again.';
        flashSet('errors', $errors);
        header('Location: /student-auth/auth/register.php');
        exit;
    }
    // --- 1. Collect & sanitize input ---
    $fullName  = trim($_POST['full_name']  ?? '');
    $email     = trim($_POST['email']      ?? '');
    $studentId = trim($_POST['student_id'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $password  = $_POST['password']         ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    // Preserve values so the user doesn't retype everything on error
    $old = [
        'full_name'  => $fullName,
        'email'      => $email,
        'student_id' => $studentId,
        'department' => $department,
    ];

    // --- 2. Validate each field ---

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
    $allowedDepartments = [
        'Computer Science', 'Software Engineering', 'Electrical Engineering',
        'Business Administration', 'Mathematics', 'Physics', 'Other',
    ];
    if ($department === '') {
        $errors['department'] = 'Please select your department.';
    } elseif (!in_array($department, $allowedDepartments, true)) {
        $errors['department'] = 'Invalid department selected.';
    }

    // Password
    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif (strlen($password) > 72) {
        // bcrypt has a 72-byte limit
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
        $errors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // --- 3. Check uniqueness (only if basic validation passed) ---
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

    // --- 4. If no errors, insert the user ---
    if (empty($errors)) {
        try {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare("
                INSERT INTO students (full_name, email, password, student_id, department)
                VALUES (:full_name, :email, :password, :student_id, :department)
            ");

            $stmt->execute([
                ':full_name'  => $fullName,
                ':email'      => $email,
                ':password'   => $hashedPassword,
                ':student_id' => $studentId,
                ':department' => $department,
            ]);

            // Success — redirect to login with a flag
            header('Location: /student-auth/auth/login.php?registered=1');
            exit;

       } catch (PDOException $e) {
            error_log("Registration insert failed: " . $e->getMessage());
            $errors['general'] = 'Something went wrong. Please try again.';
        }
    }

    // --- 5. If we got here, there were errors. Flash them and redirect back. ---
    if (!empty($errors)) {
        flashSet('errors', $errors);
        flashSet('old', $old);
        header('Location: /student-auth/auth/register.php');
        exit;
    }
}

// ============================================
// VIEW (renders for GET, or POST with errors)
// ============================================
$pageTitle = 'Create Account';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card card-wide">
    <div class="card-header">
        <h1 class="card-title">Create your account</h1>
        <p class="card-subtitle">Join the student portal in under a minute</p>
    </div>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error">
            <span><?= htmlspecialchars($errors['general']) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="/student-auth/auth/register.php" novalidate>
        <?= csrfField() ?>
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
                autocomplete="email"
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
                    <?php
                    $departments = [
                        'Computer Science', 'Software Engineering', 'Electrical Engineering',
                        'Business Administration', 'Mathematics', 'Physics', 'Other',
                    ];
                    foreach ($departments as $dept):
                        $selected = ($old['department'] === $dept) ? 'selected' : '';
                    ?>
                        <option value="<?= htmlspecialchars($dept) ?>" <?= $selected ?>>
                            <?= htmlspecialchars($dept) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['department'])): ?>
                    <span class="form-error"><?= htmlspecialchars($errors['department']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">
                Password <span class="required">*</span>
            </label>
            <input
                type="password"
                id="password"
                name="password"
                class="form-input <?= isset($errors['password']) ? 'has-error' : '' ?>"
                placeholder="Minimum 8 characters"
                autocomplete="new-password"
            >
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
                    placeholder="Re-enter your password"
                    autocomplete="new-password"
                >
                <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Show password">Show</button>
            </div>
            <?php if (isset($errors['confirm_password'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['confirm_password']) ?></span>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-block">Create Account</button>
    </form>

    <div class="card-footer">
        Already have an account? <a href="/student-auth/auth/login.php">Sign in</a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>