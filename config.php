<?php
// DB config - adjust for your XAMPP MySQL setup
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'car_dealer');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHAR', 'utf8mb4');

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
