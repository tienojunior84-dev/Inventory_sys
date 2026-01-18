<?php
// Application constants

// Session configuration
define('SESSION_LIFETIME', 3600 * 8); // 8 hours

// File upload configuration
define('UPLOAD_DIR', __DIR__ . '/../public/uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_EXTENSIONS', ['csv', 'xlsx', 'xls']);

// Product categories
define('CATEGORIES', ['Provisions', 'Wine', 'Beer']);

// Pagination
define('ITEMS_PER_PAGE', 20);

// Date format
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
