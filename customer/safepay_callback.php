<?php
/**
 * Safepay Customer Return / Callback Page
 * Verifies payment signature and shows confirmation card
 */

require_once 'auth_check.php';
require_once '../config/db.php';
require_once '../config/safepay_helper.php';

$raw_input  = @file_get_contents('php://input');
$json_input = json_decode($raw_input, true) ?? [];

// Safepay passes parameters via POST or GET or JSON
$tracker  = trim($_REQUEST['tracker'] ?? ($_REQUEST['beacon'] ?? ($_REQUEST['token'] ?? ($json_input['data']['token'] ?? ($json_input['tracker'] ?? '')))));
$sig      = trim($_REQUEST['sig'] ?? ($_REQUEST['signature'] ?? ($json_input['sig'] ?? '')));
$order_id = trim($_REQUEST['order_id'] ?? ($json_input['order_id'] ?? ($json_input['data']['metadata']['order_id'] ?? '')));

$client_id = (int)($current_user['client_id'] ?? 0);
$sub_id    = (int)($current_user['id'] ?? 0);

// Fallback 1: If tracker is missing but order_id is present, get tracker from database
if (empty($tracker) && !empty($order_id)) {
    $tStmt = $pdo->prepare("SELECT tracker_token FROM online_payments WHERE order_id = ? LIMIT 1");
    $tStmt->execute([$order_id]);
    $tracker = $tStmt->fetchColumn() ?: '';
}

// Fallback 2: If both tracker and order_id are missing, check if this subscriber has a recent pending payment
if (empty($tracker) && $sub_id > 0) {
    $lastStmt = $pdo->prepare("SELECT order_id, tracker_token, client_id FROM online_payments WHERE subscriber_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $lastStmt->execute([$sub_id]);
    $lastOrder = $lastStmt->fetch(PDO::FETCH_ASSOC);
    if ($lastOrder) {
        $tracker = $lastOrder['tracker_token'];
        $order_id = $lastOrder['order_id'];
        if ($client_id === 0) $client_id = (int)$lastOrder['client_id'];
    }
}

$safepay   = get_safepay_config($pdo, $client_id);
$mode      = strtolower($safepay['mode'] ?? 'sandbox');
$secret    = trim($safepay['api_secret'] ?? '');

$payment_verified = false;
$error_message = '';
$order_data = null;
$package_renewed = false;
$renewed_pkg_name = '';

if (empty($tracker)) {
    $error_message = "No payment transaction identifier found in return URL.";
} else {
    // 1. Verify with Safepay
    $ver = verify_safepay_payment($tracker, $sig, $secret, $mode);
    if ($ver['verified']) {
        // 2. Process successful payment (Idempotency safe)
        $process = process_successful_payment($order_id ?: $tracker, $pdo, 'Safepay (Online Checkout)');
        if ($process['success']) {
            $payment_verified = true;
            $order_data = $process['order'] ?? null;
            $package_renewed = $process['package_renewed'] ?? false;
            $renewed_pkg_name = $process['package_name'] ?? '';
        } else {
            $error_message = $process['message'] ?? 'Error updating your account balance.';
        }
    } else {
        $error_message = "Payment signature verification failed. State: " . htmlspecialchars($ver['state'] ?? 'Unknown');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status - SB Link Network / NetBee</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 20px;
        }
        .status-card {
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            max-width: 520px;
            width: 100%;
            overflow: hidden;
        }
        .status-header-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 35px 25px;
            text-align: center;
        }
        .status-header-error {
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
            color: white;
            padding: 35px 25px;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="status-card">
    <?php if ($payment_verified): ?>
        <div class="status-header-success">
            <div class="d-inline-flex align-items-center justify-content-center bg-white bg-opacity-25 rounded-circle mb-3" style="width: 70px; height: 70px;">
                <i class="fa-solid fa-check fa-2x text-white"></i>
            </div>
            <h3 class="fw-bold mb-1">Payment Successful!</h3>
            <p class="mb-0 text-white-50 small">Your transaction has been verified automatically via Safepay</p>
        </div>

        <div class="p-4 text-center">
            <div class="bg-light p-3 rounded-4 mb-3 text-start small border">
                <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
                    <span class="text-muted">Transaction ID (Tracker):</span>
                    <span class="fw-bold font-monospace text-dark"><?= htmlspecialchars($tracker) ?></span>
                </div>
                <?php if ($order_data): ?>
                <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
                    <span class="text-muted">Order ID:</span>
                    <span class="fw-bold font-monospace text-primary"><?= htmlspecialchars($order_data['order_id']) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2 pb-2 border-bottom">
                    <span class="text-muted">Amount Paid:</span>
                    <span class="fw-bold text-success fs-6">Rs. <?= number_format($order_data['amount'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Payment Type:</span>
                    <span class="badge bg-primary-subtle text-primary fw-bold text-uppercase"><?= htmlspecialchars($order_data['purpose']) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($package_renewed): ?>
            <div class="alert alert-success border-0 small text-success-emphasis bg-success-subtle rounded-3 py-2">
                <i class="fa-solid fa-circle-check text-success me-1"></i> Internet Package (<strong><?= htmlspecialchars($renewed_pkg_name ?: 'Active Plan') ?></strong>) has been renewed and activated immediately!
            </div>
            <?php else: ?>
            <div class="alert alert-success border-0 small text-success-emphasis bg-success-subtle rounded-3 py-2">
                <i class="fa-solid fa-wallet text-success me-1"></i> Your account balance has been credited with <strong>Rs. <?= number_format($order_data['amount'] ?? 0, 2) ?></strong>!
            </div>
            <?php endif; ?>

            <a href="dashboard.php" class="btn btn-primary btn-lg rounded-pill px-4 fw-bold w-100 shadow-sm mt-2">
                <i class="fa-solid fa-house me-1"></i> Return to Customer Dashboard
            </a>
            <div class="text-muted small mt-2">Redirecting automatically in <span id="countdown">5</span> seconds...</div>
        </div>

        <script>
            var sec = 5;
            var timer = setInterval(function() {
                sec--;
                document.getElementById('countdown').innerText = sec;
                if (sec <= 0) {
                    clearInterval(timer);
                    window.location.href = 'dashboard.php';
                }
            }, 1000);
        </script>

    <?php else: ?>
        <div class="status-header-error">
            <div class="d-inline-flex align-items-center justify-content-center bg-white bg-opacity-25 rounded-circle mb-3" style="width: 70px; height: 70px;">
                <i class="fa-solid fa-xmark fa-2x text-white"></i>
            </div>
            <h3 class="fw-bold mb-1">Payment Unverified</h3>
            <p class="mb-0 text-white-50 small">We could not confirm your payment transaction</p>
        </div>

        <div class="p-4 text-center">
            <div class="alert alert-danger border-0 small text-danger-emphasis bg-danger-subtle rounded-3 text-start mb-3">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= htmlspecialchars($error_message) ?>
            </div>

            <p class="text-muted small">Agar aapke bank ya wallet account se raqam kat chuki hai, toh fikr na karein. Hamara automated webhook background mein verify karke aapka balance add kar dega.</p>

            <a href="dashboard.php" class="btn btn-secondary rounded-pill px-4 fw-semibold w-100 mt-2">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
