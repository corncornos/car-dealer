<?php
session_start();
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['user'])) header('Location: login.php');

$pdo = getPDO();

// Stats
$total = $pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$available = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status='Available'")->fetchColumn();
$inventoryValue = $pdo->query('SELECT COALESCE(SUM(purchase_price),0) FROM vehicles')->fetchColumn();

require 'header.php';

?>
<?php
// ======================= HANDLE POST ACTIONS =======================

// Single Reserve
if(isset($_POST['reserve_single'])){
    $id = intval($_POST['reserve_single']);
    $stmt = $pdo->prepare("UPDATE vehicles SET status='Reserved' WHERE id=?");
    $stmt->execute([$id]);
    header("Location: dashboard.php"); exit();
}

// Bulk Reserve
if(isset($_POST['bulk_reserve']) && !empty($_POST['bulk_ids'])){
    $ids = array_map('intval', $_POST['bulk_ids']);
    $placeholders = implode(',', array_fill(0,count($ids),'?'));
    $stmt = $pdo->prepare("UPDATE vehicles SET status='Reserved' WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    header("Location: dashboard.php"); exit();
}

// Schedule Viewing
if(isset($_POST['schedule_viewing'])){
    $id = intval($_POST['vehicle_id']);
    $date = $_POST['viewing_date'];
    $person = trim($_POST['viewing_person']);
    $today = date('Y-m-d');
    if($date >= $today && $person !== ''){
        $stmt = $pdo->prepare("UPDATE vehicles SET viewing_date=?, viewing_person=? WHERE id=?");
        $stmt->execute([$date,$person,$id]);
        header("Location: dashboard.php"); exit();
    } else {
        echo "<script>alert('Invalid date or person name.');</script>";
    }
}

// Priority Marking
if(isset($_POST['priority_single'])){
    $id = intval($_POST['priority_single']);
    $stmt = $pdo->prepare("UPDATE vehicles SET status='Priority' WHERE id=?");
    $stmt->execute([$id]);
    header("Location: dashboard.php"); exit();
}

// ======================= FETCH DATA =======================

// Reserved units
$reservedUnits = $pdo->query("SELECT * FROM vehicles WHERE status='Reserved' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Available units
$availableUnits = $pdo->query("SELECT * FROM vehicles WHERE status='Available' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Viewing units (Reserved + optional scheduled)
$viewingUnits = $pdo->query("SELECT * FROM vehicles WHERE status='Reserved'ORDER BY viewing_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// Priority units
$priorityUnits = $pdo->query("SELECT * FROM vehicles WHERE status='Priority'ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

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
    $availableUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Viewing modal search
if(isset($_GET['search_viewing'])){
    $openViewingModal = true;
    $value = trim($_GET['viewing_value'] ?? '');
    $field = $_GET['viewing_field'] ?? 'brand';
    $allowed = ['brand','model','plate_number'];
    if(!in_array($field,$allowed)) $field='brand';
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE status='Reserved' AND $field LIKE :search");
    $stmt->execute([':search'=>"%$value%"]);
    $viewingUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    $availableUnits = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['unit_id'])) {

    $unitId = (int) $_POST['unit_id'];

    $stmt = $pdo->prepare("
        UPDATE vehicles 
        SET status = 'Available',
            viewing_date = NULL,
            viewing_person = NULL
        WHERE id = ?
    ");

    $stmt->execute([$unitId]);

    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}
?>


<body>
<div class="dashboard-container">
    <div class="dashboard-main-box">

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

<!-- ===== Dashboard Action Buttons ===== -->
<div class="dashboard-actions">
    <button class="action-btn" onclick="openModal('reservedModal')">Reserved Units</button>
    <button class="action-btn" onclick="openModal('viewingModal')">Viewing Schedule</button>
    <button class="action-btn" onclick="openModal('priorityModal')">Priority to Sell</button>
</div>

<!-- ===== Dashboard Preview Section ===== -->
<div class="dashboard-preview-container">

    <!-- Reserved Units Preview -->
    <div>
        <div class="preview-title">Reserved Units Preview</div>
        <div class="preview-table-container">
            <table class="preview-table">
                <thead>
                    <tr>
						<th>Year</th>
                        <th>Brand / Model</th>
                        <th>Plate</th>
                        <th>Price</th>
						<th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($reservedUnits as $unit): ?>
                    <tr>
						<td><?= htmlspecialchars($unit['year'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></td>
                        <td><?= htmlspecialchars($unit['plate_number']) ?></td>
                        <td>₱<?= number_format($unit['selling_price'],2) ?></td>
						<td>
						<form method="POST" onsubmit="return confirm('Cancel reservation?')">
							<input type="hidden" name="unit_id" value="<?= $unit['id'] ?>">
							<input type="hidden" name="action" value="cancel_reserved">
							<button type="submit" class="cancel-btn">Cancel</button>
						</form>
					</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Viewing Schedule Preview -->
    <div>
        <div class="preview-title">Viewing Schedule Preview</div>
        <div class="preview-table-container">
            <table class="preview-table">
                <thead>
                    <tr>
						<th>Viewing Date</th>
						<th>Year</th>
                        <th>Brand / Model</th>
                        <th>Plate</th>
                        <th>Price</th>
                        <th>Person</th>
						<th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($viewingUnits as $unit): ?>
                    <tr>
						<td><?= $unit['viewing_date'] ?? 'Not scheduled' ?></td>
						<td><?= htmlspecialchars($unit['year'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></td>
                        <td><?= htmlspecialchars($unit['plate_number']) ?></td>
                        <td>₱<?= number_format($unit['selling_price'],2) ?></td>
                        <td><?= htmlspecialchars($unit['viewing_person'] ?? 'N/A') ?></td>
						<td>
						<form method="POST" onsubmit="return confirm('Cancel viewing schedule?')">
							<input type="hidden" name="unit_id" value="<?= $unit['id'] ?>">
							<input type="hidden" name="action" value="cancel_viewing">
							<button type="submit" class="cancel-btn">Cancel</button>
						</form>
					</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Priority to Sell Preview -->
    <div>
        <div class="preview-title">Priority to Sell Preview</div>
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
                    <tr>
						<td><?= htmlspecialchars($unit['year'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></td>
                        <td><?= htmlspecialchars($unit['plate_number']) ?></td>
                        <td>₱<?= number_format($unit['selling_price'],2) ?></td>
                        <td><?= $unit['status'] ?></td>
						<td>
						<form method="POST" onsubmit="return confirm('Remove priority?')">
							<input type="hidden" name="unit_id" value="<?= $unit['id'] ?>">
							<input type="hidden" name="action" value="cancel_priority">
							<button type="submit" class="cancel-btn">Cancel</button>
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
        <form method="POST">
            <div class="search-results-container">
                <?php foreach($availableUnits as $unit): ?>
                    <div class="result-card">
                        <input type="checkbox" name="bulk_ids[]" value="<?= $unit['id'] ?>">
                        <strong><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></strong><br>
                        Plate: <?= htmlspecialchars($unit['plate_number']) ?><br>
                        Price: ₱<?= number_format($unit['selling_price'],2) ?><br>
                        <button type="submit" name="reserve_single" value="<?= $unit['id'] ?>">Reserve</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <br>
            <button type="submit" name="bulk_reserve">Reserve Selected</button>
        </form>
    </div>
</div>

<!-- Viewing Schedule Modal -->
<div id="viewingModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('viewingModal')">&times;</span>
        <h3>Viewing Schedule</h3>

        <form method="GET">
            <input type="text" name="viewing_value" placeholder="Search reserved units..." value="<?= htmlspecialchars($_GET['viewing_value'] ?? '') ?>">
            <select name="viewing_field">
                <option value="brand" <?= (($_GET['viewing_field'] ?? '')=='brand')?'selected':'' ?>>Brand</option>
                <option value="model" <?= (($_GET['viewing_field'] ?? '')=='model')?'selected':'' ?>>Model</option>
                <option value="plate_number" <?= (($_GET['viewing_field'] ?? '')=='plate_number')?'selected':'' ?>>Plate</option>
            </select>
            <button type="submit" name="search_viewing">Search</button>
        </form>

        <div class="search-results-container">
            <?php foreach($viewingUnits as $unit): ?>
                <div class="result-card">
                    <strong><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></strong><br>
                    Plate: <?= htmlspecialchars($unit['plate_number']) ?><br>
                    Price: ₱<?= number_format($unit['selling_price'],2) ?><br>
                    Viewing Date: <?= $unit['viewing_date'] ?? 'Not scheduled' ?><br>
                    Person: <?= htmlspecialchars($unit['viewing_person'] ?? 'N/A') ?><br>
                    <form method="POST">
                        <input type="hidden" name="vehicle_id" value="<?= $unit['id'] ?>">
                        <input type="date" name="viewing_date" value="<?= $unit['viewing_date'] ?? '' ?>" required>
                        <input type="text" name="viewing_person" value="<?= htmlspecialchars($unit['viewing_person'] ?? '') ?>" placeholder="Person Name" required>
                        <button type="submit" name="schedule_viewing">Save</button>
                    </form>
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

        <div class="search-results-container">
            <?php foreach($availableUnits as $unit): ?>
                <div class="result-card">
                    <strong><?= htmlspecialchars($unit['brand'].' '.$unit['model']) ?></strong><br>
                    Plate: <?= htmlspecialchars($unit['plate_number']) ?><br>
                    Price: ₱<?= number_format($unit['selling_price'],2) ?><br>
                    <form method="POST">
                        <button type="submit" name="priority_single" value="<?= $unit['id'] ?>">Mark Priority</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
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
</script>


</body>