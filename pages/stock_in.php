<?php
$pageTitle = 'Stock In - C&C Building Shop';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$conn = getDBConnection();
$products = getAllProducts();

// Handle stock in
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = intval($_POST['product_id'] ?? 0);
    $entryMode = sanitize($_POST['entry_mode'] ?? 'individual');
    $quantity = intval($_POST['quantity'] ?? 0);
    $costPerUnit = floatval($_POST['cost_per_unit'] ?? 0);
    $bulkQuantity = !empty($_POST['bulk_quantity']) ? floatval($_POST['bulk_quantity']) : null;
    $bulkCost = !empty($_POST['bulk_cost']) ? floatval($_POST['bulk_cost']) : null;
    $notes = sanitize($_POST['notes'] ?? '');
    
    $errors = [];
    if ($productId <= 0) $errors[] = 'Please select a product';
    if ($entryMode === 'bulk') {
        if ($bulkQuantity <= 0) $errors[] = 'Bulk quantity must be greater than 0';
        if ($bulkCost < 0) $errors[] = 'Cost per bulk unit must be positive';
    } else {
        if ($quantity <= 0) $errors[] = 'Quantity must be greater than 0';
        if ($costPerUnit < 0) $errors[] = 'Cost per unit must be positive';
    }
    
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Get current product info
            $product = getProduct($productId);
            if (!$product) {
                throw new Exception('Product not found');
            }
            
            // Handle bulk entry
            if ($entryMode === 'bulk' && $product['bulk_unit_type'] && $product['units_per_bulk']) {
                // Calculate individual units from bulk
                $quantity = intval($bulkQuantity * $product['units_per_bulk']);
                $costPerUnit = $bulkCost / $product['units_per_bulk'];
            }
            
            // Generate receipt number
            $receiptNumber = 'REC-' . date('Ymd') . '-' . str_pad($productId, 4, '0', STR_PAD_LEFT) . '-' . time();
            
            // Calculate total cost
            $totalCost = $quantity * $costPerUnit;
            
            // Update product stock
            $newStock = $product['current_stock'] + $quantity;
            $stmt = $conn->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
            $stmt->bind_param("ii", $newStock, $productId);
            $stmt->execute();
            
            // Record stock movement - check if bulk columns exist
            $userId = getCurrentUserId();
            $movementType = 'in';
            
            // Check if bulk columns exist in stock_movements
            $checkBulkColumn = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'bulk_quantity'");
            $hasBulkColumns = $checkBulkColumn->num_rows > 0;
            
            if ($hasBulkColumns) {
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, cost_per_unit, total_cost, bulk_quantity, bulk_cost, receipt_number, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isiddddssi", $productId, $movementType, $quantity, $costPerUnit, $totalCost, $bulkQuantity, $bulkCost, $receiptNumber, $notes, $userId);
            } else {
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, cost_per_unit, total_cost, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isiddsi", $productId, $movementType, $quantity, $costPerUnit, $totalCost, $notes, $userId);
            }
            $stmt->execute();
            $movementId = $conn->insert_id;
            
            $conn->commit();
            
            $productBulkLabel = $product['bulk_unit_label'] ?? '';
            $displayQuantity = $entryMode === 'bulk' && $bulkQuantity && $productBulkLabel ? 
                "{$bulkQuantity} {$productBulkLabel} ({$quantity} units)" : 
                "{$quantity} units";
            
            $_SESSION['success_message'] = "Stock received successfully. {$displayQuantity} added to {$product['name']}.";
            $_SESSION['last_receipt_id'] = $movementId;
            $_SESSION['last_receipt_number'] = $receiptNumber;
            header('Location: /Inventory_sys/pages/stock_in.php');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = 'Failed to receive stock: ' . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = implode('<br>', $errors);
    }
}

// Get recent stock movements
// Check if bulk_unit_label column exists, if not use NULL
$checkColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'bulk_unit_label'");
$hasBulkColumns = $checkColumn->num_rows > 0;

if ($hasBulkColumns) {
    $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, p.category, p.bulk_unit_label, p.units_per_bulk
                           FROM stock_movements sm 
                           JOIN products p ON sm.product_id = p.id 
                           WHERE sm.movement_type = 'in'
                           ORDER BY sm.created_at DESC 
                           LIMIT 20");
} else {
    $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, p.category, NULL as bulk_unit_label, NULL as units_per_bulk
                           FROM stock_movements sm 
                           JOIN products p ON sm.product_id = p.id 
                           WHERE sm.movement_type = 'in'
                           ORDER BY sm.created_at DESC 
                           LIMIT 20");
}
$stmt->execute();
$recentMovements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Check if we just created a receipt
$lastReceiptId = $_SESSION['last_receipt_id'] ?? null;
$lastReceiptNumber = $_SESSION['last_receipt_number'] ?? null;
if ($lastReceiptId) {
    unset($_SESSION['last_receipt_id']);
    unset($_SESSION['last_receipt_number']);
}
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h2><i class="bi bi-arrow-down-circle"></i> Stock In</h2>
        <p class="text-muted">Receive new inventory and update stock levels</p>
    </div>
    <div class="col-md-4 text-end">
        <a href="/Inventory_sys/api/downloads.php?type=stock_in&format=csv" class="btn btn-outline-primary">
            <i class="bi bi-download"></i> Download Records
        </a>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Receive Stock</h5>
            </div>
            <div class="card-body">
                <?php if ($lastReceiptId): ?>
                    <div class="alert alert-success mb-3">
                        <h5><i class="bi bi-check-circle"></i> Stock Received Successfully!</h5>
                        <p class="mb-2">Receipt Number: <strong><?php echo htmlspecialchars($lastReceiptNumber); ?></strong></p>
                        <a href="/Inventory_sys/api/receipts.php?id=<?php echo $lastReceiptId; ?>" class="btn btn-success btn-sm" target="_blank">
                            <i class="bi bi-download"></i> Download Receipt
                        </a>
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="stockInForm">
                    <input type="hidden" name="entry_mode" id="entry_mode" value="individual">
                    
                    <div class="mb-3">
                        <label class="form-label required-field">Product</label>
                        <select class="form-select" name="product_id" id="product_select" required>
                            <option value="">Select a product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>" 
                                        data-stock="<?php echo $product['current_stock']; ?>"
                                        data-price="<?php echo $product['individual_purchase_price'] ?? $product['purchase_price']; ?>"
                                        data-bulk-type="<?php echo htmlspecialchars($product['bulk_unit_type'] ?? ''); ?>"
                                        data-units-per-bulk="<?php echo $product['units_per_bulk'] ?? ''; ?>"
                                        data-bulk-label="<?php echo htmlspecialchars($product['bulk_unit_label'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($product['name']); ?> 
                                    (<?php echo htmlspecialchars($product['category']); ?>)
                                    - Current Stock: <?php echo $product['current_stock']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Entry Mode Toggle -->
                    <div class="mb-3" id="entry_mode_toggle" style="display:none;">
                        <label class="form-label">Entry Mode</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="mode_radio" id="mode_individual" value="individual" checked>
                            <label class="btn btn-outline-primary" for="mode_individual">Individual Units</label>
                            
                            <input type="radio" class="btn-check" name="mode_radio" id="mode_bulk" value="bulk">
                            <label class="btn btn-outline-primary" for="mode_bulk">Bulk Entry</label>
                        </div>
                    </div>
                    
                    <!-- Individual Entry Fields -->
                    <div id="individual_entry">
                        <div class="mb-3">
                            <label class="form-label required-field">Quantity Received</label>
                            <input type="number" class="form-control" name="quantity" min="1" id="quantity_input">
                            <small class="form-text text-muted">Enter quantity in individual units</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label required-field">Cost Per Unit</label>
                            <input type="number" step="0.01" class="form-control" name="cost_per_unit" min="0" id="cost_input">
                            <small class="form-text text-muted">Purchase cost per individual unit</small>
                        </div>
                    </div>
                    
                    <!-- Bulk Entry Fields -->
                    <div id="bulk_entry" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label required-field">Bulk Quantity</label>
                            <div class="input-group">
                                <input type="number" step="0.01" class="form-control" name="bulk_quantity" min="0.01" id="bulk_quantity_input">
                                <span class="input-group-text" id="bulk_unit_display">units</span>
                            </div>
                            <small class="form-text text-muted" id="bulk_conversion_info"></small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label required-field">Cost Per Bulk Unit</label>
                            <input type="number" step="0.01" class="form-control" name="bulk_cost" min="0" id="bulk_cost_input">
                            <small class="form-text text-muted">Purchase cost per bulk unit</small>
                        </div>
                        
                        <div class="alert alert-info">
                            <strong>Individual Units:</strong> <span id="calculated_units">0</span> units<br>
                            <strong>Cost Per Unit:</strong> <span id="calculated_unit_cost">0</span> XAF
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <div class="alert alert-info">
                            <strong>Total Cost:</strong> <span id="total_cost">0</span> XAF
                        </div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Receive Stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Recent Stock Receipts</h5>
            </div>
            <div class="card-body">
                <?php if (empty($recentMovements)): ?>
                    <p class="text-muted text-center">No stock receipts yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Total Cost</th>
                                    <th>Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentMovements as $movement): ?>
                                <tr>
                                    <td><?php echo formatDateTime($movement['created_at']); ?></td>
                                    <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                                    <td>
                                        <?php 
                                        if ($movement['bulk_quantity'] && $movement['bulk_unit_label']) {
                                            echo $movement['bulk_quantity'] . ' ' . htmlspecialchars($movement['bulk_unit_label']) . ' (' . $movement['quantity'] . ' units)';
                                        } else {
                                            echo $movement['quantity'] . ' units';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo formatCurrency($movement['total_cost']); ?></td>
                                    <td>
                                        <?php if ($movement['receipt_number']): ?>
                                            <a href="/Inventory_sys/api/receipts.php?id=<?php echo $movement['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="Download Receipt">
                                                <i class="bi bi-receipt"></i>
                                            </a>
                                        <?php endif; ?>
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const productSelect = document.getElementById('product_select');
    const entryModeInput = document.getElementById('entry_mode');
    const entryModeToggle = document.getElementById('entry_mode_toggle');
    const individualEntry = document.getElementById('individual_entry');
    const bulkEntry = document.getElementById('bulk_entry');
    const modeIndividual = document.getElementById('mode_individual');
    const modeBulk = document.getElementById('mode_bulk');
    
    const quantityInput = document.getElementById('quantity_input');
    const costInput = document.getElementById('cost_input');
    const bulkQuantityInput = document.getElementById('bulk_quantity_input');
    const bulkCostInput = document.getElementById('bulk_cost_input');
    const bulkUnitDisplay = document.getElementById('bulk_unit_display');
    const bulkConversionInfo = document.getElementById('bulk_conversion_info');
    const calculatedUnits = document.getElementById('calculated_units');
    const calculatedUnitCost = document.getElementById('calculated_unit_cost');
    const totalCostSpan = document.getElementById('total_cost');
    
    let currentProduct = null;
    
    // Entry mode toggle
    modeIndividual.addEventListener('change', function() {
        if (this.checked) {
            entryModeInput.value = 'individual';
            individualEntry.style.display = 'block';
            bulkEntry.style.display = 'none';
            quantityInput.required = true;
            costInput.required = true;
            bulkQuantityInput.required = false;
            bulkCostInput.required = false;
            calculateTotal();
        }
    });
    
    modeBulk.addEventListener('change', function() {
        if (this.checked) {
            entryModeInput.value = 'bulk';
            individualEntry.style.display = 'none';
            bulkEntry.style.display = 'block';
            quantityInput.required = false;
            costInput.required = false;
            bulkQuantityInput.required = true;
            bulkCostInput.required = true;
            calculateBulkTotal();
        }
    });
    
    // Product selection
    productSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            currentProduct = {
                bulkType: selectedOption.getAttribute('data-bulk-type'),
                unitsPerBulk: selectedOption.getAttribute('data-units-per-bulk'),
                bulkLabel: selectedOption.getAttribute('data-bulk-label'),
                defaultPrice: selectedOption.getAttribute('data-price')
            };
            
            // Show/hide entry mode toggle based on bulk unit availability
            if (currentProduct.bulkType && currentProduct.unitsPerBulk) {
                entryModeToggle.style.display = 'block';
                bulkUnitDisplay.textContent = currentProduct.bulkLabel || 'units';
                bulkConversionInfo.textContent = `1 ${currentProduct.bulkLabel} = ${currentProduct.unitsPerBulk} units`;
            } else {
                entryModeToggle.style.display = 'none';
                entryModeInput.value = 'individual';
                individualEntry.style.display = 'block';
                bulkEntry.style.display = 'none';
            }
            
            // Set default price
            if (currentProduct.defaultPrice) {
                costInput.value = parseFloat(currentProduct.defaultPrice).toLocaleString('en-US', {maximumFractionDigits: 0});
                calculateTotal();
            }
        } else {
            entryModeToggle.style.display = 'none';
            currentProduct = null;
        }
    });
    
    function calculateTotal() {
        const quantity = parseFloat(quantityInput.value) || 0;
        const cost = parseFloat(costInput.value) || 0;
        const total = quantity * cost;
        totalCostSpan.textContent = total.toLocaleString('en-US', {maximumFractionDigits: 0});
    }
    
    function calculateBulkTotal() {
        if (!currentProduct || !currentProduct.unitsPerBulk) return;
        
        const bulkQty = parseFloat(bulkQuantityInput.value) || 0;
        const bulkCost = parseFloat(bulkCostInput.value) || 0;
        const unitsPerBulk = parseFloat(currentProduct.unitsPerBulk);
        
        const individualQty = bulkQty * unitsPerBulk;
        const unitCost = bulkCost / unitsPerBulk;
        const total = bulkQty * bulkCost;
        
        calculatedUnits.textContent = individualQty.toLocaleString('en-US', {maximumFractionDigits: 0});
        calculatedUnitCost.textContent = unitCost.toLocaleString('en-US', {maximumFractionDigits: 0});
        totalCostSpan.textContent = total.toLocaleString('en-US', {maximumFractionDigits: 0});
    }
    
    quantityInput.addEventListener('input', calculateTotal);
    costInput.addEventListener('input', calculateTotal);
    bulkQuantityInput.addEventListener('input', calculateBulkTotal);
    bulkCostInput.addEventListener('input', calculateBulkTotal);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
