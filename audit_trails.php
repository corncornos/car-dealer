<?php
session_start();
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['user'])) header('Location: login.php');
$pdo = getPDO();

// simple search & pagination
$where = [];
$params = [];
$q_user = $_GET['user'] ?? '';
$q_action = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
if ($q_user !== '') { $where[] = 'user_name LIKE ?'; $params[] = '%'.$q_user.'%'; }
if ($q_action !== '') { $where[] = 'action LIKE ?'; $params[] = '%'.$q_action.'%'; }
if ($date_from !== '') { $where[] = 'created_at >= ?'; $params[] = $date_from; }
if ($date_to !== '') { $where[] = 'created_at <= ?'; $params[] = $date_to . ' 23:59:59'; }
$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $where_sql");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$stmt = $pdo->prepare("SELECT * FROM audit_logs $where_sql ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();
$pages = ceil($total / $perPage);

function format_audit_detail($detail, $action = '') {
  $detailStr = is_string($detail) ? trim($detail) : '';
  if ($detailStr === '') return '<span class="text-muted">—</span>';

  $decoded = json_decode($detailStr, true);
  if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
    return nl2br(htmlspecialchars($detailStr, ENT_QUOTES, 'UTF-8'));
  }

  // Friendly labels for common keys
  $labels = [
    'sale_id' => 'Sale ID',
    'vehicle_id' => 'Vehicle ID',
    'buyer' => 'Buyer name',
    'buyer_name' => 'Buyer name',
    'sale_price' => 'Sale price',
    'price' => 'Sale price',
    'payment_method' => 'Payment method',
    'method' => 'Payment method',
    'sale_date' => 'Sale date',
    'date' => 'Sale date',
    'vehicle_type' => 'Vehicle type',
    'brand' => 'Brand',
    'model' => 'Model',
    'year' => 'Year',
    'color' => 'Color',
    'transmission' => 'Transmission',
    'fuel_type' => 'Fuel type',
    'mileage' => 'Mileage',
    'purchase_price' => 'Purchase price',
    'selling_price' => 'Selling price',
    'status' => 'Status',
    'notes' => 'Notes',
  ];

  $toPrintable = function ($v) {
    if ($v === null) return '—';
    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (is_array($v)) return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $s = (string)$v;
    return trim($s) === '' ? '—' : $s;
  };

  // If we have before/after snapshots, show field-level diffs
  $before = $decoded['before'] ?? null;
  $after = $decoded['after'] ?? null;
  if (is_array($before) && is_array($after)) {
    $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
    $parts = [];

    foreach ($keys as $k) {
      if ($k === 'id') continue;
      $old = $toPrintable($before[$k] ?? null);
      $new = $toPrintable($after[$k] ?? null);
      if ($old === $new) continue;

      $key = (string)$k;
      $label = $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
      $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
      $oldEsc = htmlspecialchars($old, ENT_QUOTES, 'UTF-8');
      $newEsc = htmlspecialchars($new, ENT_QUOTES, 'UTF-8');
      $parts[] = '<span>' . $labelEsc . ': ' . $oldEsc . ' - ' . $labelEsc . ': ' . $newEsc . '</span>';
    }

    if ($parts) return '<div class="small">' . implode('<br>', $parts) . '</div>';
  }

  // If only before snapshot exists (e.g., delete), show the snapshot
  if (is_array($before) && $after === null) {
    $decoded = $before;
  }

  $parts = [];
  foreach ($decoded as $k => $v) {
    if ($k === 'before' || $k === 'after') continue;
    if ($v === null || $v === '') continue;
    $key = (string)$k;
    $label = $labels[$key] ?? ucwords(str_replace('_', ' ', $key));

    $value = $toPrintable($v);

    $parts[] = '<span><strong>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</strong>: ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</span>';
  }

  if (!$parts) {
    return nl2br(htmlspecialchars($detailStr, ENT_QUOTES, 'UTF-8'));
  }

  return '<div class="small">' . implode('<br>', $parts) . '</div>';
}

require 'header.php';
if (!isAdmin()) { header('Location: dashboard.php'); exit; }
?>
<h3 class="audit-title">Audit Trails</h3>

<form method="get" class="row g-2 mb-3 audit-form">
  <div class="col-md-3">
    <input name="user" value="<?php echo htmlspecialchars($q_user); ?>" class="form-control" placeholder="User">
  </div>
  <div class="col-md-3">
    <input name="action" value="<?php echo htmlspecialchars($q_action); ?>" class="form-control" placeholder="Action">
  </div>
  <div class="col-md-2">
    <input name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control" type="date" placeholder="From">
  </div>
  <div class="col-md-2">
    <input name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control" type="date" placeholder="To">
  </div>
  <div class="col-md-2">
    <button class="btn">Search</button>
  </div>
</form>

<div class="card">
  <div class="card-body">
    <table class="table table-sm table-striped">
      <thead>
        <tr>
          <th>When</th>
          <th>User</th>
          <th>Action</th>
          <th>Detail</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($logs as $l): ?>
        <tr>
          <td><?php echo htmlspecialchars($l['created_at']); ?></td>
          <td><?php echo htmlspecialchars($l['user_name']); ?></td>
          <td><?php echo htmlspecialchars($l['action']); ?></td>
          <td style="max-width:40rem;word-break:break-word;"><?php echo format_audit_detail($l['detail'], $l['action']); ?></td>
          <td><?php echo htmlspecialchars($l['ip']); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <nav aria-label="Page navigation">
      <dl class="pagination">
        <?php for($p=1;$p<=$pages;$p++): ?>
          <dt class="page-item <?php if($p==$page) echo 'active'; ?>">
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page'=>$p])); ?>"><?php echo $p; ?></a>
          </dt>
        <?php endfor; ?>
      </dl>
    </nav>
  </div>
</div>
<?php require 'footer.php'; ?>

