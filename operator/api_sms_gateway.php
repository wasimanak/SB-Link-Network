<?php
// =========================================================================
// Android Phone SMS Gateway - Secure REST API Endpoint
// Connects Android Mobile Phone app to LASANI NETWORK / NetBee Server
// Allows mobile phone SIM to pull pending messages and report delivery status
// =========================================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sms_helper.php';

ensure_sms_tables_exist($pdo);

// 1. Authenticate Request via Secret Token
$token = trim($_REQUEST['token'] ?? '');
if (empty($token) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $token = trim($m[1]);
    }
}

if (empty($token)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized: Missing gateway token. Provide ?token=YOUR_SECRET_TOKEN or Authorization Bearer header.'
    ]);
    exit;
}

// Find client with this gateway token
$stmt = $pdo->prepare("SELECT * FROM sms_gateway_settings WHERE gateway_token = ? LIMIT 1");
$stmt->execute([$token]);
$gateway = $stmt->fetch();

if (!$gateway) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Forbidden: Invalid or expired gateway token.'
    ]);
    exit;
}

$client_id = (int)$gateway['client_id'];
$action = strtolower(trim($_REQUEST['action'] ?? 'pull'));

// Track Device Metadata if supplied by Phone
$deviceName   = trim($_REQUEST['device'] ?? ($_SERVER['HTTP_X_DEVICE_NAME'] ?? ''));
$batteryLevel = trim($_REQUEST['battery'] ?? ($_SERVER['HTTP_X_BATTERY'] ?? ''));

$updFields = ["last_phone_ping_at = NOW()"];
$updParams = [];
if (!empty($deviceName)) {
    $updFields[] = "phone_device_name = ?";
    $updParams[] = substr($deviceName, 0, 95);
}
if (!empty($batteryLevel)) {
    $updFields[] = "phone_battery_level = ?";
    $updParams[] = substr($batteryLevel, 0, 15);
}
$updParams[] = $client_id;
$updSql = "UPDATE sms_gateway_settings SET " . implode(', ', $updFields) . " WHERE client_id = ?";
$pdo->prepare($updSql)->execute($updParams);

// -------------------------------------------------------------------------
// Action 1: PING (Heartbeat / Connectivity Test)
// -------------------------------------------------------------------------
if ($action === 'ping') {
    // Count pending
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM sms_queue_logs WHERE client_id = ? AND status = 'pending'");
    $cStmt->execute([$client_id]);
    $pendingCount = (int)$cStmt->fetchColumn();

    echo json_encode([
        'success'        => true,
        'status'         => 'online',
        'client_id'      => $client_id,
        'sim_slot'       => (int)($gateway['sim_slot'] ?? 1),
        'pending_count'  => $pendingCount,
        'server_time'    => date('Y-m-d H:i:s'),
        'message'        => 'Android SMS Gateway connected successfully.'
    ]);
    exit;
}

// -------------------------------------------------------------------------
// Action 2: PULL (Phone fetches pending SMS queue)
// -------------------------------------------------------------------------
elseif ($action === 'pull') {
    $limit = max(1, min(25, (int)($_REQUEST['limit'] ?? 10)));

    // Fetch oldest pending messages scheduled for now or earlier
    $stmt = $pdo->prepare("
        SELECT id, recipient_mobile as `phone`, recipient_mobile as `to`, message_text as `message`, message_type
        FROM sms_queue_logs
        WHERE client_id = ?
          AND status = 'pending'
          AND scheduled_at <= NOW()
        ORDER BY id ASC
        LIMIT $limit
    ");
    $stmt->execute([$client_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'        => true,
        'count'          => count($messages),
        'sim_slot'       => (int)($gateway['sim_slot'] ?? 1),
        'server_time'    => date('Y-m-d H:i:s'),
        'messages'       => $messages
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------
// Action 3: REPORT (Phone reports sent or failed status back to server)
// -------------------------------------------------------------------------
elseif ($action === 'report') {
    // Read input from JSON body or POST form data
    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true);

    $reports = [];
    if (is_array($inputData)) {
        // Can be array of reports or single report
        if (isset($inputData['id'])) {
            $reports[] = $inputData;
        } elseif (isset($inputData['reports']) && is_array($inputData['reports'])) {
            $reports = $inputData['reports'];
        } else {
            $reports = $inputData;
        }
    } else {
        // Fallback to query/form parameters
        $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
        $status = strtolower(trim($_POST['status'] ?? ($_GET['status'] ?? '')));
        $error = trim($_POST['error'] ?? ($_GET['error'] ?? ''));
        if ($id > 0) {
            $reports[] = ['id' => $id, 'status' => $status, 'error' => $error];
        }
    }

    $updatedCount = 0;
    $updStmt = $pdo->prepare("
        UPDATE sms_queue_logs 
        SET status = ?, 
            sent_at = CASE WHEN ? IN ('sent', 'delivered') THEN NOW() ELSE sent_at END,
            error_message = ?,
            gateway_ref = ?
        WHERE id = ? AND client_id = ?
    ");

    foreach ($reports as $r) {
        $msgId = (int)($r['id'] ?? 0);
        $rawStatus = strtolower(trim($r['status'] ?? ''));
        $targetStatus = in_array($rawStatus, ['sent', 'delivered', 'failed', 'cancelled']) ? $rawStatus : 'sent';
        $errText = !empty($r['error']) ? substr(trim($r['error']), 0, 250) : null;
        $ref = !empty($r['ref']) ? substr(trim($r['ref']), 0, 90) : 'SIM' . ($gateway['sim_slot'] ?? 1);

        if ($msgId > 0) {
            $updStmt->execute([$targetStatus, $targetStatus, $errText, $ref, $msgId, $client_id]);
            $updatedCount++;
        }
    }

    echo json_encode([
        'success'       => true,
        'updated_count' => $updatedCount,
        'message'       => "Updated {$updatedCount} SMS status records."
    ]);
    exit;
}

// -------------------------------------------------------------------------
// Action 4: CANCEL / DELETE Message from Queue
// -------------------------------------------------------------------------
elseif ($action === 'cancel') {
    $id = (int)($_REQUEST['id'] ?? 0);
    if ($id > 0) {
        $del = $pdo->prepare("UPDATE sms_queue_logs SET status = 'cancelled' WHERE id = ? AND client_id = ? AND status = 'pending'");
        $del->execute([$id, $client_id]);
        echo json_encode(['success' => true, 'message' => 'Message cancelled']);
        exit;
    }
}

// Default fallback
echo json_encode([
    'success' => false,
    'error'   => 'Unknown action. Supported actions: ping, pull, report, cancel.'
]);
exit;
