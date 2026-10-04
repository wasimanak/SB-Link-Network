<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
try {
    ob_start();
    require 'customer/dashboard.php';
    ob_end_clean();
    echo "Dashboard loaded successfully.";
} catch (Throwable $e) {
    echo "Fatal Error: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile();
}
?>
