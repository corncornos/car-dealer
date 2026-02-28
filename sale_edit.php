<?php
session_start();
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['user'])) header('Location: login.php');
$pdo = getPDO();
$id = $_GET['id'] ?? null;
if (!$id) { header('Location: sales.php'); exit; }
$stmt = $pdo->prepare('SELECT * FROM sales WHERE id = ?');
$stmt->execute([$id]);
$sale = $stmt->fetch();
if (!$sale) { header('Location: sales.php'); exit; }
// fetch vehicle for display
$vst = $pdo->prepare('SELECT brand, model FROM vehicles WHERE id = ?');
$vst->execute([$sale['vehicle_id']]);
$veh = $vst->fetch();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $buyer = $_POST['buyer_name'] ?? '';
    $price = $_POST['sale_price'] ?? $sale['sale_price'];
    $date = $_POST['sale_date'] ?? $sale['sale_date'];
    $method = $_POST['payment_method'] ?? $sale['payment_method'];
    $upd = $pdo->prepare('UPDATE sales SET buyer_name=?, sale_price=?, sale_date=?, payment_method=? WHERE id=?');
    $upd->execute([$buyer, $price, $date, $method, $id]);
    $after = [
        'buyer_name' => $buyer,
        'sale_price' => $price,
        'sale_date' => $date,
        'payment_method' => $method,
    ];
    add_audit($pdo, 'Sale Edited', json_encode(['sale_id'=>$id,'vehicle_id'=>$sale['vehicle_id'],'before'=>$sale,'after'=>$after]));
    header('Location: sales.php'); exit;
}
require 'header.php';
?>
<h3>Edit Sale</h3>
<div class="card mb-3"><div class="card-body">
  <h5><?php echo htmlspecialchars(($veh['brand'] ?? '').' '.($veh['model'] ?? '')); ?></h5>
  <form method="post">
    <div class="mb-3"><label>Buyer Name</label><input name="buyer_name" class="form-control" value="<?php echo htmlspecialchars($sale['buyer_name']); ?>"></div>
    <div class="mb-3"><label>Sale Price</label><input name="sale_price" class="form-control" value="<?php echo htmlspecialchars($sale['sale_price']); ?>" type="number" step="0.01"></div>
    <div class="mb-3"><label>Sale Date</label><input name="sale_date" class="form-control" type="date" value="<?php echo htmlspecialchars($sale['sale_date']); ?>"></div>
    <div class="mb-3"><label>Payment Method</label><select name="payment_method" class="form-select"><option <?php if($sale['payment_method']=='Cash') echo 'selected'; ?>>Cash</option><option <?php if($sale['payment_method']=='Credit Card') echo 'selected'; ?>>Credit Card</option><option <?php if($sale['payment_method']=='Financing') echo 'selected'; ?>>Financing</option></select></div>
    <button class="btn btn-primary">Save</button>
  </form>
</div></div>
<?php require 'footer.php'; ?>
