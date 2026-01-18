<?php
$pageTitle = 'My Account - C&C Building Shop';
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$userId = getCurrentUserId();
$conn = getDBConnection();

// Get user info
$stmt = $conn->prepare("SELECT username, email, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    if (empty($currentPassword)) {
        $errors[] = 'Current password is required';
    }
    
    if (empty($newPassword)) {
        $errors[] = 'New password is required';
    } elseif (strlen($newPassword) < 6) {
        $errors[] = 'New password must be at least 6 characters';
    }
    
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'New passwords do not match';
    }
    
    if (empty($errors)) {
        $result = changePassword($userId, $currentPassword, $newPassword);
        
        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'];
            header('Location: /Inventory_sys/pages/account.php');
            exit();
        } else {
            $_SESSION['error_message'] = $result['message'];
        }
    } else {
        $_SESSION['error_message'] = implode('<br>', $errors);
    }
}
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-person-circle"></i> My Account</h2>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Account Information</h5>
            </div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <th width="40%">Username:</th>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                    </tr>
                    <tr>
                        <th>Member Since:</th>
                        <td><?php echo formatDate($user['created_at']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="change_password" value="1">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" required minlength="6">
                        <small class="form-text text-muted">Minimum 6 characters</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required minlength="6">
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-key"></i> Change Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
