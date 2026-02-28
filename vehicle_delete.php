<?php
session_start();
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['user'])) header('Location: login.php');
$id = $_GET['id'] ?? null;
if ($id) {
    $pdo = getPDO();
    // capture info for audit
    $s = $pdo->prepare('SELECT * FROM vehicles WHERE id=?'); $s->execute([$id]); $before = $s->fetch();
    if (!empty($before['image_path']) && is_file(__DIR__ . '/' . $before['image_path'])) {
        @unlink(__DIR__ . '/' . $before['image_path']);
    }
    $stmt = $pdo->prepare('DELETE FROM vehicles WHERE id = ?');
    $stmt->execute([$id]);
    add_audit($pdo, 'Vehicle Deleted', json_encode(['id'=>$id,'before'=>$before]));
}
header('Location: vehicles.php');
exit;
