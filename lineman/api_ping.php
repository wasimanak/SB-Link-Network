<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');

if (!isset($_SESSION['rm_id']) || !isset($_GET['ip'])) {
    echo json_encode(['error' => 'Unauthorized or missing IP']);
    exit;
}

$client_id = $_SESSION['client_id'];
$ip = trim($_GET['ip']);

try {
    // Get NAS for the client
    $stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
    $stmt->execute([$client_id]);
    $nas = $stmt->fetch();

    if (!$nas) {
        echo json_encode(['error' => 'MikroTik Router not configured.']);
        exit;
    }

    $api = new RouterosAPI();
    $api->timeout = 3;
    if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
        
        $api->write('/ping', false);
        $api->write('=address=' . $ip, false);
        $api->write('=count=4', true);
        
        $response = $api->read();
        $api->disconnect();
        
        $sent = 0;
        $received = 0;
        $total_rtt = 0;
        
        foreach ($response as $r) {
            if (isset($r['time'])) {
                $received++;
                $time_ms = (int)str_replace('ms', '', $r['time']);
                $total_rtt += $time_ms;
            }
            $sent++;
        }
        
        $loss = $sent > 0 ? (($sent - $received) / $sent) * 100 : 100;
        $avg_rtt = $received > 0 ? round($total_rtt / $received) : 0;
        
        $status = 'ok';
        $msg = 'Line bilkul theek hai (Perfect Signal).';
        
        if ($loss == 100) {
            $status = 'critical';
            $msg = 'Router band hai ya fiber taar (cable) down hai (100% Loss).';
        } elseif ($loss > 0) {
            $status = 'warning';
            $msg = "Fibre Bend hai ya Signal bohat weak (kam) aa rahay hain (" . round($loss) . "% Loss).";
        } elseif ($avg_rtt > 50) {
            $status = 'warning';
            $msg = "Latency high hai ($avg_rtt ms). Ya tou piche se link weak hai ya user ka router load mein hai.";
        }
        
        echo json_encode([
            'success' => true,
            'sent' => $sent,
            'received' => $received,
            'loss' => $loss,
            'avg_rtt' => $avg_rtt,
            'status' => $status,
            'msg' => $msg
        ]);
        
    } else {
        echo json_encode(['error' => 'Cannot connect to MikroTik API.']);
    }

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
