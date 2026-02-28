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
    $buyer = $_POST['buyer_name'] ?? '';
    $price = $_POST['sale_price'] ?? $v['selling_price'];
    $date = $_POST['sale_date'] ?? date('Y-m-d');
    $method = $_POST['payment_method'] ?? 'Cash';
    $ins = $pdo->prepare('INSERT INTO sales (vehicle_id, buyer_name, sale_price, sale_date, payment_method) VALUES (?,?,?,?,?)');
    $ins->execute([$id, $buyer, $price, $date, $method]);
    $upd = $pdo->prepare('UPDATE vehicles SET status = ? WHERE id = ?');
    $upd->execute(['Sold', $id]);
    // Audit
    add_audit($pdo, 'Sale Created', json_encode(['vehicle_id'=>$id,'buyer'=>$buyer,'price'=>$price,'method'=>$method,'date'=>$date]));
    header('Location: vehicles.php'); exit;
}
require 'header.php';
?>
<link rel="stylesheet" href="sale_mark.css">
<h3 class="lux-title mb-4">Mark Vehicle as Sold</h3>

<div class="card lux-card mb-4">
  <div class="card-body p-4">

    <h5 class="lux-vehicle">
      <?php echo htmlspecialchars($v['brand'].' '.$v['model']); ?>
    </h5>

    <form method="post">

      <div class="mb-3">
        <label>Buyer Name</label>
        <input name="buyer_name" class="form-control" required>
      </div>

      <div class="mb-3">
        <label>Sale Price</label>
        <input name="sale_price"
               class="form-control"
               value="<?php echo htmlspecialchars($v['selling_price']); ?>"
               type="number"
               step="0.01"
               required>
      </div>

      <div class="mb-3">
        <label>Sale Date</label>
        <input name="sale_date"
               class="form-control"
               type="date"
               value="<?php echo date('Y-m-d'); ?>"
               required>
      </div>

      <div class="mb-4">
        <label>Payment Method</label>
        <select name="payment_method" class="form-select" required>
          <option>Cash</option>
          <option>Credit Card</option>
          <option>Financing</option>
          <option>Trade In</option>
        </select>
      </div>

      <button class="btn btn-gold w-100">
        Confirm Sale
      </button>

    </form>
  </div>
</div>
<?php require 'footer.php'; ?>
