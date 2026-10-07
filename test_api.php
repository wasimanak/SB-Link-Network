<?php
require_once 'config/db.php';
require_once 'config/routeros_api.class.php';

$client_id = 1; // Assuming 1
$username = 'test'; // dummy

$stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
$stmt->execute([$client_id]);
$nas = $stmt->fetch();

if (!$nas) die("No NAS\n");

$api = new RouterosAPI();
if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
    $api->write('/interface/print', false);
    $api->write('?name=<pppoe-test>', true);
    $iface = $api->read();
    print_r($iface);
    $api->disconnect();
} else {
    echo "Connection failed\n";
}
?>
