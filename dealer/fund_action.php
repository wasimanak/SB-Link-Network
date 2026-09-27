<?php
require_once 'header.php'; // Checks auth

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)$_POST['amount'];
    $payment_reference = trim($_POST['payment_reference']);
    
    if ($amount <= 0 || empty($payment_reference)) {
        echo "<script>alert('Invalid amount or missing transaction reference.'); window.history.back();</script>";
        exit;
    }
    
    try {
        // Insert pending fund request for DEALER
        $stmt = $pdo->prepare("INSERT INTO fund_requests (dealer_id, client_id, amount, payment_reference, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$dealer_id, $client_id, $amount, $payment_reference]);
        
        echo "<script>alert('Recharge request submitted successfully! Your balance will be updated once the operator verifies the transaction.'); window.location='dashboard.php';</script>";
        exit;
    } catch (Exception $e) {
        echo "<script>alert('Error submitting recharge request. Please try again.'); window.location='dashboard.php';</script>";
        exit;
    }
}
header("Location: dashboard.php");
exit;