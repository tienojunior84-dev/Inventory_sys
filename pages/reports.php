<?php
$pageTitle = 'Reports & Analytics - C&C Building Shop';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$conn = getDBConnection();

// Get date range from filters
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$reportType = $_GET['report_type'] ?? 'sales';

// Get sales data
$sales = getSalesByDateRange($startDate, $endDate);
$totalSales = array_sum(array_column($sales, 'total_amount'));
$totalProfit = calculateProfit($sales);

// Sales by category
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

// Best selling products overall
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

// Best sellers by category
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

// Slow moving items (products with sales but low quantity)
$stmt = $conn->prepare("SELECT p.id, p.name, p.category, p.current_stock, SUM(s.quantity) as total_sold
                        FROM products p
                        LEFT JOIN sales s ON p.id = s.product_id AND s.sale_date BETWEEN ? AND ?
                        GROUP BY p.id, p.name, p.category, p.current_stock
                        HAVING total_sold IS NULL OR total_sold < 5
                        ORDER BY total_sold ASC, p.name ASC");
$stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$slowMoving = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Current inventory
$inventoryByCategory = [];
foreach (CATEGORIES as $category) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE category = ? ORDER BY name ASC");
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $inventoryByCategory[$category] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-graph-up"></i> Reports & Analytics</h2>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control" name="start_date" value="<?php echo $startDate; ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control" name="end_date" value="<?php echo $endDate; ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Report Type</label>
                <select class="form-select" name="report_type">
                    <option value="sales" <?php echo $reportType === 'sales' ? 'selected' : ''; ?>>Sales Reports</option>
                    <option value="inventory" <?php echo $reportType === 'inventory' ? 'selected' : ''; ?>>Inventory Reports</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel"></i> Apply Filters
                </button>
            </div>
        </form>

        <div class="mt-3">
            <a class="btn btn-outline-secondary" target="_blank" href="/Inventory_sys/api/report_pdf.php?start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>&report_type=<?php echo urlencode($reportType); ?>">
                <i class="bi bi-file-earmark-pdf"></i> Download PDF
            </a>
        </div>
    </div>
</div>

<?php if ($reportType === 'sales'): ?>
<!-- Sales Summary -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card stat-card success">
            <div class="card-body">
                <div class="stat-value"><?php echo formatCurrency($totalSales); ?></div>
                <div class="stat-label">Total Sales Revenue</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card info">
            <div class="card-body">
                <div class="stat-value"><?php echo formatCurrency($totalProfit); ?></div>
                <div class="stat-label">Total Profit</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-value"><?php echo count($sales); ?></div>
                <div class="stat-label">Total Transactions</div>
            </div>
        </div>
    </div>
</div>

<!-- Category Performance -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Category Performance</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
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
                                <td><strong><?php echo $category; ?></strong></td>
                                <td><?php echo formatCurrency($catData['revenue']); ?></td>
                                <td><?php echo formatCurrency($catData['profit']); ?></td>
                                <td><?php echo $catData['quantity']; ?></td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar" role="progressbar" style="width: <?php echo $percentage; ?>%">
                                            <?php echo number_format($percentage, 1); ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Best Selling Products -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-trophy"></i> Best Selling Products (Overall)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($bestSellers)): ?>
                    <p class="text-muted">No sales data for this period.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
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
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($product['category']); ?></span></td>
                                    <td><?php echo $product['total_quantity']; ?></td>
                                    <td><?php echo formatCurrency($product['total_revenue']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Best Sellers by Category -->
<div class="row mb-4">
    <?php foreach (CATEGORIES as $category): ?>
    <div class="col-md-4 mb-3">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Top <?php echo $category; ?></h6>
            </div>
            <div class="card-body">
                <?php 
                $topProducts = $bestSellersByCategory[$category] ?? [];
                if (empty($topProducts)): 
                ?>
                    <p class="text-muted small">No sales data</p>
                <?php else: ?>
                    <ol class="mb-0">
                        <?php foreach ($topProducts as $product): ?>
                        <li class="mb-2">
                            <strong><?php echo htmlspecialchars($product['name']); ?></strong><br>
                            <small class="text-muted">
                                <?php echo $product['total_quantity']; ?> units - 
                                <?php echo formatCurrency($product['total_revenue']); ?>
                            </small>
                        </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Slow Moving Items -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-exclamation-circle"></i> Slow Moving Items</h5>
            </div>
            <div class="card-body">
                <?php if (empty($slowMoving)): ?>
                    <p class="text-muted">No slow moving items found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
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
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($item['category']); ?></span></td>
                                    <td><?php echo $item['current_stock']; ?></td>
                                    <td><?php echo $item['total_sold'] ?? 0; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Inventory Reports -->
<div class="row mb-4">
    <?php foreach (CATEGORIES as $category): ?>
    <div class="col-md-4 mb-3">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><?php echo $category; ?> Inventory</h5>
            </div>
            <div class="card-body">
                <?php 
                $categoryProducts = $inventoryByCategory[$category] ?? [];
                $categoryValue = calculateInventoryValue($category);
                ?>
                <div class="mb-3">
                    <strong>Total Value:</strong> <?php echo formatCurrency($categoryValue); ?><br>
                    <strong>Products:</strong> <?php echo count($categoryProducts); ?>
                </div>
                <?php if (!empty($categoryProducts)): ?>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm">
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
                                    <td><?php echo $product['current_stock']; ?></td>
                                    <td>
                                        <?php
                                        $unitCost = $product['individual_purchase_price'] ?? $product['purchase_price'] ?? 0;
                                        echo formatCurrency($product['current_stock'] * $unitCost);
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Products Below Reorder Level -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Products Below Reorder Level</h5>
            </div>
            <div class="card-body">
                <?php 
                $lowStock = getLowStockProducts();
                if (empty($lowStock)): 
                ?>
                    <p class="text-muted">All products are above reorder level.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
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
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($product['category']); ?></span></td>
                                    <td><span class="badge bg-danger"><?php echo $product['current_stock']; ?></span></td>
                                    <td><?php echo $product['reorder_level']; ?></td>
                                    <td><?php echo $product['reorder_level'] - $product['current_stock']; ?> units below</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
