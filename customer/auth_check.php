<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['customer_logged_in']) || $_SESSION['customer_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$sub_id = $_SESSION['customer_id'];
$client_id = $_SESSION['customer_client_id'];

// Security Guard: Check operator status
$stmt = $pdo->prepare("SELECT status FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client_status = $stmt->fetchColumn();

if ($client_status === 'suspended' || $client_status === 'expired') {
    session_destroy();
    header("Location: login.php?error=operator_suspended");
    exit;
}

// Fetch fresh subscriber data globally for the dashboard
$stmt = $pdo->prepare("SELECT s.*, p.name as package_name, p.rate_limit, p.price as package_price FROM subscribers s LEFT JOIN packages p ON s.package_id = p.id WHERE s.id = ?");
$stmt->execute([$sub_id]);
$current_user = $stmt->fetch();

if (!$current_user || $current_user['status'] === 'disabled') {
    session_destroy();
    header("Location: login.php?error=account_disabled");
    exit;
}
?>
