<?php
/**
 * Migration Script: Add Bulk Unit Support
 * Run this file once to add bulk unit columns to your database
 * Access via: http://localhost/Inventory_sys/database/run_migration.php
 */

require_once __DIR__ . '/../config/database.php';

$conn = getDBConnection();

echo "<h2>C&C Building Shop - Database Migration</h2>";
echo "<p>Adding bulk unit support columns...</p>";

$errors = [];
$success = [];

// Check and add columns to products table
$columnsToAdd = [
    'products' => [
        'bulk_unit_type' => "VARCHAR(50) DEFAULT NULL COMMENT 'carton, crate, etc.'",
        'units_per_bulk' => "INT DEFAULT NULL COMMENT 'How many individual units in one bulk unit'",
        'bulk_unit_label' => "VARCHAR(50) DEFAULT NULL COMMENT 'Display label: Carton, Crate, etc.'",
        'bulk_purchase_price' => "DECIMAL(10, 2) DEFAULT NULL COMMENT 'Typical cost when buying in bulk unit'",
        'individual_purchase_price' => "DECIMAL(10, 2) DEFAULT NULL COMMENT 'Typical cost per individual unit'"
    ],
    'stock_movements' => [
        'bulk_quantity' => "DECIMAL(10, 2) DEFAULT NULL COMMENT 'Quantity in bulk units'",
        'bulk_cost' => "DECIMAL(10, 2) DEFAULT NULL COMMENT 'Cost per bulk unit'",
        'receipt_number' => "VARCHAR(50) DEFAULT NULL COMMENT 'Unique receipt number'"
    ]
];

foreach ($columnsToAdd as $table => $columns) {
    foreach ($columns as $columnName => $definition) {
        // Check if column exists
        $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$columnName'");
        
        if ($check->num_rows == 0) {
            // Column doesn't exist, add it
            $sql = "ALTER TABLE `$table` ADD COLUMN `$columnName` $definition";
            if ($conn->query($sql)) {
                $success[] = "Added column `$columnName` to table `$table`";
            } else {
                $errors[] = "Failed to add column `$columnName` to table `$table`: " . $conn->error;
            }
        } else {
            $success[] = "Column `$columnName` already exists in table `$table`";
        }
    }
}

// Migrate purchase_price -> individual_purchase_price if needed
$checkIndividualPrice = $conn->query("SHOW COLUMNS FROM `products` LIKE 'individual_purchase_price'");
if ($checkIndividualPrice && $checkIndividualPrice->num_rows > 0) {
    $sql = "UPDATE `products` SET `individual_purchase_price` = `purchase_price` WHERE `individual_purchase_price` IS NULL";
    if ($conn->query($sql)) {
        $success[] = "Migrated existing purchase_price values into individual_purchase_price";
    } else {
        $errors[] = "Failed to migrate purchase_price into individual_purchase_price: " . $conn->error;
    }
}

// Add index for receipt_number if it doesn't exist
$checkIndex = $conn->query("SHOW INDEX FROM stock_movements WHERE Key_name = 'idx_receipt_number'");
if ($checkIndex->num_rows == 0) {
    $sql = "ALTER TABLE stock_movements ADD INDEX idx_receipt_number (receipt_number)";
    if ($conn->query($sql)) {
        $success[] = "Added index idx_receipt_number to stock_movements";
    } else {
        $errors[] = "Failed to add index: " . $conn->error;
    }
} else {
    $success[] = "Index idx_receipt_number already exists";
}

// Create stock_receipts table if it doesn't exist
$createReceiptsTable = "CREATE TABLE IF NOT EXISTS stock_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(50) NOT NULL,
    supplier_name VARCHAR(255) DEFAULT NULL,
    delivery_reference VARCHAR(100) DEFAULT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_receipts_receipt_number (receipt_number),
    INDEX idx_created_at (created_at),
    INDEX idx_user_id (user_id),
    CONSTRAINT fk_stock_receipts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($createReceiptsTable)) {
    $success[] = "Ensured table stock_receipts exists";
} else {
    $errors[] = "Failed to create/verify stock_receipts table: " . $conn->error;
}

// Add received_by_name to stock_receipts if missing
$checkStockReceiptsReceivedBy = $conn->query("SHOW COLUMNS FROM stock_receipts LIKE 'received_by_name'");
if ($checkStockReceiptsReceivedBy && $checkStockReceiptsReceivedBy->num_rows == 0) {
    $sql = "ALTER TABLE stock_receipts ADD COLUMN received_by_name VARCHAR(255) DEFAULT NULL";
    if ($conn->query($sql)) {
        $success[] = "Added column received_by_name to stock_receipts";
    } else {
        $errors[] = "Failed to add received_by_name to stock_receipts: " . $conn->error;
    }
} else {
    $success[] = "Column received_by_name already exists in stock_receipts";
}

// Add receipt_id to stock_movements if missing
$checkReceiptIdCol = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'receipt_id'");
if ($checkReceiptIdCol->num_rows == 0) {
    $sql = "ALTER TABLE stock_movements ADD COLUMN receipt_id INT DEFAULT NULL";
    if ($conn->query($sql)) {
        $success[] = "Added column receipt_id to stock_movements";
    } else {
        $errors[] = "Failed to add receipt_id to stock_movements: " . $conn->error;
    }
} else {
    $success[] = "Column receipt_id already exists in stock_movements";
}

// Add index and FK for receipt_id if possible
$checkReceiptIdIndex = $conn->query("SHOW INDEX FROM stock_movements WHERE Key_name = 'idx_receipt_id'");
if ($checkReceiptIdIndex->num_rows == 0) {
    $sql = "ALTER TABLE stock_movements ADD INDEX idx_receipt_id (receipt_id)";
    if ($conn->query($sql)) {
        $success[] = "Added index idx_receipt_id to stock_movements";
    } else {
        $errors[] = "Failed to add idx_receipt_id: " . $conn->error;
    }
} else {
    $success[] = "Index idx_receipt_id already exists";
}

$checkFk = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements' AND COLUMN_NAME = 'receipt_id' AND REFERENCED_TABLE_NAME = 'stock_receipts'");
if ($checkFk && $checkFk->num_rows == 0) {
    $sql = "ALTER TABLE stock_movements ADD CONSTRAINT fk_stock_movements_receipt FOREIGN KEY (receipt_id) REFERENCES stock_receipts(id) ON DELETE SET NULL";
    if ($conn->query($sql)) {
        $success[] = "Added foreign key fk_stock_movements_receipt";
    } else {
        $errors[] = "Failed to add fk_stock_movements_receipt: " . $conn->error;
    }
} else {
    $success[] = "Foreign key for receipt_id already exists";
}

// Display results
echo "<div style='padding: 20px; background: #f0f0f0; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>Migration Results:</h3>";

if (!empty($success)) {
    echo "<div style='color: green; margin: 10px 0;'>";
    echo "<strong>Success:</strong><ul>";
    foreach ($success as $msg) {
        echo "<li>$msg</li>";
    }
    echo "</ul></div>";
}

if (!empty($errors)) {
    echo "<div style='color: red; margin: 10px 0;'>";
    echo "<strong>Errors:</strong><ul>";
    foreach ($errors as $msg) {
        echo "<li>$msg</li>";
    }
    echo "</ul></div>";
}

echo "</div>";

if (empty($errors)) {
    echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
    echo "<strong>✓ Migration completed successfully!</strong><br>";
    echo "You can now use bulk unit features in the system.";
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
    echo "<strong>⚠ Some errors occurred. Please check the errors above.</strong>";
    echo "</div>";
}

echo "<p><a href='/Inventory_sys/pages/dashboard.php'>Go to Dashboard</a></p>";
