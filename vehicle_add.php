<?php
session_start();
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['user'])) header('Location: login.php');
$pdo = getPDO();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $imagePath = null;
    if (!empty($_FILES['image']['name'])) {
        $uploadDir = __DIR__ . '/uploads/vehicles';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
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
    $data = [
        $imagePath,
        $_POST['brand'] ?? null,
        $_POST['model'] ?? null,
        $_POST['year'] ?? null,
        $_POST['color'] ?? null,
        $_POST['transmission'] ?? null,
        $_POST['fuel_type'] ?? null,
        $_POST['mileage'] ?? null,
        $_POST['engine_type'] ?? null,
        $_POST['plate_number'] ?? null,
        $_POST['body_type'] ?? null,
        $_POST['purchase_price'] ?? null,
        $_POST['selling_price'] ?? null,
        $_POST['status'] ?? 'Available',
        $_POST['notes'] ?? null,
    ];

    $stmt = $pdo->prepare('INSERT INTO vehicles 
    (image_path, brand, model, year, color, 
    transmission, fuel_type, mileage, engine_type, plate_number, body_type, purchase_price, selling_price, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute($data);
    $id = $pdo->lastInsertId();
    add_audit($pdo, 'Vehicle Added', json_encode(['brand'=>$_POST['brand'] ?? null,'model'=>$_POST['model'] ?? null]));
    header('Location: vehicles.php');
    exit;
}
require 'header.php';
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
      <div class="form-row">

       

        <div class="form-group">
          <label>Brand</label>
          <select name="brand">
            <option>Toyota</option>
            <option>Honda</option>
            <option>BMW</option>
            <option>Mercedes</option>
            <option>Audi</option>
            <option>Other</option>
          </select>
        </div>

        <div class="form-group">
          <label>Model</label>
          <input type="text" name="model" placeholder="Model">
        </div>
        

        <div class="form-group">
          <label>Year</label>
          <input type="number" name="year" placeholder="Year">
        </div>

        <div class="form-group">
          <label>Color</label>
          <input type="text" name="color" placeholder="Color">
        </div>

        <div class="form-group">
          <label>Transmission</label>
          <select name="transmission">
            <option>Automatic</option>
            <option>Manual</option>
          </select>
        </div>

        <div class="form-group">
          <label>Fuel Type</label>
          <input type="text" name="fuel_type" placeholder="Fuel Type">
        </div>

        <div class="form-group">
          <label>Mileage</label>
          <input type="number" name="mileage" placeholder="Mileage">
        </div>

        <div class="form-group">
          <label>Engine Type</label>
          <input type="text" name="engine_type" placeholder="Engine Type">
        </div>
        <div class="form-group">
          <label>Plate Number</label>
          <input type="text" name="plate_number" placeholder="Plate Number">
        </div>
        <div class="form-group">
          <label>Body Type</label>
          <input type="text" name="body_type" placeholder="Body Type">
        </div>


        <div class="form-group">
          <label>Purchase Price</label>
          <input type="number" step="0.01" name="purchase_price" placeholder="Purchase Price">
        </div>

        <div class="form-group">
          <label>Selling Price</label>
          <input type="number" step="0.01" name="selling_price" placeholder="Selling Price">
        </div>

        <div class="form-group">
          <label>Image</label>
          <input type="file" name="image" accept="image/*">
        </div>

        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <option>Available</option>
            <option>Reserved</option>
          </select>
        </div>

        <div class="form-group full-width">
          <label>Notes</label>
          <textarea name="notes" placeholder="Additional notes"></textarea>
        </div>

      </div>
      <div class="form-actions">
      <button type="submit" class="btn-emoji-save">Add Vehicle</button>
      <button type="reset" class="btn-emoji-cancel" onclick="window.location.href='vehicles.php'">Cancel</button>
      </div>
    </form>
  </div>
</div>

<?php require 'footer.php'; ?>
