<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');

if (!isset($_SESSION['lineman_id']) || !isset($_GET['username'])) {
    echo json_encode(['error' => 'Unauthorized or missing params']);
    exit;
}

$client_id = $_SESSION['client_id'];
$username = $_GET['username'];

try {
    $stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
    $stmt->execute([$client_id]);
    $nas = $stmt->fetch();

    if (!$nas) {
        echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'error' => 'No router found']);
        exit;
    }

    $api = new RouterosAPI();
    if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
        
        $bytes_in = 0;
        $bytes_out = 0;
        $uptime_str = "Offline";
        $found = false;

        // Check Hotspot
        $api->write('/ip/hotspot/active/print', false);
        $api->write('?user=' . $username, true);
        $hotspot = $api->read();

        if (!empty($hotspot) && isset($hotspot[0]['bytes-in'])) {
            $bytes_in = (float)$hotspot[0]['bytes-in'];
            $bytes_out = (float)$hotspot[0]['bytes-out'];
            $uptime_str = $hotspot[0]['uptime'] ?? "Online";
            $found = true;
        } else {
            // Check PPPoE / Simple Queue (if rate limit exists)
            $api->write('/queue/simple/print', false);
            $api->write('?name=' . $username, true);
            $queues = $api->read();
            if (!empty($queues) && isset($queues[0]['bytes'])) {
                $bytes = explode('/', $queues[0]['bytes']);
                if (count($bytes) == 2) {
                    $bytes_in = (float)$bytes[0];
                    $bytes_out = (float)$bytes[1];
                    $found = true;
                }
            }
            // Fetch PPPoE Uptime
            if ($found) {
                $api->write('/ppp/active/print', false);
                $api->write('?name=' . $username, true);
                $ppp = $api->read();
                if (!empty($ppp) && isset($ppp[0]['uptime'])) {
                    $uptime_str = $ppp[0]['uptime'];
                } else {
                    $uptime_str = "Online";
                }
            }
        }

        if ($found) {
            echo json_encode(['bytes_in' => $bytes_in, 'bytes_out' => $bytes_out, 'uptime' => $uptime_str]);
        } else {
            echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'msg' => 'User not active', 'uptime' => 'Offline']);
        }
        $api->disconnect();
    } else {
        echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'error' => 'API connection failed']);
    }
} catch (Exception $e) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'error' => $e->getMessage()]);
}
