<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');

if (!isset($_SESSION['operator_id']) || !isset($_GET['username'])) {
    echo json_encode(['error' => 'Unauthorized or missing params']);
    exit;
}

$client_id = $_SESSION['operator_id'];
session_write_close();
$username_lower = strtolower($_GET['username']);

try {
    $stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
    $stmt->execute([$client_id]);
    $nas = $stmt->fetch();

    if (!$nas) {
        echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'error' => 'No router found']);
        exit;
    }

    $api = new RouterosAPI();
    $api->timeout = 2; 
    if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
        
        $bytes_in = 0;
        $bytes_out = 0;
        $rx_bps = 0;
        $tx_bps = 0;
        $uptime_str = null;
        $found = false;
        
        // 1. Find EXACT username case from Active PPP
        $api->write('/ppp/active/print');
        $ppp_active = $api->read();
        
        $exact_mikrotik_username = null;
        if (!empty($ppp_active)) {
            foreach ($ppp_active as $conn) {
                if (isset($conn['name']) && strtolower($conn['name']) === $username_lower) {
                    $exact_mikrotik_username = $conn['name'];
                    $uptime_str = $conn['uptime'] ?? null;
                    break;
                }
            }
        }
        
        // 2. Fetch Live Speed & Total Bytes
        if ($exact_mikrotik_username) {
            $interface_names_to_try = [
                '<pppoe-' . $exact_mikrotik_username . '>',
                'pppoe-' . $exact_mikrotik_username
            ];
            
            foreach ($interface_names_to_try as $iname) {
                // Get Live Speed (Bits per second) directly from router
                $api->write('/interface/monitor-traffic', false);
                $api->write('=interface=' . $iname, false);
                $api->write('=once=', true);
                $traffic = $api->read();
                
                if (!empty($traffic) && isset($traffic[0]['rx-bits-per-second'])) {
                    $rx_bps = (float)$traffic[0]['rx-bits-per-second'];
                    $tx_bps = (float)$traffic[0]['tx-bits-per-second'];
                    
                    // Get Total Bytes for Used Volume
                    $api->write('/interface/print', false);
                    $api->write('?name=' . $iname, true);
                    $iface = $api->read();
                    if (!empty($iface)) {
                        $bytes_in = (float)($iface[0]['rx-byte'] ?? 0);
                        $bytes_out = (float)($iface[0]['tx-byte'] ?? 0);
                    }
                    
                    $found = true;
                    break;
                }
            }
        }
        
        // Return Data
        if ($found) {
            echo json_encode([
                'bytes_in' => $bytes_in, 
                'bytes_out' => $bytes_out, 
                'rx_bps' => $rx_bps, 
                'tx_bps' => $tx_bps, 
                'uptime' => $uptime_str
            ]);
        } else {
            echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'msg' => 'User not active in MikroTik']);
        }
        $api->disconnect();
    } else {
        echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'error' => 'API connection failed']);
    }
} catch (Exception $e) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'error' => $e->getMessage()]);
}
?>
