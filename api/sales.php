<?php
// Sales API endpoint - Prevent any output before JSON
// Start output buffering to catch any unexpected output
ob_start();

// Disable error display, log errors instead
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set JSON header early
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/constants.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/functions.php';
    
    // Clear any output that might have been generated
    ob_clean();
    
    // Check authentication
    if (!isLoggedIn()) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        ob_end_flush();
        exit();
    }
    
    // Helper function to send JSON response
    function sendJsonResponse($data) {
        ob_clean();
        echo json_encode($data);
        ob_end_flush();
        exit();
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
    ob_end_flush();
    exit();
}

$conn = getDBConnection();
$method = $_POST['method'] ?? $_GET['action'] ?? '';

// Download template
if ($method === 'download_template' || $_GET['action'] === 'download_template') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_template.csv"');
    
    echo "Product Name,Sale Mode,Quantity (Units),Bulk Quantity,Unit Price,Date\n";
    echo "Example Product,individual,5,,15000," . date('Y-m-d') . "\n";
    exit();
}

// Handle sales recording
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($method === 'manual') {
        $productId = intval($_POST['product_id'] ?? 0);
        $saleMode = strtolower(trim($_POST['sale_mode'] ?? 'individual'));
        $quantity = intval($_POST['quantity'] ?? 0);
        $bulkQuantity = isset($_POST['bulk_quantity']) && $_POST['bulk_quantity'] !== '' ? floatval($_POST['bulk_quantity']) : null;
        $unitPrice = floatval($_POST['unit_price'] ?? 0);
        $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
        
        if (!in_array($saleMode, ['individual', 'bulk'])) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid sale mode']);
        }

        if ($productId <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid product or quantity']);
        }
        
        if ($unitPrice <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Unit price must be greater than 0']);
        }
        
        $product = getProduct($productId);
        if (!$product) {
            sendJsonResponse(['success' => false, 'message' => 'Product not found']);
        }

        if ($saleMode === 'bulk') {
            if ($bulkQuantity === null || $bulkQuantity <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'Bulk quantity must be greater than 0']);
            }
            $unitsPerBulk = intval($product['units_per_bulk'] ?? 0);
            if ($unitsPerBulk <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'This product has no Units Per Bulk set']);
            }
            $quantity = intval(round($bulkQuantity * $unitsPerBulk));
        } else {
            if ($quantity <= 0) {
                sendJsonResponse(['success' => false, 'message' => 'Quantity must be greater than 0']);
            }
        }
        
        if ($product['current_stock'] < $quantity) {
            sendJsonResponse(['success' => false, 'message' => 'Insufficient stock. Available: ' . $product['current_stock']]);
        }
        
        $conn->begin_transaction();
        
        try {
            $totalAmount = $unitPrice * $quantity;
            $userId = getCurrentUserId();
            
            $checkSaleModeCol = $conn->query("SHOW COLUMNS FROM sales LIKE 'sale_mode'");
            $hasSaleMode = $checkSaleModeCol && $checkSaleModeCol->num_rows > 0;
            $checkBulkQtyCol = $conn->query("SHOW COLUMNS FROM sales LIKE 'bulk_quantity'");
            $hasSalesBulkQty = $checkBulkQtyCol && $checkBulkQtyCol->num_rows > 0;

            // Record sale
            if ($hasSaleMode && $hasSalesBulkQty) {
                $stmt = $conn->prepare("INSERT INTO sales (product_id, quantity, unit_price, total_amount, sale_date, sale_mode, bulk_quantity, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiddssdi", $productId, $quantity, $unitPrice, $totalAmount, $saleDate, $saleMode, $bulkQuantity, $userId);
            } else {
                $stmt = $conn->prepare("INSERT INTO sales (product_id, quantity, unit_price, total_amount, sale_date, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiddsi", $productId, $quantity, $unitPrice, $totalAmount, $saleDate, $userId);
            }
            $stmt->execute();
            
            // Update stock
            $newStock = $product['current_stock'] - $quantity;
            $stmt = $conn->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
            $stmt->bind_param("ii", $newStock, $productId);
            $stmt->execute();
            
            // Record stock movement
            $movementType = 'out';
            $checkBulkColumn = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'bulk_quantity'");
            $hasMovementBulk = $checkBulkColumn && $checkBulkColumn->num_rows > 0;
            if ($hasMovementBulk) {
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, bulk_quantity, user_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("isidi", $productId, $movementType, $quantity, $bulkQuantity, $userId);
            } else {
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, user_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isii", $productId, $movementType, $quantity, $userId);
            }
            $stmt->execute();
            
            $conn->commit();
            sendJsonResponse(['success' => true, 'message' => 'Sale recorded successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            sendJsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    if ($method === 'batch') {
        $products = $_POST['products'] ?? [];
        $quantities = $_POST['quantities'] ?? [];
        $modes = $_POST['modes'] ?? [];
        $bulkQuantities = $_POST['bulk_quantities'] ?? [];
        $prices = $_POST['prices'] ?? [];
        $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
        
        if (empty($products) || count($products) !== count($quantities)) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid batch data']);
        }
        
        $conn->begin_transaction();
        
        try {
            $userId = getCurrentUserId();
            
            for ($i = 0; $i < count($products); $i++) {
                $productId = intval($products[$i]);
                $saleMode = strtolower(trim($modes[$i] ?? 'individual'));
                $quantity = intval($quantities[$i] ?? 0);
                $bulkQuantity = isset($bulkQuantities[$i]) && $bulkQuantities[$i] !== '' ? floatval($bulkQuantities[$i]) : null;
                $unitPrice = floatval($prices[$i] ?? 0);
                
                if ($productId <= 0 || $unitPrice <= 0) continue;
                if (!in_array($saleMode, ['individual', 'bulk'])) continue;
                
                $product = getProduct($productId);
                if (!$product) continue;

                if ($saleMode === 'bulk') {
                    $unitsPerBulk = intval($product['units_per_bulk'] ?? 0);
                    if ($unitsPerBulk <= 0) {
                        throw new Exception("Bulk mode not configured for {$product['name']}");
                    }
                    if ($bulkQuantity === null || $bulkQuantity <= 0) {
                        throw new Exception("Invalid bulk quantity for {$product['name']}");
                    }
                    $quantity = intval(round($bulkQuantity * $unitsPerBulk));
                } else {
                    if ($quantity <= 0) continue;
                }
                
                if ($product['current_stock'] < $quantity) {
                    throw new Exception("Insufficient stock for {$product['name']}. Available: {$product['current_stock']}");
                }
                
                $totalAmount = $unitPrice * $quantity;
                
                // Record sale
                $checkSaleModeCol = $conn->query("SHOW COLUMNS FROM sales LIKE 'sale_mode'");
                $hasSaleMode = $checkSaleModeCol && $checkSaleModeCol->num_rows > 0;
                $checkBulkQtyCol = $conn->query("SHOW COLUMNS FROM sales LIKE 'bulk_quantity'");
                $hasSalesBulkQty = $checkBulkQtyCol && $checkBulkQtyCol->num_rows > 0;
                if ($hasSaleMode && $hasSalesBulkQty) {
                    $stmt = $conn->prepare("INSERT INTO sales (product_id, quantity, unit_price, total_amount, sale_date, sale_mode, bulk_quantity, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiddssdi", $productId, $quantity, $unitPrice, $totalAmount, $saleDate, $saleMode, $bulkQuantity, $userId);
                } else {
                    $stmt = $conn->prepare("INSERT INTO sales (product_id, quantity, unit_price, total_amount, sale_date, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiddsi", $productId, $quantity, $unitPrice, $totalAmount, $saleDate, $userId);
                }
                $stmt->execute();
                
                // Update stock
                $newStock = $product['current_stock'] - $quantity;
                $stmt = $conn->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
                $stmt->bind_param("ii", $newStock, $productId);
                $stmt->execute();
                
                // Record stock movement
                $movementType = 'out';
                $checkBulkColumn = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'bulk_quantity'");
                $hasMovementBulk = $checkBulkColumn && $checkBulkColumn->num_rows > 0;
                if ($hasMovementBulk) {
                    $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, bulk_quantity, user_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("isidi", $productId, $movementType, $quantity, $bulkQuantity, $userId);
                } else {
                    $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, user_id) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("isii", $productId, $movementType, $quantity, $userId);
                }
                $stmt->execute();
            }
            
            $conn->commit();
            sendJsonResponse(['success' => true, 'message' => 'Batch sales recorded successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            sendJsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    if ($method === 'excel') {
        // Handle Excel upload
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            sendJsonResponse(['success' => false, 'message' => 'File upload error']);
        }
        
        $file = $_FILES['excel_file'];
        $fileName = $file['name'];
        $fileTmp = $file['tmp_name'];
        $fileSize = $file['size'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if (!in_array($fileExt, ALLOWED_EXTENSIONS)) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid file type. Use CSV, XLSX, or XLS']);
        }
        
        if ($fileSize > MAX_UPLOAD_SIZE) {
            sendJsonResponse(['success' => false, 'message' => 'File too large. Max 5MB']);
        }
        
        // Create uploads directory if it doesn't exist
        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0777, true);
        }
        
        $newFileName = uniqid() . '_' . $fileName;
        $filePath = UPLOAD_DIR . $newFileName;
        
        if (!move_uploaded_file($fileTmp, $filePath)) {
            sendJsonResponse(['success' => false, 'message' => 'Failed to save file']);
        }
        
        // Parse CSV file
        $salesData = [];
        $errors = [];
        
        if ($fileExt === 'csv') {
            $handle = fopen($filePath, 'r');
            $header = fgetcsv($handle);
            $headerMap = [];
            if ($header) {
                foreach ($header as $idx => $col) {
                    $key = strtolower(trim($col));
                    $headerMap[$key] = $idx;
                }
            }
            
            $lineNum = 2;
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 2) {
                    $errors[] = "Line $lineNum: Insufficient columns";
                    continue;
                }

                $productName = trim($row[$headerMap['product name'] ?? 0] ?? '');
                $saleModeRaw = trim($row[$headerMap['sale mode'] ?? 1] ?? '');
                $saleMode = $saleModeRaw !== '' ? strtolower($saleModeRaw) : 'individual';

                $quantityColIdx = $headerMap['quantity (units)'] ?? $headerMap['quantity'] ?? 1;
                $quantity = intval($row[$quantityColIdx] ?? 0);

                $bulkQtyIdx = $headerMap['bulk quantity'] ?? null;
                $bulkQuantity = ($bulkQtyIdx !== null && ($row[$bulkQtyIdx] ?? '') !== '') ? floatval($row[$bulkQtyIdx]) : null;

                $unitPriceIdx = $headerMap['unit price'] ?? 2;
                $unitPrice = !empty($row[$unitPriceIdx]) ? floatval($row[$unitPriceIdx]) : null;

                $dateIdx = $headerMap['date'] ?? 3;
                $date = !empty($row[$dateIdx]) ? trim($row[$dateIdx]) : date('Y-m-d');
                
                if (empty($productName)) {
                    $errors[] = "Line $lineNum: Product name is required";
                    continue;
                }
                
                if ($quantity <= 0) {
                    if ($saleMode !== 'bulk') {
                        $errors[] = "Line $lineNum: Invalid quantity";
                        continue;
                    }
                }
                
                // Find product by name
                $stmt = $conn->prepare("SELECT id FROM products WHERE LOWER(name) = LOWER(?)");
                $stmt->bind_param("s", $productName);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    $errors[] = "Line $lineNum: Product '$productName' not found";
                    continue;
                }
                
                $product = $result->fetch_assoc();
                $fullProduct = getProduct($product['id']);
                if (!$fullProduct) {
                    $errors[] = "Line $lineNum: Product '$productName' not found";
                    continue;
                }

                if (!in_array($saleMode, ['individual', 'bulk'])) {
                    $errors[] = "Line $lineNum: Invalid sale mode";
                    continue;
                }

                if ($saleMode === 'bulk') {
                    if ($bulkQuantity === null || $bulkQuantity <= 0) {
                        $errors[] = "Line $lineNum: Invalid bulk quantity";
                        continue;
                    }
                    $unitsPerBulk = intval($fullProduct['units_per_bulk'] ?? 0);
                    if ($unitsPerBulk <= 0) {
                        $errors[] = "Line $lineNum: Units Per Bulk not set for '$productName'";
                        continue;
                    }
                    $quantity = intval(round($bulkQuantity * $unitsPerBulk));
                }

                $finalPrice = $unitPrice !== null ? $unitPrice : ($fullProduct['selling_price'] ?? 0);
                
                $salesData[] = [
                    'product_id' => $product['id'],
                    'product_name' => $productName,
                    'quantity' => $quantity,
                    'sale_mode' => $saleMode,
                    'bulk_quantity' => $bulkQuantity,
                    'unit_price' => $finalPrice,
                    'date' => $date,
                    'line' => $lineNum
                ];
                
                $lineNum++;
            }
            fclose($handle);
        }
        
        if (!empty($errors)) {
            $errorMsg = "Errors found:\n" . implode("\n", array_slice($errors, 0, 10));
            if (count($errors) > 10) {
                $errorMsg .= "\n... and " . (count($errors) - 10) . " more errors";
            }
            sendJsonResponse(['success' => false, 'message' => $errorMsg]);
        }
        
        // Generate preview HTML
        $previewHtml = '<div class="card"><div class="card-header"><h5>Preview Sales Data</h5></div><div class="card-body">';
        $previewHtml .= '<p>Found ' . count($salesData) . ' valid sales entries</p>';
        $previewHtml .= '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Product</th><th>Mode</th><th>Quantity</th><th>Unit Price</th><th>Total</th><th>Date</th></tr></thead><tbody>';
        
        foreach ($salesData as $sale) {
            $total = ($sale['unit_price'] ?? 0) * $sale['quantity'];
            $previewHtml .= '<tr><td>' . htmlspecialchars($sale['product_name']) . '</td>';
            $previewHtml .= '<td>' . htmlspecialchars($sale['sale_mode'] ?? 'individual') . '</td>';
            $previewHtml .= '<td>' . $sale['quantity'] . '</td>';
            $previewHtml .= '<td>' . formatCurrency($sale['unit_price'] ?? 0) . '</td>';
            $previewHtml .= '<td>' . formatCurrency($total) . '</td>';
            $previewHtml .= '<td>' . htmlspecialchars($sale['date']) . '</td></tr>';
        }
        
        $previewHtml .= '</tbody></table></div></div></div>';
        
        // Store data in session for confirmation
        $_SESSION['excel_sales_data'] = $salesData;
        $_SESSION['excel_file_path'] = $filePath;
        
        sendJsonResponse([
            'success' => true,
            'preview' => true,
            'preview_html' => $previewHtml,
            'file_path' => $filePath,
            'count' => count($salesData)
        ]);
    }
    
    if ($method === 'excel_confirm') {
        $filePath = $_POST['file_path'] ?? '';
        $salesData = $_SESSION['excel_sales_data'] ?? [];
        
        if (empty($salesData)) {
            sendJsonResponse(['success' => false, 'message' => 'No sales data found']);
        }
        
        $conn->begin_transaction();
        
        try {
            $userId = getCurrentUserId();
            
            foreach ($salesData as $sale) {
                $product = getProduct($sale['product_id']);
                if (!$product) continue;
                
                if ($product['current_stock'] < $sale['quantity']) {
                    throw new Exception("Insufficient stock for {$sale['product_name']}. Available: {$product['current_stock']}");
                }
                
                $unitPrice = $sale['unit_price'] ?? $product['selling_price'];
                $totalAmount = $unitPrice * $sale['quantity'];
                
                // Record sale
                $checkSaleModeCol = $conn->query("SHOW COLUMNS FROM sales LIKE 'sale_mode'");
                $hasSaleMode = $checkSaleModeCol && $checkSaleModeCol->num_rows > 0;
                $checkBulkQtyCol = $conn->query("SHOW COLUMNS FROM sales LIKE 'bulk_quantity'");
                $hasSalesBulkQty = $checkBulkQtyCol && $checkBulkQtyCol->num_rows > 0;
                if ($hasSaleMode && $hasSalesBulkQty) {
                    $saleMode = $sale['sale_mode'] ?? 'individual';
                    $bulkQuantity = $sale['bulk_quantity'] ?? null;
                    $stmt = $conn->prepare("INSERT INTO sales (product_id, quantity, unit_price, total_amount, sale_date, sale_mode, bulk_quantity, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiddssdi", $sale['product_id'], $sale['quantity'], $unitPrice, $totalAmount, $sale['date'], $saleMode, $bulkQuantity, $userId);
                } else {
                    $stmt = $conn->prepare("INSERT INTO sales (product_id, quantity, unit_price, total_amount, sale_date, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiddsi", $sale['product_id'], $sale['quantity'], $unitPrice, $totalAmount, $sale['date'], $userId);
                }
                $stmt->execute();
                
                // Update stock
                $newStock = $product['current_stock'] - $sale['quantity'];
                $stmt = $conn->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
                $stmt->bind_param("ii", $newStock, $sale['product_id']);
                $stmt->execute();
                
                // Record stock movement
                $movementType = 'out';
                $checkBulkColumn = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'bulk_quantity'");
                $hasMovementBulk = $checkBulkColumn && $checkBulkColumn->num_rows > 0;
                $bulkQuantity = $sale['bulk_quantity'] ?? null;
                if ($hasMovementBulk) {
                    $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, bulk_quantity, user_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("isidi", $sale['product_id'], $movementType, $sale['quantity'], $bulkQuantity, $userId);
                } else {
                    $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, user_id) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("isii", $sale['product_id'], $movementType, $sale['quantity'], $userId);
                }
                $stmt->execute();
            }
            
            $conn->commit();
            
            // Clean up
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            unset($_SESSION['excel_sales_data']);
            unset($_SESSION['excel_file_path']);
            
            sendJsonResponse(['success' => true, 'processed' => true, 'message' => 'Sales processed successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            sendJsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

sendJsonResponse(['success' => false, 'message' => 'Invalid request']);
