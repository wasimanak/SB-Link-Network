<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');

if (!isset($_SESSION['operator_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$client_id = $_SESSION['operator_id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
    $stmt->execute([$client_id]);
    $nas = $stmt->fetch();

    if (!$nas) {
        echo json_encode(['error' => 'No router found']);
        exit;
    }

    $api = new RouterosAPI();
    // Reduce timeout so it doesn't hang forever if router is offline
    $api->timeout = 2; 
    
    if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
        $api->write('/system/clock/print', true);
        $clock = $api->read();
        $api->disconnect();

        if (!empty($clock) && isset($clock[0]['time']) && isset($clock[0]['date'])) {
            echo json_encode([
                'success' => true,
                'time' => $clock[0]['time'],
                'date' => $clock[0]['date'],
                'time_zone' => $clock[0]['time-zone-name'] ?? 'Unknown'
            ]);
        } else {
            echo json_encode(['error' => 'Could not read clock']);
        }
    } else {
        echo json_encode(['error' => 'API connection failed']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
