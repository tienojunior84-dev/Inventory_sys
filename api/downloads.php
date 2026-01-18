<?php
// Downloads API - CSV, Excel, PDF exports
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();

if (!isLoggedIn()) {
    die('Unauthorized');
}

$type = $_GET['type'] ?? '';
$format = $_GET['format'] ?? 'csv';
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$category = $_GET['category'] ?? '';

$format = strtolower($format);
$isXls = in_array($format, ['xls', 'excel'], true);

$conn = getDBConnection();

// Sales Download
if ($type === 'sales') {
    $query = "SELECT s.*, p.name as product_name, p.category, u.username 
              FROM sales s
              JOIN products p ON s.product_id = p.id
              JOIN users u ON s.user_id = u.id
              WHERE s.sale_date BETWEEN ? AND ?";
    $params = [$startDate, $endDate];
    $types = "ss";
    
    if ($category && in_array($category, CATEGORIES)) {
        $query .= " AND p.category = ?";
        $params[] = $category;
        $types .= "s";
    }
    
    $query .= " ORDER BY s.sale_date DESC, s.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if (count($params) > 2) {
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param($types, $params[0], $params[1]);
    }
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $filename = "sales_" . date('Y-m-d') . ($isXls ? ".xls" : ".csv");
    $headers = ['Date', 'Product Name', 'Category', 'Quantity', 'Unit Price', 'Total Amount', 'Recorded By'];

    $exporter = $isXls ? 'exportToXLS' : 'exportToCSV';
    $exporter($data, $filename, $headers, function($row) {
        return [
            formatDate($row['sale_date']),
            $row['product_name'],
            $row['category'],
            $row['quantity'],
            formatCurrency($row['unit_price']),
            formatCurrency($row['total_amount']),
            $row['username']
        ];
    });
}

// Stock In Download
if ($type === 'stock_in') {
    // Check if bulk columns exist
    $checkColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'bulk_unit_label'");
    $hasBulkColumns = $checkColumn->num_rows > 0;
    
    $checkReceiptsTable = $conn->query("SHOW TABLES LIKE 'stock_receipts'");
    $hasReceiptsTable = $checkReceiptsTable && $checkReceiptsTable->num_rows > 0;

    if ($hasBulkColumns) {
        if ($hasReceiptsTable) {
            $query = "SELECT sm.*, p.name as product_name, p.category, p.bulk_unit_label,
                      COALESCE(sr.receipt_number, sm.receipt_number) as display_receipt_number,
                      sr.supplier_name, sr.delivery_reference,
                      u.username
                      FROM stock_movements sm
                      JOIN products p ON sm.product_id = p.id
                      LEFT JOIN stock_receipts sr ON sm.receipt_id = sr.id
                      JOIN users u ON sm.user_id = u.id
                      WHERE sm.movement_type = 'in' AND DATE(sm.created_at) BETWEEN ? AND ?";
        } else {
            $query = "SELECT sm.*, p.name as product_name, p.category, p.bulk_unit_label,
                      sm.receipt_number as display_receipt_number,
                      NULL as supplier_name, NULL as delivery_reference,
                      u.username
                      FROM stock_movements sm
                      JOIN products p ON sm.product_id = p.id
                      JOIN users u ON sm.user_id = u.id
                      WHERE sm.movement_type = 'in' AND DATE(sm.created_at) BETWEEN ? AND ?";
        }
    } else {
        if ($hasReceiptsTable) {
            $query = "SELECT sm.*, p.name as product_name, p.category, NULL as bulk_unit_label,
                      COALESCE(sr.receipt_number, sm.receipt_number) as display_receipt_number,
                      sr.supplier_name, sr.delivery_reference,
                      u.username
                      FROM stock_movements sm
                      JOIN products p ON sm.product_id = p.id
                      LEFT JOIN stock_receipts sr ON sm.receipt_id = sr.id
                      JOIN users u ON sm.user_id = u.id
                      WHERE sm.movement_type = 'in' AND DATE(sm.created_at) BETWEEN ? AND ?";
        } else {
            $query = "SELECT sm.*, p.name as product_name, p.category, NULL as bulk_unit_label,
                      sm.receipt_number as display_receipt_number,
                      NULL as supplier_name, NULL as delivery_reference,
                      u.username
                      FROM stock_movements sm
                      JOIN products p ON sm.product_id = p.id
                      JOIN users u ON sm.user_id = u.id
                      WHERE sm.movement_type = 'in' AND DATE(sm.created_at) BETWEEN ? AND ?";
        }
    }
    $params = [$startDate, $endDate];
    $types = "ss";
    
    if ($category && in_array($category, CATEGORIES)) {
        $query .= " AND p.category = ?";
        $params[] = $category;
        $types .= "s";
    }
    
    $query .= " ORDER BY sm.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if (count($params) > 2) {
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param($types, $params[0], $params[1]);
    }
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $filename = "stock_in_" . $startDate . "_to_" . $endDate . ($isXls ? ".xls" : ".csv");
    $headers = ['Date', 'Receipt Number', 'Supplier', 'Delivery Ref', 'Product Name', 'Category', 'Entry Mode', 'Bulk Quantity', 'Individual Quantity (Units)', 'Cost Per Unit', 'Bulk Cost', 'Total Cost', 'Notes', 'Recorded By'];

    $exporter = $isXls ? 'exportToXLS' : 'exportToCSV';
    $exporter($data, $filename, $headers, function($row) {
        $isBulk = !empty($row['bulk_quantity']) && !empty($row['bulk_unit_label']);
        $entryMode = $isBulk ? 'Bulk' : 'Individual';
        $bulkQtyDisplay = $isBulk ? ($row['bulk_quantity'] . ' ' . $row['bulk_unit_label']) : '';
        return [
            formatDateTime($row['created_at']),
            $row['display_receipt_number'] ?? ($row['receipt_number'] ?? ''),
            $row['supplier_name'] ?? '',
            $row['delivery_reference'] ?? '',
            $row['product_name'],
            $row['category'],
            $entryMode,
            $bulkQtyDisplay,
            $row['quantity'],
            formatCurrency($row['cost_per_unit']),
            isset($row['bulk_cost']) ? formatCurrency($row['bulk_cost']) : '',
            formatCurrency($row['total_cost']),
            $row['notes'] ?? '',
            $row['username']
        ];
    });
}

// Products Download
if ($type === 'products') {
    $query = "SELECT * FROM products WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($category && in_array($category, CATEGORIES)) {
        $query .= " AND category = ?";
        $params[] = $category;
        $types .= "s";
    }
    
    $query .= " ORDER BY category, name ASC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt = $conn->prepare($query);
    }
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $filename = "products_" . date('Y-m-d') . ($isXls ? ".xls" : ".csv");
    $headers = ['Product Name', 'Category', 'Individual Purchase Price', 'Bulk Purchase Price', 'Selling Price', 'Current Stock', 'Reorder Level', 'Bulk Unit', 'Units Per Bulk'];

    $exporter = $isXls ? 'exportToXLS' : 'exportToCSV';
    $exporter($data, $filename, $headers, function($row) {
        $status = ($row['reorder_level'] && $row['current_stock'] <= $row['reorder_level']) ? 'Low Stock' : 'In Stock';
        return [
            $row['name'],
            $row['category'],
            formatCurrency($row['individual_purchase_price'] ?? $row['purchase_price']),
            isset($row['bulk_purchase_price']) ? formatCurrency($row['bulk_purchase_price'] ?? 0) : '',
            formatCurrency($row['selling_price']),
            $row['current_stock'],
            $row['reorder_level'] ?? 'N/A',
            $row['bulk_unit_label'] ?? 'N/A',
            $row['units_per_bulk'] ?? 'N/A',
            $status
        ];
    });
}

function exportToCSV($data, $filename, $headers, $callback) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write headers
    fputcsv($output, $headers);
    
    // Write data
    foreach ($data as $row) {
        fputcsv($output, $callback($row));
    }
    
    fclose($output);
    exit();
}

function exportToXLS($data, $filename, $headers, $callback) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo "<html><head><meta charset=\"UTF-8\"></head><body>";
    echo "<table border=\"1\" cellspacing=\"0\" cellpadding=\"4\">";

    echo "<tr>";
    foreach ($headers as $h) {
        echo "<th>" . htmlspecialchars($h) . "</th>";
    }
    echo "</tr>";

    foreach ($data as $row) {
        $cells = $callback($row);
        echo "<tr>";
        foreach ($cells as $cell) {
            echo "<td>" . htmlspecialchars((string)$cell) . "</td>";
        }
        echo "</tr>";
    }

    echo "</table></body></html>";
    exit();
}

die('Invalid download type');
