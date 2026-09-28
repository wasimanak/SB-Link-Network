<?php
require_once 'C:/xampp/htdocs/SB Link Network/config/routeros_api.class.php';

$api = new RouterosAPI();
if ($api->connect('10.133.13.99', 'admin', '1122', 8728)) { // assuming 10.133.13.99 is mikrotik
    $api->write('/ip/address/print');
    $read = $api->read();
    print_r($read);
    $api->disconnect();
} else {
    echo "Could not connect to 10.133.13.99";
}
?>
