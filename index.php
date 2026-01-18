<?php
// Redirect to login or dashboard based on authentication
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: /Inventory_sys/pages/dashboard.php');
} else {
    header('Location: /Inventory_sys/pages/auth/login.php');
}
exit();
