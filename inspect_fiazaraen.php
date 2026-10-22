<?php
require 'config/db.php';
$target = 'fiazaraen';

echo "<h2>Diagnostic for '$target'</h2>";

try {
    // 1. Subscriber & Package in DB
    $stmt = $pdo->prepare("SELECT s.id, s.username, s.package_id, p.id as pkg_id, p.name as pkg_name, p.rate_limit 
                           FROM subscribers s 
                           LEFT JOIN packages p ON s.package_id = p.id 
                           WHERE s.username LIKE ?");
    $stmt->execute(["%$target%"]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<h3>1. Subscriber & Package Info:</h3>";
    if ($sub) {
        echo "<table border='1' cellpadding='8'>";
        echo "<tr><th>Username</th><td>{$sub['username']}</td></tr>";
        echo "<tr><th>Package ID</th><td>{$sub['pkg_id']}</td></tr>";
        echo "<tr><th>Package Name</th><td>{$sub['pkg_name']}</td></tr>";
        echo "<tr><th>Package Rate Limit in DB</th><td style='font-size:18px; color:blue; font-weight:bold;'>{$sub['rate_limit']}</td></tr>";
        echo "</table>";
    } else {
        echo "<p style='color:red;'>User not found in subscribers table.</p>";
    }

    // 2. radreply
    echo "<h3>2. radreply Table (Individual Limits):</h3>";
    $stmt = $pdo->prepare("SELECT * FROM radreply WHERE username LIKE ?");
    $stmt->execute(["%$target%"]);
    $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($replies) {
        echo "<table border='1' cellpadding='8'><tr><th>Attribute</th><th>OP</th><th>Value</th></tr>";
        foreach ($replies as $r) {
            echo "<tr><td>{$r['attribute']}</td><td>{$r['op']}</td><td style='color:green; font-weight:bold;'>{$r['value']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No individual limits in radreply.</p>";
    }

    // 3. radusergroup & radgroupreply
    echo "<h3>3. radusergroup & radgroupreply:</h3>";
    $stmt = $pdo->prepare("SELECT g.groupname, gr.attribute, gr.op, gr.value 
                           FROM radusergroup g 
                           LEFT JOIN radgroupreply gr ON g.groupname = gr.groupname 
                           WHERE g.username LIKE ?");
    $stmt->execute(["%$target%"]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($groups) {
        echo "<table border='1' cellpadding='8'><tr><th>Group Name</th><th>Attribute</th><th>OP</th><th>Value</th></tr>";
        foreach ($groups as $g) {
            echo "<tr><td>{$g['groupname']}</td><td>{$g['attribute']}</td><td>{$g['op']}</td><td style='color:purple; font-weight:bold;'>{$g['value']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No group assigned.</p>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
