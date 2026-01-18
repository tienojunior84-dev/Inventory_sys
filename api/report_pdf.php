<?php
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

$conn = getDBConnection();

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$reportType = $_GET['report_type'] ?? 'sales';

$sales = getSalesByDateRange($startDate, $endDate);
$totalSales = array_sum(array_column($sales, 'total_amount'));
$totalProfit = calculateProfit($sales);

$salesByCategory = [];
foreach ($sales as $sale) {
    $cat = $sale['category'];
    if (!isset($salesByCategory[$cat])) {
        $salesByCategory[$cat] = ['revenue' => 0, 'profit' => 0, 'quantity' => 0];
    }
    $salesByCategory[$cat]['revenue'] += $sale['total_amount'];
    $profit = ($sale['unit_price'] - ($sale['purchase_price'] ?? 0)) * $sale['quantity'];
    $salesByCategory[$cat]['profit'] += $profit;
    $salesByCategory[$cat]['quantity'] += $sale['quantity'];
}

$stmt = $conn->prepare("SELECT p.id, p.name, p.category, SUM(s.quantity) as total_quantity, SUM(s.total_amount) as total_revenue
                        FROM sales s
                        JOIN products p ON s.product_id = p.id
                        WHERE s.sale_date BETWEEN ? AND ?
                        GROUP BY p.id, p.name, p.category
                        ORDER BY total_quantity DESC
                        LIMIT 10");
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$bestSellers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$bestSellersByCategory = [];
foreach (CATEGORIES as $category) {
    $stmt = $conn->prepare("SELECT p.id, p.name, SUM(s.quantity) as total_quantity, SUM(s.total_amount) as total_revenue
                            FROM sales s
                            JOIN products p ON s.product_id = p.id
                            WHERE p.category = ? AND s.sale_date BETWEEN ? AND ?
                            GROUP BY p.id, p.name
                            ORDER BY total_quantity DESC
                            LIMIT 5");
    $stmt->bind_param("sss", $category, $startDate, $endDate);
    $stmt->execute();
    $bestSellersByCategory[$category] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$stmt = $conn->prepare("SELECT p.id, p.name, p.category, p.current_stock, SUM(s.quantity) as total_sold
                        FROM products p
                        LEFT JOIN sales s ON p.id = s.product_id AND s.sale_date BETWEEN ? AND ?
                        GROUP BY p.id, p.name, p.category, p.current_stock
                        HAVING total_sold IS NULL OR total_sold < 5
                        ORDER BY total_sold ASC, p.name ASC");
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$slowMoving = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$inventoryByCategory = [];
foreach (CATEGORIES as $category) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE category = ? ORDER BY name ASC");
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $inventoryByCategory[$category] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$title = $reportType === 'inventory' ? 'Inventory Report' : 'Sales Report';
$generatedAt = date('Y-m-d H:i:s');
$filename = ($reportType === 'inventory' ? 'inventory_report_' : 'sales_report_') . date('Y-m-d') . '.pdf';

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($title); ?> - <?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?></title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
            .card { break-inside: avoid; }
        }
        body {
            font-family: Arial, sans-serif;
            max-width: 1000px;
            margin: 20px auto;
            padding: 20px;
            color: #111827;
            background: #fff;
        }
        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }
        .btn-print {
            background: #2563eb;
            color: #fff;
            padding: 10px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-right: 10px;
        }
        .btn-print:hover { background: #1e40af; }
        .btn-back {
            background: #6b7280;
            color: #fff;
            padding: 10px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-back:hover { background: #4b5563; }
        .header {
            border-bottom: 3px solid #2563eb;
            padding-bottom: 14px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            color: #2563eb;
            font-size: 24px;
        }
        .meta {
            margin-top: 8px;
            color: #6b7280;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .grid {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin: 18px 0;
        }
        .stat {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            min-width: 220px;
            flex: 1;
        }
        .stat .value {
            font-weight: 700;
            font-size: 18px;
        }
        .stat .label {
            color: #6b7280;
            font-size: 12px;
            margin-top: 4px;
        }
        h2 {
            font-size: 16px;
            margin: 18px 0 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        th {
            text-align: left;
            background: #2563eb;
            color: #fff;
            padding: 10px;
            font-size: 13px;
        }
        td {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
            vertical-align: top;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 12px;
            background: #e5e7eb;
            color: #111827;
        }
        .muted {
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" class="btn-print">Print / Save as PDF</button>
        <button onclick="window.history.back()" class="btn-back">Back</button>
    </div>

    <div class="header">
        <h1>C&C Building Shop — <?php echo htmlspecialchars($title); ?></h1>
        <div class="meta">
            <div><strong>Period:</strong> <?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?></div>
            <div><strong>Generated:</strong> <?php echo htmlspecialchars($generatedAt); ?></div>
            <div><strong>User:</strong> <?php echo htmlspecialchars(getCurrentUsername()); ?></div>
        </div>
    </div>

    <?php if ($reportType === 'sales'): ?>
        <div class="grid">
            <div class="stat">
                <div class="value"><?php echo formatCurrency($totalSales); ?></div>
                <div class="label">Total Sales Revenue</div>
            </div>
            <div class="stat">
                <div class="value"><?php echo formatCurrency($totalProfit); ?></div>
                <div class="label">Total Profit</div>
            </div>
            <div class="stat">
                <div class="value"><?php echo count($sales); ?></div>
                <div class="label">Total Transactions</div>
            </div>
        </div>

        <h2>Category Performance</h2>
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Revenue</th>
                    <th>Profit</th>
                    <th>Quantity Sold</th>
                    <th>% of Total Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalRevenueForPercent = $totalSales > 0 ? $totalSales : 1;
                foreach (CATEGORIES as $category):
                    $catData = $salesByCategory[$category] ?? ['revenue' => 0, 'profit' => 0, 'quantity' => 0];
                    $percentage = ($catData['revenue'] / $totalRevenueForPercent) * 100;
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($category); ?></strong></td>
                    <td><?php echo formatCurrency($catData['revenue']); ?></td>
                    <td><?php echo formatCurrency($catData['profit']); ?></td>
                    <td><?php echo intval($catData['quantity']); ?></td>
                    <td><?php echo number_format($percentage, 1); ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Best Selling Products (Overall)</h2>
        <?php if (empty($bestSellers)): ?>
            <p class="muted">No sales data for this period.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Quantity Sold</th>
                        <th>Total Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bestSellers as $index => $product): ?>
                    <tr>
                        <td><?php echo intval($index + 1); ?></td>
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><span class="badge"><?php echo htmlspecialchars($product['category']); ?></span></td>
                        <td><?php echo intval($product['total_quantity']); ?></td>
                        <td><?php echo formatCurrency($product['total_revenue']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>Best Sellers by Category</h2>
        <?php foreach (CATEGORIES as $category): ?>
            <h3 style="margin: 10px 0 6px; font-size: 14px;"><?php echo htmlspecialchars($category); ?></h3>
            <?php $topProducts = $bestSellersByCategory[$category] ?? []; ?>
            <?php if (empty($topProducts)): ?>
                <p class="muted">No sales data</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity Sold</th>
                            <th>Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topProducts as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td><?php echo intval($p['total_quantity']); ?></td>
                            <td><?php echo formatCurrency($p['total_revenue']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endforeach; ?>

        <h2>Slow Moving Items</h2>
        <?php if (empty($slowMoving)): ?>
            <p class="muted">No slow moving items found.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Quantity Sold</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slowMoving as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td><span class="badge"><?php echo htmlspecialchars($item['category']); ?></span></td>
                        <td><?php echo intval($item['current_stock']); ?></td>
                        <td><?php echo intval($item['total_sold'] ?? 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php else: ?>
        <h2>Inventory by Category</h2>
        <?php foreach (CATEGORIES as $category): ?>
            <?php
            $categoryProducts = $inventoryByCategory[$category] ?? [];
            $categoryValue = calculateInventoryValue($category);
            ?>
            <h3 style="margin: 10px 0 6px; font-size: 14px;"><?php echo htmlspecialchars($category); ?> Inventory</h3>
            <div class="muted" style="margin-bottom: 8px;">
                <strong>Total Value:</strong> <?php echo formatCurrency($categoryValue); ?>
                &nbsp;|&nbsp;
                <strong>Products:</strong> <?php echo count($categoryProducts); ?>
            </div>
            <?php if (!empty($categoryProducts)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Stock</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoryProducts as $product): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                            <td><?php echo intval($product['current_stock']); ?></td>
                            <td>
                                <?php
                                $unitCost = $product['individual_purchase_price'] ?? $product['purchase_price'] ?? 0;
                                echo formatCurrency(intval($product['current_stock']) * floatval($unitCost));
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="muted">No products in this category.</p>
            <?php endif; ?>
        <?php endforeach; ?>

        <h2>Products Below Reorder Level</h2>
        <?php $lowStock = getLowStockProducts(); ?>
        <?php if (empty($lowStock)): ?>
            <p class="muted">All products are above reorder level.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Reorder Level</th>
                        <th>Difference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lowStock as $product): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><span class="badge"><?php echo htmlspecialchars($product['category']); ?></span></td>
                        <td><?php echo intval($product['current_stock']); ?></td>
                        <td><?php echo intval($product['reorder_level']); ?></td>
                        <td><?php echo intval($product['reorder_level'] - $product['current_stock']); ?> units below</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
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

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $dompdf->output();
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
