<?php
session_start();
require_once __DIR__ . '/core/config.php';
if (!isset($_SESSION['user'])) header('Location: login.php');

// Handle clearing session error via AJAX
if (isset($_GET['clear_error'])) {
    unset($_SESSION['validation_error']);
    echo 'cleared';
    exit();
}

$pdo = getPDO();
$id = $_GET['id'] ?? null;
if (!$id) { header('Location: vehicles.php'); exit; }
$stmt = $pdo->prepare('SELECT * FROM vehicles WHERE id = ?');
$stmt->execute([$id]);
$v = $stmt->fetch();
if (!$v) { header('Location: vehicles.php'); exit; }

// When vehicle is Reserved, get buyer name from the linked customer record
$reservation = null;
$reservationBuyerName = '';
if ($v['status'] === 'Reserved') {
    $resStmt = $pdo->prepare('
        SELECT r.*, c.first_name, c.middle_name, c.last_name
        FROM reservations r
        LEFT JOIN customers c ON c.id = r.customer_id
        WHERE r.vehicle_id = ?
        ORDER BY r.created_at DESC
        LIMIT 1
    ');
    $resStmt->execute([$id]);
    $reservation = $resStmt->fetch();
    if ($reservation) {
        $reservationBuyerName = trim(
            ($reservation['first_name'] ?? '') . ' ' .
            ($reservation['middle_name'] ?? '') . ' ' .
            ($reservation['last_name'] ?? '')
        );
        $reservationBuyerName = preg_replace('/\s+/', ' ', $reservationBuyerName);
    }
}

// Re-populate sale form values after validation errors
$old = [];
if (isset($_SESSION['sale_mark_old']) && is_array($_SESSION['sale_mark_old'])) {
    $oldTs = (int)($_SESSION['sale_mark_old']['ts'] ?? 0);
    if ($oldTs > 0 && (time() - $oldTs) <= 3600) {
        $old = (array)($_SESSION['sale_mark_old']['data'] ?? []);
    } else {
        unset($_SESSION['sale_mark_old']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        showValidationError('CSRF token validation failed. Please refresh the page and try again.');
        exit();
    }
    
    $buyer = $_POST['buyer_name'] ?? '';
    $price = $_POST['sale_price'] ?? $v['selling_price'];
    $date = $_POST['sale_date'] ?? date('Y-m-d');
    $method = $_POST['payment_method'] ?? 'Cash';

    // Persist user input in case of validation errors
    $_SESSION['sale_mark_old'] = [
        'ts' => time(),
        'data' => [
            'buyer_name' => $buyer,
            'sale_price' => $price,
            'sale_date' => $date,
            'payment_method' => $method,
        ],
    ];
    
    // Validate and sanitize input
    try {
        $buyer = validateRequired($buyer, 'Buyer Name');
        $buyer = validateString($buyer, 'Buyer Name', 2, 200);
        
        $price = validatePrice($price);
        
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            throw new InvalidArgumentException("Invalid sale date format");
        }
        
        $allowedMethods = ['Cash', 'Credit Card', 'Financing', 'Bank Transfer'];
        if (!in_array($method, $allowedMethods)) {
            $method = 'Cash';
        }
        
    } catch (InvalidArgumentException $e) {
        showValidationError($e->getMessage());
        exit();
    }
    
    $ins = $pdo->prepare('INSERT INTO sales (vehicle_id, buyer_name, sale_price, sale_date, payment_method) VALUES (?,?,?,?,?)');
    $ins->execute([$id, $buyer, $price, $date, $method]);
    $saleId = $pdo->lastInsertId();

    // ── Link to existing customer, never create a duplicate ───────
    // Priority 1: vehicle was Reserved → use that reservation's customer_id
    // Priority 2: look up customer by name in customers table
    // Priority 3: only create a new customer if truly not found
    $customerId = null;

    if ($v['status'] === 'Reserved' && !empty($reservation['customer_id'])) {
        // Already have the customer from the reservation
        $customerId = $reservation['customer_id'];

    } else {
        // Try to find existing customer by matching full name
        $nameParts = preg_split('/\s+/', trim((string)$buyer));
        $first  = $nameParts[0] ?? '';
        $last   = count($nameParts) >= 2 ? end($nameParts) : '';
        $middle = count($nameParts) >= 3
            ? implode(' ', array_slice($nameParts, 1, -1))
            : '';

        // Search by first + last name (case-insensitive)
        $findStmt = $pdo->prepare("
            SELECT id FROM customers
            WHERE LOWER(first_name) = LOWER(?)
              AND LOWER(last_name)  = LOWER(?)
            LIMIT 1
        ");
        $findStmt->execute([$first, $last]);
        $existing = $findStmt->fetchColumn();

        if ($existing) {
            // Found — reuse existing customer
            $customerId = $existing;
        } else {
            // Not found — create new customer only now
            $pdo->prepare("
                INSERT INTO customers (first_name, middle_name, last_name)
                VALUES (?, ?, ?)
            ")->execute([$first, $middle, $last]);
            $customerId = $pdo->lastInsertId();
        }
    }
    
    $upd = $pdo->prepare('UPDATE vehicles SET status = ? WHERE id = ?');
    $upd->execute(['Sold', $id]);
    
    // Enhanced audit logging with complete sale and vehicle data
    $saleData = [
        'sale_id'     => $saleId,
        'vehicle_id'  => $id,
        'customer_id' => $customerId,
        'vehicle_info' => [
            'brand'        => $v['brand'],
            'model'        => $v['model'],
            'year'         => $v['year'],
            'plate_number' => $v['plate_number'],
        ],
        'buyer_name'     => $buyer,
        'sale_price'     => $price,
        'sale_date'      => $date,
        'payment_method' => $method,
        'previous_status' => $v['status'],
        'new_status'      => 'Sold',
    ];
    add_audit($pdo, 'Sale Created', json_encode($saleData));
    SheetsSync::push('all');
    unset($_SESSION['sale_mark_old']);
    header('Location: vehicles.php'); exit;
}
require 'core/header.php';
displayValidationErrorIfExists();
?>
<link rel="stylesheet" href="sale_mark.css">
<h3 class="lux-title mb-4">Mark Vehicle as Sold</h3>

<div class="card lux-card mb-4">
  <div class="card-body p-4">

    <h5 class="lux-vehicle">
      <?php echo htmlspecialchars($v['brand'].' '.$v['model']); ?>
    </h5>

    <form method="post" id="saleForm">
      <?= getCSRFInput() ?>

      <div class="mb-3">
        <label>Buyer Name</label>
        <input
          name="buyer_name"
          class="form-control"
          value="<?php echo htmlspecialchars($old['buyer_name'] ?? $reservationBuyerName ?? '', ENT_QUOTES, 'UTF-8'); ?>"
          required
        >
      </div>

      <div class="mb-3">
        <label>Sale Price</label>
        <input name="sale_price"
               class="form-control"
               value="<?php
                   $salePrice = $old['sale_price'] ?? $v['selling_price'];
                   echo htmlspecialchars($salePrice, ENT_QUOTES, 'UTF-8');
               ?>"
               type="number"
               step="0.01"
               required>
      </div>

      <div class="mb-3">
        <label>Sale Date</label>
        <input name="sale_date"
               class="form-control"
               type="date"
               value="<?php
                   $saleDate = $old['sale_date'] ?? date('Y-m-d');
                   echo htmlspecialchars($saleDate, ENT_QUOTES, 'UTF-8');
               ?>"
               required>
      </div>

      <div class="mb-4">
        <label>Payment Method</label>
        <select name="payment_method" class="form-select" required>
          <?php $oldMethod = $old['payment_method'] ?? 'Cash'; ?>
          <option value="Cash" <?php echo $oldMethod === 'Cash' ? 'selected' : ''; ?>>Cash</option>
          <option value="Credit Card" <?php echo $oldMethod === 'Credit Card' ? 'selected' : ''; ?>>Credit Card</option>
          <option value="Financing" <?php echo $oldMethod === 'Financing' ? 'selected' : ''; ?>>Financing</option>
          <option value="Trade In" <?php echo $oldMethod === 'Trade In' ? 'selected' : ''; ?>>Trade In</option>
        </select>
      </div>

      <button class="btn btn-gold w-100" type="submit" id="confirmSaleBtn">
        Confirm Sale
      </button>
      <button type="button" class="btn btn-gold w-100" id="cancelSaleBtn">Cancel</button>

    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('saleForm');
  var cancelBtn = document.getElementById('cancelSaleBtn');

  function showActionModal(options) {
    var existing = document.getElementById('actionConfirmModal');
    if (existing) {
      existing.remove();
    }

    var title = options.title || 'Please Confirm';
    var message = options.message || '';
    var confirmText = options.confirmText || 'OK';
    var cancelText = options.cancelText || 'Cancel';
    var onConfirm = typeof options.onConfirm === 'function' ? options.onConfirm : function () {};

    var modalHTML =
      '<div id="actionConfirmModal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999;">' +
        '<div style="background:white;padding:30px;border-radius:10px;max-width:420px;width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);">' +
          '<h3 style="color:#d4af37;margin-bottom:15px;">' + title.replace(/</g, '&lt;') + '</h3>' +
          '<p style="color:#444;margin-bottom:25px;line-height:1.5;">' + message.replace(/</g, '&lt;') + '</p>' +
          '<div style="display:flex;gap:10px;justify-content:center;">' +
            '<button id="actionConfirmYes" style="background:#d4af37;color:#000;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;min-width:110px;">' + confirmText + '</button>' +
            '<button id="actionConfirmNo" style="background:#ccc;color:#333;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;min-width:110px;">' + cancelText + '</button>' +
          '</div>' +
        '</div>' +
      '</div>';

    document.body.insertAdjacentHTML('beforeend', modalHTML);

    var root = document.getElementById('actionConfirmModal');
    var yesBtn = document.getElementById('actionConfirmYes');
    var noBtn = document.getElementById('actionConfirmNo');

    function close() {
      if (root) {
        root.remove();
      }
    }

    yesBtn.addEventListener('click', function () {
      close();
      onConfirm();
    });

    noBtn.addEventListener('click', function () {
      close();
    });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      showActionModal({
        title: 'Confirm Sale',
        message: 'Are you sure you want to mark this vehicle as SOLD?',
        confirmText: 'Yes, Mark as Sold',
        cancelText: 'No, Keep Editing',
        onConfirm: function () {
          form.submit();
        }
      });
    });
  }

  if (cancelBtn) {
    cancelBtn.addEventListener('click', function (e) {
      e.preventDefault();
      showActionModal({
        title: 'Cancel Sale',
        message: 'Cancel and go back without saving this sale?',
        confirmText: 'Yes, Cancel',
        cancelText: 'No, Stay Here',
        onConfirm: function () {
          window.location.href = 'vehicles.php';
        }
      });
    });
  }
});
</script>
<?php require 'core/footer.php'; ?>