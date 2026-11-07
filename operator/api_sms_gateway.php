<?php
// =========================================================================
// Android Phone SMS Gateway - High-Performance Multi-Protocol REST API
// Native Capcom6 GatewayApi.kt wire-protocol implementation:
//   - GET/POST/PATCH /device
//   - GET/PATCH /message (singular) & /messages (plural)
//   - GET /settings
//   - GET /webhooks
//   - POST /inbox
// Plus legacy /api/sms_gateway?action=pull / ping / report
// =========================================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sms_helper.php';

ensure_sms_tables_exist($pdo);

// -------------------------------------------------------------------------
// 1. Health Probe Endpoint
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
// 3. Robust Authentication Token Extraction (Bearer, Basic, Header, Query, Body)
// -------------------------------------------------------------------------
$token = trim($_REQUEST['token'] ?? '');
$basicUser = '';
$basicPass = '';

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (empty($authHeader) && function_exists('apache_request_headers')) {
    $hdrs = apache_request_headers();
    $authHeader = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
}
if (empty($authHeader) && function_exists('getallheaders')) {
    $hdrs = getallheaders();
    $authHeader = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
}

if (!empty($_SERVER['PHP_AUTH_USER'])) {
    $basicUser = trim($_SERVER['PHP_AUTH_USER']);
}
if (!empty($_SERVER['PHP_AUTH_PW'])) {
    $basicPass = trim($_SERVER['PHP_AUTH_PW']);
}

if (!empty($authHeader)) {
    if (preg_match('/^Basic\s+(.*)$/i', $authHeader, $bm)) {
        $decoded = base64_decode(trim($bm[1]));
        if (strpos($decoded, ':') !== false) {
            list($bu, $bp) = explode(':', $decoded, 2);
            $basicUser = trim($bu);
            $basicPass = trim($bp);
        } else {
            $token = trim($decoded);
        }
    } elseif (preg_match('/(?:Bearer|ServerKey|Token)\s+(\S+)/i', $authHeader, $m)) {
        $token = trim($m[1]);
    } else {
        $token = trim($authHeader);
    }
}

if (empty($token)) {
    $token = trim($_SERVER['HTTP_X_TOKEN'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ($_SERVER['HTTP_X_GATEWAY_TOKEN'] ?? ($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? ''))));
}

if (empty($token) && is_array($jsonInput)) {
    $token = trim($jsonInput['token'] ?? ($jsonInput['apiKey'] ?? ($jsonInput['api_key'] ?? ($jsonInput['login'] ?? ''))));
}

// -------------------------------------------------------------------------
// 4. Debug Request Logging
// -------------------------------------------------------------------------
$debugDir = __DIR__ . '/../logs';
if (!is_dir($debugDir)) {
    @mkdir($debugDir, 0755, true);
}
$logLine = "[" . date('Y-m-d H:i:s') . "] {$httpMethod} {$requestUri} | Token: " . substr($token, 0, 10) . "..." . " | BasicU: " . substr($basicUser, 0, 10) . " | BasicP: " . substr($basicPass, 0, 10) . " | AuthHdr: " . substr($authHeader, 0, 25) . " | Body: " . substr($rawInput, 0, 180) . "\n";
@file_put_contents($debugDir . '/sms_gateway_debug.log', $logLine, FILE_APPEND);

// -------------------------------------------------------------------------
// 5. Validate Gateway Token against Database
// -------------------------------------------------------------------------
$gateway = null;
if (!empty($token)) {
    $stmt = $pdo->prepare("SELECT * FROM sms_gateway_settings WHERE gateway_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $gateway = $stmt->fetch();
}

if (!$gateway && !empty($basicPass)) {
    $stmt = $pdo->prepare("SELECT * FROM sms_gateway_settings WHERE gateway_token = ? LIMIT 1");
    $stmt->execute([$basicPass]);
    $gateway = $stmt->fetch();
    if ($gateway) $token = $basicPass;
}

if (!$gateway && !empty($basicUser)) {
    $stmt = $pdo->prepare("SELECT * FROM sms_gateway_settings WHERE gateway_token = ? LIMIT 1");
    $stmt->execute([$basicUser]);
    $gateway = $stmt->fetch();
    if ($gateway) $token = $basicUser;
}

if (!$gateway && empty($token) && empty($basicPass) && empty($basicUser)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized: Missing gateway token.'
    ]);
    exit;
}

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
// 7. ROUTE HANDLER: CAPCOM6 NATIVE PROTOCOL (GatewayApi.kt)
// -------------------------------------------------------------------------

// Endpoint 0: Server-Sent Events (SSE) Stream for Zero-Delay Real-time Push
if ((strpos($parsedPath, '/events') !== false || substr($parsedPath, -7) === '/events') && $httpMethod === 'GET') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    @set_time_limit(40);
    @ini_set('max_execution_time', '40');

    echo "event: ping\ndata: {\"status\":\"connected\",\"server_time\":\"" . date('c') . "\"}\n\n";
    @flush();

    $startTime = time();
    $lastPingTime = time();
    $lastNotifiedMsgId = 0;

    while (time() - $startTime < 30) {
        if (connection_aborted()) {
            break;
        }

        $pStmt = $pdo->prepare("
            SELECT id FROM sms_queue_logs 
            WHERE client_id = ? 
              AND status = 'pending' 
              AND (scheduled_at IS NULL OR scheduled_at <= NOW())
              AND id > ?
            ORDER BY id ASC LIMIT 10
        ");
        $pStmt->execute([$client_id, $lastNotifiedMsgId]);
        $pendingIds = $pStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($pendingIds)) {
            $lastNotifiedMsgId = max($pendingIds);
            echo "event: MessageEnqueued\ndata: {\"count\":" . count($pendingIds) . "}\n\n";
            echo "event: message:new\ndata: {\"count\":" . count($pendingIds) . "}\n\n";
            @flush();
        }

        if (time() - $lastPingTime >= 10) {
            echo "event: ping\ndata: {\"timestamp\":\"" . date('c') . "\"}\n\n";
            @flush();
            $lastPingTime = time();

            $pdo->prepare("UPDATE sms_gateway_settings SET last_phone_ping_at = NOW() WHERE client_id = ?")->execute([$client_id]);
        }

        usleep(500000); // 0.5s check interval
    }

    exit;
}

// Endpoint A: Device Registration (POST /device OR /devices)
if ((strpos($parsedPath, '/device') !== false || strpos($parsedPath, '/devices') !== false) && $httpMethod === 'POST') {
    echo json_encode([
        'id'         => 'dev_' . $client_id,
        'token'      => $token,
        'login'      => 'admin',
        'password'   => null,
        'name'       => $deviceName ?: ($gateway['phone_device_name'] ?: 'Android Phone'),
        'createdAt'  => $gateway['created_at'] ?: date('c'),
        'updatedAt'  => date('c'),
        'lastSeen'   => date('c')
    ]);
    exit;
}

// Endpoint B: Device Info (GET /device OR /devices)
if ((strpos($parsedPath, '/device') !== false || strpos($parsedPath, '/devices') !== false) && $httpMethod === 'GET') {
    echo json_encode([
        'id'         => 'dev_' . $client_id,
        'name'       => $gateway['phone_device_name'] ?: 'Android Phone',
        'externalIp' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'createdAt'  => $gateway['created_at'] ?: date('c'),
        'updatedAt'  => date('c'),
        'lastSeen'   => $gateway['last_phone_ping_at'] ?: date('c'),
        'simCards'   => [
            [
                'slot'    => (int)($gateway['sim_slot'] ?? 1),
                'sign'    => 'SIM' . ($gateway['sim_slot'] ?? 1),
                'carrier' => 'Active SIM'
            ]
        ]
    ]);
    exit;
}

// Endpoint C: Device Patch (PATCH /device OR /devices)
if ((strpos($parsedPath, '/device') !== false || strpos($parsedPath, '/devices') !== false) && $httpMethod === 'PATCH') {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

// Endpoint D: Settings (GET /settings)
if (strpos($parsedPath, '/settings') !== false && $httpMethod === 'GET') {
    echo json_encode((object)[]);
    exit;
}

// Endpoint E: Webhooks (GET /webhooks)
if (strpos($parsedPath, '/webhooks') !== false && $httpMethod === 'GET') {
    echo json_encode([]);
    exit;
}

// Endpoint F: Inbox (POST /inbox)
if (strpos($parsedPath, '/inbox') !== false && $httpMethod === 'POST') {
    http_response_code(204);
    exit;
}

// Endpoint G: Outbound Messages Polling (GET /message OR /messages)
if ((strpos($parsedPath, '/message') !== false || strpos($parsedPath, '/messages') !== false) && $httpMethod === 'GET') {
    $limit = max(1, min(25, (int)($_REQUEST['limit'] ?? 10)));

    $stmt = $pdo->prepare("
        SELECT id, recipient_mobile, message_text
        FROM sms_queue_logs
        WHERE client_id = ?
          AND status = 'pending'
          AND (scheduled_at IS NULL OR scheduled_at <= NOW())
        ORDER BY id ASC
        LIMIT $limit
    ");
    $stmt->execute([$client_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $capcomList = [];
    foreach ($rows as $r) {
        $cleanPhone = format_mobile_for_sms($r['recipient_mobile']);
        $text = (string)$r['message_text'];

        $capcomList[] = [
            'id'                 => (string)$r['id'],
            'phoneNumbers'       => [$cleanPhone],
            'simNumber'          => (int)($gateway['sim_slot'] ?? 1),
            'withDeliveryReport' => false,
            'isEncrypted'        => false,
            'state'              => 'Pending',
            'message'            => $text,
            'textMessage'        => [
                'text' => $text
            ]
        ];
    }

    echo json_encode($capcomList, JSON_UNESCAPED_UNICODE);
    exit;
}

// Endpoint H: Message Delivery Status Patch (PATCH /message OR /messages)
if ((strpos($parsedPath, '/message') !== false || strpos($parsedPath, '/messages') !== false) && in_array($httpMethod, ['PATCH', 'POST', 'PUT'])) {
    $patchList = [];
    if (is_array($jsonInput)) {
        if (isset($jsonInput['id'])) {
            $patchList[] = $jsonInput;
        } elseif (isset($jsonInput[0]) && is_array($jsonInput[0])) {
            $patchList = $jsonInput;
        }
    }

    $updStmt = $pdo->prepare("
        UPDATE sms_queue_logs 
        SET status = ?, 
            sent_at = CASE WHEN ? IN ('sent', 'delivered') THEN NOW() ELSE sent_at END,
            error_message = ?,
            gateway_ref = ?
        WHERE id = ? AND client_id = ?
    ");

    $updated = 0;
    foreach ($patchList as $p) {
        $mId = (int)($p['id'] ?? 0);
        $rawState = strtolower(trim((string)($p['state'] ?? 'sent')));
        
        $norm = 'sent';
        if (in_array($rawState, ['delivered', 'processed'])) $norm = 'delivered';
        elseif (in_array($rawState, ['failed', 'error'])) $norm = 'failed';
        elseif (in_array($rawState, ['cancelled', 'cancelling'])) $norm = 'cancelled';

        $err = null;
        if (!empty($p['recipients']) && is_array($p['recipients'])) {
            foreach ($p['recipients'] as $rcp) {
                if (!empty($rcp['error'])) {
                    $err = substr($rcp['error'], 0, 250);
                    break;
                }
            }
        }

        if ($mId > 0) {
            $updStmt->execute([$norm, $norm, $err, 'SIM' . ($gateway['sim_slot'] ?? 1), $mId, $client_id]);
            $updated++;
        }
    }

    echo json_encode(['success' => true, 'updated' => $updated]);
    exit;
}

// -------------------------------------------------------------------------
// 8. ROUTE HANDLER: CUSTOM WEBHOOK / POLLING ACTIONS (?action=...)
// -------------------------------------------------------------------------
$action = strtolower(trim($_REQUEST['action'] ?? ''));

// Action 1: PING (Heartbeat / Connectivity Test)
if ($action === 'ping' || (empty($action) && $httpMethod === 'GET' && preg_match('#^/api/sms_gateway/?$#', $parsedPath))) {
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM sms_queue_logs WHERE client_id = ? AND status = 'pending'");
    $cStmt->execute([$client_id]);
    $pendingCount = (int)$cStmt->fetchColumn();

    echo json_encode([
        'success'        => true,
        'status'         => 'online',
        'client_id'      => $client_id,
        'device_name'    => $gateway['phone_device_name'] ?: 'Android Phone',
        'sim_slot'       => (int)($gateway['sim_slot'] ?? 1),
        'pending_count'  => $pendingCount,
        'server_time'    => date('Y-m-d H:i:s'),
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
          AND (scheduled_at IS NULL OR scheduled_at <= NOW())
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

// Action 3: REPORT
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

// Fallback
echo json_encode([
    'success' => true,
    'status'  => 'online',
    'message' => 'Android SMS Gateway is ready.'
]);
exit;
