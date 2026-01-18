<?php
// Authentication functions

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

// Require login - redirect to login page if not authenticated
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /Inventory_sys/pages/auth/login.php');
        exit();
    }
}

// Get current user ID
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Get current username
function getCurrentUsername() {
    return $_SESSION['username'] ?? null;
}

// Login user
function loginUser($userId, $username) {
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['login_time'] = time();
}

// Logout user
function logoutUser() {
    $_SESSION = array();
    
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    session_destroy();
}

// Register new user
function registerUser($username, $email, $password) {
    $conn = getDBConnection();
    
    // Check if username or email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return ['success' => false, 'message' => 'Username or email already exists'];
    }
    
    // Hash password
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
    // Insert new user
    $stmt = $conn->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $email, $passwordHash);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Registration successful'];
    } else {
        return ['success' => false, 'message' => 'Registration failed: ' . $conn->error];
    }
}

// Authenticate user login
function authenticateUser($username, $password) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
    
    $user = $result->fetch_assoc();
    
    if (password_verify($password, $user['password_hash'])) {
        return [
            'success' => true,
            'user_id' => $user['id'],
            'username' => $user['username']
        ];
    } else {
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
}

// Change password
function changePassword($userId, $currentPassword, $newPassword) {
    $conn = getDBConnection();
    
    // Verify current password
    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!password_verify($currentPassword, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Current password is incorrect'];
    }
    
    // Update password
    $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->bind_param("si", $newPasswordHash, $userId);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Password changed successfully'];
    } else {
        return ['success' => false, 'message' => 'Failed to change password'];
    }
}
