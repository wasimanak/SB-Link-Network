<?php
require 'config/db.php';

$target = 'ilyasyd';

echo "<h2>Bandwidth & Rate Limit Diagnostic for '$target'</h2>";

try {
    // 1. Fetch Subscriber Info
    $stmt = $pdo->prepare("SELECT s.id, s.username, s.package_id, p.name as pkg_name, p.rate_limit, p.speed_scheduler 
                           FROM subscribers s 
                           LEFT JOIN packages p ON s.package_id = p.id 
                           WHERE s.username LIKE ?");
    $stmt->execute(["%$target%"]);
    $sub = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<h3>1. Subscriber & Package Info:</h3>";
    if ($sub) {
        echo "<table border='1' cellpadding='8'>";
        echo "<tr><th>Username</th><td>{$sub['username']}</td></tr>";
        echo "<tr><th>Package Name</th><td>{$sub['pkg_name']}</td></tr>";
        echo "<tr><th>Package Rate Limit in DB</th><td style='font-size:18px; color:blue; font-weight:bold;'>{$sub['rate_limit']}</td></tr>";
        echo "<tr><th>Speed Scheduler</th><td>" . htmlspecialchars($sub['speed_scheduler']) . "</td></tr>";
        echo "</table>";
    } else {
        echo "<p style='color:red;'>User not found in subscribers table.</p>";
    }

    // 2. Fetch radreply attributes
    echo "<h3>2. radreply Table (Individual Limits):</h3>";
    $stmt = $pdo->prepare("SELECT * FROM radreply WHERE username LIKE ?");
    $stmt->execute(["%$target%"]);
    $replies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($replies) {
        echo "<table border='1' cellpadding='8'>";
        echo "<tr><th>Attribute</th><th>OP</th><th>Value</th></tr>";
        foreach ($replies as $r) {
            echo "<tr><td>{$r['attribute']}</td><td>{$r['op']}</td><td style='font-size:18px; color:green; font-weight:bold;'>{$r['value']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No individual limits in radreply.</p>";
    }

    // 3. Fetch radusergroup and radgroupreply
    echo "<h3>3. radgroupreply Table (Package Group Limits):</h3>";
    $stmt = $pdo->prepare("SELECT g.groupname, gr.attribute, gr.op, gr.value 
                           FROM radusergroup g 
                           LEFT JOIN radgroupreply gr ON g.groupname = gr.groupname 
                           WHERE g.username LIKE ?");
    $stmt->execute(["%$target%"]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($groups) {
        echo "<table border='1' cellpadding='8'>";
        echo "<tr><th>Group Name</th><th>Attribute</th><th>OP</th><th>Value</th></tr>";
        foreach ($groups as $g) {
            echo "<tr><td>{$g['groupname']}</td><td>{$g['attribute']}</td><td>{$g['op']}</td><td style='font-size:18px; color:purple; font-weight:bold;'>{$g['value']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No group reply limits found.</p>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
