<?php
// =========================================================================
// Android Phone SMS Gateway - High-Performance Multi-Protocol REST API
// Fully compatible with:
//   1. Capcom6 Android SMS Gateway App (Cloud & Private Mode: /mobile/v1/*)
//   2. Direct REST Webhook Polling (/api/sms_gateway?action=pull)
//   3. Custom SMS Clients & Microservices
// =========================================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sms_helper.php';

ensure_sms_tables_exist($pdo);

// -------------------------------------------------------------------------
// 1. Health Probe Endpoint (Does not require auth)
// -------------------------------------------------------------------------
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$parsedPath = parse_url($requestUri, PHP_URL_PATH);
$httpMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (strpos($parsedPath, '/health') !== false || substr($parsedPath, -7) === '/health') {
    echo json_encode([
        'status'  => 'ok',
        'service' => 'NetBee Android SMS Gateway',
        'time'    => date('Y-m-d H:i:s')
    ]);
    exit;
}

// -------------------------------------------------------------------------
// 2. Read Request Body (JSON or Form)
// -------------------------------------------------------------------------
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);

// -------------------------------------------------------------------------
// 3. Robust Authentication Token Extraction
// -------------------------------------------------------------------------
$token = trim($_REQUEST['token'] ?? '');

// Check Header sources
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (empty($authHeader) && function_exists('apache_request_headers')) {
    $hdrs = apache_request_headers();
    $authHeader = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
}
if (empty($authHeader) && function_exists('getallheaders')) {
    $hdrs = getallheaders();
    $authHeader = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
}

if (empty($token) && !empty($authHeader)) {
    if (preg_match('/(?:Bearer|ServerKey|Token)\s+(\S+)/i', $authHeader, $m)) {
        $token = trim($m[1]);
    } else {
        $token = trim($authHeader);
    }
}

// Check custom header fallbacks
if (empty($token)) {
    $token = trim($_SERVER['HTTP_X_TOKEN'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ($_SERVER['HTTP_X_GATEWAY_TOKEN'] ?? '')));
}

// Check JSON body fallback
if (empty($token) && is_array($jsonInput) && !empty($jsonInput['token'])) {
    $token = trim($jsonInput['token']);
}

// -------------------------------------------------------------------------
// 4. Debug Request Logging (Safely log last requests for troubleshooting)
// -------------------------------------------------------------------------
$debugDir = __DIR__ . '/../logs';
if (!is_dir($debugDir)) {
    @mkdir($debugDir, 0755, true);
}
$logLine = "[" . date('Y-m-d H:i:s') . "] {$httpMethod} {$requestUri} | Token: " . substr($token, 0, 10) . "..." . " | AuthHdr: " . substr($authHeader, 0, 25) . " | Body: " . substr($rawInput, 0, 180) . "\n";
@file_put_contents($debugDir . '/sms_gateway_debug.log', $logLine, FILE_APPEND);

// -------------------------------------------------------------------------
// 5. Validate Gateway Token
// -------------------------------------------------------------------------
if (empty($token)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized: Missing gateway token. Provide Authorization Bearer/ServerKey header or ?token=YOUR_SECRET_TOKEN.'
    ]);
    exit;
}

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

// -------------------------------------------------------------------------
// 6. Device Heartbeat Tracking
// -------------------------------------------------------------------------
$deviceName = trim($_REQUEST['device'] ?? ($_SERVER['HTTP_X_DEVICE_NAME'] ?? ''));
if (empty($deviceName) && is_array($jsonInput) && !empty($jsonInput['name'])) {
    $deviceName = trim($jsonInput['name']);
}
$batteryLevel = trim($_REQUEST['battery'] ?? ($_SERVER['HTTP_X_BATTERY'] ?? ''));
if (empty($batteryLevel) && is_array($jsonInput) && !empty($jsonInput['battery'])) {
    $batteryLevel = trim($jsonInput['battery']);
}

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
// 7. ROUTE HANDLER: CAPCOM6 ANDROID APP PROTOCOL
// -------------------------------------------------------------------------

// Endpoint A: Device Registration or Query (/mobile/v1/device)
if (strpos($parsedPath, '/device') !== false) {
    $dispDeviceName = !empty($deviceName) ? $deviceName : (!empty($gateway['phone_device_name']) ? $gateway['phone_device_name'] : 'Android Phone');
    
    echo json_encode([
        'id'        => 'dev_' . $client_id,
        'name'      => $dispDeviceName,
        'token'     => $token,
        'createdAt' => date('c', strtotime($gateway['created_at'] ?? 'now')),
        'updatedAt' => date('c')
    ]);
    exit;
}

// Endpoint B: Message Polling or Status Update (/mobile/v1/messages)
if (strpos($parsedPath, '/messages') !== false) {
    // Check if path has a specific message ID: e.g. /messages/123
    $pathMsgId = 0;
    if (preg_match('#/messages/(\d+)#', $parsedPath, $m)) {
        $pathMsgId = (int)$m[1];
    }

    // 1. Status Update from Phone (PATCH / POST / PUT)
    if (in_array($httpMethod, ['PATCH', 'POST', 'PUT']) || $pathMsgId > 0) {
        $targetMsgId = $pathMsgId ?: (int)($jsonInput['id'] ?? ($_REQUEST['id'] ?? 0));
        $rawStatus   = strtolower(trim($jsonInput['status'] ?? ($_REQUEST['status'] ?? 'sent')));
        $errorMsg    = trim($jsonInput['error'] ?? ($_REQUEST['error'] ?? ''));

        $normStatus = 'sent';
        if (in_array($rawStatus, ['delivered', 'success', 'ok'])) {
            $normStatus = 'delivered';
        } elseif (in_array($rawStatus, ['failed', 'error'])) {
            $normStatus = 'failed';
        } elseif (in_array($rawStatus, ['cancelled', 'cancel'])) {
            $normStatus = 'cancelled';
        }

        if ($targetMsgId > 0) {
            $upd = $pdo->prepare("
                UPDATE sms_queue_logs 
                SET status = ?, 
                    sent_at = CASE WHEN ? IN ('sent', 'delivered') THEN NOW() ELSE sent_at END,
                    error_message = ?,
                    gateway_ref = ?
                WHERE id = ? AND client_id = ?
            ");
            $upd->execute([$normStatus, $normStatus, $errorMsg ?: null, 'SIM' . ($gateway['sim_slot'] ?? 1), $targetMsgId, $client_id]);
        }

        echo json_encode([
            'success' => true,
            'id'      => $targetMsgId,
            'status'  => $normStatus
        ]);
        exit;
    }

    // 2. Outbound Message Polling (GET /mobile/v1/messages)
    if ($httpMethod === 'GET') {
        $limit = max(1, min(15, (int)($_REQUEST['limit'] ?? 5)));

        $stmt = $pdo->prepare("
            SELECT id, recipient_mobile, message_text
            FROM sms_queue_logs
            WHERE client_id = ?
              AND status = 'pending'
              AND scheduled_at <= NOW()
            ORDER BY id ASC
            LIMIT $limit
        ");
        $stmt->execute([$client_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $capcomMessages = [];
        foreach ($rows as $r) {
            $capcomMessages[] = [
                'id'           => (string)$r['id'],
                'deviceId'     => 'dev_' . $client_id,
                'message'      => $r['message_text'],
                'phoneNumbers' => [format_mobile_for_sms($r['recipient_mobile'])],
                'simNumber'    => (int)($gateway['sim_slot'] ?? 1)
            ];
        }

        echo json_encode($capcomMessages, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// -------------------------------------------------------------------------
// 8. ROUTE HANDLER: CUSTOM WEBHOOK / POLLING ACTIONS (?action=...)
// -------------------------------------------------------------------------
$action = strtolower(trim($_REQUEST['action'] ?? ''));

// If no action specified and request is GET to base URL -> Treat as ping
if (empty($action) && $httpMethod === 'GET') {
    $action = 'ping';
}

// Action 1: PING (Heartbeat / Connectivity Test)
if ($action === 'ping') {
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM sms_queue_logs WHERE client_id = ? AND status = 'pending'");
    $cStmt->execute([$client_id]);
    $pendingCount = (int)$cStmt->fetchColumn();

    $debugData = null;
    if (!empty($_REQUEST['debug'])) {
        $debugData = [
            'all_settings' => $pdo->query("SELECT id, client_id, gateway_token, last_phone_ping_at, phone_device_name FROM sms_gateway_settings")->fetchAll(),
            'queue_summary' => $pdo->query("SELECT client_id, status, count(*) as cnt FROM sms_queue_logs GROUP BY client_id, status")->fetchAll(),
            'recent_queue' => $pdo->query("SELECT id, client_id, recipient_mobile, status, scheduled_at, created_at FROM sms_queue_logs ORDER BY id DESC LIMIT 5")->fetchAll()
        ];
    }

    echo json_encode([
        'success'        => true,
        'status'         => 'online',
        'client_id'      => $client_id,
        'device_name'    => $gateway['phone_device_name'] ?: 'Android Phone',
        'sim_slot'       => (int)($gateway['sim_slot'] ?? 1),
        'pending_count'  => $pendingCount,
        'server_time'    => date('Y-m-d H:i:s'),
        'debug'          => $debugData,
        'message'        => 'Android SMS Gateway connected successfully.'
    ]);
    exit;
}

// Action 2: PULL (Fetch Pending Queue)
elseif ($action === 'pull') {
    $limit = max(1, min(25, (int)($_REQUEST['limit'] ?? 10)));

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

// Action 3: REPORT (Delivery Report)
elseif ($action === 'report') {
    $reports = [];
    if (is_array($jsonInput)) {
        if (isset($jsonInput['id'])) {
            $reports[] = $jsonInput;
        } elseif (isset($jsonInput['reports']) && is_array($jsonInput['reports'])) {
            $reports = $jsonInput['reports'];
        } else {
            $reports = $jsonInput;
        }
    } else {
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

// Action 4: CANCEL
elseif ($action === 'cancel') {
    $id = (int)($_REQUEST['id'] ?? 0);
    if ($id > 0) {
        $del = $pdo->prepare("UPDATE sms_queue_logs SET status = 'cancelled' WHERE id = ? AND client_id = ? AND status = 'pending'");
        $del->execute([$id, $client_id]);
        echo json_encode(['success' => true, 'message' => 'Message cancelled']);
        exit;
    }
}

// Fallback response
echo json_encode([
    'success' => true,
    'status'  => 'online',
    'message' => 'Android SMS Gateway is ready.'
]);
exit;
