<?php
session_start();
if (!isset($_SESSION['operator_logged_in'])) {
    http_response_code(401);
    exit;
}

$client_id = (int)$_SESSION['operator_id'];

require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');

$log_file = 'time_debug.log';
file_put_contents($log_file, "Client ID: $client_id\n", FILE_APPEND);

try {
    $stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
    $stmt->execute([$client_id]);
    $nas = $stmt->fetch();

    if ($nas) {
        $api = new RouterosAPI();
        $api->timeout = 2;
        $port = !empty($nas['api_port']) ? $nas['api_port'] : 8728;
        if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $port)) {
            $api->write('/system/clock/print');
            $clock = $api->read();
            $api->disconnect();

            file_put_contents($log_file, "Router clock: " . print_r($clock, true) . "\n", FILE_APPEND);

            if (isset($clock[0]['time']) && isset($clock[0]['date'])) {
                $raw_date = $clock[0]['date'];
                if (strpos($raw_date, '/') !== false) {
                    $parts = explode('/', $raw_date);
                    if (count($parts) == 3) {
                        $raw_date = $parts[2] . '-' . $parts[0] . '-' . $parts[1];
                    }
                }
                
                $out = json_encode(['status' => 'success', 'time_str' => $raw_date . 'T' . $clock[0]['time']]);
                file_put_contents($log_file, "Output: $out\n", FILE_APPEND);
                echo $out;
                exit;
            }
        } else {
            file_put_contents($log_file, "Failed to connect to router.\n", FILE_APPEND);
        }
    } else {
        file_put_contents($log_file, "No NAS found.\n", FILE_APPEND);
    }
} catch (Exception $e) {
    file_put_contents($log_file, "Exception: " . $e->getMessage() . "\n", FILE_APPEND);
}

$out = json_encode(['status' => 'error', 'time_str' => date('Y-m-d\TH:i:s')]);
file_put_contents($log_file, "Fallback Output: $out\n", FILE_APPEND);
echo $out;
?>
