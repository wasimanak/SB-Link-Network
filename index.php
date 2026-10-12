<?php
session_start();

$host = $_SERVER['HTTP_HOST'] ?? '';

// Check if customer subdomain is being accessed (e.g. customer.sblink.tech)
if (stripos($host, 'customer.') === 0 || stripos($host, 'customer') !== false) {
    if (isset($_SESSION['customer_logged_in']) && $_SESSION['customer_logged_in'] === true) {
        header("Location: customer/dashboard.php");
    } else {
        header("Location: customer/login.php");
    }
    exit;
}

// Default root redirect
header("Location: login.php");
exit;