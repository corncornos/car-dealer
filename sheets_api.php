<?php
/**
 * sheets_api.php
 * ==============
 * REST endpoint polled by Google Apps Script inside your spreadsheet.
 * Returns JSON data for any table that has pending sync in the queue.
 *
 * Endpoints:
 *   GET sheets_api.php?token=XXX&action=pending
 *       → returns list of tables with pending syncs
 *
 *   GET sheets_api.php?token=XXX&action=data&table=vehicles
 *       → returns full table data as JSON rows
 *
 *   POST sheets_api.php?token=XXX&action=clear&table=vehicles
 *       → marks that table as synced (clears queue)
 */

require_once __DIR__ . '/core/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // needed for Apps Script

// ── Auth ──────────────────────────────────────────────────────
$token = $_GET['token'] ?? $_POST['token'] ?? '';
if (!hash_equals(SHEETS_API_TOKEN, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$table  = preg_replace('/[^a-z_]/', '', strtolower($_GET['table'] ?? $_POST['table'] ?? ''));

$pdo = getPDO();

// ── Ensure queue table exists ──────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS sync_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(50) NOT NULL,
    queued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(table_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── action=pending → which tables need syncing? ───────────────
if ($action === 'pending') {
    $stmt = $pdo->query("SELECT DISTINCT table_name FROM sync_queue ORDER BY table_name");
    $pending = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Cleanup stale entries older than 10 minutes
    $pdo->exec("DELETE FROM sync_queue WHERE queued_at < NOW() - INTERVAL 10 MINUTE");
    echo json_encode(['pending' => $pending, 'timestamp' => date('Y-m-d H:i:s')]);
    exit;
}

// ── action=data → return full table as 2D array ───────────────
if ($action === 'data' && $table) {
    $queries = [
        'vehicles' => "SELECT id, year, brand, model, transmission, mileage,
                              plate_number, body_type, color, engine_type,
                              fuel_type, status, purchase_price, selling_price,
                              notes, created_at
                       FROM vehicles ORDER BY created_at DESC",

        'sales'    => "SELECT s.id, v.brand, v.model, v.plate_number,
                              s.buyer_name, s.sale_price, s.sale_date,
                              s.payment_method, s.created_at
                       FROM sales s
                       JOIN vehicles v ON v.id = s.vehicle_id
                       ORDER BY s.sale_date DESC",

        'customers' => "SELECT id, last_name, first_name, middle_name, date_created
                        FROM customers ORDER BY date_created DESC",

        'reservations' => "SELECT r.id,
                                  CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,'')) AS customer_name,
                                  r.contact, r.reservation_payment,
                                  v.brand, v.model, v.plate_number, r.created_at
                           FROM reservations r
                           LEFT JOIN customers c ON c.id = r.customer_id
                           LEFT JOIN vehicles  v ON v.id = r.vehicle_id
                           ORDER BY r.created_at DESC",
    ];

    if (!isset($queries[$table])) {
        echo json_encode(['error' => "Unknown table: $table"]);
        exit;
    }

    $stmt = $pdo->query($queries[$table]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build header row from column names
    $headers = empty($rows) ? [] : array_map(
        fn($h) => ucwords(str_replace('_', ' ', $h)),
        array_keys($rows[0])
    );

    // Convert all values to strings (Sheets-safe)
    $data = [$headers];
    foreach ($rows as $row) {
        $data[] = array_map(fn($v) => $v === null ? '' : (string)$v, array_values($row));
    }

    echo json_encode(['table' => $table, 'rows' => $data, 'count' => count($rows)]);
    exit;
}

// ── action=clear → remove table from queue ────────────────────
if ($action === 'clear' && $table) {
    $pdo->prepare("DELETE FROM sync_queue WHERE table_name = ?")->execute([$table]);
    echo json_encode(['cleared' => $table, 'timestamp' => date('Y-m-d H:i:s')]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);