<?php
session_start();
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['user'])) header('Location: login.php');
$pdo = getPDO();
$id = $_GET['id'] ?? null;
if (!$id) { header('Location: vehicles.php'); exit; }
$stmt = $pdo->prepare('SELECT * FROM vehicles WHERE id = ?');
$stmt->execute([$id]);
$v = $stmt->fetch();
if (!$v) { header('Location: vehicles.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $imagePath = $v['image_path'] ?? null;
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
                // optionally remove old file
                if (!empty($imagePath) && is_file(__DIR__ . '/' . $imagePath)) {
                    @unlink(__DIR__ . '/' . $imagePath);
                }
                $imagePath = 'uploads/vehicles/' . $filename;
            }
        }
    }
    $after = [
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
        'image_path' => $imagePath,
        'status' => $_POST['status'] ?? 'Available',
        'notes' => $_POST['notes'] ?? null,
    ];
    $data = [
        $after['brand'],
        $after['model'],
        $after['year'],
        $after['color'],
        $after['transmission'],
        $after['fuel_type'],
        $after['mileage'],
        $after['engine_type'],
        $after['plate_number'],
        $after['body_type'],
        $after['purchase_price'],
        $after['selling_price'],
        $after['image_path'],
        $after['status'],
        $after['notes'],
        $id
    ];


    $stmt = $pdo->prepare('UPDATE vehicles SET brand=?,
     model=?, year=?, color=?, transmission=?
     , fuel_type=?, mileage=?, engine_type=?, plate_number=?,
      body_type=?, purchase_price=?, selling_price=?, image_path=?
      , status=?, notes=? WHERE id=?');
    $stmt->execute($data);
    add_audit($pdo, 'Vehicle Updated', json_encode(['id'=>$id,'before'=>$v,'after'=>$after]));
    header('Location: vehicles.php'); exit;
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
<?php require 'footer.php'; ?>
