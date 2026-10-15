<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['operator_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$client_id = $_SESSION['operator_id'];
$nas_id = (int)($_POST['nas_id'] ?? $_GET['nas_id'] ?? 0);

if ($nas_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Please select a valid router.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, nasname, shortname, api_port, api_user, api_password FROM nas WHERE id = ? AND client_id = ?");
$stmt->execute([$nas_id, $client_id]);
$nas = $stmt->fetch();

if (!$nas) {
    echo json_encode(['status' => 'error', 'message' => 'Router not found or not assigned to your account.']);
    exit;
}

$api_port = !empty($nas['api_port']) ? (int)$nas['api_port'] : 8728;
$api_user = $nas['api_user'] ?: 'admin';
$api_pass = $nas['api_password'] ?: '';

$API = new RouterosAPI();
$API->timeout = 3;
$API->attempts = 1;
$API->delay = 1;
error_reporting(0);

if ($API->connect($nas['nasname'], $api_user, $api_pass, $api_port)) {
    // 1. Fetch all interfaces
    $raw_interfaces = $API->comm('/interface/print');
    
    // 2. Fetch existing IP pools on router
    $raw_pools = $API->comm('/ip/pool/print');
    
    // 3. Fetch default routes / gateways if possible
    $raw_routes = $API->comm('/ip/route/print', ['?dst-address' => '0.0.0.0/0']);
    
    $API->disconnect();
    
    $interfaces = [];
    if (is_array($raw_interfaces)) {
        foreach ($raw_interfaces as $iface) {
            $name = $iface['name'] ?? '';
            if (empty($name)) continue;
            
            $interfaces[] = [
                'name' => $name,
                'type' => $iface['type'] ?? 'ether',
                'running' => ($iface['running'] ?? 'false') === 'true',
                'disabled' => ($iface['disabled'] ?? 'false') === 'true',
                'comment' => $iface['comment'] ?? '',
                'mac_address' => $iface['mac-address'] ?? ''
            ];
        }
    }
    
    $pools = [];
    if (is_array($raw_pools)) {
        foreach ($raw_pools as $p) {
            if (!empty($p['name'])) {
                $pools[] = [
                    'name' => $p['name'],
                    'ranges' => $p['ranges'] ?? ''
                ];
            }
        }
    }
    
    $gateways = [];
    if (is_array($raw_routes)) {
        foreach ($raw_routes as $r) {
            if (!empty($r['gateway'])) {
                $gateways[] = [
                    'gateway' => $r['gateway'],
                    'routing_mark' => $r['routing-mark'] ?? 'main'
                ];
            }
        }
    }

    echo json_encode([
        'status' => 'success',
        'nas_id' => $nas['id'],
        'nas_name' => $nas['shortname'] ?: $nas['nasname'],
        'nas_ip' => $nas['nasname'],
        'interfaces' => $interfaces,
        'pools' => $pools,
        'gateways' => $gateways
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'nas_id' => $nas['id'],
        'nas_name' => $nas['shortname'] ?: $nas['nasname'],
        'message' => 'Could not connect to router (' . $nas['nasname'] . ':' . $api_port . '). Check if Router is online and API service (port 8728) is enabled in MikroTik IP > Services.'
    ]);
}
