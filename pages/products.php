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
        $purchasePrice = floatval($_POST['purchase_price'] ?? 0);
        $sellingPrice = floatval($_POST['selling_price'] ?? 0);
        $initialStock = intval($_POST['initial_stock'] ?? 0);
        $reorderLevel = !empty($_POST['reorder_level']) ? intval($_POST['reorder_level']) : null;
        $bulkUnitType = !empty($_POST['bulk_unit_type']) ? sanitize($_POST['bulk_unit_type']) : null;
        $unitsPerBulk = !empty($_POST['units_per_bulk']) ? intval($_POST['units_per_bulk']) : null;
        $bulkUnitLabel = !empty($_POST['bulk_unit_label']) ? sanitize($_POST['bulk_unit_label']) : null;
        
        $errors = [];
        if (empty($name)) $errors[] = 'Product name is required';
        if (empty($category) || !in_array($category, CATEGORIES)) $errors[] = 'Valid category is required';
        if ($purchasePrice < 0) $errors[] = 'Purchase price must be positive';
        if ($sellingPrice < 0) $errors[] = 'Selling price must be positive';
        if ($bulkUnitType && $unitsPerBulk <= 0) $errors[] = 'Units per bulk must be greater than 0';
        
        if (empty($errors)) {
            // Check if bulk columns exist
            $checkColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'bulk_unit_type'");
            $hasBulkColumns = $checkColumn->num_rows > 0;
            
            if ($hasBulkColumns) {
                $stmt = $conn->prepare("INSERT INTO products (name, category, purchase_price, selling_price, current_stock, reorder_level, bulk_unit_type, units_per_bulk, bulk_unit_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                // Type string: s=string, d=double, i=integer
                // Parameters: name(s), category(s), purchase_price(d), selling_price(d), current_stock(i), reorder_level(i/null), bulk_unit_type(s/null), units_per_bulk(i/null), bulk_unit_label(s/null)
                // Type: ssddiisss (9 characters for 9 parameters)
                $stmt->bind_param("ssddiisss", $name, $category, $purchasePrice, $sellingPrice, $initialStock, $reorderLevel, $bulkUnitType, $unitsPerBulk, $bulkUnitLabel);
            } else {
                $reorderLevelValue = $reorderLevel ?? null;
                $stmt = $conn->prepare("INSERT INTO products (name, category, purchase_price, selling_price, current_stock, reorder_level) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssddii", $name, $category, $purchasePrice, $sellingPrice, $initialStock, $reorderLevelValue);
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
        $purchasePrice = floatval($_POST['purchase_price'] ?? 0);
        $sellingPrice = floatval($_POST['selling_price'] ?? 0);
        $reorderLevel = !empty($_POST['reorder_level']) ? intval($_POST['reorder_level']) : null;
        $bulkUnitType = !empty($_POST['bulk_unit_type']) ? sanitize($_POST['bulk_unit_type']) : null;
        $unitsPerBulk = !empty($_POST['units_per_bulk']) ? intval($_POST['units_per_bulk']) : null;
        $bulkUnitLabel = !empty($_POST['bulk_unit_label']) ? sanitize($_POST['bulk_unit_label']) : null;
        
        $errors = [];
        if (empty($name)) $errors[] = 'Product name is required';
        if (empty($category) || !in_array($category, CATEGORIES)) $errors[] = 'Valid category is required';
        if ($bulkUnitType && $unitsPerBulk <= 0) $errors[] = 'Units per bulk must be greater than 0';
        
        if (empty($errors)) {
            // Check if bulk columns exist
            $checkColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'bulk_unit_type'");
            $hasBulkColumns = $checkColumn->num_rows > 0;
            
            if ($hasBulkColumns) {
                $stmt = $conn->prepare("UPDATE products SET name = ?, category = ?, purchase_price = ?, selling_price = ?, reorder_level = ?, bulk_unit_type = ?, units_per_bulk = ?, bulk_unit_label = ? WHERE id = ?");
                // Parameters: name(s), category(s), purchase_price(d), selling_price(d), reorder_level(i/null), bulk_unit_type(s/null), units_per_bulk(i/null), bulk_unit_label(s/null), id(i)
                // Type string: ssddiissi (9 characters for 9 parameters)
                $stmt->bind_param("ssddiissi", $name, $category, $purchasePrice, $sellingPrice, $reorderLevel, $bulkUnitType, $unitsPerBulk, $bulkUnitLabel, $id);
            } else {
                $stmt = $conn->prepare("UPDATE products SET name = ?, category = ?, purchase_price = ?, selling_price = ?, reorder_level = ? WHERE id = ?");
                $stmt->bind_param("ssddii", $name, $category, $purchasePrice, $sellingPrice, $reorderLevel, $id);
            }
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = 'Product updated successfully';
                header('Location: /Inventory_sys/pages/products.php');
                exit();
            } else {
                $_SESSION['error_message'] = 'Failed to update product';
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
                        <th>Purchase Price</th>
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
                                <td><?php echo formatCurrency($product['purchase_price']); ?></td>
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
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required-field">Product Name</label>
                        <input type="text" class="form-control" name="name" required>
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
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Purchase Price</label>
                            <input type="number" step="0.01" class="form-control" name="purchase_price" required min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Selling Price</label>
                            <input type="number" step="0.01" class="form-control" name="selling_price" required min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Initial Stock Quantity</label>
                        <input type="number" class="form-control" name="initial_stock" value="0" min="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reorder Level (Optional)</label>
                        <input type="number" class="form-control" name="reorder_level" min="0">
                        <small class="form-text text-muted">Alert when stock falls to this level</small>
                    </div>
                    <hr>
                    <h6 class="mb-3">Bulk Unit Configuration (Optional)</h6>
                    <div class="mb-3">
                        <label class="form-label">Bulk Unit Type</label>
                        <select class="form-select" name="bulk_unit_type" id="add_bulk_unit_type">
                            <option value="">None</option>
                            <option value="carton">Carton</option>
                            <option value="crate">Crate</option>
                        </select>
                        <small class="form-text text-muted">Select if product is purchased in bulk</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Units Per Bulk</label>
                        <input type="number" class="form-control" name="units_per_bulk" id="add_units_per_bulk" min="1">
                        <small class="form-text text-muted">e.g., 24 units per carton</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Bulk Unit Label</label>
                        <input type="text" class="form-control" name="bulk_unit_label" id="add_bulk_unit_label" placeholder="e.g., Carton, Crate">
                        <small class="form-text text-muted">Display name for bulk unit</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_product_id">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
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
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Purchase Price</label>
                            <input type="number" step="0.01" class="form-control" name="purchase_price" id="edit_product_purchase_price" required min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Selling Price</label>
                            <input type="number" step="0.01" class="form-control" name="selling_price" id="edit_product_selling_price" required min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reorder Level (Optional)</label>
                        <input type="number" class="form-control" name="reorder_level" id="edit_product_reorder_level" min="0">
                    </div>
                    <hr>
                    <h6 class="mb-3">Bulk Unit Configuration (Optional)</h6>
                    <div class="mb-3">
                        <label class="form-label">Bulk Unit Type</label>
                        <select class="form-select" name="bulk_unit_type" id="edit_bulk_unit_type">
                            <option value="">None</option>
                            <option value="carton">Carton</option>
                            <option value="crate">Crate</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Units Per Bulk</label>
                        <input type="number" class="form-control" name="units_per_bulk" id="edit_units_per_bulk" min="1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Bulk Unit Label</label>
                        <input type="text" class="form-control" name="bulk_unit_label" id="edit_bulk_unit_label" placeholder="e.g., Carton, Crate">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Product</button>
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
    document.getElementById('edit_product_purchase_price').value = product.purchase_price;
    document.getElementById('edit_product_selling_price').value = product.selling_price;
    document.getElementById('edit_product_reorder_level').value = product.reorder_level || '';
    document.getElementById('edit_bulk_unit_type').value = product.bulk_unit_type || '';
    document.getElementById('edit_units_per_bulk').value = product.units_per_bulk || '';
    document.getElementById('edit_bulk_unit_label').value = product.bulk_unit_label || '';
    
    var editModal = new bootstrap.Modal(document.getElementById('editProductModal'));
    editModal.show();
}

// Auto-suggest bulk unit defaults based on category
document.addEventListener('DOMContentLoaded', function() {
    const addCategory = document.getElementById('addProductModal').querySelector('[name="category"]');
    const addBulkType = document.getElementById('add_bulk_unit_type');
    const addUnitsPerBulk = document.getElementById('add_units_per_bulk');
    const addBulkLabel = document.getElementById('add_bulk_unit_label');
    
    if (addCategory) {
        addCategory.addEventListener('change', function() {
            const category = this.value;
            if (category === 'Provisions' || category === 'Wine') {
                addBulkType.value = 'carton';
                addUnitsPerBulk.value = category === 'Provisions' ? '24' : '12';
                addBulkLabel.value = 'Carton';
            } else if (category === 'Beer') {
                addBulkType.value = 'crate';
                addUnitsPerBulk.value = '24';
                addBulkLabel.value = 'Crate';
            } else {
                addBulkType.value = '';
                addUnitsPerBulk.value = '';
                addBulkLabel.value = '';
            }
        });
    }
    
    const editCategory = document.getElementById('edit_product_category');
    const editBulkType = document.getElementById('edit_bulk_unit_type');
    const editUnitsPerBulk = document.getElementById('edit_units_per_bulk');
    const editBulkLabel = document.getElementById('edit_bulk_unit_label');
    
    if (editCategory) {
        editCategory.addEventListener('change', function() {
            const category = this.value;
            if (!editBulkType.value) { // Only auto-fill if not already set
                if (category === 'Provisions' || category === 'Wine') {
                    editBulkType.value = 'carton';
                    if (!editUnitsPerBulk.value) {
                        editUnitsPerBulk.value = category === 'Provisions' ? '24' : '12';
                    }
                    if (!editBulkLabel.value) {
                        editBulkLabel.value = 'Carton';
                    }
                } else if (category === 'Beer') {
                    editBulkType.value = 'crate';
                    if (!editUnitsPerBulk.value) {
                        editUnitsPerBulk.value = '24';
                    }
                    if (!editBulkLabel.value) {
                        editBulkLabel.value = 'Crate';
                    }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
