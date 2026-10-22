<?php
require 'config/db.php';

$pkg_name = '7Mbps (Piplan)';

echo "<h2>Diagnostic for Package: '$pkg_name'</h2>";

try {
    // 1. Package details
    $stmt = $pdo->prepare("SELECT id, name, rate_limit, speed_scheduler FROM packages WHERE name = ?");
    $stmt->execute([$pkg_name]);
    $pkg = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<h3>1. Package in packages table:</h3>";
    print_r($pkg);

    // 2. radgroupreply for this package
    echo "<h3>2. radgroupreply for '$pkg_name':</h3>";
    $stmt = $pdo->prepare("SELECT * FROM radgroupreply WHERE groupname = ?");
    $stmt->execute([$pkg_name]);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    // 3. Find all subscribers subscribed to this package
    if ($pkg) {
        $stmt = $pdo->prepare("SELECT s.id, s.username, s.status, g.groupname, r.value as individual_limit 
                               FROM subscribers s 
                               LEFT JOIN radusergroup g ON s.username = g.username 
                               LEFT JOIN radreply r ON s.username = r.username AND r.attribute = 'Mikrotik-Rate-Limit'
                               WHERE s.package_id = ?");
        $stmt->execute([$pkg['id']]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<h3>3. Subscribers on this package (" . count($subs) . " users):</h3>";
        echo "<table border='1' cellpadding='6' style='border-collapse:collapse;'>";
        echo "<tr><th>Username</th><th>Status</th><th>radusergroup</th><th>radreply (Individual Limit)</th></tr>";
        foreach ($subs as $s) {
            echo "<tr><td>{$s['username']}</td><td>{$s['status']}</td><td>{$s['groupname']}</td><td style='color:red; font-weight:bold;'>{$s['individual_limit']}</td></tr>";
        }
        echo "</table>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
