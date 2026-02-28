<?php
if (session_status() == PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';
function isAdmin() {
    return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin';
}
// allow pages to set $hide_nav = true before including this file
$hide_nav = isset($hide_nav) && $hide_nav;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="vehicles.css">
  <link rel="stylesheet" href="vehicle_add_edit.css">
  <link rel="stylesheet" href="Sales.css">
  <link rel="stylesheet" href="audit_trails.css">
  <title>Autoluxe Car Dealer Inventory</title>
</head>
<body>
<?php if (!$hide_nav): ?>
<!-- Side Navigation -->
<nav class="side-nav">

  <div class="side-nav-header">
<div class="dashboard-header">
    <img src="images/AL5.png" alt="Dashboard Logo">
</div>
    <a href="dashboard.php">
        <img src="images/AL6.png" 
             class="side-nav-logo" 
             alt="Autoluxe Logo">
    </a>
</div>
  <ul class="side-nav-menu">
    <li class="side-nav-item">
      <a class="side-nav-link" href="dashboard.php">Dashboard</a>
    </li>
    <li class="side-nav-item">
      <a class="side-nav-link" href="vehicles.php">Inventory</a>
    </li>
    <li class="side-nav-item">
      <a class="side-nav-link" href="sales.php">Sales</a>
    </li>
    <?php if(isAdmin()): ?>
    <li class="side-nav-item">
      <a class="side-nav-link" href="audit_trails.php">Audit Trails</a>
    </li>
    <?php endif; ?>
  </ul>
  <div class="side-nav-user">
    <?php if(isset($_SESSION['user'])): ?>
        <div class="side-nav-user-info">
            <span class="user-icon">👤</span>
            <span class="user-name"><?php echo htmlspecialchars($_SESSION['user']['name']); ?></span>
        </div>
        <a class="logout-btn" href="logout.php">↪ Logout</a>
    <?php else: ?>
        <a class="login-btn" href="login.php">Login</a>
    <?php endif; ?>
</div>
</nav>
<?php endif; ?>
<div class="main-content">
<div class="container">
<script>
function toggleDetails(element) {
    element.parentElement.classList.toggle("active");
}
</script>
</body>
</html>