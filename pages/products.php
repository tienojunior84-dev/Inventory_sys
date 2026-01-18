<?php
$pageTitle = 'Products - C&C Building Shop';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$conn = getDBConnection();
$categoryFilter = $_GET['category'] ?? '';

// Handle product operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = sanitize($_POST['name'] ?? '');
        $category = sanitize($_POST['category'] ?? '');
        $purchaseMode = sanitize($_POST['purchase_mode'] ?? '');
        $individualPurchasePrice = floatval($_POST['individual_purchase_price'] ?? 0);
        $bulkPurchasePrice = !empty($_POST['bulk_purchase_price']) ? floatval($_POST['bulk_purchase_price']) : null;
        $sellingPrice = floatval($_POST['selling_price'] ?? 0);
        $initialStockEntryMode = sanitize($_POST['initial_stock_entry_mode'] ?? 'individual');
        $initialStockQuantity = isset($_POST['initial_stock_quantity']) ? floatval($_POST['initial_stock_quantity']) : 0;
        $reorderLevel = !empty($_POST['reorder_level']) ? intval($_POST['reorder_level']) : null;
        $bulkUnitType = null;
        $unitsPerBulk = !empty($_POST['units_per_bulk']) ? intval($_POST['units_per_bulk']) : null;
        $bulkUnitLabel = !empty($_POST['bulk_unit_label']) ? sanitize($_POST['bulk_unit_label']) : null;
        
        $errors = [];
        if (empty($name)) $errors[] = 'Product name is required';
        if (empty($category) || !in_array($category, CATEGORIES)) $errors[] = 'Valid category is required';
        if (empty($purchaseMode) || !in_array($purchaseMode, ['individual', 'bulk'])) $errors[] = 'Please choose a purchase configuration';
        if ($sellingPrice <= 0) $errors[] = 'Selling price must be greater than 0';

        if ($purchaseMode === 'individual') {
            if ($individualPurchasePrice <= 0) $errors[] = 'Individual purchase price must be greater than 0';
            // Clear bulk fields (Option A)
            $bulkPurchasePrice = null;
            $bulkUnitType = null;
            $unitsPerBulk = null;
            $bulkUnitLabel = null;
        } elseif ($purchaseMode === 'bulk') {
            if (empty($bulkUnitLabel)) $errors[] = 'Bulk unit name is required';
            if ($unitsPerBulk === null || $unitsPerBulk <= 0) $errors[] = 'Units per bulk must be greater than 0';
            if ($bulkPurchasePrice === null || $bulkPurchasePrice <= 0) $errors[] = 'Bulk purchase price must be greater than 0';

            if (empty($errors) && $unitsPerBulk > 0) {
                $individualPurchasePrice = $bulkPurchasePrice / $unitsPerBulk;
            }
        }

        if ($initialStockQuantity < 0) $errors[] = 'Initial stock quantity must be 0 or greater';
        if ($purchaseMode === 'bulk' && $initialStockEntryMode === 'bulk' && ($unitsPerBulk === null || $unitsPerBulk <= 0)) {
            $errors[] = 'Units per bulk must be set to use bulk stock entry';
        }
        
        if (empty($errors)) {
            $initialStock = 0;
            if ($purchaseMode === 'bulk' && $initialStockEntryMode === 'bulk' && $unitsPerBulk) {
                $initialStock = intval(round($initialStockQuantity * $unitsPerBulk));
            } else {
                $initialStock = intval(round($initialStockQuantity));
            }

            // Check if bulk/pricing columns exist
            $checkBulkColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'bulk_unit_type'");
            $hasBulkColumns = $checkBulkColumn->num_rows > 0;
            $checkPricingColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'individual_purchase_price'");
            $hasPricingColumns = $checkPricingColumn->num_rows > 0;
            
            if ($hasBulkColumns && $hasPricingColumns) {
                $stmt = $conn->prepare("INSERT INTO products (name, category, individual_purchase_price, bulk_purchase_price, selling_price, current_stock, reorder_level, bulk_unit_type, units_per_bulk, bulk_unit_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdddiisis", $name, $category, $individualPurchasePrice, $bulkPurchasePrice, $sellingPrice, $initialStock, $reorderLevel, $bulkUnitType, $unitsPerBulk, $bulkUnitLabel);
            } elseif ($hasPricingColumns) {
                $stmt = $conn->prepare("INSERT INTO products (name, category, individual_purchase_price, bulk_purchase_price, selling_price, current_stock, reorder_level) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdddii", $name, $category, $individualPurchasePrice, $bulkPurchasePrice, $sellingPrice, $initialStock, $reorderLevel);
            } elseif ($hasBulkColumns) {
                $stmt = $conn->prepare("INSERT INTO products (name, category, purchase_price, selling_price, current_stock, reorder_level, bulk_unit_type, units_per_bulk, bulk_unit_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssddiisss", $name, $category, $individualPurchasePrice, $sellingPrice, $initialStock, $reorderLevel, $bulkUnitType, $unitsPerBulk, $bulkUnitLabel);
            } else {
                $reorderLevelValue = $reorderLevel ?? null;
                $stmt = $conn->prepare("INSERT INTO products (name, category, purchase_price, selling_price, current_stock, reorder_level) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssddii", $name, $category, $individualPurchasePrice, $sellingPrice, $initialStock, $reorderLevelValue);
            }
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Product added successfully';
                header('Location: /Inventory_sys/pages/products.php');
                exit();
            } else {
                $_SESSION['error_message'] = 'Failed to add product: ' . $conn->error;
            }
        } else {
            $_SESSION['error_message'] = implode('<br>', $errors);
        }
    }
    
    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = sanitize($_POST['name'] ?? '');
        $category = sanitize($_POST['category'] ?? '');
        $purchaseMode = sanitize($_POST['purchase_mode'] ?? '');
        $individualPurchasePrice = floatval($_POST['individual_purchase_price'] ?? 0);
        $bulkPurchasePrice = !empty($_POST['bulk_purchase_price']) ? floatval($_POST['bulk_purchase_price']) : null;
        $sellingPrice = floatval($_POST['selling_price'] ?? 0);
        $reorderLevel = !empty($_POST['reorder_level']) ? intval($_POST['reorder_level']) : null;
        $bulkUnitType = null;
        $unitsPerBulk = !empty($_POST['units_per_bulk']) ? intval($_POST['units_per_bulk']) : null;
        $bulkUnitLabel = !empty($_POST['bulk_unit_label']) ? sanitize($_POST['bulk_unit_label']) : null;

        $newCurrentStockRaw = $_POST['new_current_stock'] ?? '';
        $newCurrentStock = $newCurrentStockRaw !== '' ? intval($newCurrentStockRaw) : null;
        $stockAdjustmentNotes = sanitize($_POST['stock_adjustment_notes'] ?? '');
        
        $errors = [];
        if (empty($name)) $errors[] = 'Product name is required';
        if (empty($category) || !in_array($category, CATEGORIES)) $errors[] = 'Valid category is required';
        if (empty($purchaseMode) || !in_array($purchaseMode, ['individual', 'bulk'])) $errors[] = 'Please choose a purchase configuration';
        if ($sellingPrice <= 0) $errors[] = 'Selling price must be greater than 0';

        if ($purchaseMode === 'individual') {
            if ($individualPurchasePrice <= 0) $errors[] = 'Individual purchase price must be greater than 0';
            // Clear bulk fields (Option A)
            $bulkPurchasePrice = null;
            $bulkUnitType = null;
            $unitsPerBulk = null;
            $bulkUnitLabel = null;
        } elseif ($purchaseMode === 'bulk') {
            if (empty($bulkUnitLabel)) $errors[] = 'Bulk unit name is required';
            if ($unitsPerBulk === null || $unitsPerBulk <= 0) $errors[] = 'Units per bulk must be greater than 0';
            if ($bulkPurchasePrice === null || $bulkPurchasePrice <= 0) $errors[] = 'Bulk purchase price must be greater than 0';

            if (empty($errors) && $unitsPerBulk > 0) {
                $individualPurchasePrice = $bulkPurchasePrice / $unitsPerBulk;
            }
        }

        if ($newCurrentStock !== null && $newCurrentStock < 0) {
            $errors[] = 'Stock quantity must be 0 or greater';
        }
        
        if (empty($errors)) {
            $conn->begin_transaction();

            // Check if bulk/pricing columns exist
            $checkBulkColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'bulk_unit_type'");
            $hasBulkColumns = $checkBulkColumn->num_rows > 0;
            $checkPricingColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'individual_purchase_price'");
            $hasPricingColumns = $checkPricingColumn->num_rows > 0;
            
            if ($hasBulkColumns && $hasPricingColumns) {
                $stmt = $conn->prepare("UPDATE products SET name = ?, category = ?, individual_purchase_price = ?, bulk_purchase_price = ?, selling_price = ?, reorder_level = ?, bulk_unit_type = ?, units_per_bulk = ?, bulk_unit_label = ? WHERE id = ?");
                $stmt->bind_param("ssdddisisi", $name, $category, $individualPurchasePrice, $bulkPurchasePrice, $sellingPrice, $reorderLevel, $bulkUnitType, $unitsPerBulk, $bulkUnitLabel, $id);
            } elseif ($hasPricingColumns) {
                $stmt = $conn->prepare("UPDATE products SET name = ?, category = ?, individual_purchase_price = ?, bulk_purchase_price = ?, selling_price = ?, reorder_level = ? WHERE id = ?");
                $stmt->bind_param("ssdddii", $name, $category, $individualPurchasePrice, $bulkPurchasePrice, $sellingPrice, $reorderLevel, $id);
            } elseif ($hasBulkColumns) {
                $stmt = $conn->prepare("UPDATE products SET name = ?, category = ?, purchase_price = ?, selling_price = ?, reorder_level = ?, bulk_unit_type = ?, units_per_bulk = ?, bulk_unit_label = ? WHERE id = ?");
                $stmt->bind_param("ssddiissi", $name, $category, $individualPurchasePrice, $sellingPrice, $reorderLevel, $bulkUnitType, $unitsPerBulk, $bulkUnitLabel, $id);
            } else {
                $stmt = $conn->prepare("UPDATE products SET name = ?, category = ?, purchase_price = ?, selling_price = ?, reorder_level = ? WHERE id = ?");
                $stmt->bind_param("ssddii", $name, $category, $individualPurchasePrice, $sellingPrice, $reorderLevel, $id);
            }
            
            try {
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update product');
                }

                // Optional stock adjustment (recorded as stock movement)
                if ($newCurrentStock !== null) {
                    $product = getProduct($id);
                    if (!$product) {
                        throw new Exception('Product not found');
                    }

                    $oldStock = intval($product['current_stock']);
                    $delta = $newCurrentStock - $oldStock;

                    if ($delta !== 0) {
                        $stmt = $conn->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
                        $stmt->bind_param("ii", $newCurrentStock, $id);
                        if (!$stmt->execute()) {
                            throw new Exception('Failed to update stock');
                        }

                        $movementType = $delta > 0 ? 'in' : 'out';
                        $movementQty = abs($delta);
                        $userId = getCurrentUserId();
                        $notes = trim($stockAdjustmentNotes);
                        if ($notes === '') {
                            $notes = 'Stock adjustment via product edit';
                        }

                        $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, notes, user_id) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("isisi", $id, $movementType, $movementQty, $notes, $userId);
                        if (!$stmt->execute()) {
                            throw new Exception('Failed to record stock movement');
                        }
                    }
                }

                $conn->commit();
                $_SESSION['success_message'] = 'Product updated successfully';
                header('Location: /Inventory_sys/pages/products.php');
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = $e->getMessage();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = 'Product deleted successfully';
            header('Location: /Inventory_sys/pages/products.php');
            exit();
        } else {
            $_SESSION['error_message'] = 'Failed to delete product';
        }
    }
}

// Get products
$products = getAllProducts($categoryFilter ?: null);
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2><i class="bi bi-box"></i> Products</h2>
    </div>
    <div class="col-md-6 text-end">
        <a href="/Inventory_sys/api/downloads.php?type=products&format=csv" class="btn btn-outline-primary me-2">
            <i class="bi bi-download"></i> Download
        </a>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
            <i class="bi bi-plus-circle"></i> Add Product
        </button>
    </div>
</div>

<!-- Category Filter -->
<div class="row mb-3">
    <div class="col-md-4">
        <form method="GET" action="">
            <select name="category" class="form-select" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php foreach (CATEGORIES as $cat): ?>
                    <option value="<?php echo $cat; ?>" <?php echo $categoryFilter === $cat ? 'selected' : ''; ?>>
                        <?php echo $cat; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Individual Purchase Price</th>
                        <th>Selling Price</th>
                        <th>Current Stock</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">No products found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($product['category']); ?></span></td>
                                <td>
                                    <?php
                                    $displayPurchase = $product['individual_purchase_price'] ?? $product['purchase_price'] ?? 0;
                                    echo formatCurrency($displayPurchase);
                                    ?>
                                </td>
                                <td><?php echo formatCurrency($product['selling_price']); ?></td>
                                <td><?php echo $product['current_stock']; ?></td>
                                <td><?php echo $product['reorder_level'] ?? 'N/A'; ?></td>
                                <td>
                                    <?php if ($product['reorder_level'] && $product['current_stock'] <= $product['reorder_level']): ?>
                                        <span class="badge bg-danger">Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">In Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary" onclick="editProduct(<?php echo htmlspecialchars(json_encode($product)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirmDelete('Are you sure you want to delete this product?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $product['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="addProductForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="mb-3">Basic Information</h6>
                    <div class="mb-3">
                        <label class="form-label required-field">Product Name</label>
                        <input type="text" class="form-control" name="name" placeholder="Enter product name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-field">Category</label>
                        <select class="form-select" name="category" required>
                            <option value="">Select Category</option>
                            <?php foreach (CATEGORIES as $cat): ?>
                                <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <hr>
                    <h6 class="mb-1">Purchase Configuration</h6>
                    <small class="text-muted d-block mb-3">How do you purchase this product from suppliers?</small>

                    <div class="mb-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="purchase_mode" id="add_purchase_mode_individual" value="individual" required>
                            <label class="form-check-label" for="add_purchase_mode_individual">Individual Units Only</label>
                            <div class="form-text">Product purchased as single items (bottles, pieces, bags)</div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="purchase_mode" id="add_purchase_mode_bulk" value="bulk" required>
                            <label class="form-check-label" for="add_purchase_mode_bulk">Bulk Units</label>
                            <div class="form-text">Product purchased in bulk packaging (cartons, cases, crates)</div>
                        </div>
                    </div>

                    <div id="add_individual_purchase_section" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label required-field">Purchase Price</label>
                            <div class="input-group">
                                <input type="number" step="0.01" class="form-control" name="individual_purchase_price" id="add_individual_purchase_price" min="0" placeholder="0">
                                <span class="input-group-text">FCFA per unit</span>
                            </div>
                            <div class="form-text">What you pay when buying single items</div>
                        </div>
                    </div>

                    <div id="add_bulk_purchase_section" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label required-field">Bulk Unit Name</label>
                            <input type="text" class="form-control" name="bulk_unit_label" id="add_bulk_unit_label" placeholder="e.g., Carton, Case, Crate, Pack, Box">
                            <div class="form-text">What is the bulk packaging called?</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required-field">Units per Bulk</label>
                            <input type="number" class="form-control" name="units_per_bulk" id="add_units_per_bulk" min="1" step="1" placeholder="0">
                            <div class="form-text">How many individual items in one bulk unit?</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required-field">Bulk Purchase Price</label>
                            <div class="input-group">
                                <input type="number" step="0.01" class="form-control" name="bulk_purchase_price" id="add_bulk_purchase_price" min="0" placeholder="0">
                                <span class="input-group-text">FCFA per <span id="add_bulk_unit_suffix">bulk unit</span></span>
                            </div>
                            <div class="form-text">What you pay for one complete bulk unit</div>
                        </div>
                        <div class="alert alert-info py-2" id="add_individual_price_calc" style="display:none;">
                            <strong>→ Individual price:</strong> <span id="add_individual_price_calc_value">0</span> FCFA per item
                        </div>

                        <div class="mb-3" style="display:none;">
                            <input type="number" step="0.01" class="form-control" name="individual_purchase_price" id="add_individual_purchase_price_bulk" readonly>
                        </div>
                    </div>

                    <hr>
                    <h6 class="mb-3">Selling Price</h6>
                    <div class="mb-3">
                        <label class="form-label required-field">Selling Price</label>
                        <div class="input-group">
                            <input type="number" step="0.01" class="form-control" name="selling_price" id="add_selling_price" min="0" placeholder="0" required>
                            <span class="input-group-text">FCFA per unit</span>
                        </div>
                        <div class="form-text">What customers pay per item</div>
                    </div>
                    <div class="alert py-2" id="add_profit_box" style="display:none;">
                        <strong>Profit per unit:</strong> <span id="add_profit_value">0</span> FCFA
                    </div>

                    <hr>
                    <h6 class="mb-3">Initial Stock</h6>
                    <div id="add_initial_stock_individual_section" style="display:none;">
                        <label class="form-label required-field">Initial Stock Quantity</label>
                        <div class="input-group mb-2">
                            <input type="number" class="form-control" name="initial_stock_quantity" id="add_initial_stock_quantity_individual" min="0" step="1" placeholder="0">
                            <span class="input-group-text">units</span>
                        </div>
                        <div class="form-text">How many units do you currently have?</div>
                        <input type="hidden" name="initial_stock_entry_mode" value="individual">
                    </div>

                    <div id="add_initial_stock_bulk_section" style="display:none;">
                        <label class="form-label">How do you want to enter initial stock?</label>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="initial_stock_entry_mode" id="add_stock_entry_bulk" value="bulk">
                            <label class="form-check-label" for="add_stock_entry_bulk">As Bulk Units (<span id="add_stock_bulk_label">bulk</span>)</label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="initial_stock_entry_mode" id="add_stock_entry_individual" value="individual" checked>
                            <label class="form-check-label" for="add_stock_entry_individual">As Individual Units (items)</label>
                        </div>

                        <div class="mb-2">
                            <label class="form-label required-field" id="add_initial_stock_label">Initial Quantity (in items)</label>
                            <input type="number" class="form-control" name="initial_stock_quantity" id="add_initial_stock_quantity_bulk" min="0" step="1" placeholder="0">
                        </div>
                        <div class="alert alert-info py-2" id="add_stock_conversion_box" style="display:none;">
                            <strong>→ Will add:</strong> <span id="add_stock_conversion_value">0</span> items to stock
                        </div>
                    </div>

                    <hr>
                    <h6 class="mb-1">Stock Management (Optional)</h6>
                    <div class="mb-3">
                        <label class="form-label">Reorder Level</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="reorder_level" id="add_reorder_level" min="0" placeholder="Leave empty to disable">
                            <span class="input-group-text">units</span>
                        </div>
                        <div class="form-text">Alert when stock falls below this level (leave empty to disable alerts)</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="add_save_btn" disabled>Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editProductForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_product_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="mb-3">Basic Information</h6>
                    <div class="mb-3">
                        <label class="form-label required-field">Product Name</label>
                        <input type="text" class="form-control" name="name" id="edit_product_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-field">Category</label>
                        <select class="form-select" name="category" id="edit_product_category" required>
                            <?php foreach (CATEGORIES as $cat): ?>
                                <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <hr>
                    <h6 class="mb-1">Purchase Configuration</h6>
                    <small class="text-muted d-block mb-3">How do you purchase this product from suppliers?</small>

                    <div class="mb-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="purchase_mode" id="edit_purchase_mode_individual" value="individual" required>
                            <label class="form-check-label" for="edit_purchase_mode_individual">Individual Units Only</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="purchase_mode" id="edit_purchase_mode_bulk" value="bulk" required>
                            <label class="form-check-label" for="edit_purchase_mode_bulk">Bulk Units</label>
                        </div>
                    </div>

                    <div id="edit_individual_purchase_section" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label required-field">Purchase Price</label>
                            <div class="input-group">
                                <input type="number" step="0.01" class="form-control" name="individual_purchase_price" id="edit_individual_purchase_price" min="0" placeholder="0">
                                <span class="input-group-text">FCFA per unit</span>
                            </div>
                            <div class="form-text">What you pay when buying single items</div>
                        </div>
                    </div>

                    <div id="edit_bulk_purchase_section" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label required-field">Bulk Unit Name</label>
                            <input type="text" class="form-control" name="bulk_unit_label" id="edit_bulk_unit_label" placeholder="e.g., Carton, Case, Crate, Pack, Box">
                        </div>
                        <div class="mb-3">
                            <label class="form-label required-field">Units per Bulk</label>
                            <input type="number" class="form-control" name="units_per_bulk" id="edit_units_per_bulk" min="1" step="1" placeholder="0">
                        </div>
                        <div class="mb-3">
                            <label class="form-label required-field">Bulk Purchase Price</label>
                            <div class="input-group">
                                <input type="number" step="0.01" class="form-control" name="bulk_purchase_price" id="edit_bulk_purchase_price" min="0" placeholder="0">
                                <span class="input-group-text">FCFA per <span id="edit_bulk_unit_suffix">bulk unit</span></span>
                            </div>
                        </div>
                        <div class="alert alert-info py-2" id="edit_individual_price_calc" style="display:none;">
                            <strong>→ Individual price:</strong> <span id="edit_individual_price_calc_value">0</span> FCFA per item
                        </div>
                        <div class="mb-3" style="display:none;">
                            <input type="number" step="0.01" class="form-control" name="individual_purchase_price" id="edit_individual_purchase_price_bulk" readonly>
                        </div>
                    </div>

                    <hr>
                    <h6 class="mb-3">Selling Price</h6>
                    <div class="mb-3">
                        <label class="form-label required-field">Selling Price</label>
                        <div class="input-group">
                            <input type="number" step="0.01" class="form-control" name="selling_price" id="edit_selling_price" min="0" placeholder="0" required>
                            <span class="input-group-text">FCFA per unit</span>
                        </div>
                        <div class="form-text">What customers pay per item</div>
                    </div>
                    <div class="alert py-2" id="edit_profit_box" style="display:none;">
                        <strong>Profit per unit:</strong> <span id="edit_profit_value">0</span> FCFA
                    </div>

                    <hr>
                    <h6 class="mb-3">Stock Adjustment</h6>
                    <div class="mb-3">
                        <label class="form-label">New Current Stock (units)</label>
                        <input type="number" class="form-control" name="new_current_stock" id="edit_new_current_stock" min="0" placeholder="Leave empty to keep unchanged">
                        <div class="form-text">If you change this, a stock movement will be recorded automatically.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Adjustment Notes (Optional)</label>
                        <input type="text" class="form-control" name="stock_adjustment_notes" id="edit_stock_adjustment_notes" placeholder="Reason for adjustment">
                    </div>

                    <hr>
                    <h6 class="mb-1">Stock Management (Optional)</h6>
                    <div class="mb-3">
                        <label class="form-label">Reorder Level</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="reorder_level" id="edit_product_reorder_level" min="0" placeholder="Leave empty to disable">
                            <span class="input-group-text">units</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="edit_save_btn" disabled>Update Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editProduct(product) {
    document.getElementById('edit_product_id').value = product.id;
    document.getElementById('edit_product_name').value = product.name;
    document.getElementById('edit_product_category').value = product.category;
    document.getElementById('edit_selling_price').value = product.selling_price;
    document.getElementById('edit_product_reorder_level').value = product.reorder_level || '';
    document.getElementById('edit_new_current_stock').value = '';
    document.getElementById('edit_stock_adjustment_notes').value = '';

    const hasBulk = (product.units_per_bulk && parseInt(product.units_per_bulk, 10) > 0) && (product.bulk_purchase_price && parseFloat(product.bulk_purchase_price) > 0) && (product.bulk_unit_label && product.bulk_unit_label !== '');
    if (hasBulk) {
        document.getElementById('edit_purchase_mode_bulk').checked = true;
        document.getElementById('edit_bulk_unit_label').value = product.bulk_unit_label || '';
        document.getElementById('edit_units_per_bulk').value = product.units_per_bulk || '';
        document.getElementById('edit_bulk_purchase_price').value = product.bulk_purchase_price || '';
        document.getElementById('edit_individual_purchase_price_bulk').value = product.individual_purchase_price || product.purchase_price || '';
    } else {
        document.getElementById('edit_purchase_mode_individual').checked = true;
        document.getElementById('edit_individual_purchase_price').value = product.individual_purchase_price || product.purchase_price || '';
        document.getElementById('edit_bulk_unit_label').value = '';
        document.getElementById('edit_units_per_bulk').value = '';
        document.getElementById('edit_bulk_purchase_price').value = '';
        document.getElementById('edit_individual_purchase_price_bulk').value = '';
    }

    var editModal = new bootstrap.Modal(document.getElementById('editProductModal'));
    editModal.show();

    updatePurchaseModeUI('edit');
    recalcAll('edit');
    validateForm('edit');
}

function numberOrNull(val) {
    const n = parseFloat(val);
    return isNaN(n) ? null : n;
}

function updatePurchaseModeUI(prefix) {
    const individualRadio = document.getElementById(prefix + '_purchase_mode_individual');
    const bulkRadio = document.getElementById(prefix + '_purchase_mode_bulk');
    const individualSection = document.getElementById(prefix + '_individual_purchase_section');
    const bulkSection = document.getElementById(prefix + '_bulk_purchase_section');
    const stockInd = document.getElementById(prefix + '_initial_stock_individual_section');
    const stockBulk = document.getElementById(prefix + '_initial_stock_bulk_section');

    const individualPriceInput = document.getElementById(prefix + '_individual_purchase_price');
    const bulkDerivedPriceInput = document.getElementById(prefix + '_individual_purchase_price_bulk');

    const addInitialStockIndQty = document.getElementById('add_initial_stock_quantity_individual');
    const addInitialStockBulkQty = document.getElementById('add_initial_stock_quantity_bulk');
    const addInitialStockHiddenMode = document.querySelector('#addProductForm input[name="initial_stock_entry_mode"][type="hidden"]');

    if (!individualRadio || !bulkRadio) return;

    if (bulkRadio.checked) {
        if (individualSection) individualSection.style.display = 'none';
        if (bulkSection) bulkSection.style.display = '';
        if (stockInd) stockInd.style.display = 'none';
        if (stockBulk) stockBulk.style.display = '';

        if (individualPriceInput) {
            individualPriceInput.disabled = true;
            individualPriceInput.required = false;
        }
        if (bulkDerivedPriceInput) {
            bulkDerivedPriceInput.disabled = false;
            bulkDerivedPriceInput.readOnly = true;
        }

        if (prefix === 'add') {
            if (addInitialStockIndQty) addInitialStockIndQty.disabled = true;
            if (addInitialStockBulkQty) addInitialStockBulkQty.disabled = false;
            if (addInitialStockHiddenMode) addInitialStockHiddenMode.disabled = true;
        }
    } else if (individualRadio.checked) {
        if (individualSection) individualSection.style.display = '';
        if (bulkSection) bulkSection.style.display = 'none';
        if (stockInd) stockInd.style.display = '';
        if (stockBulk) stockBulk.style.display = 'none';

        if (individualPriceInput) {
            individualPriceInput.disabled = false;
            individualPriceInput.required = true;
        }
        if (bulkDerivedPriceInput) {
            bulkDerivedPriceInput.disabled = true;
        }

        if (prefix === 'add') {
            if (addInitialStockIndQty) addInitialStockIndQty.disabled = false;
            if (addInitialStockBulkQty) addInitialStockBulkQty.disabled = true;
            if (addInitialStockHiddenMode) addInitialStockHiddenMode.disabled = false;
        }
    }
}

function recalcAll(prefix) {
    const bulkLabel = document.getElementById(prefix + '_bulk_unit_label');
    const bulkSuffix = document.getElementById(prefix + '_bulk_unit_suffix');
    const stockBulkLabel = document.getElementById(prefix + '_stock_bulk_label');
    const unitsPerBulk = document.getElementById(prefix + '_units_per_bulk');
    const bulkPrice = document.getElementById(prefix + '_bulk_purchase_price');
    const indPriceCalcBox = document.getElementById(prefix + '_individual_price_calc');
    const indPriceCalcVal = document.getElementById(prefix + '_individual_price_calc_value');
    const indPriceHidden = document.getElementById(prefix + '_individual_purchase_price_bulk');
    const indPriceInput = document.getElementById(prefix + '_individual_purchase_price');
    const selling = document.getElementById(prefix + '_selling_price');
    const profitBox = document.getElementById(prefix + '_profit_box');
    const profitVal = document.getElementById(prefix + '_profit_value');

    if (bulkSuffix && bulkLabel) {
        const label = (bulkLabel.value || '').trim();
        bulkSuffix.textContent = label !== '' ? label : 'bulk unit';
        if (stockBulkLabel) stockBulkLabel.textContent = label !== '' ? label.toLowerCase() : 'bulk';
    }

    const units = unitsPerBulk ? parseInt(unitsPerBulk.value || '0', 10) : 0;
    const bulkCost = bulkPrice ? numberOrNull(bulkPrice.value) : null;
    let individualCost = null;

    if (units > 0 && bulkCost !== null && bulkCost > 0) {
        individualCost = bulkCost / units;
        if (indPriceHidden) indPriceHidden.value = individualCost.toFixed(2);
        if (indPriceCalcVal) indPriceCalcVal.textContent = Math.round(individualCost).toString();
        if (indPriceCalcBox) indPriceCalcBox.style.display = '';
    } else {
        if (indPriceHidden) indPriceHidden.value = '';
        if (indPriceCalcBox) indPriceCalcBox.style.display = 'none';
    }

    const purchaseModeBulk = document.getElementById(prefix + '_purchase_mode_bulk');
    if (purchaseModeBulk && purchaseModeBulk.checked) {
        // In bulk mode, profit uses calculated individual cost
    } else {
        individualCost = indPriceInput ? numberOrNull(indPriceInput.value) : null;
    }

    const sellingPrice = selling ? numberOrNull(selling.value) : null;
    if (profitBox && profitVal && sellingPrice !== null && individualCost !== null) {
        const profit = sellingPrice - individualCost;
        profitVal.textContent = Math.round(profit).toString();
        profitBox.style.display = '';
        profitBox.classList.remove('alert-success', 'alert-danger', 'alert-secondary');
        if (profit > 0) profitBox.classList.add('alert-success');
        else if (profit < 0) profitBox.classList.add('alert-danger');
        else profitBox.classList.add('alert-secondary');
    } else if (profitBox) {
        profitBox.style.display = 'none';
    }

    // Stock conversion box for Add (bulk mode)
    if (prefix === 'add') {
        const stockEntryBulk = document.getElementById('add_stock_entry_bulk');
        const stockEntryInd = document.getElementById('add_stock_entry_individual');
        const stockQty = document.getElementById('add_initial_stock_quantity_bulk');
        const convBox = document.getElementById('add_stock_conversion_box');
        const convVal = document.getElementById('add_stock_conversion_value');

        if (stockQty) {
            stockQty.disabled = false;
        }

        if (stockEntryBulk && stockQty && convBox && convVal && stockEntryBulk.checked) {
            const qty = numberOrNull(stockQty.value) || 0;
            const total = units > 0 ? qty * units : 0;
            convVal.textContent = Math.round(total).toString();
            convBox.style.display = '';
        } else if (convBox) {
            convBox.style.display = 'none';
        }

        if (stockEntryBulk && stockEntryInd) {
            const labelEl = document.getElementById('add_initial_stock_label');
            if (labelEl) {
                if (stockEntryBulk.checked) {
                    labelEl.textContent = 'Initial Quantity (in ' + ((bulkLabel && bulkLabel.value) ? bulkLabel.value : 'bulk units') + ')';
                } else {
                    labelEl.textContent = 'Initial Quantity (in items)';
                }
            }
        }
    }
}

function validateForm(prefix) {
    const saveBtn = document.getElementById(prefix + '_save_btn');
    if (!saveBtn) return;

    const name = document.querySelector('#' + prefix + 'ProductForm [name="name"]');
    const category = document.querySelector('#' + prefix + 'ProductForm [name="category"]');
    const modeInd = document.getElementById(prefix + '_purchase_mode_individual');
    const modeBulk = document.getElementById(prefix + '_purchase_mode_bulk');

    let ok = true;
    if (!name || name.value.trim() === '') ok = false;
    if (!category || category.value.trim() === '') ok = false;
    if (!modeInd || !modeBulk || (!modeInd.checked && !modeBulk.checked)) ok = false;

    const selling = document.getElementById(prefix + '_selling_price');
    const sellingPrice = selling ? numberOrNull(selling.value) : null;
    if (sellingPrice === null || sellingPrice <= 0) ok = false;

    if (modeInd && modeInd.checked) {
        const indPrice = document.getElementById(prefix + '_individual_purchase_price');
        const v = indPrice ? numberOrNull(indPrice.value) : null;
        if (v === null || v <= 0) ok = false;

        if (prefix === 'add') {
            const stock = document.getElementById('add_initial_stock_quantity_individual');
            const sv = stock ? numberOrNull(stock.value) : null;
            if (sv === null || sv < 0) ok = false;
        }
    }

    if (modeBulk && modeBulk.checked) {
        const bulkLabel = document.getElementById(prefix + '_bulk_unit_label');
        const units = document.getElementById(prefix + '_units_per_bulk');
        const bulkPrice = document.getElementById(prefix + '_bulk_purchase_price');
        const ul = bulkLabel ? bulkLabel.value.trim() : '';
        const u = units ? parseInt(units.value || '0', 10) : 0;
        const bp = bulkPrice ? numberOrNull(bulkPrice.value) : null;
        if (ul === '') ok = false;
        if (!u || u <= 0) ok = false;
        if (bp === null || bp <= 0) ok = false;

        if (prefix === 'add') {
            const stockEntryBulk = document.getElementById('add_stock_entry_bulk');
            const stockQty = document.getElementById('add_initial_stock_quantity_bulk');
            const sv = stockQty ? numberOrNull(stockQty.value) : null;
            if (sv === null || sv < 0) ok = false;
            if (stockEntryBulk && stockEntryBulk.checked && u <= 0) ok = false;
        }
    }

    if (prefix === 'edit') {
        const stockInput = document.getElementById('edit_new_current_stock');
        if (stockInput && stockInput.value !== '') {
            const sv = numberOrNull(stockInput.value);
            if (sv === null || sv < 0) ok = false;
        }
    }

    saveBtn.disabled = !ok;
}

document.addEventListener('DOMContentLoaded', function() {
    const bind = (prefix) => {
        const modeInd = document.getElementById(prefix + '_purchase_mode_individual');
        const modeBulk = document.getElementById(prefix + '_purchase_mode_bulk');
        const inputs = document.querySelectorAll('#' + prefix + 'ProductForm input, #' + prefix + 'ProductForm select');

        if (modeInd) {
            modeInd.addEventListener('change', function() {
                updatePurchaseModeUI(prefix);
                recalcAll(prefix);
                validateForm(prefix);
            });
        }
        if (modeBulk) {
            modeBulk.addEventListener('change', function() {
                updatePurchaseModeUI(prefix);
                recalcAll(prefix);
                validateForm(prefix);
            });
        }

        inputs.forEach((el) => {
            el.addEventListener('input', function() {
                recalcAll(prefix);
                validateForm(prefix);
            });
            el.addEventListener('change', function() {
                recalcAll(prefix);
                validateForm(prefix);
            });
        });

        if (prefix === 'add') {
            const bulkLabel = document.getElementById('add_bulk_unit_label');
            if (bulkLabel) {
                bulkLabel.addEventListener('input', function() {
                    recalcAll('add');
                });
            }
        }
    };

    bind('add');
    bind('edit');

    updatePurchaseModeUI('add');
    recalcAll('add');
    validateForm('add');
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
