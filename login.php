<?php
session_start();
require_once __DIR__ . '/core/config.php';

// Handle clearing session error via AJAX
if (isset($_GET['clear_error'])) {
    unset($_SESSION['validation_error']);
    echo 'cleared';
    exit();
}

// Check for validation errors and display them
displayValidationErrorIfExists();

// Redirect if already logged in
if (isset($_SESSION['user'])) header('Location: dashboard.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        showValidationError('CSRF token validation failed. Please refresh the page and try again.');
        exit();
    }
    
    $email = $_POST['email'] ?? '';
    $pass = $_POST['password'] ?? '';
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($pass, $u['password'])) {
            unset($u['password']);
            
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            $_SESSION['user'] = $u;

            // audit successful login
            add_audit_login_attempt($pdo, $u['email'], true);

            header('Location: dashboard.php');
            exit;
        } else {
            $err = 'Invalid credentials';
            // audit failed login attempt
            add_audit_login_attempt($pdo, $email, false, 'Invalid credentials');
        }
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// hide navigation
$hide_nav = true;
require 'core/header.php';
?>
<link rel="stylesheet" href="assets/css/login.css">

<div class="container">
    <div class="login-card">
        <!-- Logo -->
        <img src="images/AL4.png" class="logo" alt="Autoluxe Logo">

        <h2>Welcome Ka-BroomBroom!💨</h2>
    

        <?php if($err): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="post">
            <?= getCSRFInput() ?>
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>


    </div>
</div>

<?php require 'core/footer.php'; ?>