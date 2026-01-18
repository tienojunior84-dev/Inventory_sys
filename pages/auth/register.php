<?php
$pageTitle = 'Register - C&C Building Shop';
require_once __DIR__ . '/../../includes/header.php';

// Handle registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    // Validation
    if (empty($username)) {
        $errors[] = 'Username is required';
    } elseif (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }
    
    if (empty($errors)) {
        $result = registerUser($username, $email, $password);
        
        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'];
            header('Location: /Inventory_sys/pages/auth/login.php');
            exit();
        } else {
            $errors[] = $result['message'];
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card">
            <div class="card-header text-center">
                <h4 class="mb-0"><i class="bi bi-person-plus"></i> Create Account</h4>
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
                        <label for="username" class="form-label required-field">Username</label>
                        <input type="text" class="form-control" id="username" name="username" 
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                               required autofocus>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label required-field">Email</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                               required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label required-field">Password</label>
                        <input type="password" class="form-control" id="password" name="password" 
                               required minlength="6">
                        <small class="form-text text-muted">Minimum 6 characters</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label required-field">Confirm Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                               required minlength="6">
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-plus"></i> Register
                        </button>
                    </div>
                </form>
                
                <hr>
                
                <div class="text-center">
                    <p class="mb-0">Already have an account? 
                        <a href="/Inventory_sys/pages/auth/login.php">Login here</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
