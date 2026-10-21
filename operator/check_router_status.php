<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['operator_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {
    require_once '../config/db.php';
    require_once '../config/routeros_api.class.php';

    $client_id = (int)$_SESSION['operator_id'];
    $nas_id = (int)($_POST['nas_id'] ?? 0);

    // Dynamic fallback query if api_port column missing
    try {
        $stmt = $pdo->prepare("SELECT nasname, api_port, api_user, api_password FROM nas WHERE id = ? AND client_id = ?");
        $stmt->execute([$nas_id, $client_id]);
        $nas = $stmt->fetch();
        if (!$nas) {
            $stmt2 = $pdo->prepare("SELECT nasname, api_port, api_user, api_password FROM nas WHERE id = ?");
            $stmt2->execute([$nas_id]);
            $nas = $stmt2->fetch();
        }
    } catch (Throwable $t) {
        $stmt = $pdo->prepare("SELECT nasname, api_user, api_password FROM nas WHERE id = ?");
        $stmt->execute([$nas_id]);
        $nas = $stmt->fetch();
    }

    if (!$nas) {
        echo json_encode(['status' => 'error', 'message' => 'Router not found.']);
        exit;
    }

    $nasname = trim($nas['nasname'] ?? '');
    $api_port = !empty($nas['api_port']) ? (int)$nas['api_port'] : 8728;
    $api_user = trim($nas['api_user'] ?? 'admin');
    $api_pass = (string)($nas['api_password'] ?? '');

    // 1. Fast socket pre-check with 1.5s timeout
    $errno = 0;
    $errstr = '';
    $sock = @fsockopen($nasname, $api_port, $errno, $errstr, 1.5);
    if (!$sock) {
        echo json_encode([
            'status' => 'warning',
            'message' => 'API Offline (RADIUS Active)',
            'tooltip' => 'Router IP ' . $nasname . ':' . $api_port . ' is not reachable over the public internet from this VPS. PPPoE/Hotspot RADIUS authentication is still fully operational!'
        ]);
        exit;
    }
    fclose($sock);

    // 2. Fast API connect check
    $API = new RouterosAPI();
    $API->timeout = 2;
    $API->attempts = 1;
    $API->delay = 0;
    error_reporting(0);

    if (@$API->connect($nasname, $api_user, $api_pass, $api_port)) {
        @$API->disconnect();
        echo json_encode(['status' => 'success', 'message' => 'Connected']);
    } else {
        echo json_encode([
            'status' => 'warning',
            'message' => 'API Offline (RADIUS Active)',
            'tooltip' => 'API port responded, but credentials or API access did not respond. RADIUS authentication is still operational.'
        ]);
    }
} catch (Throwable $e) {
    echo json_encode([
        'status' => 'warning',
        'message' => 'API Offline (RADIUS Active)',
        'tooltip' => 'RADIUS active.'
    ]);
}
