<?php
session_start();
require_once '../config/db.php';

// If impersonated from superadmin, session holds 'operator_logged_in'
if (!isset($_SESSION['operator_logged_in']) || $_SESSION['operator_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$operator_id = $_SESSION['operator_id'];

// Live DB Validation: Check status and expiry
$stmt = $pdo->prepare("SELECT status, expiry_date FROM clients WHERE id = :id");
$stmt->execute(['id' => $operator_id]);
$client = $stmt->fetch();

if (!$client || $client['status'] !== 'active' || (strtotime($client['expiry_date']) < time() && $client['expiry_date'] !== null)) {
    session_unset();
    session_destroy();
    header("Location: login.php?error=account_blocked");
    exit;
}
