<?php
session_start();
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
if (isset($_SESSION['customer_logged_in']) && $_SESSION['customer_logged_in'] === true) {
    header("Location: dashboard.php" . $qs);
} else {
    header("Location: login.php" . $qs);
}
exit;
