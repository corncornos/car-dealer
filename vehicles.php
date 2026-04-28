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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        showValidationError('CSRF token validation failed. Please refresh the page and try again.');
        exit();
    }

    // 1. Check if file exists
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        showValidationError("Please select a valid CSV file.");
        exit();
    }

    // 2. Validate file type
    $fileTmpPath = $_FILES['file']['tmp_name'];
    $fileName = $_FILES['file']['name'];
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if ($fileExtension !== 'csv') {
        showValidationError("Only CSV files are allowed.");
        exit();
    }

    // 3. Open file safely
    if (($handle = fopen($fileTmpPath, 'r')) === false) {
        showValidationError("Unable to open uploaded file.");
        exit();
    }

    // 4. Read header row
    $header = fgetcsv($handle);

    if (!$header) {
        showValidationError("Invalid CSV format.");
        fclose($handle);
        exit();
    }

    // 5. Prepare INSERT statement for vehicles table
    $stmt = $pdo->prepare("
        INSERT INTO vehicles (
            image_path, year, brand, model, transmission,
            mileage, plate_number, body_type, color,
            engine_type, fuel_type, status,
            purchase_price, selling_price, created_at
        ) VALUES (
            :image_path, :year, :brand, :model, :transmission,
            :mileage, :plate_number, :body_type, :color,
            :engine_type, :fuel_type, :status,
            :purchase_price, :selling_price, NOW()
        )
    ");

    // 6. Begin transaction (FAST bulk insert)
    $pdo->beginTransaction();

    while (($row = fgetcsv($handle, 1000, ",")) !== false) {

        if (count($row) !== count($header)) {
            continue; // skip invalid rows
        }

        $data = array_combine($header, $row);

        $stmt->execute([
            ':image_path'     => $data['image_path'] ?? null,
            ':year'           => $data['year'] ?? null,
            ':brand'          => $data['brand'] ?? null,
            ':model'          => $data['model'] ?? null,
            ':transmission'   => $data['transmission'] ?? null,
            ':mileage'        => $data['mileage'] ?? null,
            ':plate_number'   => $data['plate_number'] ?? null,
            ':body_type'      => $data['body_type'] ?? null,
            ':color'          => $data['color'] ?? null,
            ':engine_type'    => $data['engine_type'] ?? null,
            ':fuel_type'      => $data['fuel_type'] ?? null,
            ':status'         => $data['status'] ?? 'Available',
            ':purchase_price' => $data['purchase_price'] ?? 0,
            ':selling_price'  => $data['selling_price'] ?? 0,
        ]);
    }

    $pdo->commit();
    fclose($handle);

    // Log CSV import operation
    $importData = [
        'file_name' => $fileName,
        'total_rows_processed' => $rowCount,
        'import_timestamp' => date('Y-m-d H:i:s'),
        'status' => 'Success',
        'columns_imported' => $header
    ];
    add_audit($pdo, 'CSV Import Completed', json_encode($importData));

    echo "<script>alert('Bulk import completed successfully! $rowCount vehicles imported.'); window.location.href='vehicles.php';</script>";
}


// Pagination setup
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Build WHERE conditions
$where = [];
$params = [];

// By default hide sold units from inventory listing. If a status is explicitly requested, honor it.
if (empty($_GET['status'])) {
  $where[] = "status <> 'Sold'";
}
if (!empty($_GET['brand'])) { 
  $where[] = "brand LIKE ?"; 
  $params[] = '%'.$_GET['brand'].'%'; 
}
if (!empty($_GET['model'])) { 
  $where[] = "model LIKE ?"; 
  $params[] = '%'.$_GET['model'].'%'; 
}
if (!empty($_GET['year'])) { 
  $where[] = "year = ?"; 
  $params[] = $_GET['year']; 
}
if (!empty($_GET['status'])) { 
  $where[] = "status = ?"; 
  $params[] = $_GET['status']; 
}

$whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

// Sort order
$order = 'created_at DESC';
if (!empty($_GET['sort'])) {
    if ($_GET['sort'] === 'price_asc') $order = 'selling_price ASC';
    if ($_GET['sort'] === 'price_desc') $order = 'selling_price DESC';
    if ($_GET['sort'] === 'date_asc') $order = 'created_at ASC';
    if ($_GET['sort'] === 'date_desc') $order = 'created_at DESC';
}

// Count total records for pagination
$countSql = "SELECT COUNT(*) FROM vehicles $whereClause";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$pages = ceil($total / $perPage);

// Get paginated results
$sql = "SELECT * FROM vehicles $whereClause ORDER BY $order LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

require __DIR__ . '/core/header.php';
displayValidationErrorIfExists();
?>


<div class="inventory-container">

  <!-- Header -->
  <div class="inventory-header">
    <div>
      <h2> Vehicle Inventory</h2>
      <p>Manage and monitor all available units</p>
      <h2>ADD CSV HERE</h2>
      <form method="post" class="form-data" enctype="multipart/form-data">
      <?= getCSRFInput() ?>
      <input type="file" name="file" accept=".csv">
      <input type="submit" name="import" value="Import">
    </form>
    </div>
    <div><a href="vehicle_add.php" class="btn-add">+ Add Vehicle</a></div>
  </div>
    <?php

?>
    
  

  <!-- Filter Card -->
  <div class="filter-card">
    <form method="get" class="filter-form">
      <input name="brand" value="<?php echo htmlspecialchars($_GET['brand'] ?? '') ?>" placeholder="Brand">

      <input name="model" value="<?php echo htmlspecialchars($_GET['model'] ?? '') ?>" placeholder="Model">

      <input name="year" value="<?php echo htmlspecialchars($_GET['year'] ?? '') ?>" placeholder="Year">

      <select name="status">
        <option value="">Any Status</option>
        <option value="Available" <?php if(($_GET['status'] ?? '')=='Available') echo 'selected'; ?>>Available</option>
        <option value="Sold" <?php if(($_GET['status'] ?? '')=='Sold') echo 'selected'; ?>>Sold</option>
        <option value="Reserved" <?php if(($_GET['status'] ?? '')=='Reserved') echo 'selected'; ?>>Reserved</option>
        <option value="Priority" <?php if(($_GET['status'] ?? '')=='Priority') echo 'selected'; ?>>Priority</option>
      </select>

      <select name="sort">
        <option value="">Sort</option>
        <option value="price_asc">Price ↑</option>
        <option value="price_desc">Price ↓</option>
        <option value="date_asc">Date ↑</option>
        <option value="date_desc">Date ↓</option>
      </select>

      <button type="submit" class="btn-search">Search</button>
    </form>
  </div>

  <!-- Table -->
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Image</th>
          <th>Brand</th>
          <th>Model</th>
          <th>Year</th>
          <th>Price</th>
          <th>Status</th>
          <th>Date Added</th>
          <th>Actions</th>
        </tr>
      </thead>

      <tbody>
        <?php if (empty($vehicles)): ?>
          <tr>
            <td colspan="8" style="text-align: center; padding: 40px; color: #888;">
              <div style="font-size: 16px; margin-bottom: 10px;">🚗 No vehicles found</div>
              <div style="font-size: 14px;">Try adjusting your filters or add a new vehicle to get started.</div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach($vehicles as $v): ?>
        <?php
          $img = htmlspecialchars($v['image_path'] ?? '');
          $brand = htmlspecialchars($v['brand']);
          $model = htmlspecialchars($v['model']);
          $year = htmlspecialchars($v['year']);
          $status = htmlspecialchars($v['status']);
          $price = '₱' . number_format($v['selling_price'], 2);
          $date = htmlspecialchars($v['created_at']);
          // Build JSON data for modal (escape for HTML attribute)
          $data = htmlspecialchars(json_encode([
            'img'            => $v['image_path'] ?? '',
            'brand'          => $v['brand'],
            'model'          => $v['model'],
            'year'           => $v['year'],
            'status'         => $v['status'],
            'price'          => $price,
            'date'           => $v['created_at'],
            'id'             => $v['id'],
            'vehicle_type'   => $v['vehicle_type'] ?? '',
            'purchase_price' => $v['purchase_price'] ? '₱' . number_format($v['purchase_price'], 2) : '',
            'color'          => $v['color'] ?? '',
            'transmission'   => $v['transmission'] ?? '',
            'fuel_type'      => $v['fuel_type'] ?? '',
            'mileage'        => $v['mileage'] ?? '',
            'notes'          => $v['notes'] ?? '',
            'engine_type'    => $v['engine_type'] ?? '',
            'plate_number'   => $v['plate_number'] ?? '',
            'body_type'      => $v['body_type'] ?? '',
          ]), ENT_QUOTES);
        ?>
        <tr onclick="openModal(this)" data-vehicle='<?php echo $data; ?>'>
          <td>
            <?php if(!empty($v['image_path'])): ?>
              <img src="<?php echo $img; ?>" class="vehicle-img">
            <?php else: ?>
              <span class="no-image">No Image</span>
            <?php endif; ?>
          </td>

          <td><?php echo $brand; ?></td>
          <td><?php echo $model; ?></td>
          <td><?php echo $year; ?></td>
          <td class="price"><?php echo $price; ?></td>

          <td>
            <span class="badge <?php echo strtolower($v['status']); ?>">
              <?php echo $status; ?>
            </span>
          </td>

          <td><?php echo $date; ?></td>

          <td class="actions" onclick="event.stopPropagation()">
            <a href="vehicle_edit.php?id=<?php echo $v['id']; ?>" class="btn-action edit">Edit</a>
            <a href="vehicle_delete.php?id=<?php echo $v['id']; ?>" class="btn-action delete" onclick="return confirmVehicleDelete(event, <?php echo (int)$v['id']; ?>)">Delete</a>

            <?php if($v['status'] !== 'Sold'): ?>
              <a href="sale_mark.php?id=<?php echo $v['id']; ?>" class="btn-action sold">Mark Sold</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>

    </table>
  </div>

</div>

<!-- Vehicle Detail Modal -->
<div class="modal-overlay" id="vehicleModal" onclick="closeModalOnOverlay(event)">
  <div class="modal-card">
    <button class="modal-close" onclick="closeModal()" title="Close">&times;</button>
    <img id="mImg" class="modal-img" src="" alt="Vehicle Image" style="display:none">
    <div id="mNoImg" style="display:none; text-align:center; padding:20px; color:#555; font-size:13px;">No Image Available</div>
    <div class="modal-title" id="mTitle"></div>
    <div class="modal-grid">
      <div class="modal-field"><label>Vehicle Type</label><span id="mVehicleType"></span></div>
      <div class="modal-field"><label>Brand</label><span id="mBrand"></span></div>
      <div class="modal-field"><label>Model</label><span id="mModel"></span></div>
      <div class="modal-field"><label>Year</label><span id="mYear"></span></div>
      <div class="modal-field"><label>Purchase Price</label><span id="mPurchasePrice"></span></div>
      <div class="modal-field"><label>Selling Price</label><span class="price" id="mPrice"></span></div>
      <div class="modal-field"><label>Status</label><span id="mStatus"></span></div>
      <div class="modal-field"><label>Color</label><span id="mColor"></span></div>
      <div class="modal-field"><label>Transmission</label><span id="mTransmission"></span></div>
      <div class="modal-field"><label>Fuel Type</label><span id="mFuelType"></span></div>
      <div class="modal-field"><label>Mileage</label><span id="mMileage"></span></div>
      <div class="modal-field"><label>Engine Type</label><span id="mEngineType"></span></div>
      <div class="modal-field"><label>Plate Number</label><span id="mPlateNumber"></span></div>
      <div class="modal-field"><label>Body Type</label><span id="mBodyType"></span></div>
      <div class="modal-field"><label>Date Added</label><span id="mDate"></span></div>
    </div>
    <div class="modal-notes-section">
      <div class="modal-field"><label>Notes</label><span id="mNotes"></span></div>
    </div>
    <div class="modal-actions" id="mActions"></div>
  </div>
</div>

<script>
function openModal(row) {
  const v = JSON.parse(row.getAttribute('data-vehicle'));
  const img = document.getElementById('mImg');
  const noImg = document.getElementById('mNoImg');
  if (v.img) {
    img.src = v.img;
    img.style.display = 'block';
    noImg.style.display = 'none';
  } else {
    img.style.display = 'none';
    noImg.style.display = 'block';
  }
  document.getElementById('mTitle').textContent = v.brand + ' ' + v.model;
  
  // Populate all fields
  document.getElementById('mVehicleType').textContent = v.vehicle_type || 'N/A';
  document.getElementById('mBrand').textContent = v.brand || 'N/A';
  document.getElementById('mModel').textContent = v.model || 'N/A';
  document.getElementById('mYear').textContent = v.year || 'N/A';
  document.getElementById('mPurchasePrice').textContent = v.purchase_price || 'N/A';
  document.getElementById('mPrice').textContent = v.price || 'N/A';
  document.getElementById('mColor').textContent = v.color || 'N/A';
  document.getElementById('mTransmission').textContent = v.transmission || 'N/A';
  document.getElementById('mFuelType').textContent = v.fuel_type || 'N/A';
  document.getElementById('mMileage').textContent = v.mileage || 'N/A';
  document.getElementById('mEngineType').textContent = v.engine_type || 'N/A';
  document.getElementById('mPlateNumber').textContent = v.plate_number || 'N/A';
  document.getElementById('mBodyType').textContent = v.body_type || 'N/A';
  document.getElementById('mDate').textContent = v.date || 'N/A';
  document.getElementById('mNotes').textContent = v.notes || 'No notes available';

  const statusEl = document.getElementById('mStatus');
  statusEl.innerHTML = '<span class="badge ' + v.status.toLowerCase() + '">' + v.status + '</span>';

  const actions = document.getElementById('mActions');
  actions.innerHTML =
    '<a href="vehicle_edit.php?id=' + v.id + '" class="btn-action edit">Edit</a>' +
    '<a href="vehicle_delete.php?id=' + v.id + '" class="btn-action delete" onclick="return confirmVehicleDelete(event, ' + v.id + ')">Delete</a>' +
    (v.status !== 'Sold' ? '<a href="sale_mark.php?id=' + v.id + '" class="btn-action sold">Mark Sold</a>' : '');

  document.getElementById('vehicleModal').classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeModal() {
  document.getElementById('vehicleModal').classList.remove('active');
  document.body.style.overflow = '';
}

function closeModalOnOverlay(e) {
  if (e.target === document.getElementById('vehicleModal')) closeModal();
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeModal();
});

function showDeleteActionModal(options) {
  var existing = document.getElementById('actionConfirmModal');
  if (existing) {
    existing.remove();
  }

  var title = options.title || 'Please Confirm';
  var message = options.message || '';
  var confirmText = options.confirmText || 'OK';
  var cancelText = options.cancelText || 'Cancel';
  var onConfirm = typeof options.onConfirm === 'function' ? options.onConfirm : function () {};

  var modalHTML =
    '<div id="actionConfirmModal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999;">' +
      '<div style="background:white;padding:30px;border-radius:10px;max-width:420px;width:90%;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);">' +
        '<h3 style="color:#d32f2f;margin-bottom:15px;">' + title.replace(/</g, '&lt;') + '</h3>' +
        '<p style="color:#444;margin-bottom:25px;line-height:1.5;">' + message.replace(/</g, '&lt;') + '</p>' +
        '<div style="display:flex;gap:10px;justify-content:center;">' +
          '<button id="actionConfirmYes" style="background:#d32f2f;color:#fff;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;min-width:110px;">' + confirmText + '</button>' +
          '<button id="actionConfirmNo" style="background:#ccc;color:#333;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;min-width:110px;">' + cancelText + '</button>' +
        '</div>' +
      '</div>' +
    '</div>';

  document.body.insertAdjacentHTML('beforeend', modalHTML);

  var root = document.getElementById('actionConfirmModal');
  var yesBtn = document.getElementById('actionConfirmYes');
  var noBtn = document.getElementById('actionConfirmNo');

  function close() {
    if (root) {
      root.remove();
    }
  }

  yesBtn.addEventListener('click', function () {
    close();
    onConfirm();
  });

  noBtn.addEventListener('click', function () {
    close();
  });
}

function confirmVehicleDelete(e, id) {
  if (e && e.preventDefault) {
    e.preventDefault();
  }

  showDeleteActionModal({
    title: 'Delete Vehicle',
    message: 'Are you sure you want to delete this vehicle? This action cannot be undone.',
    confirmText: 'Yes, Delete',
    cancelText: 'No, Keep Vehicle',
    onConfirm: function () {
      window.location.href = 'vehicle_delete.php?id=' + encodeURIComponent(id);
    }
  });

  return false;
}
</script>
<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="pagination-wrapper">
  <div class="pagination-info">
    Showing <?php echo (($page - 1) * $perPage) + 1; ?> to <?php echo min($page * $perPage, $total); ?> of <?php echo $total; ?> vehicles
  </div>
  
  <nav aria-label="Page navigation">
    <ul class="pagination">
      <!-- Previous button -->
      <?php if ($page > 1): ?>
        <li class="page-item">
          <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
            <span aria-hidden="true">&laquo;</span>
            <span class="sr-only">Previous</span>
          </a>
        </li>
      <?php else: ?>
        <li class="page-item disabled">
          <span class="page-link" aria-label="Previous">
            <span aria-hidden="true">&laquo;</span>
            <span class="sr-only">Previous</span>
          </span>
        </li>
      <?php endif; ?>
      
      <!-- Page numbers -->
      <?php
        $showPages = 5; // Number of page links to show
        $startPage = max(1, $page - floor($showPages / 2));
        $endPage = min($pages, $startPage + $showPages - 1);
        
        if ($endPage - $startPage < $showPages - 1) {
          $startPage = max(1, $endPage - $showPages + 1);
        }
        
        // First page
        if ($startPage > 1) {
          echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a></li>';
          if ($startPage > 2) {
            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
        }
        
        // Page range
        for ($p = $startPage; $p <= $endPage; $p++):
      ?>
        <li class="page-item <?php if ($p == $page) echo 'active'; ?>">
          <?php if ($p == $page): ?>
            <span class="page-link"><?php echo $p; ?></span>
          <?php else: ?>
            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
          <?php endif; ?>
        </li>
      <?php endfor; ?>
      
      <!-- Last page -->
      <?php if ($endPage < $pages): ?>
        <?php if ($endPage < $pages - 1): ?>
          <li class="page-item disabled"><span class="page-link">...</span></li>
        <?php endif; ?>
        <li class="page-item"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pages])); ?>"><?php echo $pages; ?></a></li>
      <?php endif; ?>
      
      <!-- Next button -->
      <?php if ($page < $pages): ?>
        <li class="page-item">
          <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
            <span class="sr-only">Next</span>
            <span aria-hidden="true">&raquo;</span>
          </a>
        </li>
      <?php else: ?>
        <li class="page-item disabled">
          <span class="page-link" aria-label="Next">
            <span class="sr-only">Next</span>
            <span aria-hidden="true">&raquo;</span>
          </span>
        </li>
      <?php endif; ?>
    </ul>
  </nav>
</div>
<link rel="stylesheet" href="assets/css/export_button.css">
<a href="export.php?type=vehicles" class="btn-export">⬇ Export Vehicles CSV</a>
<?php endif; ?>

<?php require __DIR__ . '/core/footer.php'; ?>