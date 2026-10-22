<?php
/**
 * Safepay Automated Webhook Receiver
 * Endpoint: https://YOUR_DOMAIN/safepay_webhook.php
 * Handles server-to-server asynchronous notifications from Safepay
 */

// Disable output buffering
while (ob_get_level() > 0) ob_end_clean();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/safepay_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure table exists
ensure_online_payments_table($pdo);

// Read raw body
$raw_body = file_get_contents('php://input');
if (empty($raw_body)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
    exit;
}

// Log incoming webhook for audit
@file_put_contents(__DIR__ . '/safepay_webhook.log', "[" . date('Y-m-d H:i:s') . "] " . $raw_body . "\n", FILE_APPEND);

$payload = json_decode($raw_body, true);
if (!$payload) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// Extract tracker token or order reference
$tracker = $payload['data']['token'] 
    ?? ($payload['data']['tracker'] 
    ?? ($payload['data']['beacon'] 
    ?? ($payload['tracker'] 
    ?? ($payload['token'] ?? ''))));

$state   = strtoupper($payload['data']['state'] 
    ?? ($payload['event'] 
    ?? ($payload['status'] ?? '')));

$meta_order = $payload['data']['metadata']['order_id'] 
    ?? ($payload['order_id'] 
    ?? ($payload['metadata']['order_id'] ?? ''));

if (empty($tracker)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Missing tracker token']);
    exit;
}

// Find order
$stmt = $pdo->prepare("SELECT * FROM online_payments WHERE tracker_token = ? LIMIT 1");
$stmt->execute([$tracker]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    // If not found by tracker, check by metadata order_id if present
    $meta_order = $payload['data']['metadata']['order_id'] ?? '';
    if (!empty($meta_order)) {
        $stmt2 = $pdo->prepare("SELECT * FROM online_payments WHERE order_id = ? LIMIT 1");
        $stmt2->execute([$meta_order]);
        $order = $stmt2->fetch(PDO::FETCH_ASSOC);
    }
}

if (!$order) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Order not found in system']);
    exit;
}

// Check if already processed
if ($order['status'] === 'completed') {
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Already processed']);
    exit;
}

// Check state (PAID, COMPLETED, or payment.successful)
if (str_contains($state, 'PAID') || str_contains($state, 'COMPLETED') || str_contains($state, 'SUCCESS')) {
    $res = process_successful_payment($order['id'], $pdo, 'Safepay Webhook');
    if ($res['success']) {
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Payment processed and user balance/package updated']);
        exit;
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $res['message']]);
        exit;
    }
}

http_response_code(200);
echo json_encode(['status' => 'ignored', 'state' => $state]);
exit;
