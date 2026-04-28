<?php
// DB config - adjust for your XAMPP MySQL setup
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'car_dealer');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHAR', 'utf8mb4');

// Google Sheets real-time sync
if (file_exists(__DIR__ . '/../sheets_sync.php')) {
    require_once __DIR__ . '/../sheets_sync.php';
} elseif (file_exists(__DIR__ . '/sheets_sync.php')) {
    require_once __DIR__ . '/sheets_sync.php';
}

function getPDO($withDb = true) {
    $host = DB_HOST;
    $db = $withDb ? DB_NAME : null;
    $dsn = "mysql:host=$host" . ($db ? ";dbname=$db" : '') . ";charset=" . DB_CHAR;
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    return new PDO($dsn, DB_USER, DB_PASS, $opts);
}

function add_audit($pdo, $action, $detail = null) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_name VARCHAR(150),
            action VARCHAR(100),
            detail TEXT,
            ip VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $user = (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? ($_SESSION['user']['name'] ?? null) : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_name, action, detail, ip) VALUES (?,?,?,?)');
        $stmt->execute([$user, $action, $detail, $ip]);
    } catch (Exception $e) {
        // don't break main flow if auditing fails
    }
}

function add_audit_with_diff($pdo, $action, $before = null, $after = null, $entityId = null) {
    $detail = [];
    if ($entityId) {
        $detail['id'] = $entityId;
    }
    if ($before !== null) {
        $detail['before'] = $before;
    }
    if ($after !== null) {
        $detail['after'] = $after;
    }
    
    // If both before and after exist, also store a simplified diff
    if ($before !== null && $after !== null) {
        $detail['changes'] = calculate_diff($before, $after);
    }
    
    add_audit($pdo, $action, json_encode($detail));
}

function calculate_diff($before, $after) {
    $changes = [];
    $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));
    
    foreach ($allKeys as $key) {
        if ($key === 'id') continue; // Skip ID field as it's already stored separately
        
        $beforeValue = $before[$key] ?? null;
        $afterValue = $after[$key] ?? null;
        
        // Normalize values for comparison
        $beforeNorm = normalize_value_for_diff($beforeValue);
        $afterNorm = normalize_value_for_diff($afterValue);
        
        if ($beforeNorm !== $afterNorm) {
            $changes[$key] = [
                'from' => $beforeValue,
                'to' => $afterValue
            ];
        }
    }
    
    return $changes;
}

function normalize_value_for_diff($value) {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_numeric($value)) return (string)$value;
    return trim((string)$value);
}

function add_audit_login_attempt($pdo, $email, $success, $reason = '') {
    $detail = [
        'email' => $email,
        'success' => $success,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if (!$success && $reason) {
        $detail['reason'] = $reason;
    }
    
    $action = $success ? 'User Login' : 'Failed Login Attempt';
    add_audit($pdo, $action, json_encode($detail));
}

// CSRF Protection Functions
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }
    
    // Check if token matches
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    
    // Check if token is not expired (1 hour)
    if (time() - $_SESSION['csrf_token_time'] > 3600) {
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);
        return false;
    }
    
    return true;
}

function getCSRFToken() {
    return generateCSRFToken();
}

function getCSRFInput() {
    $token = getCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

// Input Validation Functions
function validateRequired($value, $fieldName) {
    if (empty(trim($value))) {
        throw new InvalidArgumentException("$fieldName is required");
    }
    return trim($value);
}

function validateString($value, $fieldName, $minLength = 1, $maxLength = 255) {
    $value = trim($value);
    if (strlen($value) < $minLength || strlen($value) > $maxLength) {
        throw new InvalidArgumentException("$fieldName must be between $minLength and $maxLength characters");
    }
    return $value;
}

function validateEmail($email) {
    $email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("Invalid email format");
    }
    return $email;
}

function validateNumber($value, $fieldName, $min = null, $max = null) {
    $value = trim($value);
    if (!is_numeric($value)) {
        throw new InvalidArgumentException("$fieldName must be a valid number");
    }
    $num = floatval($value);
    if ($min !== null && $num < $min) {
        throw new InvalidArgumentException("$fieldName must be at least $min");
    }
    if ($max !== null && $num > $max) {
        throw new InvalidArgumentException("$fieldName must not exceed $max");
    }
    return $num;
}

function validateYear($year) {
    $year = validateNumber($year, "Year", 1900, date('Y') + 1);
    return intval($year);
}

function validatePrice($price) {
    return validateNumber($price, "Price", 0, 999999999.99);
}

function validatePlateNumber($plate) {
    $plate = validateString($plate, "Plate Number", 2, 20);
    if (!preg_match('/^[A-Z0-9\s-]+$/', strtoupper($plate))) {
        throw new InvalidArgumentException("Plate number can only contain letters, numbers, spaces, and hyphens");
    }
    return $plate;
}

function validatePhone($phone) {
    $phone = trim($phone);
    if (!preg_match('/^[0-9\s\-\+\(\)]+$/', $phone)) {
        throw new InvalidArgumentException("Phone number can only contain digits, spaces, and +()-");
    }
    return $phone;
}

function validateFileUpload($file, $allowedTypes = [], $maxSize = 5242880) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException("File size exceeds maximum allowed size");
        }
        throw new InvalidArgumentException("File upload error");
    }
    
    if ($file['size'] > $maxSize) {
        throw new InvalidArgumentException("File size exceeds maximum allowed size of " . ($maxSize / 1024 / 1024) . "MB");
    }
    
    if (!empty($allowedTypes)) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed = in_array($mimeType, $allowedTypes, true);

        // If all allowed types are image/* mime types, accept any image/* MIME returned by finfo
        if (!$allowed) {
            $allImages = true;
            foreach ($allowedTypes as $type) {
                if (strpos($type, 'image/') !== 0) {
                    $allImages = false;
                    break;
                }
            }

            if ($allImages && strpos((string)$mimeType, 'image/') === 0) {
                $allowed = true;
            }
        }

        if (!$allowed) {
            throw new InvalidArgumentException("Invalid file type. Allowed types: " . implode(', ', $allowedTypes));
        }
    }
    
    return true;
}

function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function showValidationError($message) {
    // Store error message in session for display after page loads
    $_SESSION['validation_error'] = $message;
    
    // If headers already sent, output JavaScript directly
    if (headers_sent()) {
        echo "<script>
            alert('" . addslashes($message) . "');
        </script>";
        return;
    }

    // For standard form submissions, redirect back so the page can render the modal
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($method === 'POST' && !$isAjax) {
        $target = $_SERVER['REQUEST_URI'] ?? '';
        if ($target === '') {
            $target = $_SERVER['PHP_SELF'] ?? '';
        }
        if ($target === '') {
            $target = 'index.php';
        }

        if (!empty($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_HOST'])) {
            $refParts = parse_url($_SERVER['HTTP_REFERER']);
            if (!empty($refParts['host']) && strcasecmp($refParts['host'], $_SERVER['HTTP_HOST']) === 0) {
                $target = $_SERVER['HTTP_REFERER'];
            }
        }

        header('Location: ' . $target);
        exit();
    }
}

function displayValidationErrorIfExists() {
    if (isset($_SESSION['validation_error'])) {
        $error = $_SESSION['validation_error'];
        echo "<script>
            // Wait for page to be fully loaded, then show modal
            window.addEventListener('load', function() {
                // Remove any existing modal
                var existingModal = document.getElementById('errorModal');
                if (existingModal) {
                    existingModal.remove();
                }
                
                // Create modal HTML
                var modalHTML = '<div id=\"errorModal\" style=\"position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999;\"><div style=\"background:white;padding:30px;border-radius:10px;max-width:400px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);\"><h3 style=\"color:#d32f2f;margin-bottom:15px;\">Validation Error</h3><p style=\"color:#666;margin-bottom:20px;line-height:1.5;\">" . addslashes(htmlspecialchars($error)) . "</p><button onclick=\"document.getElementById(\\'errorModal\\').remove()\" style=\"background:#d32f2f;color:white;border:none;padding:10px 20px;border-radius:5px;cursor:pointer;font-size:14px;\">OK</button></div></div>';
                
                // Add to page
                document.body.insertAdjacentHTML('beforeend', modalHTML);
                
                // Clear session via AJAX to prevent showing again
                var xhr = new XMLHttpRequest();
                xhr.open('GET', window.location.href.split('?')[0] + '?clear_error=1', true);
                xhr.send();
            });
        </script>";
        
        // Clear the session error
        unset($_SESSION['validation_error']);
    }
}