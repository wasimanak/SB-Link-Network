<?php
/**
 * Safepay Checkout Initiator
 * Creates payment tracker and redirects customer to Safepay Hosted Checkout
 */

require_once 'auth_check.php';
require_once '../config/db.php';
require_once '../config/safepay_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

$subscriber_id = (int)$current_user['id'];
$client_id     = (int)$current_user['client_id'];
$purpose       = trim($_POST['purpose'] ?? 'recharge');
$package_id    = (int)($_POST['package_id'] ?? 0);
$amount        = (float)($_POST['amount'] ?? 0);

if ($purpose === 'package_renew' && $package_id > 0 && $amount <= 0) {
    // Look up package price
    $pStmt = $pdo->prepare("SELECT price FROM packages WHERE id = ?");
    $pStmt->execute([$package_id]);
    $amount = (float)$pStmt->fetchColumn();
}

if ($amount <= 0) {
    echo "<script>alert('Invalid payment amount.'); window.history.back();</script>";
    exit;
}

// Ensure database table exists
ensure_online_payments_table($pdo);

// Fetch active Safepay configuration
$safepay = get_safepay_config($pdo, $client_id);
if (!$safepay || empty($safepay['api_key']) || empty($safepay['api_secret'])) {
    echo "<script>alert('Online payment gateway (Safepay) is not configured by your operator. Please contact support or use Bank Transfer.'); window.location='dashboard.php';</script>";
    exit;
}

$api_key    = trim($safepay['api_key']);
$api_secret = trim($safepay['api_secret']);
$mode       = strtolower($safepay['mode'] ?? 'sandbox');

// 1. Create Tracker Token with Safepay
$trackerRes = create_safepay_tracker($api_key, $api_secret, $amount, $mode);
if (!$trackerRes['success'] || empty($trackerRes['token'])) {
    $err = htmlspecialchars($trackerRes['error'] ?? 'Unknown Safepay error');
    echo "<script>alert('Failed to initialize Safepay checkout: {$err}'); window.location='dashboard.php';</script>";
    exit;
}

$tracker_token = $trackerRes['token'];
$order_id = 'ORD_' . $subscriber_id . '_' . time() . '_' . rand(100, 999);

// 2. Save order in database
try {
    $ins = $pdo->prepare("INSERT INTO online_payments (client_id, subscriber_id, order_id, tracker_token, amount, currency, purpose, package_id, gateway, status, raw_response) VALUES (?, ?, ?, ?, ?, 'PKR', ?, ?, 'Safepay', 'pending', ?)");
    $ins->execute([
        $client_id,
        $subscriber_id,
        $order_id,
        $tracker_token,
        $amount,
        $purpose,
        $package_id ?: null,
        json_encode($trackerRes['raw'] ?? [])
    ]);
} catch (Exception $e) {
    echo "<script>alert('Database error saving order: " . addslashes($e->getMessage()) . "'); window.location='dashboard.php';</script>";
    exit;
}

// 3. Build Redirect URLs
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
// Base path of installation
$dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');

$redirect_url = $protocol . $host . $dir . '/safepay_callback.php';
$cancel_url   = $protocol . $host . $dir . '/dashboard.php?payment_status=cancelled';

$checkout_url = get_safepay_checkout_url($tracker_token, $order_id, $redirect_url, $cancel_url, $mode);

// 4. Redirect to Safepay Checkout
header("Location: " . $checkout_url);
exit;
