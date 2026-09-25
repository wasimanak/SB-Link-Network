<?php
require_once 'config/db.php';
require_once 'config/routeros_api.class.php';

$API = new RouterosAPI();
$stmt = $pdo->query("SELECT * FROM nas LIMIT 1");
$nas = $stmt->fetch();

echo "Connecting to " . $nas['nasname'] . "...\n";
if ($API->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
    echo "Connected.\n";
    $secrets = $API->comm('/ppp/secret/print');
    echo "Found " . count($secrets) . " PPP secrets.\n";
    $API->disconnect();
}
