<?php
// Stock In Receipt Generator
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();

if (!isLoggedIn()) {
    die('Unauthorized');
}

$movementId = intval($_GET['id'] ?? 0);

if ($movementId <= 0) {
    die('Invalid receipt ID');
}

$conn = getDBConnection();

// Check if bulk columns exist
$checkColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'bulk_unit_label'");
$hasBulkColumns = $checkColumn->num_rows > 0;

if ($hasBulkColumns) {
    $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, p.category, p.bulk_unit_label, p.units_per_bulk, u.username
                            FROM stock_movements sm
                            JOIN products p ON sm.product_id = p.id
                            JOIN users u ON sm.user_id = u.id
                            WHERE sm.id = ? AND sm.movement_type = 'in'");
} else {
    $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, p.category, NULL as bulk_unit_label, NULL as units_per_bulk, u.username
                            FROM stock_movements sm
                            JOIN products p ON sm.product_id = p.id
                            JOIN users u ON sm.user_id = u.id
                            WHERE sm.id = ? AND sm.movement_type = 'in'");
}
$stmt->bind_param("i", $movementId);
$stmt->execute();
$result = $stmt->get_result();
$movement = $result->fetch_assoc();

if (!$movement) {
    die('Receipt not found');
}

// Generate PDF receipt using simple HTML to PDF approach
// For now, we'll use a simple approach - in production, use TCPDF or similar

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Stock Receipt - <?php echo htmlspecialchars($movement['receipt_number']); ?></title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #2563eb;
            margin: 0;
            font-size: 28px;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .receipt-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .info-section {
            flex: 1;
        }
        .info-section h3 {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 14px;
            text-transform: uppercase;
        }
        .info-section p {
            margin: 5px 0;
            font-size: 16px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .items-table th {
            background: #2563eb;
            color: #fff;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        .items-table td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .items-table tr:last-child td {
            border-bottom: none;
        }
        .total-section {
            text-align: right;
            margin-top: 20px;
        }
        .total-row {
            display: flex;
            justify-content: flex-end;
            padding: 10px 0;
        }
        .total-label {
            font-weight: 600;
            width: 200px;
            text-align: right;
            padding-right: 20px;
        }
        .total-value {
            width: 150px;
            text-align: right;
            font-size: 18px;
            font-weight: 700;
            color: #2563eb;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        .signature-box {
            width: 250px;
            border-top: 2px solid #333;
            padding-top: 10px;
            text-align: center;
        }
        .btn-print {
            background: #2563eb;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        .btn-print:hover {
            background: #1e40af;
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center; margin-bottom: 20px;">
        <button onclick="window.print()" class="btn-print">Print / Save as PDF</button>
    </div>
    
    <div class="header">
        <h1>C&C Building Shop</h1>
        <p>Stock Receipt</p>
    </div>
    
    <div class="receipt-info">
        <div class="info-section">
            <h3>Receipt Information</h3>
            <p><strong>Receipt Number:</strong> <?php echo htmlspecialchars($movement['receipt_number']); ?></p>
            <p><strong>Date:</strong> <?php echo formatDateTime($movement['created_at']); ?></p>
            <p><strong>Recorded By:</strong> <?php echo htmlspecialchars($movement['username']); ?></p>
        </div>
        <div class="info-section">
            <h3>Product Details</h3>
            <p><strong>Product:</strong> <?php echo htmlspecialchars($movement['product_name']); ?></p>
            <p><strong>Category:</strong> <?php echo htmlspecialchars($movement['category']); ?></p>
        </div>
    </div>
    
    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th style="text-align: right;">Quantity</th>
                <th style="text-align: right;">Unit Cost</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <?php echo htmlspecialchars($movement['product_name']); ?>
                    <?php if ($movement['bulk_quantity'] && $movement['bulk_unit_label']): ?>
                        <br><small style="color: #666;">
                            <?php echo $movement['bulk_quantity']; ?> <?php echo htmlspecialchars($movement['bulk_unit_label']); ?> 
                            × <?php echo $movement['units_per_bulk']; ?> units = <?php echo $movement['quantity']; ?> units
                        </small>
                    <?php endif; ?>
                </td>
                <td style="text-align: right;">
                    <?php 
                    if ($movement['bulk_quantity'] && $movement['bulk_unit_label']) {
                        echo $movement['bulk_quantity'] . ' ' . htmlspecialchars($movement['bulk_unit_label']) . '<br>';
                        echo '<small style="color: #666;">(' . $movement['quantity'] . ' units)</small>';
                    } else {
                        echo $movement['quantity'] . ' units';
                    }
                    ?>
                </td>
                <td style="text-align: right;">
                    <?php 
                    if ($movement['bulk_quantity'] && $movement['bulk_cost']) {
                        echo formatCurrency($movement['bulk_cost']) . ' / ' . htmlspecialchars($movement['bulk_unit_label']) . '<br>';
                        echo '<small style="color: #666;">(' . formatCurrency($movement['cost_per_unit']) . ' / unit)</small>';
                    } else {
                        echo formatCurrency($movement['cost_per_unit']) . ' / unit';
                    }
                    ?>
                </td>
                <td style="text-align: right; font-weight: 600;">
                    <?php echo formatCurrency($movement['total_cost']); ?>
                </td>
            </tr>
        </tbody>
    </table>
    
    <div class="total-section">
        <div class="total-row">
            <div class="total-label">Total Cost:</div>
            <div class="total-value"><?php echo formatCurrency($movement['total_cost']); ?></div>
        </div>
    </div>
    
    <?php if ($movement['notes']): ?>
    <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
        <strong>Notes:</strong><br>
        <?php echo nl2br(htmlspecialchars($movement['notes'])); ?>
    </div>
    <?php endif; ?>
    
    <div class="signature-section">
        <div class="signature-box">
            <p>Received By</p>
        </div>
        <div class="signature-box">
            <p>Authorized By</p>
        </div>
    </div>
    
    <div class="footer">
        <p>This is a computer-generated receipt. No signature required.</p>
        <p>&copy; <?php echo date('Y'); ?> C&C Building Shop. All rights reserved.</p>
    </div>
</body>
</html>
