<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

require_once 'C:/xampp/htdocs/SB Link Network/config/routeros_api.class.php';

// Find NAS details to connect to MikroTik
$nas = $pdo->query("SELECT * FROM nas LIMIT 1")->fetch();

if ($nas) {
    $api = new RouterosAPI();
    if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], 8728)) {
        // Fetch Hotspot users
        $api->write('/ip/hotspot/user/print');
        $hs_users = $api->read();
        
        $count = 0;
        foreach ($hs_users as $u) {
            $uname = $u['name'] ?? '';
            if ($uname && $uname !== 'default') {
                $pdo->prepare("UPDATE subscribers SET service_type = 'hotspot' WHERE username = ?")->execute([$uname]);
                $count++;
            }
        }
        $api->disconnect();
        echo "Successfully updated $count existing Hotspot users to 'hotspot' service_type.\n";
    } else {
        echo "Failed to connect to MikroTik to fix existing users.\n";
    }
} else {
    echo "No NAS configured to sync from.\n";
}
?>
