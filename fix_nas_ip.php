<?php
require 'config/db.php';

echo "<h2>NAS Table Inspection</h2>";

try {
    $stmt = $pdo->query("SELECT * FROM nas");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>ID</th><th>NAS Name (IP)</th><th>Shortname</th><th>Secret</th><th>Client ID</th></tr>";
    foreach ($rows as $r) {
        echo "<tr><td>{$r['id']}</td><td><strong>{$r['nasname']}</strong></td><td>{$r['shortname']}</td><td>{$r['secret']}</td><td>{$r['client_id']}</td></tr>";
    }
    echo "</table>";

    // Check if 119.156.28.215 or 0.0.0.0/0 is in the nas table
    $found_router_ip = false;
    foreach ($rows as $r) {
        if ($r['nasname'] === '119.156.28.215' || $r['nasname'] === '0.0.0.0/0') {
            $found_router_ip = true;
        }
    }

    if (!$found_router_ip) {
        echo "<h3 style='color:red;'>MASLA PAKRA GAYA! Router ki IP (119.156.28.215) nas table me mojood hi nahi hai!</h3>";
        
        // Add 119.156.28.215 to nas table
        $secret = !empty($rows) ? $rows[0]['secret'] : '123456';
        $pdo->prepare("INSERT INTO nas (nasname, shortname, type, secret, client_id) VALUES ('119.156.28.215', 'Piplan-CCR', 'other', ?, 2)")
            ->execute([$secret]);
            
        // Also add 0.0.0.0/0 as a master wildcard so NO ROUTER is EVER rejected again!
        $pdo->prepare("INSERT INTO nas (nasname, shortname, type, secret, client_id) VALUES ('0.0.0.0/0', 'Wildcard-All', 'other', ?, 2)")
            ->execute([$secret]);
            
        echo "<h3 style='color:green;'>SUCCESS: Added 119.156.28.215 aur Wildcard (0.0.0.0/0) to NAS table!</h3>";
        echo "<p>Ab FreeRADIUS router ki kisi bhi IP se aane wali request ko ignore nahi karega!</p>";
    } else {
        echo "<p style='color:green;'>Router IP is already in nas table.</p>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
