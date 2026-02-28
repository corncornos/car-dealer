<?php
session_start();
require_once __DIR__ . '/config.php';


// Redirect if already logged in
if (isset($_SESSION['user'])) header('Location: dashboard.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $pass = $_POST['password'] ?? '';
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($pass, $u['password'])) {
            unset($u['password']);
            $_SESSION['user'] = $u;

            // audit login
            try {
                add_audit($pdo, 'User Login', json_encode(['user_id'=>$u['id'],'email'=>$u['email']]));
            } catch (Exception $e) {}

            header('Location: dashboard.php');
            exit;
        } else {
            $err = 'Invalid credentials';
        }
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// hide navigation
$hide_nav = true;
require 'header.php';
?>
<link rel="stylesheet" href="login.css">

<div class="container">
    <div class="login-card">
        <!-- Logo -->
        <img src="images/AL4.png" class="logo" alt="Autoluxe Logo">

        <h2>Welcome Ka-Broom Broom 🚗💨</h2>
        <small>Ma'am Maye Only!!</small>

        <?php if($err): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="post">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>


    </div>
</div>

<?php require 'footer.php'; ?>