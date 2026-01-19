<?php
$pageTitle = 'Stock In - C&C Building Shop';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$conn = getDBConnection();
$products = getAllProducts();

// Receipt list date range
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$receiptMode = $_GET['receipt_mode'] ?? 'single';

$activeReceiptId = $_SESSION['active_stock_receipt_id'] ?? null;
$activeReceiptNumber = $_SESSION['active_stock_receipt_number'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receipt_action'])) {
    $receiptAction = sanitize($_POST['receipt_action'] ?? '');

    if ($receiptAction === 'start_receipt') {
        $supplierName = sanitize($_POST['supplier_name'] ?? '');
        $deliveryReference = sanitize($_POST['delivery_reference'] ?? '');

        $userId = getCurrentUserId();
        $receiptNumber = 'DEL-' . date('Ymd') . '-' . time();

        $hasReceiptsTable = $conn->query("SHOW TABLES LIKE 'stock_receipts'");
        if ($hasReceiptsTable && $hasReceiptsTable->num_rows > 0) {
            $stmt = $conn->prepare("INSERT INTO stock_receipts (receipt_number, supplier_name, delivery_reference, user_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $receiptNumber, $supplierName, $deliveryReference, $userId);
            if ($stmt->execute()) {
                $_SESSION['active_stock_receipt_id'] = $conn->insert_id;
                $_SESSION['active_stock_receipt_number'] = $receiptNumber;
                $_SESSION['success_message'] = 'Batch receipt started: ' . $receiptNumber;
            } else {
                $_SESSION['error_message'] = 'Failed to start receipt: ' . $conn->error;
            }
        } else {
            $_SESSION['error_message'] = 'Receipt batching is not available. Please run database migration.';
        }

        header('Location: /Inventory_sys/pages/stock_in.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&receipt_mode=' . urlencode($receiptMode));
        exit();
    }

    if ($receiptAction === 'finish_receipt' || $receiptAction === 'cancel_receipt') {
        unset($_SESSION['active_stock_receipt_id']);
        unset($_SESSION['active_stock_receipt_number']);

        if ($receiptAction === 'finish_receipt') {
            $_SESSION['success_message'] = 'Batch receipt finished.';
        } else {
            $_SESSION['success_message'] = 'Batch receipt canceled.';
        }

        header('Location: /Inventory_sys/pages/stock_in.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&receipt_mode=' . urlencode($receiptMode));
        exit();
    }
}

// Handle stock in
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = intval($_POST['product_id'] ?? 0);
    $entryMode = sanitize($_POST['entry_mode'] ?? 'individual');
    $quantity = intval($_POST['quantity'] ?? 0);
    $costPerUnit = floatval($_POST['cost_per_unit'] ?? 0);
    $bulkQuantity = !empty($_POST['bulk_quantity']) ? floatval($_POST['bulk_quantity']) : null;
    $bulkCost = !empty($_POST['bulk_cost']) ? floatval($_POST['bulk_cost']) : null;
    $receivedByName = sanitize($_POST['received_by_name'] ?? '');
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
            if ($entryMode === 'bulk' && !empty($product['units_per_bulk'])) {
                // Calculate individual units from bulk
                $quantity = intval($bulkQuantity * $product['units_per_bulk']);
                $costPerUnit = $bulkCost / $product['units_per_bulk'];
            }

            // Default cost per unit from product pricing when not provided
            if ($entryMode !== 'bulk' && ($costPerUnit <= 0)) {
                $costPerUnit = floatval($product['individual_purchase_price'] ?? $product['purchase_price'] ?? 0);
            }
            
            $receiptId = $_SESSION['active_stock_receipt_id'] ?? null;
            $receiptNumber = $_SESSION['active_stock_receipt_number'] ?? null;
            if (empty($receiptNumber)) {
                $receiptNumber = 'REC-' . date('Ymd') . '-' . str_pad($productId, 4, '0', STR_PAD_LEFT) . '-' . time();
            }
            
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
            
            $checkReceiptIdColumn = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'receipt_id'");
            $hasReceiptId = $checkReceiptIdColumn && $checkReceiptIdColumn->num_rows > 0;

            $checkReceivedByColumn = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'received_by_name'");
            $hasReceivedBy = $checkReceivedByColumn && $checkReceivedByColumn->num_rows > 0;

            $checkStockReceiptsReceivedBy = $conn->query("SHOW TABLES LIKE 'stock_receipts'");
            $hasReceiptsTableLocal = $checkStockReceiptsReceivedBy && $checkStockReceiptsReceivedBy->num_rows > 0;
            if (!empty($receiptId) && $hasReceiptsTableLocal && $receivedByName !== '') {
                $checkCol = $conn->query("SHOW COLUMNS FROM stock_receipts LIKE 'received_by_name'");
                if ($checkCol && $checkCol->num_rows > 0) {
                    $stmt = $conn->prepare("UPDATE stock_receipts SET received_by_name = COALESCE(NULLIF(received_by_name, ''), ?) WHERE id = ?");
                    $stmt->bind_param("si", $receivedByName, $receiptId);
                    $stmt->execute();
                }
            }

            if ($hasBulkColumns) {
                if ($hasReceiptId) {
                    if ($hasReceivedBy) {
                        $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, cost_per_unit, total_cost, bulk_quantity, bulk_cost, receipt_number, receipt_id, received_by_name, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isiddddssissi", $productId, $movementType, $quantity, $costPerUnit, $totalCost, $bulkQuantity, $bulkCost, $receiptNumber, $receiptId, $receivedByName, $notes, $userId);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, cost_per_unit, total_cost, bulk_quantity, bulk_cost, receipt_number, receipt_id, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isiddddsisi", $productId, $movementType, $quantity, $costPerUnit, $totalCost, $bulkQuantity, $bulkCost, $receiptNumber, $receiptId, $notes, $userId);
                    }
                } else {
                    if ($hasReceivedBy) {
                        $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, cost_per_unit, total_cost, bulk_quantity, bulk_cost, receipt_number, received_by_name, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isiddddsssi", $productId, $movementType, $quantity, $costPerUnit, $totalCost, $bulkQuantity, $bulkCost, $receiptNumber, $receivedByName, $notes, $userId);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, cost_per_unit, total_cost, bulk_quantity, bulk_cost, receipt_number, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isiddddssi", $productId, $movementType, $quantity, $costPerUnit, $totalCost, $bulkQuantity, $bulkCost, $receiptNumber, $notes, $userId);
                    }
                }
            } else {
                if ($hasReceivedBy) {
                    $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, cost_per_unit, total_cost, received_by_name, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isiddssi", $productId, $movementType, $quantity, $costPerUnit, $totalCost, $receivedByName, $notes, $userId);
                } else {
                    $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, cost_per_unit, total_cost, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isiddsi", $productId, $movementType, $quantity, $costPerUnit, $totalCost, $notes, $userId);
                }
            }
            $stmt->execute();
            $movementId = $conn->insert_id;
            
            $conn->commit();
            
            $productBulkLabel = $product['bulk_unit_label'] ?? '';
            $displayQuantity = $entryMode === 'bulk' && $bulkQuantity && $productBulkLabel ? 
                "{$bulkQuantity} {$productBulkLabel} ({$quantity} units)" : 
                "{$quantity} units";
            
            $_SESSION['success_message'] = "Stock received successfully. {$displayQuantity} added to {$product['name']}.";
            if (!empty($receiptId)) {
                $_SESSION['last_receipt_id'] = null;
                $_SESSION['last_receipt_number'] = $receiptNumber;
            } else {
                $_SESSION['last_receipt_id'] = $movementId;
                $_SESSION['last_receipt_number'] = $receiptNumber;
            }
            header('Location: /Inventory_sys/pages/stock_in.php?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) . '&receipt_mode=' . urlencode($receiptMode));
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

$checkReceiptsTable = $conn->query("SHOW TABLES LIKE 'stock_receipts'");
$hasReceiptsTable = $checkReceiptsTable && $checkReceiptsTable->num_rows > 0;

if ($hasBulkColumns) {
    if ($hasReceiptsTable) {
        $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, p.category, p.bulk_unit_label, p.units_per_bulk,
                               COALESCE(sr.receipt_number, sm.receipt_number) as display_receipt_number,
                               sr.supplier_name, sr.delivery_reference
                               FROM stock_movements sm
                               JOIN products p ON sm.product_id = p.id
                               LEFT JOIN stock_receipts sr ON sm.receipt_id = sr.id
                               WHERE sm.movement_type = 'in'
                               AND DATE(sm.created_at) BETWEEN ? AND ?
                               ORDER BY sm.created_at DESC
                               LIMIT 20");
    } else {
        $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, p.category, p.bulk_unit_label, p.units_per_bulk,
                               sm.receipt_number as display_receipt_number,
                               NULL as supplier_name, NULL as delivery_reference
                               FROM stock_movements sm
                               JOIN products p ON sm.product_id = p.id
                               WHERE sm.movement_type = 'in'
                               AND DATE(sm.created_at) BETWEEN ? AND ?
                               ORDER BY sm.created_at DESC
                               LIMIT 20");
    }
} else {
    if ($hasReceiptsTable) {
        $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, p.category, NULL as bulk_unit_label, NULL as units_per_bulk,
                               COALESCE(sr.receipt_number, sm.receipt_number) as display_receipt_number,
                               sr.supplier_name, sr.delivery_reference
                               FROM stock_movements sm
                               JOIN products p ON sm.product_id = p.id
                               LEFT JOIN stock_receipts sr ON sm.receipt_id = sr.id
                               WHERE sm.movement_type = 'in'
                               AND DATE(sm.created_at) BETWEEN ? AND ?
                               ORDER BY sm.created_at DESC
                               LIMIT 20");
    } else {
        $stmt = $conn->prepare("SELECT sm.*, p.name as product_name, p.category, NULL as bulk_unit_label, NULL as units_per_bulk,
                               sm.receipt_number as display_receipt_number,
                               NULL as supplier_name, NULL as delivery_reference
                               FROM stock_movements sm
                               JOIN products p ON sm.product_id = p.id
                               WHERE sm.movement_type = 'in'
                               AND DATE(sm.created_at) BETWEEN ? AND ?
                               ORDER BY sm.created_at DESC
                               LIMIT 20");
    }
}
$stmt->bind_param("ss", $startDate, $endDate);
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
        <a href="/Inventory_sys/api/downloads.php?type=stock_in&format=xls&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="btn btn-outline-primary">
            <i class="bi bi-download"></i> Download Records
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Start Date</label>
                <input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">End Date</label>
                <input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" required>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel"></i> Filter Receipts
                </button>
            </div>
        </form>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Receive Stock</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Receipt Type</label>
                    <form method="GET" class="row g-2">
                        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                        <div class="col-12">
                            <div class="btn-group w-100" role="group">
                                <input class="btn-check" type="radio" name="receipt_mode" id="receipt_mode_single" value="single" <?php echo $receiptMode === 'single' ? 'checked' : ''; ?> onchange="this.form.submit()">
                                <label class="btn btn-outline-primary" for="receipt_mode_single">Single</label>

                                <input class="btn-check" type="radio" name="receipt_mode" id="receipt_mode_multiple" value="multiple" <?php echo $receiptMode === 'multiple' ? 'checked' : ''; ?> onchange="this.form.submit()">
                                <label class="btn btn-outline-primary" for="receipt_mode_multiple">Multiple</label>
                            </div>
                            <small class="form-text text-muted">Single = one stock entry gives one receipt. Multiple = add several items then download one receipt.</small>
                        </div>
                    </form>
                </div>

                <?php if ($receiptMode === 'multiple'): ?>
                    <?php if ($activeReceiptId && $activeReceiptNumber): ?>
                        <div class="alert alert-warning mb-3">
                            <h5><i class="bi bi-receipt"></i> Active Batch Receipt</h5>
                            <p class="mb-2">Receipt Number: <strong><?php echo htmlspecialchars($activeReceiptNumber); ?></strong></p>
                            <div class="d-flex gap-2">
                                <a href="/Inventory_sys/api/receipts.php?receipt_id=<?php echo intval($activeReceiptId); ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                                    <i class="bi bi-receipt"></i> Preview Receipt
                                </a>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="receipt_action" value="finish_receipt">
                                    <button type="submit" class="btn btn-success btn-sm">Finish Receipt</button>
                                </form>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="receipt_action" value="cancel_receipt">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Cancel</button>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="mb-3">Start Batch Receipt (Optional)</h6>
                                <form method="POST" class="row g-2">
                                    <input type="hidden" name="receipt_action" value="start_receipt">
                                    <div class="col-md-6">
                                        <input type="text" class="form-control" name="supplier_name" placeholder="Supplier name (optional)">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="text" class="form-control" name="delivery_reference" placeholder="Delivery reference (optional)">
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-outline-primary w-100">Start Receipt</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($activeReceiptId && $activeReceiptNumber): ?>
                        <div class="alert alert-warning mb-3">
                            <h5 class="mb-1">A batch receipt is still active</h5>
                            <div class="small text-muted mb-2">Receipt: <?php echo htmlspecialchars($activeReceiptNumber); ?></div>
                            <form method="POST" class="m-0">
                                <input type="hidden" name="receipt_action" value="cancel_receipt">
                                <button type="submit" class="btn btn-outline-danger btn-sm">End Active Batch Receipt</button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($lastReceiptId): ?>
                    <div class="alert alert-success mb-3">
                        <h5><i class="bi bi-check-circle"></i> Stock Received Successfully!</h5>
                        <p class="mb-2">Receipt Number: <strong><?php echo htmlspecialchars($lastReceiptNumber); ?></strong></p>
                        <a href="/Inventory_sys/api/receipts.php?id=<?php echo $lastReceiptId; ?>" class="btn btn-success btn-sm" target="_blank">
                            <i class="bi bi-download"></i> Download Receipt
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (!$lastReceiptId && !empty($lastReceiptNumber) && $receiptMode === 'multiple' && $activeReceiptId): ?>
                    <div class="alert alert-info mb-3">
                        <h6 class="mb-1">Item added to batch receipt</h6>
                        <div class="small text-muted mb-2">Receipt: <?php echo htmlspecialchars($lastReceiptNumber); ?></div>
                        <a href="/Inventory_sys/api/receipts.php?receipt_id=<?php echo intval($activeReceiptId); ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                            <i class="bi bi-receipt"></i> Preview Receipt
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
                                        data-individual-price="<?php echo $product['individual_purchase_price'] ?? $product['purchase_price']; ?>"
                                        data-bulk-price="<?php echo $product['bulk_purchase_price'] ?? ''; ?>"
                                        data-units-per-bulk="<?php echo $product['units_per_bulk'] ?? ''; ?>"
                                        data-bulk-label="<?php echo htmlspecialchars($product['bulk_unit_label'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($product['name']); ?> 
                                    (<?php echo htmlspecialchars($product['category']); ?>)
                                    - Current Stock: <?php echo $product['current_stock']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3" id="product_pricing_box" style="display:none;">
                        <div class="alert alert-secondary mb-0">
                            <div><strong>Individual Purchase Price (Cost per unit):</strong> <span id="display_individual_price">0</span> XAF</div>
                            <div><strong>Bulk Purchase Price:</strong> <span id="display_bulk_price">N/A</span></div>
                        </div>
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
                            <small class="form-text text-muted">Defaults to the product's Individual Purchase Price (you can edit if needed)</small>
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
                            <small class="form-text text-muted">Defaults to the product's Bulk Purchase Price (you can edit if needed)</small>
                        </div>
                        
                        <div class="alert alert-info">
                            <strong>Individual Units:</strong> <span id="calculated_units">0</span> units<br>
                            <strong>Cost Per Unit:</strong> <span id="calculated_unit_cost">0</span> XAF
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Received By (Optional)</label>
                        <input type="text" class="form-control" name="received_by_name" placeholder="Name of person who received the stock">
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
                                    <th>Receipt</th>
                                    <th>Product</th>
                                    <th>Mode</th>
                                    <th>Quantity</th>
                                    <th>Total Cost</th>
                                    <th>Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $lastDay = null;
                                foreach ($recentMovements as $movement):
                                    $dayKey = date('Y-m-d', strtotime($movement['created_at']));
                                    if ($lastDay !== $dayKey):
                                        $lastDay = $dayKey;
                                ?>
                                <tr class="table-light">
                                    <td colspan="7"><strong><?php echo formatDate($movement['created_at']); ?></strong></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td><?php echo formatDateTime($movement['created_at']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($movement['display_receipt_number'] ?? $movement['receipt_number'] ?? 'N/A'); ?></span></td>
                                    <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                                    <td>
                                        <?php
                                        $isBulk = !empty($movement['bulk_quantity']) && !empty($movement['bulk_unit_label']);
                                        echo $isBulk ? '<span class="badge bg-info">Bulk</span>' : '<span class="badge bg-primary">Individual</span>';
                                        ?>
                                    </td>
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
                                            <?php if (!empty($movement['receipt_id'])): ?>
                                                <a href="/Inventory_sys/api/receipts.php?receipt_id=<?php echo intval($movement['receipt_id']); ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="Download Receipt">
                                                    <i class="bi bi-receipt"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="/Inventory_sys/api/receipts.php?id=<?php echo $movement['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank" title="Download Receipt">
                                                    <i class="bi bi-receipt"></i>
                                                </a>
                                            <?php endif; ?>
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

    const productPricingBox = document.getElementById('product_pricing_box');
    const displayIndividualPrice = document.getElementById('display_individual_price');
    const displayBulkPrice = document.getElementById('display_bulk_price');
    
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
                unitsPerBulk: selectedOption.getAttribute('data-units-per-bulk'),
                bulkLabel: selectedOption.getAttribute('data-bulk-label'),
                individualPrice: selectedOption.getAttribute('data-individual-price'),
                bulkPrice: selectedOption.getAttribute('data-bulk-price')
            };

            if (productPricingBox) {
                productPricingBox.style.display = 'block';
            }

            const indPriceNum = parseFloat(currentProduct.individualPrice) || 0;
            if (displayIndividualPrice) {
                displayIndividualPrice.textContent = indPriceNum.toLocaleString('en-US', { maximumFractionDigits: 0 });
            }
            const bulkPriceNum = parseFloat(currentProduct.bulkPrice);
            if (displayBulkPrice) {
                if (!isNaN(bulkPriceNum) && bulkPriceNum > 0 && currentProduct.bulkLabel) {
                    displayBulkPrice.textContent = bulkPriceNum.toLocaleString('en-US', { maximumFractionDigits: 0 }) + ' XAF per ' + currentProduct.bulkLabel;
                } else {
                    displayBulkPrice.textContent = 'N/A';
                }
            }
            
            // Show/hide entry mode toggle based on bulk unit availability
            if (currentProduct.unitsPerBulk && currentProduct.bulkLabel) {
                entryModeToggle.style.display = 'block';
                bulkUnitDisplay.textContent = currentProduct.bulkLabel || 'units';
                bulkConversionInfo.textContent = `1 ${currentProduct.bulkLabel} = ${currentProduct.unitsPerBulk} units`;
            } else {
                entryModeToggle.style.display = 'none';
                entryModeInput.value = 'individual';
                individualEntry.style.display = 'block';
                bulkEntry.style.display = 'none';
            }
            
            // Set default unit cost (individual purchase price)
            if (!isNaN(indPriceNum) && indPriceNum > 0) {
                costInput.value = indPriceNum.toFixed(2);
            }

            // Set default bulk cost (bulk purchase price)
            if (!isNaN(bulkPriceNum) && bulkPriceNum > 0) {
                bulkCostInput.value = bulkPriceNum.toFixed(2);
            } else {
                bulkCostInput.value = '';
            }

            calculateTotal();
            calculateBulkTotal();
        } else {
            entryModeToggle.style.display = 'none';
            currentProduct = null;
            if (productPricingBox) {
                productPricingBox.style.display = 'none';
            }
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
