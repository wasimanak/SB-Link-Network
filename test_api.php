<?php
require_once 'config/routeros_api.class.php';

$api = new RouterosAPI();
$api->debug = true; // Output debug info

if ($api->connect('10.133.13.104', 'admin', '1122', 8728)) {
    // Let's get all simple queues to see their names and structure
    $api->write('/queue/simple/print');
    $queues = $api->read();
    
    echo "--- QUEUES ---\n";
    print_r($queues);
    
    // Also let's check active hotspot users
    $api->write('/ip/hotspot/active/print');
    $active = $api->read();
    echo "\n--- ACTIVE HOTSPOT USERS ---\n";
    print_r($active);
    
    $api->disconnect();
} else {
    echo "Could not connect to RouterOS API.\n";
}
