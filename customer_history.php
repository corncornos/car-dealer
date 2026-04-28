<?php
if (session_status() == PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/core/config.php';
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$pdo = getPDO();

// ======================= POST ACTIONS =======================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        showValidationError('CSRF token validation failed. Please refresh and try again.');
        header('Location: customer_history.php');
        exit;
    }

    // --- Delete single customer ---
    if (isset($_POST['delete_customer_id'])) {
        $customerId = intval($_POST['delete_customer_id']);
        if ($customerId > 0) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE reservations SET customer_id = NULL WHERE customer_id = ?")
                    ->execute([$customerId]);
                $pdo->prepare("UPDATE viewing_schedules SET customer_id = NULL WHERE customer_id = ?")
                    ->execute([$customerId]);
                $pdo->prepare("DELETE FROM customers WHERE id = ?")
                    ->execute([$customerId]);
                $pdo->commit();
                add_audit($pdo, 'Customer Deleted', json_encode(['customer_id' => $customerId]));
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                showValidationError('Error deleting customer: ' . $e->getMessage());
            }
        }
        header('Location: customer_history.php');
        exit;
    }

    // --- Truncate all customers ---
    if (isset($_POST['truncate_customers'])) {
        try {
            $pdo->beginTransaction();
            $pdo->exec("UPDATE reservations SET customer_id = NULL");
            $pdo->exec("UPDATE viewing_schedules SET customer_id = NULL");
            $pdo->exec("DELETE FROM customers");
            $pdo->commit();
            // DDL (ALTER TABLE) causes an implicit commit in MySQL — run outside the transaction
            $pdo->exec("ALTER TABLE customers AUTO_INCREMENT = 1");
            add_audit($pdo, 'All Customers Deleted', 'Truncated customers table');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            showValidationError('Error clearing customers: ' . $e->getMessage());
        }
        header('Location: customer_history.php');
        exit;
    }
}

// ======================= FETCH DATA =======================

$stmt = $pdo->prepare("SELECT * FROM customers ORDER BY date_created DESC");
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$reservationStmt = $pdo->prepare("
    SELECT 'Reservation' AS type, r.created_at AS tx_date,
           v.brand, v.model, v.plate_number, r.reservation_payment AS amount
    FROM reservations r
    LEFT JOIN vehicles v ON r.vehicle_id = v.id
    WHERE r.customer_id = :customer_id
    ORDER BY r.created_at DESC
");

$viewingStmt = $pdo->prepare("
    SELECT 'Viewing' AS type, vs.viewing_date AS tx_date,
           v.brand, v.model, v.plate_number, NULL AS amount
    FROM viewing_schedules vs
    LEFT JOIN vehicles v ON vs.vehicle_id = v.id
    WHERE vs.customer_id = :customer_id
    ORDER BY vs.viewing_date DESC
");

$salesStmt = $pdo->prepare("
    SELECT 'Sale' AS type, s.sale_date AS tx_date,
           v.brand, v.model, v.plate_number, s.sale_price AS amount
    FROM sales s
    LEFT JOIN vehicles v ON s.vehicle_id = v.id
    WHERE s.buyer_name = :full_name
    ORDER BY s.sale_date DESC
");

require __DIR__ . '/core/header.php';
?>
<link rel="stylesheet" href="assets/css/export_button.css">
<a href="export.php?type=customers" class="btn-export">⬇ Export Customers CSV</a>
<link rel="stylesheet" href="assets/css/customer_history.css">

<style>
.ch-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    gap: 12px;
    flex-wrap: wrap;
}
.ch-toolbar h1 { margin: 0; }
.btn-delete-all {
    background: #c0392b;
    color: #fff;
    border: none;
    padding: 9px 22px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.4px;
    transition: background 0.2s;
}
.btn-delete-all:hover { background: #a93226; }
.btn-delete-row {
    background: transparent;
    color: #c0392b;
    border: 1.5px solid #c0392b;
    padding: 4px 12px;
    border-radius: 5px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: background 0.2s, color 0.2s;
    white-space: nowrap;
}
.btn-delete-row:hover { background: #c0392b; color: #fff; }
#chConfirmModal {
    display: none;
    position: fixed; top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.52);
    align-items: center; justify-content: center;
    z-index: 99999;
}
#chConfirmModal.active { display: flex; }
.ch-modal-box {
    background: #fff;
    padding: 32px 30px 26px;
    border-radius: 10px;
    max-width: 420px; width: 92%;
    text-align: center;
    box-shadow: 0 6px 28px rgba(0,0,0,0.22);
}
.ch-modal-box h3 { color: #c0392b; margin: 0 0 12px; font-size: 20px; }
.ch-modal-box p  { color: #444; margin: 0 0 24px; line-height: 1.55; }
.ch-modal-actions { display: flex; gap: 12px; justify-content: center; }
.ch-modal-actions .btn-confirm {
    background: #c0392b; color: #fff;
    border: none; padding: 10px 24px;
    border-radius: 6px; cursor: pointer;
    font-size: 14px; font-weight: 600; min-width: 110px;
}
.ch-modal-actions .btn-cancel {
    background: #ccc; color: #333;
    border: none; padding: 10px 24px;
    border-radius: 6px; cursor: pointer;
    font-size: 14px; min-width: 110px;
}
.ch-modal-actions .btn-confirm:hover { background: #a93226; }
.ch-modal-actions .btn-cancel:hover  { background: #b0b0b0; }
</style>

<div class="container">
    <div class="ch-toolbar">
        <h1>Customer History</h1>
        <?php if (!empty($customers)): ?>
        <button class="btn-delete-all"
                onclick="chConfirm(
                    'Delete All Customers',
                    'This will permanently delete ALL <?= count($customers) ?> customer record(s). This cannot be undone.',
                    'deleteAllForm'
                )">
            Delete All Customers
        </button>
        <?php endif; ?>
    </div>

    <form id="deleteAllForm" method="POST" style="display:none;">
        <?= getCSRFInput() ?>
        <input type="hidden" name="truncate_customers" value="1">
    </form>

    <?php if (empty($customers)): ?>
        <p style="color:#888;font-style:italic;">No customers on record.</p>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr>
                <th>Last Name</th>
                <th>First Name</th>
                <th>Middle Name</th>
                <th>Transactions</th>
                <th style="width:90px;text-align:center;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($customers as $c): ?>
            <?php
                $first    = $c['first_name']  ?? '';
                $middle   = $c['middle_name'] ?? '';
                $last     = $c['last_name']   ?? '';
                $fullName = trim(preg_replace('/\s+/', ' ', "$first $middle $last"));

                $transactions = [];

                $reservationStmt->execute([':customer_id' => $c['id']]);
                $transactions = array_merge($transactions, $reservationStmt->fetchAll(PDO::FETCH_ASSOC));

                $viewingStmt->execute([':customer_id' => $c['id']]);
                $transactions = array_merge($transactions, $viewingStmt->fetchAll(PDO::FETCH_ASSOC));

                if ($fullName !== '') {
                    $salesStmt->execute([':full_name' => $fullName]);
                    $transactions = array_merge($transactions, $salesStmt->fetchAll(PDO::FETCH_ASSOC));
                }

                usort($transactions, function($a, $b) {
                    $da = isset($a['tx_date']) && $a['tx_date'] !== '' ? strtotime($a['tx_date']) : 0;
                    $db = isset($b['tx_date']) && $b['tx_date'] !== '' ? strtotime($b['tx_date']) : 0;
                    return $db <=> $da;
                });

                $safeLabel = htmlspecialchars($fullName ?: "Customer #{$c['id']}", ENT_QUOTES);
            ?>
            <tr>
                <td><?= htmlspecialchars($last) ?></td>
                <td><?= htmlspecialchars($first) ?></td>
                <td><?= htmlspecialchars($middle) ?></td>
                <td>
                    <?php if (empty($transactions)): ?>
                        <em>No transactions yet</em>
                    <?php else: ?>
                        <ul style="margin:0;padding-left:18px;">
                            <?php foreach ($transactions as $t): ?>
                                <li>
                                    <strong><?= htmlspecialchars($t['type']) ?></strong>
                                    <?php if (!empty($t['tx_date'])): ?>
                                        on <?= htmlspecialchars($t['tx_date']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($t['brand']) || !empty($t['model']) || !empty($t['plate_number'])): ?>
                                        &ndash; <?= htmlspecialchars(trim(($t['brand'] ?? '') . ' ' . ($t['model'] ?? ''))) ?>
                                        <?php if (!empty($t['plate_number'])): ?>
                                            (Plate: <?= htmlspecialchars($t['plate_number']) ?>)
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($t['amount'] !== null): ?>
                                        &ndash; Amount: &curren;<?= number_format((float)$t['amount'], 2) ?>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <form id="delForm<?= $c['id'] ?>" method="POST" style="display:none;">
                        <?= getCSRFInput() ?>
                        <input type="hidden" name="delete_customer_id" value="<?= $c['id'] ?>">
                    </form>
                    <button class="btn-delete-row"
                            onclick="chConfirm(
                                'Delete Customer',
                                'Delete <?= $safeLabel ?>? This cannot be undone.',
                                'delForm<?= $c['id'] ?>'
                            )">
                        Delete
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Shared confirmation modal -->
<div id="chConfirmModal">
    <div class="ch-modal-box">
        <h3 id="chModalTitle"></h3>
        <p  id="chModalMessage"></p>
        <div class="ch-modal-actions">
            <button class="btn-confirm" id="chModalConfirm">Yes, Delete</button>
            <button class="btn-cancel"  onclick="chCloseModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
var _chTargetFormId = null;

function chConfirm(title, message, formId) {
    _chTargetFormId = formId;
    document.getElementById('chModalTitle').textContent   = title;
    document.getElementById('chModalMessage').textContent = message;
    document.getElementById('chConfirmModal').classList.add('active');
}

function chCloseModal() {
    document.getElementById('chConfirmModal').classList.remove('active');
    _chTargetFormId = null;
}

document.getElementById('chModalConfirm').addEventListener('click', function () {
    if (_chTargetFormId) {
        document.getElementById(_chTargetFormId).submit();
    }
    chCloseModal();
});

// Close on backdrop click
document.getElementById('chConfirmModal').addEventListener('click', function (e) {
    if (e.target === this) chCloseModal();
});
</script>

<?php require __DIR__ . '/core/footer.php'; ?>
