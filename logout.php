<?php
session_start();
require_once __DIR__ . '/config.php';
// capture username before destroying session
$user = isset($_SESSION['user']) ? ($_SESSION['user']['name'] ?? null) : null;
$pdo = null;
try { $pdo = getPDO(); } catch (Exception $e) {}
if ($pdo) {
	try { add_audit($pdo, 'User Logout', json_encode(['user'=>$user])); } catch (Exception $e) {}
}
session_unset();
session_destroy();
header('Location: login.php');
exit;
