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
    
    echo "Product Name,Quantity,Unit Price,Date\n";
    echo "Example Product,5,15000," . date('Y-m-d') . "\n";
    exit();
}

// Handle sales recording
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($method === 'manual') {
        $productId = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $unitPrice = floatval($_POST['unit_price'] ?? 0);
        $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
        
        if ($productId <= 0 || $quantity <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid product or quantity']);
        }
        
        if ($unitPrice <= 0) {
            sendJsonResponse(['success' => false, 'message' => 'Unit price must be greater than 0']);
        }
        
        $product = getProduct($productId);
        if (!$product) {
            sendJsonResponse(['success' => false, 'message' => 'Product not found']);
        }
        
        if ($product['current_stock'] < $quantity) {
            sendJsonResponse(['success' => false, 'message' => 'Insufficient stock. Available: ' . $product['current_stock']]);
        }
        
        $conn->begin_transaction();
        
        try {
            $totalAmount = $unitPrice * $quantity;
            $userId = getCurrentUserId();
            
            // Record sale
            $stmt = $conn->prepare("INSERT INTO sales (product_id, quantity, unit_price, total_amount, sale_date, user_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiddsi", $productId, $quantity, $unitPrice, $totalAmount, $saleDate, $userId);
            $stmt->execute();
            
            // Update stock
            $newStock = $product['current_stock'] - $quantity;
            $stmt = $conn->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
            $stmt->bind_param("ii", $newStock, $productId);
            $stmt->execute();
            
            // Record stock movement
            $movementType = 'out';
            $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, user_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isii", $productId, $movementType, $quantity, $userId);
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
        $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
        
        if (empty($products) || count($products) !== count($quantities)) {
            sendJsonResponse(['success' => false, 'message' => 'Invalid batch data']);
        }
        
        $conn->begin_transaction();
        
        try {
            $userId = getCurrentUserId();
            
            for ($i = 0; $i < count($products); $i++) {
                $productId = intval($products[$i]);
                $quantity = intval($quantities[$i]);
                $unitPrice = floatval($prices[$i] ?? 0);
                
                if ($productId <= 0 || $quantity <= 0 || $unitPrice <= 0) continue;
                
                $product = getProduct($productId);
                if (!$product) continue;
                
                if ($product['current_stock'] < $quantity) {
                    throw new Exception("Insufficient stock for {$product['name']}. Available: {$product['current_stock']}");
                }
                
                $totalAmount = $unitPrice * $quantity;
                
                // Record sale
                $stmt = $conn->prepare("INSERT INTO sales (product_id, quantity, unit_price, total_amount, sale_date, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiddsi", $productId, $quantity, $unitPrice, $totalAmount, $saleDate, $userId);
                $stmt->execute();
                
                // Update stock
                $newStock = $product['current_stock'] - $quantity;
                $stmt = $conn->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
                $stmt->bind_param("ii", $newStock, $productId);
                $stmt->execute();
                
                // Record stock movement
                $movementType = 'out';
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, user_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isii", $productId, $movementType, $quantity, $userId);
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
            $header = fgetcsv($handle); // Skip header
            
            $lineNum = 2;
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 2) {
                    $errors[] = "Line $lineNum: Insufficient columns";
                    continue;
                }
                
                $productName = trim($row[0]);
                $quantity = intval($row[1]);
                $unitPrice = !empty($row[2]) ? floatval($row[2]) : null;
                $date = !empty($row[3]) ? trim($row[3]) : date('Y-m-d');
                
                if (empty($productName)) {
                    $errors[] = "Line $lineNum: Product name is required";
                    continue;
                }
                
                if ($quantity <= 0) {
                    $errors[] = "Line $lineNum: Invalid quantity";
                    continue;
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
                // Use provided price or default to product's selling price
                $finalPrice = $unitPrice !== null ? $unitPrice : $product['selling_price'];
                
                $salesData[] = [
                    'product_id' => $product['id'],
                    'product_name' => $productName,
                    'quantity' => $quantity,
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
        $previewHtml .= '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Product</th><th>Quantity</th><th>Unit Price</th><th>Total</th><th>Date</th></tr></thead><tbody>';
        
        foreach ($salesData as $sale) {
            $total = ($sale['unit_price'] ?? 0) * $sale['quantity'];
            $previewHtml .= '<tr><td>' . htmlspecialchars($sale['product_name']) . '</td>';
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
                $stmt = $conn->prepare("INSERT INTO sales (product_id, quantity, unit_price, total_amount, sale_date, user_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiddsi", $sale['product_id'], $sale['quantity'], $unitPrice, $totalAmount, $sale['date'], $userId);
                $stmt->execute();
                
                // Update stock
                $newStock = $product['current_stock'] - $sale['quantity'];
                $stmt = $conn->prepare("UPDATE products SET current_stock = ? WHERE id = ?");
                $stmt->bind_param("ii", $newStock, $sale['product_id']);
                $stmt->execute();
                
                // Record stock movement
                $movementType = 'out';
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, user_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isii", $sale['product_id'], $movementType, $sale['quantity'], $userId);
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
