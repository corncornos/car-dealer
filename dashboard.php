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

// ===== POST ACTIONS (run before any output) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        showValidationError('CSRF token validation failed. Please refresh the page and try again.');
        exit();
    }
    
    if(isset($_POST['delete_viewing_id'])){
        $id = intval($_POST['delete_viewing_id']);
        $stmt = $pdo->prepare("DELETE FROM viewing_schedules WHERE id=?");
        $stmt->execute([$id]);
        header("Location: dashboard.php");
        exit();
    }

    if(isset($_POST['reserve_single'])){
        $id = intval($_POST['reserve_single']);
        $stmt = $pdo->prepare("UPDATE vehicles SET status='Reserved' WHERE id=?");
        $stmt->execute([$id]);
        header("Location: dashboard.php"); exit();
    }

    if(isset($_POST['cancel_reserved'])){
        $id = intval($_POST['cancel_reserved']);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM reservations WHERE vehicle_id=?");
        $stmt->execute([$id]);
        $stmt = $pdo->prepare("DELETE FROM viewing_schedules WHERE vehicle_id=?");
        $stmt->execute([$id]);
        $pdo->commit();
        $stmt = $pdo->prepare("UPDATE vehicles 
            SET status='Available', viewing_date = NULL, viewing_person = NULL
            WHERE id=?");
        $stmt->execute([$id]);
        header("Location: dashboard.php"); exit();
    }

    if(isset($_POST['schedule_viewing'])){
        // Handled fully in the second POST block below; redirect to avoid
        // double-processing on the rare case this early block fires first.
        // (The full transactional handler at the second POST block is authoritative.)
    }

    if(isset($_POST['priority_single'])){
        $id = intval($_POST['priority_single']);
        $stmt = $pdo->prepare("UPDATE vehicles SET status='Priority' WHERE id=?");
        $stmt->execute([$id]);
        header("Location: dashboard.php"); exit();
    }

    if(isset($_POST['cancel_priority'])){
        $id = intval($_POST['cancel_priority']);
        $stmt = $pdo->prepare("UPDATE vehicles SET status='Available' WHERE id=?");
        $stmt->execute([$id]);
        header("Location: dashboard.php"); exit();
    }

    // Handle reservation creation
    if(isset($_POST['create_reservation'])){
        try {
            $firstName = validateRequired($_POST['first_name'] ?? '', 'First Name');
            $firstName = validateString($firstName, 'First Name', 2, 100);
            
            $middleName = validateString($_POST['middle'] ?? '', 'Middle Name', 0, 100);
            
            $lastName = validateRequired($_POST['last_name'] ?? '', 'Last Name');
            $lastName = validateString($lastName, 'Last Name', 2, 100);
            
            $contact = validateRequired($_POST['contact'] ?? '', 'Contact');
            $contact = validatePhone($contact);
            
            $payment = validateRequired($_POST['reservation_payment'] ?? '', 'Reservation Payment');
            $payment = validateString($payment, 'Reservation Payment', 1, 200);

            // Viewing date is optional; validate only when provided
            $viewingDate = trim($_POST['viewing_date'] ?? '');
            $today = date('Y-m-d');
            if ($viewingDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $viewingDate) || $viewingDate < $today)) {
                throw new InvalidArgumentException('Viewing Date must be today or a future date.');
            }
            if ($viewingDate === '') {
                $viewingDate = null;
            }
            
            $vehicleId = validateNumber($_POST['vehicle_id'] ?? '0', 'Vehicle ID', 1, 999999);
            $vehicleId = intval($vehicleId);
            
            // Verify vehicle exists and is available
            $vehicleCheck = $pdo->prepare('SELECT id FROM vehicles WHERE id = ? AND status IN (?,?)');
            $vehicleCheck->execute([$vehicleId, 'Available', 'Priority']);
            if (!$vehicleCheck->fetch()) {  
                throw new InvalidArgumentException('Vehicle not available for reservation');
            }
            
        } catch (InvalidArgumentException $e) {
            showValidationError($e->getMessage());
            exit();
        }
        
        try {
            $pdo->beginTransaction();

            // Upsert customer – find existing or create new record
            $checkCust = $pdo->prepare("
                SELECT id FROM customers
                WHERE first_name = ? AND middle_name = ? AND last_name = ?
                LIMIT 1
            ");
            $checkCust->execute([$firstName, $middleName, $lastName]);
            $customerId = $checkCust->fetchColumn();

            if (!$customerId) {
                $custStmt = $pdo->prepare("
                    INSERT INTO customers (first_name, middle_name, last_name)
                    VALUES (?, ?, ?)
                ");
                $custStmt->execute([$firstName, $middleName, $lastName]);
                $customerId = (int)$pdo->lastInsertId();
            }

            // Insert reservation linked to the customer record
            $stmt = $pdo->prepare("
                INSERT INTO reservations (vehicle_id, customer_id, contact, reservation_payment)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$vehicleId, $customerId, $contact, $payment]);

            $reservationId = $pdo->lastInsertId();

            // Only create a viewing schedule when a date was provided
            $viewingId = null;
            if ($viewingDate !== null) {
                $vsInsert = $pdo->prepare("
                    INSERT INTO viewing_schedules (vehicle_id, customer_id, viewing_date)
                    VALUES (?, ?, ?)
                ");
                $vsInsert->execute([$vehicleId, $customerId, $viewingDate]);
                $viewingId = $pdo->lastInsertId();

                // Remove all other viewing schedules for this vehicle
                $vsDelete = $pdo->prepare("
                    DELETE FROM viewing_schedules
                    WHERE vehicle_id = ? AND id <> ?
                ");
                $vsDelete->execute([$vehicleId, $viewingId]);
            }
            
            // Update vehicle status to Reserved
            $reserveStmt = $pdo->prepare("UPDATE vehicles SET status='Reserved' WHERE id=?");
            $reserveStmt->execute([$vehicleId]);
            
            $pdo->commit();
            
            // Log reservation creation
            $reservationData = [
                'reservation_id'  => $reservationId,
                'customer_id'     => $customerId,
                'vehicle_id'      => $vehicleId,
                'customer_name' => trim($firstName . ' ' . $middleName . ' ' . $lastName),
                'contact' => $contact,
                'reservation_payment' => $payment,
                'vehicle_status_changed' => 'Available -> Reserved',
                'viewing_date' => $viewingDate
            ];
            add_audit($pdo, 'Reservation Created', json_encode($reservationData));
            
            echo "<script>alert('Reservation created successfully!'); window.location.href='dashboard.php';</script>";
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error creating reservation: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
            exit();
        }
    }
}

// Stats
$stmt = $pdo->prepare('SELECT COUNT(*) FROM vehicles WHERE status IN (:status1, :status2, :status3)');
$stmt->execute([':status1' => 'Available', ':status2' => 'Reserved', ':status3' => 'Priority']);
$total = $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM vehicles WHERE status IN (:status1, :status3)');
$stmt->execute([':status1' => 'Available', ':status3' => 'Priority']);
$available = $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(purchase_price),0) FROM vehicles WHERE status IN (:status1, :status2, :status3)');
$stmt->execute([':status1' => 'Available', ':status2' => 'Reserved', ':status3' => 'Priority']);
$inventoryValue = $stmt->fetchColumn();

require __DIR__ . '/core/header.php';
displayValidationErrorIfExists();
?>
<?php /* duplicate POST processing removed, handled above */
if(isset($_POST['delete_viewing_id'])){
    $id = intval($_POST['delete_viewing_id']);
    $stmt = $pdo->prepare("DELETE FROM viewing_schedules WHERE id=:id");
    $stmt->execute([':id' => $id]);
    header("Location: dashboard.php");
    exit();
}

// ======================= HANDLE POST ACTIONS =======================

// Single Reserve
if(isset($_POST['reserve_single'])){
    $id = intval($_POST['reserve_single']);
    $stmt = $pdo->prepare("UPDATE vehicles SET status=:status WHERE id=:id");
    $stmt->execute([':status' => 'Reserved', ':id' => $id]);
    header("Location: dashboard.php"); exit();
}

// Cancel reservation (no JS conflict and explicit handling)
if(isset($_POST['cancel_reserved'])){
    $id = intval($_POST['cancel_reserved']);
    
    // Get reservation details before cancellation for audit
    $reservationStmt = $pdo->prepare("
        SELECT r.*, v.brand, v.model, v.plate_number,
               c.first_name, c.middle_name, c.last_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN customers c ON c.id = r.customer_id
        WHERE r.vehicle_id = ?
    ");
    $reservationStmt->execute([$id]);
    $reservation = $reservationStmt->fetch();
    
    $stmt = $pdo->prepare("UPDATE vehicles 
        SET status=:status, viewing_date = NULL, viewing_person = NULL
        WHERE id=:id");
    $stmt->execute([':status' => 'Available', ':id' => $id]);
    
    // Log reservation cancellation
    if ($reservation) {
        $cancellationData = [
            'reservation_id' => $reservation['id'],
            'vehicle_id' => $id,
            'vehicle_info' => [
                'brand' => $reservation['brand'],
                'model' => $reservation['model'],
                'plate_number' => $reservation['plate_number']
            ],
            'customer_name' => trim($reservation['first_name'] . ' ' . $reservation['middle_name'] . ' ' . $reservation['last_name']),
            'contact' => $reservation['contact'],
            'reservation_payment' => $reservation['reservation_payment'],
            'vehicle_status_changed' => 'Reserved -> Available'
        ];
        add_audit($pdo, 'Reservation Cancelled', json_encode($cancellationData));
    }
    
    header("Location: dashboard.php"); exit();
}


// ================= SCHEDULE VIEWING =================
if(isset($_POST['schedule_viewing'])){

    $vehicleId = !empty($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : null;
    $date = $_POST['viewing_date'];
    $firstName = trim($_POST['customer_first_name'] ?? '');
    $middleName = trim($_POST['customer_middle_name'] ?? '');
    $lastName = trim($_POST['customer_last_name'] ?? '');
    $today = date('Y-m-d');

    if($date >= $today && $firstName !== '' && $lastName !== ''){

        $pdo->beginTransaction();

        try {
            // Upsert customer
            $checkStmt = $pdo->prepare("SELECT id FROM customers WHERE first_name = ? AND middle_name = ? AND last_name = ?");
            $checkStmt->execute([$firstName, $middleName, $lastName]);
            $customerId = $checkStmt->fetchColumn();

            if (!$customerId) {
                $custStmt = $pdo->prepare("INSERT INTO customers (first_name, middle_name, last_name) VALUES (?, ?, ?)");
                $custStmt->execute([$firstName, $middleName, $lastName]);
                $customerId = (int)$pdo->lastInsertId();
            }

            // Insert viewing schedule linked to customer
            $stmt = $pdo->prepare("INSERT INTO viewing_schedules (vehicle_id, customer_id, viewing_date) VALUES (?, ?, ?)");
            $stmt->execute([$vehicleId, $customerId, $date]);

            $pdo->commit();
            header("Location: dashboard.php");
            exit();
        } catch (Exception $e) {
            $pdo->rollback();
            echo "<script>alert('Error creating viewing schedule: " . addslashes($e->getMessage()) . "');</script>";
            exit();
        }
    } else {
        echo "<script>alert('Invalid date or customer name.');</script>";
    }
}

// Priority Marking
if(isset($_POST['priority_single'])){
    $id = intval($_POST['priority_single']);
    $stmt = $pdo->prepare("UPDATE vehicles SET status=:status WHERE id=:id");
    $stmt->execute([':status' => 'Priority', ':id' => $id]);
    header("Location: dashboard.php"); exit();
}

// Cancel priority marker
if(isset($_POST['cancel_priority'])){
    $id = intval($_POST['cancel_priority']);
    $stmt = $pdo->prepare("UPDATE vehicles SET status=:status WHERE id=:id");
    $stmt->execute([':status' => 'Available', ':id' => $id]);
    header("Location: dashboard.php"); exit();
}

// ================= EDIT MODALS (SAVE) =================
if (isset($_POST['update_reservation'])) {
    $reservationId = intval($_POST['reservation_id'] ?? 0);
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $middleName = trim((string)($_POST['middle_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $contact = trim((string)($_POST['contact'] ?? ''));
    $payment = trim((string)($_POST['reservation_payment'] ?? ''));
    $viewingDate = trim((string)($_POST['viewing_date'] ?? ''));

    if ($reservationId > 0 && $vehicleId > 0) {
        $today = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $viewingDate) || $viewingDate < $today) {
            echo "<script>alert('Viewing Date must be today or a future date.'); window.history.back();</script>";
            exit();
        }

        try {
            $pdo->beginTransaction();

            // Resolve or create the customer record
            $checkCust = $pdo->prepare("SELECT id FROM customers WHERE first_name = ? AND middle_name = ? AND last_name = ? LIMIT 1");
            $checkCust->execute([$firstName, $middleName, $lastName]);
            $customerId = $checkCust->fetchColumn();

            if (!$customerId) {
                $insCust = $pdo->prepare("INSERT INTO customers (first_name, middle_name, last_name) VALUES (?, ?, ?)");
                $insCust->execute([$firstName, $middleName, $lastName]);
                $customerId = (int)$pdo->lastInsertId();
            }

            // Update reservation to point at (possibly new) customer
            $upd = $pdo->prepare("
                UPDATE reservations
                SET customer_id = ?, contact = ?, reservation_payment = ?
                WHERE id = ?
            ");
            $upd->execute([$customerId, $contact, $payment, $reservationId]);

            // Update latest viewing schedule for this vehicle, or create one if missing
            $vsSel = $pdo->prepare("SELECT id FROM viewing_schedules WHERE vehicle_id = ? ORDER BY id DESC LIMIT 1");
            $vsSel->execute([$vehicleId]);
            $vsId = (int)($vsSel->fetchColumn() ?: 0);

            if ($vsId > 0) {
                $vsUpd = $pdo->prepare("UPDATE viewing_schedules SET viewing_date = ?, customer_id = ? WHERE id = ?");
                $vsUpd->execute([$viewingDate, $customerId, $vsId]);
            } else {
                $vsIns = $pdo->prepare("INSERT INTO viewing_schedules (vehicle_id, customer_id, viewing_date) VALUES (?,?,?)");
                $vsIns->execute([$vehicleId, $customerId, $viewingDate]);
                $vsId = (int)$pdo->lastInsertId();
            }

            // Keep only this viewing schedule for the reserved vehicle
            $vsDel = $pdo->prepare("DELETE FROM viewing_schedules WHERE vehicle_id = ? AND id <> ?");
            $vsDel->execute([$vehicleId, $vsId]);

            $pdo->commit();
            header("Location: dashboard.php"); exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error updating reservation: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
            exit();
        }
    }
}

if (isset($_POST['update_viewing'])) {
    $viewingId = intval($_POST['viewing_id'] ?? 0);
    $date = trim((string)($_POST['viewing_date'] ?? ''));
    $firstName = trim((string)($_POST['customer_first_name'] ?? ''));
    $middleName = trim((string)($_POST['customer_middle_name'] ?? ''));
    $lastName = trim((string)($_POST['customer_last_name'] ?? ''));
    $today = date('Y-m-d');

    if ($viewingId > 0 && $firstName !== '' && $lastName !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= $today) {

        $pdo->beginTransaction();

        try {
            // Fetch the customer already linked to this viewing schedule
            $fetchStmt = $pdo->prepare("SELECT customer_id FROM viewing_schedules WHERE id = ? LIMIT 1");
            $fetchStmt->execute([$viewingId]);
            $existingCustomerId = $fetchStmt->fetchColumn();

            if ($existingCustomerId) {
                // Edit the linked customer in-place
                $updCust = $pdo->prepare("UPDATE customers SET first_name = ?, middle_name = ?, last_name = ? WHERE id = ?");
                $updCust->execute([$firstName, $middleName, $lastName, $existingCustomerId]);
                $customerId = $existingCustomerId;
            } else {
                // No customer linked yet — create one and link it
                $insCust = $pdo->prepare("INSERT INTO customers (first_name, middle_name, last_name) VALUES (?, ?, ?)");
                $insCust->execute([$firstName, $middleName, $lastName]);
                $customerId = (int)$pdo->lastInsertId();
            }

            // Update the viewing schedule date (customer_id stays the same or is newly set)
            $stmt = $pdo->prepare("UPDATE viewing_schedules SET viewing_date = ?, customer_id = ? WHERE id = ?");
            $stmt->execute([$date, $customerId, $viewingId]);

            $pdo->commit();
            header("Location: dashboard.php");
            exit();
        } catch (Exception $e) {
            $pdo->rollback();
            echo "<script>alert('Error updating viewing schedule: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
            exit();
        }
    }
    echo "<script>alert('Invalid viewing schedule data.'); window.history.back();</script>";
    exit();
}

if (isset($_POST['update_priority'])) {
    $vehicleId = intval($_POST['vehicle_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $allowed = ['Priority', 'Available'];
    if ($vehicleId > 0 && in_array($status, $allowed, true)) {
        $stmt = $pdo->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
        $stmt->execute([$status, $vehicleId]);
        header("Location: dashboard.php"); exit();
    }
    echo "<script>alert('Invalid priority update.'); window.history.back();</script>";
    exit();
}



// ======================= FETCH DATA =======================

// Reserved units with reservation details + viewing person/date (linked to viewing_schedules + reservations + vehicles + sales)
$stmt = $pdo->prepare("
    SELECT v.*,
        r.id AS reservation_id,
        c.first_name, c.middle_name, c.last_name,
        r.contact,
        r.reservation_payment, r.created_at as reservation_date,
        s.buyer_name,
        v.viewing_person AS vehicle_viewing_person,
        (SELECT vs.viewing_date
         FROM viewing_schedules vs
         WHERE vs.vehicle_id = v.id
         ORDER BY vs.viewing_date DESC, vs.id DESC
         LIMIT 1) AS schedule_viewing_date,
        (SELECT TRIM(CONCAT(
                    COALESCE(sc.first_name,''), ' ',
                    COALESCE(sc.middle_name,''), ' ',
                    COALESCE(sc.last_name,'')
                ))
         FROM viewing_schedules vs2
         LEFT JOIN customers sc ON sc.id = vs2.customer_id
         WHERE vs2.vehicle_id = v.id
         ORDER BY vs2.viewing_date DESC, vs2.id DESC
         LIMIT 1) AS schedule_viewing_person
    FROM vehicles v
    LEFT JOIN reservations r ON r.id =(
        SELECT r2.id
        FROM reservations r2
        WHERE r2.vehicle_id = v.id
        ORDER BY r2.created_at DESC
        LIMIT 1)
    LEFT JOIN customers c ON c.id = r.customer_id
    LEFT JOIN sales s ON s.id = (
        SELECT s2.id
        FROM sales s2
        WHERE s2.vehicle_id = v.id
        ORDER BY s2.sale_date DESC, s2.id DESC
        LIMIT 1
    )
    WHERE v.status=:status
    ORDER BY v.created_at DESC");
$stmt->execute([':status' => 'Reserved']);
$reservedUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

//Reservation payments
$stmt = $pdo->prepare('SELECT * FROM reservations ORDER BY created_at DESC');
$stmt->execute();
$reservationPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Available units (for modals)
$stmt = $pdo->prepare('SELECT * FROM vehicles WHERE status IN (:status1, :status3) ORDER BY created_at DESC');
$stmt->execute([':status1' => 'Available',':status3' => 'Priority']);
$availableUnitsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
$availableUnitsReservedAll = $availableUnitsAll;
$availableUnitsViewingAll = $availableUnitsAll;

//Priority units (for modals)
$stmt = $pdo->prepare('SELECT * FROM vehicles WHERE status=:status ORDER BY created_at DESC');
$stmt->execute([':status' => 'Available']);
$priorityUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
$availableUnitsPriorityAll = $priorityUnits;
// pagination parameters
$perPage = 16;
$reservedPage = max(1, intval($_GET['reserved_preview_page'] ?? 1));
$reservedModalPage = max(1, intval($_GET['reserved_modal_page'] ?? 1));
$priorityModalPage = max(1, intval($_GET['priority_modal_page'] ?? 1));
$viewingModalPage = max(1, intval($_GET['viewing_modal_page'] ?? 1));

$stmt = $pdo->prepare("
    SELECT vs.*,
        v.brand, v.model, v.plate_number, v.year, v.selling_price,
        v.viewing_person AS vehicle_viewing_person,
        r.contact,
        s.buyer_name,
        c.first_name AS customer_first_name,
        c.middle_name AS customer_middle_name,
        c.last_name AS customer_last_name
    FROM viewing_schedules vs
    LEFT JOIN vehicles v ON vs.vehicle_id = v.id
    LEFT JOIN customers c ON c.id = vs.customer_id
    LEFT JOIN reservations r ON r.id = (
        SELECT r2.id
        FROM reservations r2
        WHERE r2.vehicle_id = v.id
        ORDER BY r2.created_at DESC
        LIMIT 1
    )
    LEFT JOIN sales s ON s.id = (
        SELECT s2.id
        FROM sales s2
        WHERE s2.vehicle_id = v.id
        ORDER BY s2.sale_date DESC, s2.id DESC
        LIMIT 1
    )
    WHERE (v.id IS NULL OR v.status != 'Sold')
    ORDER BY vs.viewing_date ASC");
$stmt->execute();
$viewingUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Priority units
$stmt = $pdo->prepare('SELECT * FROM vehicles WHERE status=:status ORDER BY created_at DESC');
$stmt->execute([':status' => 'Priority']);
$priorityUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ======================= HANDLE SEARCH =======================

$openReservedModal = false;
$openViewingModal = false;
$openPriorityModal = false;

// Reserved modal search
if(isset($_GET['search_reserved'])){
    $openReservedModal = true;
    $value = trim($_GET['reserved_value'] ?? '');
    $field = $_GET['reserved_field'] ?? 'brand';
    $allowed = ['brand','model','plate_number'];
    if(!in_array($field,$allowed)) $field='brand';
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE status='Available' AND $field LIKE :search");
    $stmt->execute([':search'=>"%$value%"]);
    $availableUnitsReservedAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Priority modal search
if(isset($_GET['search_priority'])){
    $openPriorityModal = true;
    $value = trim($_GET['priority_value'] ?? '');
    $field = $_GET['priority_field'] ?? 'brand';
    $allowed = ['brand','model','plate_number'];
    if(!in_array($field,$allowed)) $field='brand';
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE status='Available' AND $field LIKE :search");
    $stmt->execute([':search'=>"%$value%"]);
    $availableUnitsPriorityAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Viewing modal search
if(isset($_GET['search_viewing'])){
    $openViewingModal = true;
    $value = trim($_GET['viewing_value'] ?? '');
    $field = $_GET['viewing_field'] ?? 'brand';
    $allowed = ['brand','model','plate_number'];
    if(!in_array($field,$allowed)) $field='brand';
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE status='Available' AND $field LIKE :search");
    $stmt->execute([':search'=>"%$value%"]);
    $availableUnitsViewingAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Keep modal open when paginating within it (non-JS fallback)
if (isset($_GET['reserved_modal_page'])) $openReservedModal = true;
if (isset($_GET['priority_modal_page'])) $openPriorityModal = true;
if (isset($_GET['viewing_modal_page'])) $openViewingModal = true;

// apply pagination slices
$reservedTotal = count($reservedUnits);
$reservedUnits = array_slice($reservedUnits, ($reservedPage-1)*$perPage, $perPage);

$availableReservedTotal = count($availableUnitsReservedAll);
$availableUnitsReserved = array_slice($availableUnitsReservedAll, ($reservedModalPage-1)*$perPage, $perPage);

$availablePriorityTotal = count($availableUnitsPriorityAll);
$availableUnitsPriority = array_slice($availableUnitsPriorityAll, ($priorityModalPage-1)*$perPage, $perPage);

$availableViewingTotal = count($availableUnitsViewingAll);
$availableUnitsViewing = array_slice($availableUnitsViewingAll, ($viewingModalPage-1)*$perPage, $perPage);


// old generic cancellation handler removed; specific handlers are above

?>


<body>
<div class = "dashboard-container-stat">
<!-- Stats Section -->
        <div class="dashboard-stats-container">
            <div class="dashboard-stat">
                <h5>Total Vehicles</h5>
                <h3><?php echo $total; ?></h3>
            </div>

            <div class="dashboard-stat">
                <h5>Available</h5>
                <h3><?php echo $available; ?></h3>
            </div>

            <div class="dashboard-stat">
                <h5>Inventory Value</h5>
                <h3>₱<?php echo number_format($inventoryValue,2); ?></h3>
            </div>
        </div>
</div>


<!-- ===== Dashboard Preview Section ===== -->
<div class="dashboard-preview-container">

    <!-- Reserved Units Preview -->
    
    <div onclick="handleReservedPreviewClick(event)">
        <div class="preview-reserved-title">Reserved Units Preview</div>
        <div class="preview-table-container ">
            <table class="preview-table">
                <thead>
                    <tr>
						<th>Year</th>
                        <th>Brand / Model</th>
                        <th>Plate</th>
                        <th>Customer Name</th>
                        <th>Reservation Payment</th>
                        <th>Reservation Date</th>
                        <th>Viewing Date</th>
                   
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($reservedUnits as $unit): ?>
                    <tr class="clickable-row"
                        data-reservation-id="<?= htmlspecialchars($unit['reservation_id'] ?? '') ?>"
                        data-vehicle-id="<?= htmlspecialchars($unit['id'] ?? '') ?>"
                        data-first-name="<?= htmlspecialchars($unit['first_name'] ?? '') ?>"
                        data-middle-name="<?= htmlspecialchars($unit['middle_name'] ?? '') ?>"
                        data-last-name="<?= htmlspecialchars($unit['last_name'] ?? '') ?>"
                        data-contact="<?= htmlspecialchars($unit['contact'] ?? '') ?>"
                        data-payment="<?= htmlspecialchars($unit['reservation_payment'] ?? '') ?>"
                        data-viewing-date="<?= htmlspecialchars($unit['schedule_viewing_date'] ?? '') ?>"
                        onclick="openEditReservationFromRow(this, event)">
						<td><?= htmlspecialchars($unit['year'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></td>
                        <td><?= htmlspecialchars($unit['plate_number']) ?></td>
                        <td>
                            <?php
                                $buyerName = trim((string)($unit['buyer_name'] ?? ''));
                                $reservedCustomer = trim(
                                    ($unit['first_name'] ?? '') . ' ' .
                                    ($unit['middle_name'] ?? '') . ' ' .
                                    ($unit['last_name'] ?? '')
                                );
                                $reservedCustomer = $reservedCustomer !== '' ? preg_replace('/\s+/', ' ', $reservedCustomer) : '';
                                $nameOut = $buyerName !== '' ? $buyerName : $reservedCustomer;
                                echo htmlspecialchars($nameOut !== '' ? $nameOut : 'N/A');
                            ?>
                        </td>
                        <td><?= htmlspecialchars($unit['reservation_payment'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($unit['reservation_date'] ?? 'N/A') ?></td>
                        <td><?= !empty($unit['schedule_viewing_date']) ? htmlspecialchars($unit['schedule_viewing_date']) : '—' ?></td>
                    

						<td>
						<form method="POST" class="cancel-reservation-form">
						<input type="hidden" name="cancel_reserved" value="<?= $unit['id'] ?>">
						<?= getCSRFInput() ?>
							<button type="submit" class="cancel-btn">x</button>
						</form>
					</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if(isset($reservedTotal) && $reservedTotal > $perPage): ?>
            <div class="pagination" style="text-align:center;margin:10px 0;">
                <?php
                    $showPages = 5; // Number of page links to show
                    $totalPages = ceil($reservedTotal / $perPage);
                    $startPage = max(1, $reservedPage - floor($showPages / 2));
                    $endPage = min($totalPages, $startPage + $showPages - 1);
                    
                    if ($endPage - $startPage < $showPages - 1) {
                      $startPage = max(1, $endPage - $showPages + 1);
                    }
                    
                    // First page
                    if ($startPage > 1) {
                      echo '<a href="?' . http_build_query(array_merge($_GET, ['reserved_preview_page' => 1])) . '" class="ajax-link">1</a>';
                      if ($startPage > 2) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                    }
                    
                    // Page range
                    for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['reserved_preview_page' => $p])); ?>" class="ajax-link <?= $p==$reservedPage?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                
                <?php
                    // Last page
                    if ($endPage < $totalPages) {
                      if ($endPage < $totalPages - 1) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                      echo '<a href="?' . http_build_query(array_merge($_GET, ['reserved_preview_page' => $totalPages])) . '" class="ajax-link">' . $totalPages . '</a>';
                    }
                ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Viewing Schedule Preview -->
    <div onclick="handleViewingPreviewClick(event)">
        <div class="preview-title">Viewing Schedule Preview</div>
        <div class="preview-table-container">
            <table class="preview-table">
                <thead>
                    <tr>
						<th>Viewing Date</th>
						<th>Year</th>
                        <th>Brand / Model</th>
                        <th>Plate</th>
                        <th>Person</th>
						<th>Action</th>
                    </tr>
                </thead>
                <tbody>
                   <?php foreach($viewingUnits as $unit): ?>
<tr class="clickable-row"
    data-viewing-id="<?= htmlspecialchars($unit['id'] ?? '') ?>"
    data-viewing-date="<?= htmlspecialchars($unit['viewing_date'] ?? '') ?>"
    data-customer-first-name="<?= htmlspecialchars($unit['customer_first_name'] ?? '') ?>"
    data-customer-middle-name="<?= htmlspecialchars($unit['customer_middle_name'] ?? '') ?>"
    data-customer-last-name="<?= htmlspecialchars($unit['customer_last_name'] ?? '') ?>"
    onclick="openEditViewingFromRow(this, event)">
    <td><?= htmlspecialchars($unit['viewing_date']) ?></td>
    <td><?= $unit['vehicle_id'] ? htmlspecialchars($unit['year']) : '—' ?></td>
    <td>
        <?= $unit['vehicle_id'] 
            ? htmlspecialchars($unit['brand'].' '.$unit['model']) 
            : '<strong>All Available Cars</strong>' ?>
    </td>
    <td><?= $unit['vehicle_id'] ? htmlspecialchars($unit['plate_number']) : '—' ?></td>
    <td>
        <?php
            $buyerName = trim((string)($unit['buyer_name'] ?? ''));
            $customerName = trim(
                ($unit['customer_first_name'] ?? '') . ' ' .
                ($unit['customer_middle_name'] ?? '') . ' ' .
                ($unit['customer_last_name'] ?? '')
            );
            $customerName = $customerName !== '' ? preg_replace('/\s+/', ' ', $customerName) : '';
            $vehiclePerson = trim((string)($unit['vehicle_viewing_person'] ?? ''));

            $personOut =
                $buyerName !== '' ? $buyerName :
                ($customerName !== '' ? $customerName : $vehiclePerson);
            echo htmlspecialchars($personOut !== '' ? $personOut : '—');
        ?>
    </td>
    <td>
        <form method="POST" class="cancel-viewing-form">
            <input type="hidden" name="delete_viewing_id" value="<?= $unit['id'] ?>">
            <?= getCSRFInput() ?>
            <button type="submit" class="cancel-btn">x</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Priority to Sell Preview -->
    <div onclick="handlePriorityPreviewClick(event)">
        <div class="preview-priority-title">Priority to Sell Preview</div>
        <div class="preview-table-container">
            <table class="preview-table">
                <thead>
                    <tr>
						<th>Year</th>
                        <th>Brand / Model</th>
                        <th>Plate</th>
                        <th>Price</th>
                        <th>Status</th>
						<th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($priorityUnits as $unit): ?>
                    <tr class="clickable-row"
                        data-vehicle-id="<?= htmlspecialchars($unit['id'] ?? '') ?>"
                        data-status="<?= htmlspecialchars($unit['status'] ?? '') ?>"
                        onclick="openEditPriorityFromRow(this, event)">
						<td><?= htmlspecialchars($unit['year'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></td>
                        <td><?= htmlspecialchars($unit['plate_number']) ?></td>
                        <td>₱<?= number_format($unit['selling_price'],2) ?></td>
                        <td><?= $unit['status'] ?></td>
						<td>
						<form method="POST" class="cancel-priority-form">
						<input type="hidden" name="cancel_priority" value="<?= $unit['id'] ?>">
						<?= getCSRFInput() ?>
							<button type="submit" class="cancel-btn">x</button>
						</form>
					</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ================= MODALS ================= -->

<!-- Reserved Modal -->
<div id="reservedModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('reservedModal')">&times;</span>
        <h3>Reserved Units & Available Inventory</h3>

        <form method="GET">
            <input type="text" name="reserved_value" placeholder="Search available..." value="<?= htmlspecialchars($_GET['reserved_value'] ?? '') ?>">
            <select name="reserved_field">
                <option value="brand" <?= (($_GET['reserved_field'] ?? '')=='brand')?'selected':'' ?>>Brand</option>
                <option value="model" <?= (($_GET['reserved_field'] ?? '')=='model')?'selected':'' ?>>Model</option>
                <option value="plate_number" <?= (($_GET['reserved_field'] ?? '')=='plate_number')?'selected':'' ?>>Plate</option>
            </select>
            <button type="submit" name="search_reserved">Search</button>
        </form>
        <h4>Available Units</h4>
         <?php if($availableReservedTotal > $perPage): ?>
                <div class="pagination" style="text-align:center;margin:10px 0;">
                    <?php
                        $showPages = 5; // Number of page links to show
                        $totalPages = ceil($availableReservedTotal / $perPage);
                        $startPage = max(1, $reservedModalPage - floor($showPages / 2));
                        $endPage = min($totalPages, $startPage + $showPages - 1);
                        
                        if ($endPage - $startPage < $showPages - 1) {
                          $startPage = max(1, $endPage - $showPages + 1);
                        }
                        
                        // First page
                        if ($startPage > 1) {
                          $href = '?reserved_modal_page=1';
                          if (isset($_GET['search_reserved'])) {
                            $href = '?search_reserved=1&reserved_value=' . urlencode($_GET['reserved_value'] ?? '') . '&reserved_field=' . urlencode($_GET['reserved_field'] ?? 'brand') . '&reserved_modal_page=1';
                          }
                          echo '<a href="' . $href . '" class="ajax-link">1</a>';
                          if ($startPage > 2) {
                            echo '<span class="ajax-link" style="cursor:default;">...</span>';
                          }
                        }
                        
                        // Page range
                        for ($p = $startPage; $p <= $endPage; $p++):
                    ?>
                        <?php
                            $href = '?reserved_modal_page=' . $p;
                            if (isset($_GET['search_reserved'])) {
                                $href =
                                    '?search_reserved=1' .
                                    '&reserved_value=' . urlencode($_GET['reserved_value'] ?? '') .
                                    '&reserved_field=' . urlencode($_GET['reserved_field'] ?? 'brand') .
                                    '&reserved_modal_page=' . $p;
                            }
                        ?>
                        <a href="<?= $href ?>" class="ajax-link <?= $p==$reservedModalPage?'active':'' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    
                    <?php
                        // Last page
                        if ($endPage < $totalPages) {
                          if ($endPage < $totalPages - 1) {
                            echo '<span class="ajax-link" style="cursor:default;">...</span>';
                          }
                          $href = '?reserved_modal_page=' . $totalPages;
                          if (isset($_GET['search_reserved'])) {
                            $href = '?search_reserved=1&reserved_value=' . urlencode($_GET['reserved_value'] ?? '') . '&reserved_field=' . urlencode($_GET['reserved_field'] ?? 'brand') . '&reserved_modal_page=' . $totalPages;
                          }
                          echo '<a href="' . $href . '" class="ajax-link">' . $totalPages . '</a>';
                        }
                    ?>
                </div>
            <?php endif; ?>

        <form method="POST">
            <?= getCSRFInput() ?>
            <div class="search-results-container">
                <?php foreach($availableUnitsReserved as $unit): ?>
                    <div class="result-card" onclick="openReservationModal(<?= $unit['id'] ?>, '<?= htmlspecialchars($unit['brand']) ?>', '<?= htmlspecialchars($unit['model']) ?>', '<?= htmlspecialchars($unit['plate_number']) ?>', '<?= number_format($unit['selling_price'], 2) ?>')">
                      
                        <strong><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></strong><br>
                        Plate: <?= htmlspecialchars($unit['plate_number']) ?><br>
                        Price: ₱<?= number_format($unit['selling_price'],2) ?><br>
                    
                    </div>
                <?php endforeach; ?>
            </div>

           
            <br>
           
        </form>
    </div>
</div>

<!-- Viewing Schedule Modal -->
<div id="viewingModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('viewingModal')">&times;</span>
        <h3>Viewing Schedule</h3>

        <form method="GET">
            <input type="text" name="viewing_value" placeholder="Search available units..." value="<?= htmlspecialchars($_GET['viewing_value'] ?? '') ?>">
            <select name="viewing_field">
                <option value="brand" <?= (($_GET['viewing_field'] ?? '')=='brand')?'selected':'' ?>>Brand</option>
                <option value="model" <?= (($_GET['viewing_field'] ?? '')=='model')?'selected':'' ?>>Model</option>
                <option value="plate_number" <?= (($_GET['viewing_field'] ?? '')=='plate_number')?'selected':'' ?>>Plate</option>
            </select>
            <button type="submit" name="search_viewing">Search</button>
        </form>

        <?php if($availableViewingTotal > $perPage): ?>
            <div class="pagination" style="text-align:center;margin:10px 0;">
                <?php
                    $showPages = 5; // Number of page links to show
                    $totalPages = ceil($availableViewingTotal / $perPage);
                    $startPage = max(1, $viewingModalPage - floor($showPages / 2));
                    $endPage = min($totalPages, $startPage + $showPages - 1);
                    
                    if ($endPage - $startPage < $showPages - 1) {
                      $startPage = max(1, $endPage - $showPages + 1);
                    }
                    
                    // First page
                    if ($startPage > 1) {
                      $href = '?viewing_modal_page=1';
                      if (isset($_GET['search_viewing'])) {
                        $href = '?search_viewing=1&viewing_value=' . urlencode($_GET['viewing_value'] ?? '') . '&viewing_field=' . urlencode($_GET['viewing_field'] ?? 'brand') . '&viewing_modal_page=1';
                      }
                      echo '<a href="' . $href . '" class="ajax-link">1</a>';
                      if ($startPage > 2) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                    }
                    
                    // Page range
                    for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                    <?php
                        $href = '?viewing_modal_page=' . $p;
                        if (isset($_GET['search_viewing'])) {
                            $href =
                                '?search_viewing=1' .
                                '&viewing_value=' . urlencode($_GET['viewing_value'] ?? '') .
                                '&viewing_field=' . urlencode($_GET['viewing_field'] ?? 'brand') .
                                '&viewing_modal_page=' . $p;
                        }
                    ?>
                    <a href="<?= $href ?>" class="ajax-link <?= $p==$viewingModalPage?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                
                <?php
                    // Last page
                    if ($endPage < $totalPages) {
                      if ($endPage < $totalPages - 1) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                      $href = '?viewing_modal_page=' . $totalPages;
                      if (isset($_GET['search_viewing'])) {
                        $href = '?search_viewing=1&viewing_value=' . urlencode($_GET['viewing_value'] ?? '') . '&viewing_field=' . urlencode($_GET['viewing_field'] ?? 'brand') . '&viewing_modal_page=' . $totalPages;
                      }
                      echo '<a href="' . $href . '" class="ajax-link">' . $totalPages . '</a>';
                    }
                ?>
            </div>
        <?php endif; ?>

        <div class="search-results-container">
            <div class="view-card" onclick="openViewingScheduleModal('', 'All Available Cars', '', '—')">
                <strong>All Available Cars</strong><br>
                Plate: —<br>
            
            </div>

            <?php foreach($availableUnitsViewing as $unit): ?>
                <div class="view-card" onclick="openViewingScheduleModal(<?= $unit['id'] ?>, '<?= htmlspecialchars($unit['brand']) ?>', '<?= htmlspecialchars($unit['model']) ?>', '<?= htmlspecialchars($unit['plate_number']) ?>')">
                    <strong><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></strong><br>
                    Plate: <?= htmlspecialchars($unit['plate_number']) ?><br>
                    Price: ₱<?= number_format($unit['selling_price'],2) ?><br>
                   
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Priority Modal -->
<div id="priorityModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('priorityModal')">&times;</span>
        <h3>Priority to Sell</h3>

        <form method="GET">
            <input type="text" name="priority_value" placeholder="Search available units..." value="<?= htmlspecialchars($_GET['priority_value'] ?? '') ?>">
            <select name="priority_field">
                <option value="brand" <?= (($_GET['priority_field'] ?? '')=='brand')?'selected':'' ?>>Brand</option>
                <option value="model" <?= (($_GET['priority_field'] ?? '')=='model')?'selected':'' ?>>Model</option>
                <option value="plate_number" <?= (($_GET['priority_field'] ?? '')=='plate_number')?'selected':'' ?>>Plate</option>
            </select>
            <button type="submit" name="search_priority">Search</button>
        </form>

        <?php if($availablePriorityTotal > $perPage): ?>
            <div class="pagination" style="text-align:center;margin:10px 0;">
                <?php
                    $showPages = 5; // Number of page links to show
                    $totalPages = ceil($availablePriorityTotal / $perPage);
                    $startPage = max(1, $priorityModalPage - floor($showPages / 2));
                    $endPage = min($totalPages, $startPage + $showPages - 1);
                    
                    if ($endPage - $startPage < $showPages - 1) {
                      $startPage = max(1, $endPage - $showPages + 1);
                    }
                    
                    // First page
                    if ($startPage > 1) {
                      $href = '?priority_modal_page=1';
                      if (isset($_GET['search_priority'])) {
                        $href = '?search_priority=1&priority_value=' . urlencode($_GET['priority_value'] ?? '') . '&priority_field=' . urlencode($_GET['priority_field'] ?? 'brand') . '&priority_modal_page=1';
                      }
                      echo '<a href="' . $href . '" class="ajax-link">1</a>';
                      if ($startPage > 2) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                    }
                    
                    // Page range
                    for ($p = $startPage; $p <= $endPage; $p++):
                ?>
                    <?php
                        $href = '?priority_modal_page=' . $p;
                        if (isset($_GET['search_priority'])) {
                            $href =
                                '?search_priority=1' .
                                '&priority_value=' . urlencode($_GET['priority_value'] ?? '') .
                                '&priority_field=' . urlencode($_GET['priority_field'] ?? 'brand') .
                                '&priority_modal_page=' . $p;
                        }
                    ?>
                    <a href="<?= $href ?>" class="ajax-link <?= $p==$priorityModalPage?'active':'' ?>"><?= $p ?></a>
                <?php endfor; ?>
                
                <?php
                    // Last page
                    if ($endPage < $totalPages) {
                      if ($endPage < $totalPages - 1) {
                        echo '<span class="ajax-link" style="cursor:default;">...</span>';
                      }
                      $href = '?priority_modal_page=' . $totalPages;
                      if (isset($_GET['search_priority'])) {
                        $href = '?search_priority=1&priority_value=' . urlencode($_GET['priority_value'] ?? '') . '&priority_field=' . urlencode($_GET['priority_field'] ?? 'brand') . '&priority_modal_page=' . $totalPages;
                      }
                      echo '<a href="' . $href . '" class="ajax-link">' . $totalPages . '</a>';
                    }
                ?>
            </div>
        <?php endif; ?>

        <div class="search-results-container">
            <?php foreach($availableUnitsPriority as $unit): ?>
                <form method="POST" style="margin:0;">
                    <?= getCSRFInput() ?>
                    <input type="hidden" name="priority_single" value="<?= $unit['id'] ?>">
                    <div class="priority-card" onclick="this.closest('form').submit()">
                        <strong><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></strong><br>
                        Plate: <?= htmlspecialchars($unit['plate_number']) ?><br>
                        Price: ₱<?= number_format($unit['selling_price'],2) ?><br>
                
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Reservation Modal -->
<div id="reservationModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('reservationModal')">&times;</span>
        <h3>Reserve Vehicle</h3>
        
        <div id="reservationDetails" style="margin-bottom: 20px;">
            <!-- Vehicle details will be populated here -->
        </div>
        
        <form method="POST" id="reservationForm">
            <input type="hidden" name="vehicle_id" id="reservationVehicleId">
            <?= getCSRFInput() ?>
            <div class="form-row">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name" required>
                </div>
                
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle">
                </div>
                
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Viewing Date <span style="font-weight:normal;opacity:0.7;">(optional)</span></label>
                    <input type="date" name="viewing_date">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Contact</label>
                    <input type="text" name="contact" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Reservation Payment</label>
                    <input type="text" name="reservation_payment" required>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="create_reservation" class="action-btn">Submit Reservation</button>
                <button type="button" onclick="closeModal('reservationModal')" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Viewing Create Modal -->
<div id="viewingCreateModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('viewingCreateModal')">&times;</span>
        <h3>Create Viewing Schedule</h3>

        <div id="viewingDetails" style="margin-bottom: 20px;">
            <!-- Vehicle details will be populated here -->
        </div>

        <form method="POST" id="viewingCreateForm">
            <input type="hidden" name="vehicle_id" id="viewingVehicleId">
            <?= getCSRFInput() ?>
            <div class="form-row">
                <div class="form-group" style="width:100%;">
                    <label>Viewing Date</label>
                    <input type="date" name="viewing_date" id="viewingDateInput" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="customer_first_name" placeholder="First Name" required>
                </div>
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="customer_middle_name" placeholder="Middle Name">
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="customer_last_name" placeholder="Last Name" required>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" name="schedule_viewing" class="action-btn">Create Schedule</button>
                <button type="button" onclick="closeModal('viewingCreateModal')" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Reserved Unit Modal -->
<div id="editReservedModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editReservedModal')">&times;</span>
        <h3>Edit Reservation</h3>

        <form method="POST" id="editReservedForm">
            <?= getCSRFInput() ?>
            <input type="hidden" name="update_reservation" value="1">
            <input type="hidden" name="reservation_id" id="editReservationId">
            <input type="hidden" name="vehicle_id" id="editReservedVehicleId">

            <div class="form-row">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="first_name" id="editResFirstName" required>
                </div>
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" id="editResMiddleName">
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name" id="editResLastName" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Viewing Date</label>
                    <input type="date" name="viewing_date" id="editResViewingDate" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Contact</label>
                    <input type="text" name="contact" id="editResContact" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Reservation Payment</label>
                    <input type="text" name="reservation_payment" id="editResPayment" required>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="action-btn">Save</button>
                <button type="button" onclick="closeModal('editReservedModal')" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Viewing Schedule Modal -->
<div id="editViewingModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editViewingModal')">&times;</span>
        <h3>Edit Viewing Schedule</h3>

        <form method="POST" id="editViewingForm">
            <?= getCSRFInput() ?>
            <input type="hidden" name="update_viewing" value="1">
            <input type="hidden" name="viewing_id" id="editViewingId">

            <div class="form-row">
                <div class="form-group" style="width:100%;">
                    <label>Viewing Date</label>
                    <input type="date" name="viewing_date" id="editViewingDate" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>First Name</label>
                    <input type="text" name="customer_first_name" id="editCustomerFirstName" required>
                </div>
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="customer_middle_name" id="editCustomerMiddleName">
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="customer_last_name" id="editCustomerLastName" required>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="action-btn">Save</button>
                <button type="button" onclick="closeModal('editViewingModal')" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Priority Modal -->
<div id="editPriorityModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editPriorityModal')">&times;</span>
        <h3>Edit Priority Status</h3>

        <form method="POST" id="editPriorityForm">
            <?= getCSRFInput() ?>
            <input type="hidden" name="update_priority" value="1">
            <input type="hidden" name="vehicle_id" id="editPriorityVehicleId">

            <div class="form-row">
                <div class="form-group" style="width:100%;">
                    <label>Status</label>
                    <select name="status" id="editPriorityStatus" required>
                        <option value="Priority">Priority</option>
                        <option value="Available">Available</option>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="action-btn">Save</button>
                <button type="button" onclick="closeModal('editPriorityModal')" class="cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= JAVASCRIPT ================= -->
<!-- ================= JS MODAL CONTROL ================= -->
<script>
function openModal(id){document.getElementById(id).style.display="flex";}
function closeModal(id){document.getElementById(id).style.display="none";}
window.addEventListener("click",function(e){document.querySelectorAll(".modal").forEach(function(m){if(e.target===m)m.style.display="none";});});
<?php if($openReservedModal): ?>document.addEventListener("DOMContentLoaded",()=>{openModal('reservedModal');});<?php endif; ?>
<?php if($openViewingModal): ?>document.addEventListener("DOMContentLoaded",()=>{openModal('viewingModal');});<?php endif; ?>
<?php if($openPriorityModal): ?>document.addEventListener("DOMContentLoaded",()=>{openModal('priorityModal');});<?php endif; ?>

// Handle clicks on the Reserved Units preview container:
// - Clicking on the background/title opens the modal
// - Clicks on the table (rows, headers) or cancel buttons do NOT open it
function handleReservedPreviewClick(e){
    const target = e.target;
    // Ignore clicks inside the preview table or on the cancel-reservation form/button
    if (target.closest('.preview-table') || target.closest('.cancel-reservation-form')) {
        return;
    }
    openModal('reservedModal');
}

// Same behavior for Viewing Schedule preview
function handleViewingPreviewClick(e){
    const target = e.target;
    if (target.closest('.preview-table') || target.closest('.cancel-viewing-form')) {
        return;
    }
    openModal('viewingModal');
}

// Same behavior for Priority to Sell preview
function handlePriorityPreviewClick(e){
    const target = e.target;
    if (target.closest('.preview-table') || target.closest('.cancel-priority-form')) {
        return;
    }
    openModal('priorityModal');
}

// Row click → open edit modals (do not trigger when clicking the "x" cancel buttons)
function openEditReservationFromRow(row, e){
    const target = e.target;
    if (target.closest('.cancel-reservation-form')) return;

    document.getElementById('editReservationId').value = row.dataset.reservationId || '';
    document.getElementById('editReservedVehicleId').value = row.dataset.vehicleId || '';
    document.getElementById('editResFirstName').value = row.dataset.firstName || '';
    document.getElementById('editResMiddleName').value = row.dataset.middleName || '';
    document.getElementById('editResLastName').value = row.dataset.lastName || '';
    document.getElementById('editResContact').value = row.dataset.contact || '';
    document.getElementById('editResPayment').value = row.dataset.payment || '';
    document.getElementById('editResViewingDate').value = row.dataset.viewingDate || '';

    openModal('editReservedModal');
}

function openEditViewingFromRow(row, e){
    const target = e.target;
    if (target.closest('.cancel-viewing-form')) return;

    document.getElementById('editViewingId').value = row.dataset.viewingId || '';
    document.getElementById('editViewingDate').value = row.dataset.viewingDate || '';

    document.getElementById('editCustomerFirstName').value = row.dataset.customerFirstName || '';
    document.getElementById('editCustomerMiddleName').value = row.dataset.customerMiddleName || '';
    document.getElementById('editCustomerLastName').value = row.dataset.customerLastName || '';

    openModal('editViewingModal');
}

function openEditPriorityFromRow(row, e){
    const target = e.target;
    if (target.closest('.cancel-priority-form')) return;

    document.getElementById('editPriorityVehicleId').value = row.dataset.vehicleId || '';
    const status = row.dataset.status || 'Priority';
    const sel = document.getElementById('editPriorityStatus');
    sel.value = (status === 'Available') ? 'Available' : 'Priority';

    openModal('editPriorityModal');
}

function toggleVehicleSelect(){
    const type = document.getElementById('viewingType').value;
    const wrapper = document.getElementById('vehicleSelectWrapper');

    if(type === 'all'){
        wrapper.style.display = 'none';
        wrapper.querySelector('select').value = '';
    } else {
        wrapper.style.display = 'block';
    }
}

// Open reservation modal with vehicle details
function openReservationModal(vehicleId, brand, model, plate, price) {
    // Set vehicle ID in hidden field
    document.getElementById('reservationVehicleId').value = vehicleId;
    
    // Populate vehicle details
    const detailsDiv = document.getElementById('reservationDetails');
    detailsDiv.innerHTML = `
        <div style="background: rgba(255, 215, 0, 0.1); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h4 style="color: #ffd700; margin-bottom: 10px;">Vehicle Details</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div><strong>Brand:</strong> ${brand}</div>
                <div><strong>Model:</strong> ${model}</div>
                <div><strong>Plate:</strong> ${plate}</div>
                <div><strong>Price:</strong> ₱${price}</div>
            </div>
        </div>
    `;
    
    // Open modal
    openModal('reservationModal');
}

// Open viewing schedule create modal with vehicle details
function openViewingScheduleModal(vehicleId, brand, model, plate) {
    document.getElementById('viewingVehicleId').value = vehicleId || '';

    const detailsDiv = document.getElementById('viewingDetails');
    const title = (brand + ' ' + (model || '')).trim();
    detailsDiv.innerHTML = `
        <div style="background: rgba(255, 215, 0, 0.1); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h4 style="color: #ffd700; margin-bottom: 10px;">Vehicle Details</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <div><strong>Vehicle:</strong> ${title || 'All Available Cars'}</div>
                <div><strong>Plate:</strong> ${plate || '—'}</div>
            </div>
        </div>
    `;

    // default to today
    const dateInput = document.getElementById('viewingDateInput');
    if (dateInput && !dateInput.value) {
        const now = new Date();
        const yyyy = now.getFullYear();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        dateInput.value = `${yyyy}-${mm}-${dd}`;
    }

    openModal('viewingCreateModal');
}

// ================= AJAX HELPERS =================
async function fetchFragment(url, selector) {
    const res = await fetch(url, {credentials: 'same-origin'});
    const text = await res.text();
    const temp = document.createElement('div');
    temp.innerHTML = text;
    const fragment = temp.querySelector(selector);
    return fragment ? fragment.innerHTML : '';
}

function updatePreview(html) {
    const container = document.querySelector('.dashboard-preview-container');
    if (container) container.innerHTML = html;
}

// link and form interception
window.addEventListener('click', function(e){
    const a = e.target.closest('a.ajax-link');
    if(a){
        e.preventDefault();
        const url = a.href;
        const modal = a.closest('.modal');
        if(modal){
            // update only this modal's content
            const selector = '#' + modal.id + ' .modal-content';
            fetchFragment(url, selector).then(html=>{
                modal.querySelector('.modal-content').innerHTML = html;
            });
        } else {
            fetchFragment(url, '.dashboard-preview-container').then(updatePreview);
        }
    }
});

window.addEventListener('submit', function(e){
    const form = e.target;
    // ignore forms inside modals (let them submit normally)
    if(form.matches('form') && !form.closest('.modal')){
        const isCancelReservation = form.classList.contains('cancel-reservation-form');
        const isCancelViewing = form.classList.contains('cancel-viewing-form');
        const isCancelPriority = form.classList.contains('cancel-priority-form');

        if (isCancelReservation || isCancelViewing || isCancelPriority) {
            e.preventDefault();

            var existing = document.getElementById('actionConfirmModal');
            if (existing) existing.remove();

            var title = 'Please Confirm';
            var message = 'Are you sure?';
            if (isCancelReservation) {
                title = 'Cancel Reservation';
                message = 'Cancel this reservation?';
            } else if (isCancelViewing) {
                title = 'Cancel Viewing';
                message = 'Cancel this viewing schedule?';
            } else if (isCancelPriority) {
                title = 'Remove Priority';
                message = 'Remove this vehicle from Priority to Sell?';
            }

            var modalHTML =
              '<div id="actionConfirmModal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999;">' +
                '<div style="background:white;padding:30px;border-radius:10px;max-width:420px;width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);">' +
                  '<h3 style="color:#d4af37;margin-bottom:15px;">' + title.replace(/</g, '&lt;') + '</h3>' +
                  '<p style="color:#444;margin-bottom:25px;line-height:1.5;">' + message.replace(/</g, '&lt;') + '</p>' +
                  '<div style="display:flex;gap:10px;justify-content:center;">' +
                    '<button id="actionConfirmYes" style="background:#d4af37;color:#000;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;min-width:110px;">OK</button>' +
                    '<button id="actionConfirmNo" style="background:#ccc;color:#333;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;min-width:110px;">Cancel</button>' +
                  '</div>' +
                '</div>' +
              '</div>';

            document.body.insertAdjacentHTML('beforeend', modalHTML);

            var root = document.getElementById('actionConfirmModal');
            var yesBtn = document.getElementById('actionConfirmYes');
            var noBtn = document.getElementById('actionConfirmNo');

            function close() {
                if (root) root.remove();
            }

            yesBtn.addEventListener('click', function () {
                close();
                form.submit();
            });

            noBtn.addEventListener('click', function () {
                close();
            });

            return;
        }

        e.preventDefault();
        const method = (form.method||'get').toLowerCase();
        // use attribute so named controls don't override the property
        const action = form.getAttribute('action') || window.location.href;
        if(method === 'get'){
            const params = new URLSearchParams(new FormData(form));
            const url = action.split('?')[0] + '?' + params.toString();
            fetchFragment(url, '.dashboard-preview-container').then(updatePreview);
        } else {
            fetch(action, {method:'post', body:new FormData(form), credentials:'same-origin'})
                .then(()=> fetchFragment(window.location.href, '.dashboard-preview-container'))
                .then(updatePreview);
        }
    }
});
</script>
</body>