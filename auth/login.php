<?php
/**
 * Login page — handles GET (show form) and POST (authenticate).
 */

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

// If already logged in, send to dashboard
redirectIfLoggedIn();

// ============================================
// CONTROLLER LOGIC
// ============================================
$errors = flashGet('errors', []);
$old    = flashGet('old', ['email' => '']);

// Success message after registration
$justRegistered = isset($_GET['registered']) && $_GET['registered'] === '1';
$justLoggedOut  = isset($_GET['logged_out']) && $_GET['logged_out'] === '1';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- 0. CSRF check ---
    if (!csrfVerify($_POST['_csrf'] ?? null)) {
        $errors['general'] = 'Security token mismatch. Please refresh the page and try again.';
        flashSet('errors', $errors);
        header('Location: /student-auth/auth/login.php');
        exit;
    }
   // --- 1. Collect input ---
    $email     = trim($_POST['email']    ?? '');
    $password  = $_POST['password']      ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $old = ['email' => $email];

    // --- 2. Validate input format ---
    if ($email === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    }

    // --- 3. Rate limit check (only if format passed) ---
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS attempts
                FROM login_attempts
                WHERE email = :email
                  AND successful = 0
                  AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $stmt->execute([':email' => $email]);
            $recentFailures = (int) $stmt->fetch()['attempts'];

            if ($recentFailures >= 5) {
                $errors['general'] = 'Too many failed attempts. Please try again in 15 minutes.';
            }
        } catch (PDOException $e) {
            error_log("Rate limit check failed: " . $e->getMessage());
            // Fail open: don't block legitimate users if rate-limit DB query fails
        }
    }

    // --- 4. Authenticate (only if rate limit not hit) ---
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                SELECT id, full_name, email, password, student_id, department
                FROM students
                WHERE email = :email
                LIMIT 1
            ");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            $authSuccess = $user && password_verify($password, $user['password']);

            // Log the attempt (success or failure)
            $logStmt = $pdo->prepare("
                INSERT INTO login_attempts (email, ip_address, successful)
                VALUES (:email, :ip, :success)
            ");
            $logStmt->execute([
                ':email'   => $email,
                ':ip'      => $ipAddress,
                ':success' => $authSuccess ? 1 : 0,
            ]);

            if (!$authSuccess) {
                $errors['general'] = 'Invalid email or password.';
            } else {
                // --- 5. SUCCESS: create session ---
                session_regenerate_id(true);

                $_SESSION['user_id']    = $user['id'];
                $_SESSION['full_name']  = $user['full_name'];
                $_SESSION['email']      = $user['email'];
                $_SESSION['student_id'] = $user['student_id'];
                $_SESSION['department'] = $user['department'];
                $_SESSION['logged_in']  = true;
                $_SESSION['login_time'] = time();

                header('Location: /student-auth/dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log("Login query failed: " . $e->getMessage());
            $errors['general'] = 'Something went wrong. Please try again.';
        }
    }

    // If errors exist, flash and redirect (avoids resubmit-on-refresh prompt)
    if (!empty($errors)) {
        flashSet('errors', $errors);
        flashSet('old', $old);
        header('Location: /student-auth/auth/login.php');
        exit;
    }
}

// ============================================
// VIEW
// ============================================
$pageTitle = 'Sign In';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">Welcome back</h1>
        <p class="card-subtitle">Sign in to access your dashboard</p>
    </div>

    <?php if ($justRegistered): ?>
        <div class="alert alert-success">
            <span>✓ Account created successfully. Please sign in.</span>
        </div>
    <?php endif; ?>

    <?php if ($justLoggedOut): ?>
        <div class="alert alert-success">
            <span>✓ You've been signed out successfully.</span>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error">
            <span><?= htmlspecialchars($errors['general']) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="/student-auth/auth/login.php" novalidate>
        <?= csrfField() ?>
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
                placeholder="you@university.edu"
                maxlength="150"
                autocomplete="email"
                autofocus
            >
            <?php if (isset($errors['email'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['email']) ?></span>
            <?php endif; ?>
        </div>

        <<div class="form-group">
            <label class="form-label" for="password">
                Password <span class="required">*</span>
            </label>
            <div class="password-wrapper">
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-input <?= isset($errors['password']) ? 'has-error' : '' ?>"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                >
                <button type="button" class="password-toggle" data-target="password" aria-label="Show password">Show</button>
            </div>
            <?php if (isset($errors['password'])): ?>
                <span class="form-error"><?= htmlspecialchars($errors['password']) ?></span>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-block">Sign In</button>
    </form>

    <div class="card-footer">
        Don't have an account? <a href="/student-auth/auth/register.php">Create one</a>
    </div>
</div>


<?php require_once __DIR__ . '/../includes/footer.php';
 ?>