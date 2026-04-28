<?php
session_start();
require_once __DIR__ . '/core/config.php';

// capture username before destroying session
$user = isset($_SESSION['user']) ? ($_SESSION['user']['name'] ?? null) : null;
$pdo = null;
try { $pdo = getPDO(); } catch (Exception $e) {}
if ($pdo) {
	try { add_audit($pdo, 'User Logout', json_encode(['user'=>$user])); } catch (Exception $e) {}
}

// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
