<?php
session_start();
require_once __DIR__ . '/core/config.php';
if (!isset($_SESSION['user'])) header('Location: login.php');
$id = $_GET['id'] ?? null;
if ($id) {
    $pdo = getPDO();
    // capture full vehicle info for audit before deletion
    $s = $pdo->prepare('SELECT * FROM vehicles WHERE id=?'); 
    $s->execute([$id]); 
    $before = $s->fetch();
    
    if ($before) {
        // Delete image file if exists
        if (!empty($before['image_path']) && is_file(__DIR__ . '/' . $before['image_path'])) {
            @unlink(__DIR__ . '/' . $before['image_path']);
        }
        
        // Delete vehicle record
        $stmt = $pdo->prepare('DELETE FROM vehicles WHERE id = ?');
        $stmt->execute([$id]);
        
        // Log deletion with full vehicle data
        add_audit_with_diff($pdo, 'Vehicle Deleted', $before, null, $id);
        SheetsSync::push('vehicles');
    }
}
header('Location: vehicles.php');
exit;