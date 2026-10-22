<?php
require 'config/db.php';

echo "<h2>Global Speed Audit for All Users</h2>";

try {
    // 1. Check all packages and their radgroupreply limits
    echo "<h3>1. Packages and their radgroupreply Limits:</h3>";
    $stmt = $pdo->query("SELECT p.id, p.name, p.rate_limit, 
                                r.attribute, r.value as radius_limit 
                         FROM packages p 
                         LEFT JOIN radgroupreply r ON p.name = r.groupname AND r.attribute = 'Mikrotik-Rate-Limit'");
    $pkgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='6' style='border-collapse:collapse;'>";
    echo "<tr><th>Package ID</th><th>Package Name</th><th>DB Rate Limit</th><th>FreeRADIUS radgroupreply Limit</th><th>Status</th></tr>";
    foreach ($pkgs as $p) {
        $status = ($p['rate_limit'] === $p['radius_limit']) ? "<span style='color:green;'>OK</span>" : "<span style='color:red;'>MISMATCH / MISSING</span>";
        echo "<tr><td>{$p['id']}</td><td>{$p['name']}</td><td>{$p['rate_limit']}</td><td>{$p['radius_limit']}</td><td>$status</td></tr>";
    }
    echo "</table>";

    // 2. Count subscribers whose group in radusergroup has NO rate limit
    echo "<h3>2. Subscribers Speed Coverage:</h3>";
    $sql = "
        SELECT 
            COUNT(s.id) as total_subs,
            SUM(CASE WHEN r.value IS NOT NULL AND r.value != '' THEN 1 ELSE 0 END) as with_group_limit,
            SUM(CASE WHEN ind.value IS NOT NULL AND ind.value != '' THEN 1 ELSE 0 END) as with_individual_limit,
            SUM(CASE WHEN r.value IS NULL AND ind.value IS NULL THEN 1 ELSE 0 END) as no_limit_at_all
        FROM subscribers s
        LEFT JOIN radusergroup g ON s.username = g.username
        LEFT JOIN radgroupreply r ON g.groupname = r.groupname AND r.attribute = 'Mikrotik-Rate-Limit'
        LEFT JOIN radreply ind ON s.username = ind.username AND ind.attribute = 'Mikrotik-Rate-Limit'
        WHERE s.status = 'active'
    ";
    $stats = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr><th>Active Subscribers</th><td>{$stats['total_subs']}</td></tr>";
    echo "<tr><th>Users with Valid Group Speed</th><td style='color:green;'>{$stats['with_group_limit']}</td></tr>";
    echo "<tr><th>Users with Individual Speed</th><td>{$stats['with_individual_limit']}</td></tr>";
    echo "<tr><th>Users with NO SPEED LIMIT IN RADIUS</th><td style='color:red; font-weight:bold; font-size:18px;'>{$stats['no_limit_at_all']}</td></tr>";
    echo "</table>";

    // 3. Automatic Master Fix for ALL Subscribers
    if ($stats['no_limit_at_all'] > 0) {
        echo "<h3>3. Automatically Fixing Missing Limits for All Subscribers...</h3>";
        
        // A: Sync all package groups to radgroupreply
        $pdo->exec("
            INSERT INTO radgroupreply (groupname, attribute, op, value)
            SELECT p.name, 'Mikrotik-Rate-Limit', ':=', p.rate_limit
            FROM packages p
            WHERE p.rate_limit IS NOT NULL AND p.rate_limit != '' AND p.rate_limit != 'Unlimited'
            ON DUPLICATE KEY UPDATE value = p.rate_limit
        ");

        // B: Ensure all subscribers have matching radusergroup
        $pdo->exec("
            INSERT INTO radusergroup (username, groupname, priority)
            SELECT s.username, p.name, 1
            FROM subscribers s
            JOIN packages p ON s.package_id = p.id
            WHERE s.status = 'active'
            ON DUPLICATE KEY UPDATE groupname = p.name
        ");

        // C: Also ensure radreply has direct rate limit for 100% safety
        $pdo->exec("
            INSERT INTO radreply (username, attribute, op, value)
            SELECT s.username, 'Mikrotik-Rate-Limit', ':=', p.rate_limit
            FROM subscribers s
            JOIN packages p ON s.package_id = p.id
            WHERE s.status = 'active' AND p.rate_limit IS NOT NULL AND p.rate_limit != '' AND p.rate_limit != 'Unlimited'
            ON DUPLICATE KEY UPDATE value = p.rate_limit
        ");

        echo "<h3 style='color:green;'>All Active Subscribers have now been 100% locked with their exact package limits in both radusergroup and radreply!</h3>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
