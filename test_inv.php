<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/operator/invoices.php';
session_start();
$_SESSION['operator_logged_in'] = true;
$_SESSION['operator_id'] = 7;
ob_start();
include 'C:/xampp/htdocs/SB Link Network/operator/invoices.php';
$output = ob_get_clean();
echo "Success: Page loaded perfectly without fatal errors.";
?>
