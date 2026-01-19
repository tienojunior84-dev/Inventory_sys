<?php
// Stock In Receipt Generator
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    die('Unauthorized');
}

$movementId = intval($_GET['id'] ?? 0);
$receiptId = intval($_GET['receipt_id'] ?? 0);

if ($movementId <= 0 && $receiptId <= 0) {
    die('Invalid receipt ID');
}

$conn = getDBConnection();

// Check if bulk columns exist
$checkColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'bulk_unit_label'");
$hasBulkColumns = $checkColumn->num_rows > 0;

$hasReceiptsTable = false;
$checkReceiptsTable = $conn->query("SHOW TABLES LIKE 'stock_receipts'");
if ($checkReceiptsTable && $checkReceiptsTable->num_rows > 0) {
    $hasReceiptsTable = true;
}

$receipt = null;
$items = [];

if ($receiptId > 0 && $hasReceiptsTable) {
    $stmt = $conn->prepare("SELECT sr.*, u.username
                            FROM stock_receipts sr
                            JOIN users u ON sr.user_id = u.id
                            WHERE sr.id = ?");
    $stmt->bind_param("i", $receiptId);
    $stmt->execute();
    $receipt = $stmt->get_result()->fetch_assoc();

    if (!$receipt) {
        die('Receipt not found');
    }

    $checkMovementReceivedBy = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'received_by_name'");
    $hasMovementReceivedBy = $checkMovementReceivedBy && $checkMovementReceivedBy->num_rows > 0;

    if ($hasBulkColumns) {
        $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, p.category, p.bulk_unit_label, p.units_per_bulk
                                FROM stock_movements sm
                                JOIN products p ON sm.product_id = p.id
                                WHERE sm.receipt_id = ? AND sm.movement_type = 'in'
                                ORDER BY sm.created_at ASC");
    } else {
        $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, p.category, NULL as bulk_unit_label, NULL as units_per_bulk
                                FROM stock_movements sm
                                JOIN products p ON sm.product_id = p.id
                                WHERE sm.receipt_id = ? AND sm.movement_type = 'in'
                                ORDER BY sm.created_at ASC");
    }
    $stmt->bind_param("i", $receiptId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($items)) {
        die('No receipt items found');
    }
}

if ($movementId > 0) {
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
    $movement = $stmt->get_result()->fetch_assoc();

    if (!$movement) {
        die('Receipt not found');
    }

    $receipt = [
        'receipt_number' => $movement['receipt_number'],
        'supplier_name' => null,
        'delivery_reference' => null,
        'received_by_name' => $movement['received_by_name'] ?? null,
        'created_at' => $movement['created_at'],
        'username' => $movement['username']
    ];
    $items = [$movement];
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Stock Receipt - <?php echo htmlspecialchars($receipt['receipt_number']); ?></title>
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
    <div class="header">
        <h1>C&C Building Shop</h1>
        <p>Stock Receipt</p>
    </div>
    
    <div class="receipt-info">
        <div class="info-section">
            <h3>Receipt Information</h3>
            <p><strong>Receipt Number:</strong> <?php echo htmlspecialchars($receipt['receipt_number']); ?></p>
            <p><strong>Date:</strong> <?php echo formatDateTime($receipt['created_at']); ?></p>
            <?php if (!empty($receipt['supplier_name'])): ?>
                <p><strong>Supplier:</strong> <?php echo htmlspecialchars($receipt['supplier_name']); ?></p>
            <?php endif; ?>
            <?php if (!empty($receipt['delivery_reference'])): ?>
                <p><strong>Delivery Ref:</strong> <?php echo htmlspecialchars($receipt['delivery_reference']); ?></p>
            <?php endif; ?>
            <?php
            $receivedBy = $receipt['received_by_name'] ?? null;
            if (empty($receivedBy)) {
                foreach ($items as $it) {
                    if (!empty($it['received_by_name'])) {
                        $receivedBy = $it['received_by_name'];
                        break;
                    }
                }
            }
            ?>
            <?php if (!empty($receivedBy)): ?>
                <p><strong>Received By:</strong> <?php echo htmlspecialchars($receivedBy); ?></p>
            <?php endif; ?>
        </div>
        <div class="info-section">
            <h3>Receipt Summary</h3>
            <p><strong>Items:</strong> <?php echo count($items); ?></p>
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
            <?php $grandTotal = 0; ?>
            <?php foreach ($items as $item): ?>
                <?php $grandTotal += floatval($item['total_cost'] ?? 0); ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($item['product_name']); ?>
                        <?php if ($item['bulk_quantity'] && $item['bulk_unit_label']): ?>
                            <br><small style="color: #666;">
                                <?php echo $item['bulk_quantity']; ?> <?php echo htmlspecialchars($item['bulk_unit_label']); ?>
                                <?php if (!empty($item['units_per_bulk'])): ?>
                                    × <?php echo $item['units_per_bulk']; ?> units = <?php echo $item['quantity']; ?> units
                                <?php endif; ?>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php
                        if ($item['bulk_quantity'] && $item['bulk_unit_label']) {
                            echo $item['bulk_quantity'] . ' ' . htmlspecialchars($item['bulk_unit_label']) . '<br>';
                            echo '<small style="color: #666;">(' . $item['quantity'] . ' units)</small>';
                        } else {
                            echo $item['quantity'] . ' units';
                        }
                        ?>
                    </td>
                    <td style="text-align: right;">
                        <?php
                        if ($item['bulk_quantity'] && $item['bulk_cost']) {
                            echo formatCurrency($item['bulk_cost']) . ' / ' . htmlspecialchars($item['bulk_unit_label']) . '<br>';
                            echo '<small style="color: #666;">(' . formatCurrency($item['cost_per_unit']) . ' / unit)</small>';
                        } else {
                            echo formatCurrency($item['cost_per_unit']) . ' / unit';
                        }
                        ?>
                    </td>
                    <td style="text-align: right; font-weight: 600;">
                        <?php echo formatCurrency($item['total_cost']); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="total-section">
        <div class="total-row">
            <div class="total-label">Total Cost:</div>
            <div class="total-value"><?php echo formatCurrency($grandTotal); ?></div>
        </div>
    </div>
    
    <?php
    $notesToShow = null;
    foreach ($items as $item) {
        if (!empty($item['notes'])) {
            $notesToShow = $item['notes'];
            break;
        }
    }
    ?>
    <?php if ($notesToShow): ?>
        <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
            <strong>Notes:</strong><br>
            <?php echo nl2br(htmlspecialchars($notesToShow)); ?>
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
<?php
$html = ob_get_clean();

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;

    if (class_exists('Dompdf\\Dompdf')) {
        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $filename = 'receipt_' . preg_replace('/[^A-Za-z0-9\-_.]/', '_', (string)$receipt['receipt_number']) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $dompdf->output();
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
