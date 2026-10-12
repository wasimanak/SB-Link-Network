<?php
session_start();

if (isset($_SESSION['customer_logged_in']) && $_SESSION['customer_logged_in'] === true) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}
exit;
