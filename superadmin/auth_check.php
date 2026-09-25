<?php
session_start();
if (!isset($_SESSION['superadmin_logged_in']) || $_SESSION['superadmin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}