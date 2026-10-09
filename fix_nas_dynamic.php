<?php
require_once 'config/db.php';

echo "<h2>Fixing NAS IP (Dynamic/Multi-WAN)</h2>";

// Insert a wildcard NAS entry to allow requests from any IP of the MikroTik (Useful for Load Balancing / Changing IPs)
$check = $pdo->query("SELECT COUNT(*) FROM nas WHERE nasname = '0.0.0.0/0'")->fetchColumn();
if ($check == 0) {
    $stmt = $pdo->prepare("INSERT INTO nas (nasname, shortname, type, ports, secret, server, community, description) VALUES ('0.0.0.0/0', 'Any-MikroTik', 'other', NULL, '123456', NULL, NULL, 'Wildcard for Dynamic IPs')");
    $stmt->execute();
    echo "<p style='color: green;'>Successfully added 0.0.0.0/0 to allow MikroTik to connect from ANY dynamic IP (like 223.123.73.x).</p>";
} else {
    echo "<p>Wildcard NAS already exists.</p>";
}

// Ensure the specific IPs from the log are covered just in case
$ips = ['223.123.73.0/24'];
foreach($ips as $ip) {
    $check = $pdo->query("SELECT COUNT(*) FROM nas WHERE nasname = '$ip'")->fetchColumn();
    if ($check == 0) {
        $stmt = $pdo->prepare("INSERT INTO nas (nasname, shortname, type, ports, secret, server, community, description) VALUES ('$ip', 'MikroTik-WAN2', 'other', NULL, '123456', NULL, NULL, 'Added to fix unknown client error')");
        $stmt->execute();
    }
}
echo "<p style='color: green;'>Successfully added 223.123.73.0/24 subnet to NAS.</p>";

echo "<h3>CRITICAL: You must restart FreeRADIUS on your VPS now!</h3>";
?>
