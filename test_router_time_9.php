<?php
require_once 'C:/xampp/htdocs/SB Link Network/config/db.php';
require_once 'C:/xampp/htdocs/SB Link Network/config/routeros_api.class.php';

$stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = 9 LIMIT 1");
$stmt->execute();
$nas = $stmt->fetch();

if ($nas) {
    echo "Connecting to: " . $nas['nasname'] . ":" . $nas['api_port'] . "\n";
    $api = new RouterosAPI();
    $api->timeout = 2; // Don't hang forever
    
    $start = microtime(true);
    if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
        echo "Connected in " . (microtime(true) - $start) . "s\n";
        $api->write('/system/clock/print');
        $clock = $api->read();
        print_r($clock);
        $api->disconnect();
    } else {
        echo "Failed to connect to " . $nas['nasname'] . " in " . (microtime(true) - $start) . "s\n";
    }
}
?>
