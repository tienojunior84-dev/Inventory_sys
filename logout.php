<?php
require_once __DIR__ . '/includes/auth.php';

logoutUser();
header('Location: /Inventory_sys/pages/auth/login.php');
exit();
