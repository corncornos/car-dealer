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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        showValidationError('CSRF token validation failed. Please refresh the page and try again.');
        exit();
    }
    
    $imagePath = $v['image_path'] ?? null;
    if (!empty($_FILES['image']['name'])) {
        $uploadDir = __DIR__ . '/uploads/vehicles';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
    }
    
    // Validate and sanitize input
    try {
        // Validate file upload if image was provided
        if (!empty($_FILES['image']['name'])) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            validateFileUpload($_FILES['image'], $allowedTypes, 5242880); // 5MB limit
            
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $ext = strtolower($ext);
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $filename = 'veh_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $target = $uploadDir . '/' . $filename;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                    $imagePath = 'uploads/vehicles/' . $filename;
                }
            }
        }
        
        $brand = validateRequired($_POST['brand'] ?? '', 'Brand');
        $brand = validateString($brand, 'Brand', 1, 100);
        
        $model = validateRequired($_POST['model'] ?? '', 'Model');
        $model = validateString($model, 'Model', 1, 100);
        
        $year = validateYear($_POST['year'] ?? '');
        
        $color = validateString($_POST['color'] ?? '', 'Color', 0, 50);
        
        $transmission = validateString($_POST['transmission'] ?? '', 'Transmission', 0, 50);
        
        $fuel_type = validateString($_POST['fuel_type'] ?? '', 'Fuel Type', 0, 50);
        
        $mileage = validateNumber($_POST['mileage'] ?? '0', 'Mileage', 0, 9999999);
        
        $engine_type = validateString($_POST['engine_type'] ?? '', 'Engine Type', 0, 100);
        
        $plate_number = validatePlateNumber($_POST['plate_number'] ?? '');
        
        $body_type = validateString($_POST['body_type'] ?? '', 'Body Type', 0, 50);
        
        $purchase_price = validatePrice($_POST['purchase_price'] ?? '0');
        $selling_price = validatePrice($_POST['selling_price'] ?? '0');
        
        $status = in_array($_POST['status'] ?? '', ['Available', 'Reserved', 'Sold', 'Priority']) 
            ? $_POST['status'] 
            : 'Available';
        
        $notes = validateString($_POST['notes'] ?? '', 'Notes', 0, 1000);
        
    } catch (InvalidArgumentException $e) {
        showValidationError($e->getMessage());
        exit();
    }
    
    $stmt = $pdo->prepare('UPDATE vehicles SET brand=?, model=?, year=?, color=?, transmission=?, fuel_type=?, mileage=?, engine_type=?, plate_number=?, body_type=?, purchase_price=?, selling_price=?, image_path=?, status=?, notes=? WHERE id=?');
    $stmt->execute([$brand, $model, $year, $color, $transmission, $fuel_type, $mileage, $engine_type, $plate_number, $body_type, $purchase_price, $selling_price, $imagePath, $status, $notes, $id]);
    
    // Create after state for audit
    $afterState = [
        'brand' => $brand,
        'model' => $model,
        'year' => $year,
        'color' => $color,
        'transmission' => $transmission,
        'fuel_type' => $fuel_type,
        'mileage' => $mileage,
        'engine_type' => $engine_type,
        'plate_number' => $plate_number,
        'body_type' => $body_type,
        'purchase_price' => $purchase_price,
        'selling_price' => $selling_price,
        'image_path' => $imagePath,
        'status' => $status,
        'notes' => $notes
    ];
    
    add_audit_with_diff($pdo, 'Vehicle Updated', $v, $afterState, $id);
    SheetsSync::push('vehicles');
    header('Location: vehicles.php');
    exit;
}
require 'core/header.php';
displayValidationErrorIfExists();
?>
<div class="add-edit-vehicle-page">
  <div class="emoji-form-card">

    <!-- Logo -->
    <div class="form-logo">
      <img src="images/AL4.png" alt="Autoluxe Logo">
    </div>

    <!-- Title -->
    <h3>Add Vehicle</h3>

    <!-- Form -->
    <form method="post" enctype="multipart/form-data" class="vehicle-form">
      <?= getCSRFInput() ?>
      <div class="form-row">

        <div class="form-group">
          <label>Brand</label>
          <input type="text" name="brand" value="<?php echo htmlspecialchars($v['brand']); ?>" class="form-control">
        </div>

        <div class="form-group">
          <label>Model</label>
          <input type="text" name="model" value="<?php echo htmlspecialchars($v['model']); ?>" class="form-control">
        </div>

        <div class="form-group">
          <label>Year</label>
          <input type="number" name="year" value="<?php echo htmlspecialchars($v['year']); ?>" class="form-control">
        </div>

        <div class="form-group">
          <label>Color</label>
          <input type="text" name="color" value="<?php echo htmlspecialchars($v['color']); ?>" class="form-control">
        </div>

        <div class="form-group">
          <label>Transmission</label>
          <select name="transmission" class="form-control">
            <option <?php echo ($v['transmission']=='Automatic') ? 'selected' : ''; ?>>Automatic</option>
            <option <?php echo ($v['transmission']=='Manual') ? 'selected' : ''; ?>>Manual</option>
          </select>
        </div>

        <div class="form-group">
          <label>Fuel Type</label>
          <input type="text" name="fuel_type" value="<?php echo htmlspecialchars($v['fuel_type']); ?>" class="form-control">
        </div>

        <div class="form-group">
          <label>Mileage</label>
          <input type="number" name="mileage" value="<?php echo htmlspecialchars($v['mileage']); ?>" class="form-control">
        </div>

         <div class="form-group">
          <label>Engine Type</label>
          <input type="text" name="engine_type" value="<?php echo htmlspecialchars($v['engine_type']); ?>" class="form-control">
        </div>
         <div class="form-group">
          <label>Plate Number</label>
          <input type="text" name="plate_number" value="<?php echo htmlspecialchars($v['plate_number']); ?>" class="form-control">
        </div>
         <div class="form-group">
          <label>Body Type</label>
          <input type="text" name="body_type" value="<?php echo htmlspecialchars($v['body_type']); ?>" class="form-control">
        </div>

        <div class="form-group">
          <label>Purchase Price</label>
          <input type="number" step="0.01" name="purchase_price" value="<?php echo htmlspecialchars($v['purchase_price']); ?>" class="form-control">
        </div>

        <div class="form-group">
          <label>Selling Price</label>
          <input type="number" step="0.01" name="selling_price" value="<?php echo htmlspecialchars($v['selling_price']); ?>" class="form-control">
        </div>

        <div class="form-group">
          <label>Image</label>
          <input type="file" name="image" value="<?php echo htmlspecialchars($v['image_path']); ?>" accept="image/*">
        </div>

        <div class="form-group">
          <label>Status</label>
          <select name="status" value="<?php echo htmlspecialchars($v['status']); ?>">
            <option>Available</option>
            <option>Reserved</option>
          </select>
        </div>

        <div class="form-group full-width">
          <label>Notes</label>
          <textarea name="notes" value="<?php echo htmlspecialchars($v['notes']); ?>" class="form-control"></textarea>
        </div>
        
        </div>
        <div class="form-actions">
          <button type="submit" class="btn-emoji-save">Update Vehicle</button>
          <button type="reset" class="btn-emoji-cancel" onclick="window.location.href='vehicles.php'">Cancel</button>
        </div>
        </form>
    </div>
</div>
<?php require 'core/footer.php'; ?>