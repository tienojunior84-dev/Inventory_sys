-- C&C Building Shop Database Schema

CREATE DATABASE IF NOT EXISTS inventory_sys CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventory_sys;

-- Users table for authentication
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Products table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    category ENUM('Provisions', 'Wine', 'Beer') NOT NULL,
    purchase_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    selling_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    current_stock INT NOT NULL DEFAULT 0,
    reorder_level INT DEFAULT NULL,
    bulk_unit_type VARCHAR(50) DEFAULT NULL COMMENT 'carton, crate, etc.',
    units_per_bulk INT DEFAULT NULL COMMENT 'How many individual units in one bulk unit',
    bulk_unit_label VARCHAR(50) DEFAULT NULL COMMENT 'Display label: Carton, Crate, etc.',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_name (name),
    INDEX idx_stock (current_stock)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock movements table (for tracking all stock changes)
CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    movement_type ENUM('in', 'out') NOT NULL,
    quantity INT NOT NULL,
    cost_per_unit DECIMAL(10, 2) DEFAULT NULL,
    total_cost DECIMAL(10, 2) DEFAULT NULL,
    bulk_quantity DECIMAL(10, 2) DEFAULT NULL COMMENT 'Quantity in bulk units',
    bulk_cost DECIMAL(10, 2) DEFAULT NULL COMMENT 'Cost per bulk unit',
    receipt_number VARCHAR(50) DEFAULT NULL COMMENT 'Unique receipt number',
    notes TEXT,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_product (product_id),
    INDEX idx_type (movement_type),
    INDEX idx_date (created_at),
    INDEX idx_receipt_number (receipt_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sales table
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    sale_date DATE NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_product (product_id),
    INDEX idx_date (sale_date),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
