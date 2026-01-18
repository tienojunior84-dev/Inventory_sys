<?php
$pageTitle = 'Login - C&C Building Shop';
require_once __DIR__ . '/../../includes/header.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: /Inventory_sys/pages/dashboard.php');
    exit();
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $errors = [];
    
    if (empty($username)) {
        $errors[] = 'Username or email is required';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    }
    
    if (empty($errors)) {
        $result = authenticateUser($username, $password);
        
        if ($result['success']) {
            loginUser($result['user_id'], $result['username']);
            $_SESSION['success_message'] = 'Welcome back, ' . $result['username'] . '!';
            header('Location: /Inventory_sys/pages/dashboard.php');
            exit();
        } else {
            $errors[] = $result['message'];
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card">
            <div class="card-header text-center">
                <h4 class="mb-0"><i class="bi bi-box-arrow-in-right"></i> Login</h4>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username or Email</label>
                        <input type="text" class="form-control" id="username" name="username" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                               required autofocus>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Login
                        </button>
                    </div>
                </form>
                
                <hr>
                
                <div class="text-center">
                    <p class="mb-0">Don't have an account? 
                        <a href="/Inventory_sys/pages/auth/register.php">Register here</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
