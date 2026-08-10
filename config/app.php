<?php
define('APP_NAME',    'LibraryMS');
define('APP_VERSION', '1.0.0');
define('BORROW_DAYS', 14);
if (getenv('BASE_URL') !== false) {
    define('BASE_URL', getenv('BASE_URL'));
} elseif (php_sapi_name() === 'cli-server') {
    define('BASE_URL', 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000'));
} else {
    define('BASE_URL', 'http://localhost/library-system');
}

date_default_timezone_set('Africa/Nairobi');

session_name('LIBMS');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/helpers.php';
