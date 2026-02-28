<?php
// Run this once to create database, tables and an initial admin user.
require_once __DIR__ . '/config.php';

try {
    $pdo = getPDO(false);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = getPDO(true);

    // Create tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
      email VARCHAR(150) NOT NULL UNIQUE,
      password VARCHAR(255) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicles (
      id INT AUTO_INCREMENT PRIMARY KEY,
      stock_number VARCHAR(100) UNIQUE,
      vehicle_type VARCHAR(50),
      brand VARCHAR(100),
      model VARCHAR(100),
      year SMALLINT,
      color VARCHAR(50),
      transmission VARCHAR(50),
      fuel_type VARCHAR(50),
      mileage VARCHAR(50),
      purchase_price DECIMAL(12,2),
      selling_price DECIMAL(12,2),
      image_path VARCHAR(255),
      status ENUM('Available','Reserved','Sold') DEFAULT 'Available',
      notes TEXT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Ensure image_path exists for upgrades
    try {
        $pdo->exec("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS image_path VARCHAR(255) NULL");
    } catch (Exception $e) {
        // ignore if not supported / already exists
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
      id INT AUTO_INCREMENT PRIMARY KEY,
      vehicle_id INT NOT NULL,
      buyer_name VARCHAR(200),
      sale_price DECIMAL(12,2),
      sale_date DATE,
      payment_method VARCHAR(50),
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Create default admin if none exists
    $stmt = $pdo->query("SELECT COUNT(*) as c FROM users");
    $c = $stmt->fetchColumn();
    if ($c == 0) {
        $pass = password_hash('admin123', PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO users (name, role, email, password) VALUES (?,?,?,?)");
        $ins->execute(['Administrator','admin','admin@local',$pass]);
        echo "Created default admin: admin@local / admin123\n";
    } else {
        echo "Users already exist, skipping admin creation.\n";
    }

    echo "Install complete.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
