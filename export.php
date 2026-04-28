<?php
session_start();
require_once __DIR__ . '/core/config.php';

// Must be logged in
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$type = $_GET['type'] ?? '';
$allowed = ['sales', 'vehicles', 'customers', 'audit'];

if (!in_array($type, $allowed)) {
    die('Invalid export type.');
}

// Audit-only pages require admin
$isAdmin = isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
if ($type === 'audit' && !$isAdmin) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getPDO();

// ─────────────────────────────────────────────
// Helper: send CSV headers and stream rows
// ─────────────────────────────────────────────
function streamCSV(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens it correctly
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

// ─────────────────────────────────────────────
// SALES
// ─────────────────────────────────────────────
if ($type === 'sales') {
    $stmt = $pdo->prepare("
        SELECT s.id, v.brand, v.model, v.plate_number,
               s.buyer_name, s.sale_price, s.sale_date,
               s.payment_method
        FROM sales s
        JOIN vehicles v ON v.id = s.vehicle_id
        ORDER BY s.sale_date DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    add_audit($pdo, 'CSV Export', 'Exported: Sales');

    streamCSV(
        'sales_export_' . date('Y-m-d') . '.csv',
        ['Sale ID','Brand','Model','Plate Number','Buyer Name','Sale Price','Sale Date','Payment Method'],
        $rows
    );
}

// ─────────────────────────────────────────────
// VEHICLES
// ─────────────────────────────────────────────
if ($type === 'vehicles') {
    $stmt = $pdo->prepare("
        SELECT id, year, brand, model, transmission, mileage,
               plate_number, body_type, color, engine_type,
               fuel_type, status, purchase_price, selling_price,
               created_at
        FROM vehicles
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    add_audit($pdo, 'CSV Export', 'Exported: Vehicles');

    streamCSV(
        'vehicles_export_' . date('Y-m-d') . '.csv',
        ['ID','Year','Brand','Model','Transmission','Mileage','Plate Number','Body Type','Color','Engine Type','Fuel Type','Status','Purchase Price','Selling Price','Date Added'],
        $rows
    );
}

// ─────────────────────────────────────────────
// CUSTOMERS
// ─────────────────────────────────────────────
if ($type === 'customers') {
    // Base customers
    $stmt = $pdo->prepare("SELECT * FROM customers ORDER BY date_created DESC");
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $csvRows = [];

    foreach ($customers as $c) {
        $first  = $c['first_name']  ?? '';
        $middle = $c['middle_name'] ?? '';
        $last   = $c['last_name']   ?? '';
        $fullName = trim(preg_replace('/\s+/', ' ', "$first $middle $last"));

        // Count transactions
        $rStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE customer_id = ?");
        $rStmt->execute([$c['id']]);
        $reservations = $rStmt->fetchColumn();

        $vStmt = $pdo->prepare("SELECT COUNT(*) FROM viewing_schedules WHERE customer_id = ?");
        $vStmt->execute([$c['id']]);
        $viewings = $vStmt->fetchColumn();

        $sStmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(sale_price),0) FROM sales WHERE buyer_name = ?");
        $sStmt->execute([$fullName]);
        $saleRow = $sStmt->fetch(PDO::FETCH_NUM);

        $csvRows[] = [
            $c['id'],
            $last,
            $first,
            $middle,
            $c['date_created'] ?? '',
            $reservations,
            $viewings,
            $saleRow[0],
            number_format((float)$saleRow[1], 2, '.', '')
        ];
    }

    add_audit($pdo, 'CSV Export', 'Exported: Customers');

    streamCSV(
        'customers_export_' . date('Y-m-d') . '.csv',
        ['ID','Last Name','First Name','Middle Name','Date Created','Reservations','Viewings','Sales Count','Total Sales Amount'],
        $csvRows
    );
}

// ─────────────────────────────────────────────
// AUDIT TRAILS (Admin only)
// ─────────────────────────────────────────────
if ($type === 'audit') {
    $where = [];
    $params = [];
    if (!empty($_GET['user']))      { $where[] = 'user_name LIKE ?'; $params[] = '%'.$_GET['user'].'%'; }
    if (!empty($_GET['action']))    { $where[] = 'action LIKE ?';    $params[] = '%'.$_GET['action'].'%'; }
    if (!empty($_GET['date_from'])) { $where[] = 'created_at >= ?';  $params[] = $_GET['date_from']; }
    if (!empty($_GET['date_to']))   { $where[] = 'created_at <= ?';  $params[] = $_GET['date_to'] . ' 23:59:59'; }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("SELECT id, created_at, user_name, action, detail, ip FROM audit_logs $where_sql ORDER BY created_at DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_NUM);

    add_audit($pdo, 'CSV Export', 'Exported: Audit Trails');

    streamCSV(
        'audit_export_' . date('Y-m-d') . '.csv',
        ['ID','Timestamp','User','Action','Detail','IP Address'],
        $rows
    );
}