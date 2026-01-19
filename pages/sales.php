<?php
$pageTitle = 'Record Sales - C&C Building Shop';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$conn = getDBConnection();
$products = getAllProducts();
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h2><i class="bi bi-cart-check"></i> Record Sales</h2>
        <p class="text-muted">Record sales using manual entry, batch entry, or Excel upload</p>
    </div>
    <div class="col-md-4 text-end">
        <a href="/Inventory_sys/api/downloads.php?type=sales&format=xls" class="btn btn-outline-primary">
            <i class="bi bi-download"></i> Download Sales
        </a>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="salesTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="manual-tab" data-bs-toggle="tab" data-bs-target="#manual" type="button">
            <i class="bi bi-pencil"></i> Manual Entry
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="batch-tab" data-bs-toggle="tab" data-bs-target="#batch" type="button">
            <i class="bi bi-list-check"></i> Batch Entry
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="excel-tab" data-bs-toggle="tab" data-bs-target="#excel" type="button">
            <i class="bi bi-file-earmark-excel"></i> Excel Upload
        </button>
    </li>
</ul>

<div class="tab-content" id="salesTabContent">
    <!-- Manual Entry Tab -->
    <div class="tab-pane fade show active" id="manual" role="tabpanel">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="/Inventory_sys/api/sales.php" id="manualSalesForm">
                    <input type="hidden" name="method" value="manual">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Product</label>
                            <select class="form-select" name="product_id" id="manual_product" required>
                                <option value="">Select a product</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>" 
                                            data-price="<?php echo $product['selling_price']; ?>"
                                            data-stock="<?php echo $product['current_stock']; ?>"
                                            data-units-per-bulk="<?php echo $product['units_per_bulk'] ?? ''; ?>"
                                            data-bulk-label="<?php echo htmlspecialchars($product['bulk_unit_label'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($product['name']); ?> 
                                        (<?php echo htmlspecialchars($product['category']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Current Stock</label>
                            <input type="text" class="form-control" id="manual_stock_display" readonly value="Select a product">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Sale Mode</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="sale_mode" id="manual_mode_individual" value="individual" checked>
                                <label class="btn btn-outline-primary" for="manual_mode_individual">Individual</label>

                                <input type="radio" class="btn-check" name="sale_mode" id="manual_mode_bulk" value="bulk">
                                <label class="btn btn-outline-primary" for="manual_mode_bulk">Bulk</label>
                            </div>
                            <small class="form-text text-muted" id="manual_bulk_hint" style="display:none;">Bulk quantity will be converted to units using Units Per Bulk.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label required-field">Unit Price</label>
                            <input type="number" step="0.01" class="form-control" name="unit_price" id="manual_unit_price" required min="0">
                            <small class="form-text text-muted">Default selling price (editable)</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3" id="manual_qty_units_group">
                            <label class="form-label required-field">Quantity Sold (Units)</label>
                            <input type="number" class="form-control" name="quantity" id="manual_quantity" required min="1">
                        </div>
                        <div class="col-md-6 mb-3" id="manual_qty_bulk_group" style="display:none;">
                            <label class="form-label required-field">Quantity Sold (Bulk)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" class="form-control" name="bulk_quantity" id="manual_bulk_quantity" min="0.01">
                                <span class="input-group-text" id="manual_bulk_label">Bulk</span>
                            </div>
                            <small class="form-text text-muted" id="manual_bulk_conversion"></small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Sale Amount</label>
                            <input type="text" class="form-control" id="manual_amount_display" readonly value="0 XAF">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Sale Date</label>
                            <input type="date" class="form-control" name="sale_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Record Sale
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Batch Entry Tab -->
    <div class="tab-pane fade" id="batch" role="tabpanel">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="/Inventory_sys/api/sales.php" id="batchSalesForm">
                    <input type="hidden" name="method" value="batch">
                    <div id="batch_rows">
                        <div class="sales-row">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required-field">Product</label>
                                    <select class="form-select batch-product" name="products[]" required>
                                        <option value="">Select product</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>" 
                                                    data-price="<?php echo $product['selling_price']; ?>"
                                                    data-units-per-bulk="<?php echo $product['units_per_bulk'] ?? ''; ?>"
                                                    data-bulk-label="<?php echo htmlspecialchars($product['bulk_unit_label'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label required-field">Mode</label>
                                    <select class="form-select batch-mode" name="modes[]" required>
                                        <option value="individual" selected>Individual</option>
                                        <option value="bulk">Bulk</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label required-field">Quantity (Units)</label>
                                    <input type="number" class="form-control batch-quantity" name="quantities[]" required min="1">
                                    <input type="hidden" class="batch-bulk-quantity" name="bulk_quantities[]" value="">
                                    <small class="form-text text-muted batch-bulk-info" style="display:none;"></small>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label required-field">Unit Price</label>
                                    <input type="number" step="0.01" class="form-control batch-price" name="prices[]" required min="0">
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">Amount</label>
                                    <input type="text" class="form-control batch-amount" readonly>
                                </div>
                                <div class="col-md-1 mb-3 d-flex align-items-end">
                                    <button type="button" class="btn btn-danger btn-sm remove-row" style="display:none;">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-outline-primary" id="add_batch_row">
                            <i class="bi bi-plus-circle"></i> Add Row
                        </button>
                    </div>
                    <div class="mb-3">
                        <div class="alert alert-info">
                            <strong>Total Amount:</strong> <span id="batch_total">0</span> XAF
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sale Date</label>
                        <input type="date" class="form-control" name="sale_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Record All Sales
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Excel Upload Tab -->
    <div class="tab-pane fade" id="excel" role="tabpanel">
        <div class="card">
            <div class="card-body">
                <div class="alert alert-info">
                    <h5><i class="bi bi-info-circle"></i> Instructions</h5>
                    <ol>
                        <li>Download the template file</li>
                        <li>Fill in Product Name, Quantity, and Date columns</li>
                        <li>Upload the completed file</li>
                        <li>Review the preview and confirm</li>
                    </ol>
                </div>
                
                <div class="mb-3">
                    <a href="/Inventory_sys/api/sales.php?action=download_template" class="btn btn-outline-primary">
                        <i class="bi bi-download"></i> Download Template
                    </a>
                </div>
                
                <form method="POST" action="/Inventory_sys/api/sales.php" enctype="multipart/form-data" id="excelUploadForm">
                    <input type="hidden" name="method" value="excel">
                    <div class="mb-3">
                        <label class="form-label required-field">Upload Excel/CSV File</label>
                        <input type="file" class="form-control" name="excel_file" accept=".csv,.xlsx,.xls" required>
                        <small class="form-text text-muted">Accepted formats: CSV, XLSX, XLS (Max 5MB)</small>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload"></i> Upload and Preview
                        </button>
                    </div>
                </form>
                
                <div id="excel_preview" class="mt-4" style="display:none;"></div>
            </div>
        </div>
    </div>
</div>

<script src="/Inventory_sys/public/js/sales.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
