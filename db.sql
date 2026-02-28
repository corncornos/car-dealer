-- MySQL schema for Car Dealer MVP
CREATE DATABASE IF NOT EXISTS `car_dealer` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `car_dealer`;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vehicles (
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
);

CREATE TABLE IF NOT EXISTS sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id INT NOT NULL,
  buyer_name VARCHAR(200),
  sale_price DECIMAL(12,2),
  sale_date DATE,
  payment_method VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
);
