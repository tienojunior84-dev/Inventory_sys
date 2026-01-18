<?php
$pageTitle = 'Dashboard - C&C Building Shop';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

// Get dashboard statistics
$todaySales = getTodaySalesTotal();
$lowStockCount = count(getLowStockProducts());
$lowStockProducts = getLowStockProducts();

// Calculate inventory values by category
$inventoryValueProvisions = calculateInventoryValue('Provisions');
$inventoryValueWine = calculateInventoryValue('Wine');
$inventoryValueBeer = calculateInventoryValue('Beer');
$totalInventoryValue = calculateInventoryValue();

// Get recent sales
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT s.*, p.name as product_name, p.category 
                       FROM sales s 
                       JOIN products p ON s.product_id = p.id 
                       ORDER BY s.created_at DESC 
                       LIMIT 10");
$stmt->execute();
$recentSales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-speedometer2"></i> Dashboard</h2>
        <p class="text-muted">Overview of your inventory and sales</p>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card stat-card success">
            <div class="card-body">
                <div class="stat-value"><?php echo formatCurrency($todaySales); ?></div>
                <div class="stat-label">Today's Sales</div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card info">
            <div class="card-body">
                <div class="stat-value"><?php echo formatCurrency($totalInventoryValue); ?></div>
                <div class="stat-label">Total Inventory Value</div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card <?php echo $lowStockCount > 0 ? 'danger' : 'success'; ?>">
            <div class="card-body">
                <div class="stat-value"><?php echo $lowStockCount; ?></div>
                <div class="stat-label">Low Stock Items</div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card">
            <div class="card-body">
                <div class="stat-value"><?php echo count(getAllProducts()); ?></div>
                <div class="stat-label">Total Products</div>
            </div>
        </div>
    </div>
</div>

<!-- Inventory Value by Category -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Inventory Value by Category</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                            <div>
                                <strong>Provisions</strong>
                                <div class="text-muted small">Stock Value</div>
                            </div>
                            <div class="h4 mb-0 text-primary"><?php echo formatCurrency($inventoryValueProvisions); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                            <div>
                                <strong>Wine</strong>
                                <div class="text-muted small">Stock Value</div>
                            </div>
                            <div class="h4 mb-0 text-danger"><?php echo formatCurrency($inventoryValueWine); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                            <div>
                                <strong>Beer</strong>
                                <div class="text-muted small">Stock Value</div>
                            </div>
                            <div class="h4 mb-0 text-warning"><?php echo formatCurrency($inventoryValueBeer); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Low Stock Alerts -->
<?php if ($lowStockCount > 0): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Low Stock Alerts</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Current Stock</th>
                                <th>Reorder Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStockProducts as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($product['category']); ?></span></td>
                                <td><span class="badge bg-danger"><?php echo $product['current_stock']; ?></span></td>
                                <td><?php echo $product['reorder_level']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Sales -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Sales</h5>
            </div>
            <div class="card-body">
                <?php if (empty($recentSales)): ?>
                    <p class="text-muted text-center">No sales recorded yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentSales as $sale): ?>
                                <tr>
                                    <td><?php echo formatDate($sale['sale_date']); ?></td>
                                    <td><?php echo htmlspecialchars($sale['product_name']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($sale['category']); ?></span></td>
                                    <td><?php echo $sale['quantity']; ?></td>
                                    <td><?php echo formatCurrency($sale['total_amount']); ?></td>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
