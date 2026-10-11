<?php
require 'config/db.php';

echo "<h2>Strict & Secure NAS IP Management</h2>";

try {
    // 1. Remove any wildcard (0.0.0.0/0) immediately as requested
    $pdo->exec("DELETE FROM nas WHERE nasname = '0.0.0.0/0'");
    echo "<p style='color:blue;'>1. Wildcard (0.0.0.0/0) completely removed. Strict IP matching is enforced.</p>";

    // 2. Fetch all registered NAS routers
    $stmt = $pdo->query("SELECT * FROM nas");
    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>Currently Registered Router IPs in FreeRADIUS:</h3>";
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr style='background:#f0f0f0;'><th>ID</th><th>Router IP (nasname)</th><th>Name</th><th>Client ID</th><th>Secret</th></tr>";
    $registered_ips = [];
    foreach ($routers as $r) {
        $registered_ips[] = $r['nasname'];
        echo "<tr><td>{$r['id']}</td><td><strong>{$r['nasname']}</strong></td><td>{$r['shortname']}</td><td>{$r['client_id']}</td><td>{$r['secret']}</td></tr>";
    }
    echo "</table>";

    // 3. Ensure 119.156.28.215 (Piplan Router) is strictly registered
    $piplan_ip = '119.156.28.215';
    if (!in_array($piplan_ip, $registered_ips)) {
        $default_secret = !empty($routers) ? $routers[0]['secret'] : '123456';
        $pdo->prepare("INSERT INTO nas (nasname, shortname, type, secret, client_id) VALUES (?, 'Piplan-CCR', 'other', ?, 2)")
            ->execute([$piplan_ip, $default_secret]);
        echo "<h3 style='color:green;'>Added Piplan Router IP strictly: <strong>$piplan_ip</strong> (Secret: $default_secret)</h3>";
    } else {
        echo "<p style='color:green;'>Piplan Router IP (<strong>$piplan_ip</strong>) is already strictly registered in database.</p>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
