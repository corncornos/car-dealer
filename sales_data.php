<?php
require_once 'config.php';
$pdo = getPDO();

$year  = $_GET['year'] ?? date('Y');
$start = $_GET['start'] ?? null;
$end   = $_GET['end'] ?? null;

$where = "WHERE YEAR(sale_date)=:year";
$params = ['year'=>$year];

if($start && $end){
    $where = "WHERE YEAR(sale_date)=:year AND sale_date BETWEEN :start AND :end";
    $params = [
        'year'=>$year,
        'start'=>$start,
        'end'=>$end
    ];
}

/* TABLE DATA */
$stmt = $pdo->prepare("
    SELECT s.*, v.brand, v.model
    FROM sales s
    JOIN vehicles v ON v.id = s.vehicle_id
    $where
    ORDER BY s.sale_date DESC
");
$stmt->execute($params);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* SUMMARY */
$stmt = $pdo->prepare("SELECT COALESCE(SUM(sale_price),0) FROM sales $where");
$stmt->execute($params);
$totalSales = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM sales $where");
$stmt->execute($params);
$totalSold = $stmt->fetchColumn();
/* MONTHLY DATA */
$stmt = $pdo->prepare("
    SELECT MONTH(sale_date) as month,
           SUM(sale_price) as total_revenue,
           COUNT(*) as total_units
    FROM sales
    $where
    GROUP BY MONTH(sale_date)
    ORDER BY MONTH(sale_date)
");
$stmt->execute($params);
$monthly = $stmt->fetchAll(PDO::FETCH_ASSOC);

$revenueData = array_fill(0, 12, 0);
$unitsData   = array_fill(0, 12, 0);

foreach ($monthly as $row) {
    $index = (int)$row['month'] - 1;
    $revenueData[$index] = (float)$row['total_revenue'];
    $unitsData[$index]   = (int)$row['total_units'];
}

?>
<div class="sales-wrapper">

    <!-- PRINT HEADER -->
    <div class="print-header">
        <img src="images/logo.png" class="print-logo">
        <div class="print-title">
            <h2>CAR INVENTORY SALES REPORT</h2>
            <p>Date Printed: <?= date('F d, Y') ?></p>
        </div>
    </div>

<div class="charts-row">
    <div class="chart-box">
        <h3>Monthly Revenue</h3>
        <canvas id="revenueChart"
            data-chart='<?= json_encode($revenueData) ?>'>
        </canvas>
    </div>

    <div class="chart-box">
        <h3>Monthly Cars Sold</h3>
        <canvas id="unitChart"
            data-chart='<?= json_encode($unitsData) ?>'>
        </canvas>
    </div>
</div>

<div class="summary">
    <div class="box">
        <h4>Total Sales</h4>
        <p>₱<?= number_format($totalSales,2) ?></p>
    </div>
    <div class="box">
        <h4>Sold</h4>
        <p><?= $totalSold ?></p>
    </div>
</div>

<h3 class="sales-title">Sales</h3>

<table class="sales-table">
<thead>
<tr>
<th>Vehicle</th>
<th>Buyer</th>
<th>Price</th>
<th>Date</th>
<th>Method</th>
</tr>
</thead>
<tbody>
<?php foreach($sales as $s): ?>
    <tr>
        <td><?= htmlspecialchars($s['brand'].' '.$s['model']) ?></td>
        <td><?= htmlspecialchars($s['buyer_name']) ?></td>
        <td>₱<?= number_format($s['sale_price'],2) ?></td>
        <td><?= htmlspecialchars($s['sale_date']) ?></td>
        <td><?= htmlspecialchars($s['payment_method']) ?></td>
    </tr>
    <?php endforeach; ?>
</tbody>
</table>
