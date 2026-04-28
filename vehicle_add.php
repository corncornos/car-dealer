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
$err = '';

// Re-populate form values after validation errors (saved in session)
$old = [];
if (isset($_SESSION['vehicle_add_old']) && is_array($_SESSION['vehicle_add_old'])) {
    $oldTs = (int)($_SESSION['vehicle_add_old']['ts'] ?? 0);
    if ($oldTs > 0 && (time() - $oldTs) <= 3600) { // keep draft for up to 1 hour
        $old = (array)($_SESSION['vehicle_add_old']['data'] ?? []);
    } else {
        unset($_SESSION['vehicle_add_old']);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['vehicle_add_old'] = [
            'ts' => time(),
            'data' => [
                'brand' => $_POST['brand'] ?? '',
                'model' => $_POST['model'] ?? '',
                'year' => $_POST['year'] ?? '',
                'color' => $_POST['color'] ?? '',
                'transmission' => $_POST['transmission'] ?? '',
                'fuel_type' => $_POST['fuel_type'] ?? '',
                'mileage' => $_POST['mileage'] ?? '',
                'engine_type' => $_POST['engine_type'] ?? '',
                'plate_number' => $_POST['plate_number'] ?? '',
                'body_type' => $_POST['body_type'] ?? '',
                'purchase_price' => $_POST['purchase_price'] ?? '',
                'selling_price' => $_POST['selling_price'] ?? '',
                'status' => $_POST['status'] ?? '',
                'notes' => $_POST['notes'] ?? '',
            ],
        ];
        showValidationError('CSRF token validation failed. Please refresh the page and try again.');
        exit();
    }
    
    $imagePath = null;
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
                if (!move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                    throw new InvalidArgumentException('Failed to save uploaded image. Please try again.');
                }
                $imagePath = 'uploads/vehicles/' . $filename;
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
        $_SESSION['vehicle_add_old'] = [
            'ts' => time(),
            'data' => [
                'brand' => $_POST['brand'] ?? '',
                'model' => $_POST['model'] ?? '',
                'year' => $_POST['year'] ?? '',
                'color' => $_POST['color'] ?? '',
                'transmission' => $_POST['transmission'] ?? '',
                'fuel_type' => $_POST['fuel_type'] ?? '',
                'mileage' => $_POST['mileage'] ?? '',
                'engine_type' => $_POST['engine_type'] ?? '',
                'plate_number' => $_POST['plate_number'] ?? '',
                'body_type' => $_POST['body_type'] ?? '',
                'purchase_price' => $_POST['purchase_price'] ?? '',
                'selling_price' => $_POST['selling_price'] ?? '',
                'status' => $_POST['status'] ?? '',
                'notes' => $_POST['notes'] ?? '',
            ],
        ];
        showValidationError($e->getMessage());
        exit();
    }
    
    $data = [
        $imagePath,
        $brand,
        $model,
        $year,
        $color,
        $transmission,
        $fuel_type,
        $mileage,
        $engine_type,
        $plate_number,
        $body_type,
        $purchase_price,
        $selling_price,
        $status,
        $notes,
    ];

    $stmt = $pdo->prepare('INSERT INTO vehicles 
    (image_path, brand, model, year, color, 
    transmission, fuel_type, mileage, engine_type, plate_number, body_type, purchase_price, selling_price, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute($data);
    $id = $pdo->lastInsertId();
    
    // Log complete vehicle data for audit
    $vehicleData = [
        'id' => $id,
        'brand' => $_POST['brand'] ?? null,
        'model' => $_POST['model'] ?? null,
        'year' => $_POST['year'] ?? null,
        'color' => $_POST['color'] ?? null,
        'transmission' => $_POST['transmission'] ?? null,
        'fuel_type' => $_POST['fuel_type'] ?? null,
        'mileage' => $_POST['mileage'] ?? null,
        'engine_type' => $_POST['engine_type'] ?? null,
        'plate_number' => $_POST['plate_number'] ?? null,
        'body_type' => $_POST['body_type'] ?? null,
        'purchase_price' => $_POST['purchase_price'] ?? null,
        'selling_price' => $_POST['selling_price'] ?? null,
        'status' => $_POST['status'] ?? null,
        'notes' => $_POST['notes'] ?? null,
        'image_path' => $imagePath
    ];
    add_audit($pdo, 'Vehicle Added', json_encode($vehicleData));
    SheetsSync::push('vehicles');
    unset($_SESSION['vehicle_add_old']);
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
          <input type="text" name="brand" placeholder="Brand" value="<?= htmlspecialchars($old['brand'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
      
        
        </div>

        <div class="form-group">
          <label>Model</label>
          <input type="text" name="model" placeholder="Model" value="<?= htmlspecialchars($old['model'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        

        <div class="form-group">
          <label>Year</label>
          <input type="number" name="year" placeholder="Year" value="<?= htmlspecialchars($old['year'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
          <label>Color</label>
          <input type="text" name="color" placeholder="Color" value="<?= htmlspecialchars($old['color'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
          <label>Transmission</label>
          <select name="transmission">
            <?php $oldTransmission = $old['transmission'] ?? 'Automatic'; ?>
            <option value="Automatic" <?= $oldTransmission === 'Automatic' ? 'selected' : '' ?>>Automatic</option>
            <option value="Manual" <?= $oldTransmission === 'Manual' ? 'selected' : '' ?>>Manual</option>
          </select>
        </div>

        <div class="form-group">
          <label>Fuel Type</label>
          <input type="text" name="fuel_type" placeholder="Fuel Type" value="<?= htmlspecialchars($old['fuel_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
          <label>Mileage</label>
          <input type="number" name="mileage" placeholder="Mileage" value="<?= htmlspecialchars($old['mileage'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
          <label>Engine Type</label>
          <input type="text" name="engine_type" placeholder="Engine Type" value="<?= htmlspecialchars($old['engine_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
          <label>Plate Number</label>
          <input type="text" name="plate_number" placeholder="Plate Number" value="<?= htmlspecialchars($old['plate_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
          <label>Body Type</label>
          <input type="text" name="body_type" placeholder="Body Type" value="<?= htmlspecialchars($old['body_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>


        <div class="form-group">
          <label>Purchase Price</label>
          <input type="number" step="0.01" name="purchase_price" placeholder="Purchase Price" value="<?= htmlspecialchars($old['purchase_price'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
          <label>Selling Price</label>
          <input type="number" step="0.01" name="selling_price" placeholder="Selling Price" value="<?= htmlspecialchars($old['selling_price'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="form-group">
          <label>Image</label>
          <input type="file" name="image" accept="image/*">
        </div>

        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <?php $oldStatus = $old['status'] ?? 'Available'; ?>
            <option value="Available" <?= $oldStatus === 'Available' ? 'selected' : '' ?>>Available</option>
            <option value="Reserved" <?= $oldStatus === 'Reserved' ? 'selected' : '' ?>>Reserved</option>
          </select>
        </div>

        <div class="form-group full-width">
          <label>Notes</label>
          <textarea name="notes" placeholder="Additional notes"><?= htmlspecialchars($old['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

      </div>
      <div class="form-actions">
      <button type="submit" class="btn-emoji-save">Add Vehicle</button>
      <button type="reset" class="btn-emoji-cancel" onclick="window.location.href='vehicles.php'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<?php require 'core/footer.php'; ?>