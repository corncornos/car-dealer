<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$pdo = getPDO();

/* =============================
   FETCH TABLE DATA
============================= */
$stmt = $pdo->query("
    SELECT s.*, v.brand, v.model
    FROM sales s
    JOIN vehicles v ON v.id = s.vehicle_id
    ORDER BY s.sale_date DESC
");
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =============================
   SUMMARY DATA
============================= */
$totalSales = $pdo->query("
    SELECT COALESCE(SUM(sale_price),0) FROM sales
")->fetchColumn();

$totalSold = $pdo->query("
    SELECT COUNT(*) FROM sales
")->fetchColumn();

require 'header.php';
?>

<link rel="stylesheet" href="assets/css/sales.css">

<div class="sales-wrapper">

    <div class="filter-bar">

    <!-- YEAR -->
    <select id="yearFilter">
        <?php 
        $currentYear = date('Y');
        for($y=$currentYear; $y >= $currentYear-5; $y--): ?>
            <option value="<?= $y ?>"><?= $y ?></option>
        <?php endfor; ?>
    </select>

    <!-- DATE RANGE -->
    <input type="date" id="startDate">
    <input type="date" id="endDate">

    <button id="applyFilter">Apply</button>
    <button id="printReport">Print</button>

</div>

<!-- AJAX LOAD AREA -->
<div id="salesContent">
    <?php include 'sales_data.php'; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let revenueChartInstance = null;
let unitChartInstance = null;

function initializeCharts() {

    const revenueCanvas = document.getElementById('revenueChart');
    const unitCanvas = document.getElementById('unitChart');

    if (!revenueCanvas || !unitCanvas) return;

    const months = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];

    const revenueData = JSON.parse(revenueCanvas.dataset.chart);
    const unitData = JSON.parse(unitCanvas.dataset.chart);

    if (revenueChartInstance) revenueChartInstance.destroy();
    if (unitChartInstance) unitChartInstance.destroy();


    revenueChartInstance = new Chart(revenueCanvas, {
        type: 'line',
        data: {
            labels: months,
            datasets: [{
                label: 'Revenue',
                data: revenueData,
                borderColor: '#d4af37',
                backgroundColor: 'rgba(212,175,55,0.15)',
                fill: true,
                tension: 0.4
            }]
        }
    });

    unitChartInstance = new Chart(unitCanvas, {
        type: 'bar',
        data: {
            labels: months,
            datasets: [{
                label: 'Cars Sold',
                data: unitData,
                backgroundColor: 'rgba(222, 176, 25, 0.6)'
            }]
        }
    });
}
/* Initialize first load */
document.addEventListener("DOMContentLoaded", function() {
    initializeCharts();
});

/* Reinitialize after filter */
document.getElementById('applyFilter').addEventListener('click', function(){

    let year  = document.getElementById('yearFilter').value;
    let start = document.getElementById('startDate').value;
    let end   = document.getElementById('endDate').value;

    fetch(`sales_data.php?year=${year}&start=${start}&end=${end}`)
    .then(response => response.text())
    .then(data => {
        document.getElementById('salesContent').innerHTML = data;
        initializeCharts(); // 🔥 VERY IMPORTANT
    });
});
document.getElementById('printReport').addEventListener('click', function(){

    let year  = document.getElementById('yearFilter').value;
    let start = document.getElementById('startDate').value;
    let end   = document.getElementById('endDate').value;

    // Reload filtered data first
    fetch(`sales_data.php?year=${year}&start=${start}&end=${end}`)
    .then(response => response.text())
    .then(data => {
        document.getElementById('salesContent').innerHTML = data;

        initializeCharts(); // re-render charts

        // Wait a moment so charts fully render
        setTimeout(() => {
            window.print();
        }, 500);
    });
});
</script>


<?php require 'footer.php'; ?>