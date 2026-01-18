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
        'bulk_unit_label' => "VARCHAR(50) DEFAULT NULL COMMENT 'Display label: Carton, Crate, etc.'"
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
