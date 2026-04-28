<?php
/**
 * sheets_worker.php
 * -----------------
 * Background worker — called internally by sheets_sync().
 * NOT meant to be accessed directly by users.
 * Protected by a shared-secret token.
 */

require_once __DIR__ . '/core/config.php';

// ── Security: verify internal token ──────────────────────────
$token = $_GET['token'] ?? '';
if (!hash_equals(sheets_worker_token(), $token)) {
    http_response_code(403);
    exit('Forbidden');
}

$table = strtolower(trim($_GET['table'] ?? 'all'));

// ── Table definitions ─────────────────────────────────────────
$TABLES = [
    'vehicles' => [
        'tab' => 'Vehicles',
        'sql' => "SELECT id, year, brand, model, transmission, mileage,
                         plate_number, body_type, color, engine_type,
                         fuel_type, status, purchase_price, selling_price,
                         notes, created_at
                  FROM vehicles ORDER BY created_at DESC",
    ],
    'sales' => [
        'tab' => 'Sales',
        'sql' => "SELECT s.id, v.brand, v.model, v.plate_number,
                         s.buyer_name, s.sale_price, s.sale_date,
                         s.payment_method, s.created_at
                  FROM sales s
                  JOIN vehicles v ON v.id = s.vehicle_id
                  ORDER BY s.sale_date DESC",
    ],
    'customers' => [
        'tab' => 'Customers',
        'sql' => "SELECT id, last_name, first_name, middle_name, date_created
                  FROM customers ORDER BY date_created DESC",
    ],
    'reservations' => [
        'tab' => 'Reservations',
        'sql' => "SELECT r.id,
                         CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                         r.contact, r.reservation_payment,
                         v.brand, v.model, v.plate_number, r.created_at
                  FROM reservations r
                  LEFT JOIN customers c ON c.id = r.customer_id
                  LEFT JOIN vehicles  v ON v.id = r.vehicle_id
                  ORDER BY r.created_at DESC",
    ],
    'all' => null, // handled below
];

// ── Run sync ──────────────────────────────────────────────────
try {
    $pdo        = getPDO();
    $apiToken   = sheets_get_token();
    $sid        = sheets_get_spreadsheet_id($apiToken, SHEETS_SPREADSHEET);
    $tabIds     = sheets_get_tab_ids($apiToken, $sid);

    $toSync = ($table === 'all') ? array_filter($TABLES, fn($v) => $v !== null) : [$table => $TABLES[$table] ?? null];

    foreach ($toSync as $key => $def) {
        if (!$def) continue;
        sheets_ensure_tab($apiToken, $sid, $def['tab'], $tabIds);
        $rows = sheets_rows_from_pdo($pdo, $def['sql']);
        sheets_write_tab($apiToken, $sid, $def['tab'], $rows);
        $sheetId = $tabIds[$def['tab']] ?? 0;
        sheets_format_header($apiToken, $sid, $sheetId);
    }

} catch (Exception $e) {
    // Log silently — never break the main app
    error_log('[sheets_worker] ' . $e->getMessage());
}

http_response_code(200);
echo 'ok';