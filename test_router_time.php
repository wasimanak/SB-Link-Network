<?php
require_once 'C:/xampp/htdocs/SB Link Network/config/db.php';
require_once 'C:/xampp/htdocs/SB Link Network/config/routeros_api.class.php';

$client_id = 7;
$stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
$stmt->execute([$client_id]);
$nas = $stmt->fetch();

if ($nas) {
    $api = new RouterosAPI();
    $api->debug = true; // Turn on debugging to see why it fails
    if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
        $api->write('/system/clock/print');
        $clock = $api->read();
        print_r($clock);
        $api->disconnect();
    } else {
        echo "Failed to connect to " . $nas['nasname'];
    }
}
?>
