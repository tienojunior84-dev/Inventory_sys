<?php
// Helper functions

// Sanitize input
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Format currency (Cameroon XAF)
function formatCurrency($amount) {
    return number_format($amount, 0, '.', ' ') . ' XAF';
}

// Format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

// Format datetime
function formatDateTime($datetime) {
    return date('M d, Y h:i A', strtotime($datetime));
}

// Get product by ID
function getProduct($productId) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Get all products
function getAllProducts($category = null) {
    $conn = getDBConnection();
    
    if ($category && in_array($category, CATEGORIES)) {
        $stmt = $conn->prepare("SELECT * FROM products WHERE category = ? ORDER BY name ASC");
        $stmt->bind_param("s", $category);
    } else {
        $stmt = $conn->prepare("SELECT * FROM products ORDER BY name ASC");
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $products = [];
    
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    return $products;
}

// Calculate inventory value
function calculateInventoryValue($category = null) {
    $conn = getDBConnection();
    
    if ($category && in_array($category, CATEGORIES)) {
        $stmt = $conn->prepare("SELECT SUM(current_stock * purchase_price) as total_value FROM products WHERE category = ?");
        $stmt->bind_param("s", $category);
    } else {
        $stmt = $conn->prepare("SELECT SUM(current_stock * purchase_price) as total_value FROM products");
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row['total_value'] ?? 0;
}

// Get low stock products
function getLowStockProducts($category = null) {
    $conn = getDBConnection();
    
    if ($category && in_array($category, CATEGORIES)) {
        $stmt = $conn->prepare("SELECT * FROM products WHERE category = ? AND reorder_level IS NOT NULL AND current_stock <= reorder_level ORDER BY current_stock ASC");
        $stmt->bind_param("s", $category);
    } else {
        $stmt = $conn->prepare("SELECT * FROM products WHERE reorder_level IS NOT NULL AND current_stock <= reorder_level ORDER BY current_stock ASC");
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $products = [];
    
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    return $products;
}

// Get today's sales total
function getTodaySalesTotal() {
    $conn = getDBConnection();
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT SUM(total_amount) as total FROM sales WHERE sale_date = ?");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['total'] ?? 0;
}

// Get sales by date range
function getSalesByDateRange($startDate, $endDate) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT s.*, p.name as product_name, p.category, p.purchase_price 
                           FROM sales s 
                           JOIN products p ON s.product_id = p.id 
                           WHERE s.sale_date BETWEEN ? AND ? 
                           ORDER BY s.sale_date DESC, s.created_at DESC");
    $stmt->bind_param("ss", $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $sales = [];
    
    while ($row = $result->fetch_assoc()) {
        $sales[] = $row;
    }
    
    return $sales;
}

// Calculate profit for sales
function calculateProfit($sales) {
    $totalProfit = 0;
    foreach ($sales as $sale) {
        $profit = ($sale['unit_price'] - $sale['purchase_price']) * $sale['quantity'];
        $totalProfit += $profit;
    }
    return $totalProfit;
}
