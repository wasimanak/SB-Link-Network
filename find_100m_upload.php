<?php
require 'config/db.php';

echo "<h2>Investigating '100M' Upload Mystery</h2>";

try {
    // 1. Check a few users from the screenshot (e.g. ranafaizan, Shanphotostudio, jumamajhota)
    $sample_users = ['ranafaizan', 'Shanphotostudio', 'jumamajhota', 'ranasubhanwk'];
    $in = str_repeat('?,', count($sample_users) - 1) . '?';

    echo "<h3>1. radreply Table for Sample Users:</h3>";
    $stmt = $pdo->prepare("SELECT * FROM radreply WHERE username IN ($in)");
    $stmt->execute($sample_users);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Username</th><th>Attribute</th><th>OP</th><th>Value</th></tr>";
    foreach ($rows as $r) {
        echo "<tr><td>{$r['username']}</td><td>{$r['attribute']}</td><td>{$r['op']}</td><td><strong>{$r['value']}</strong></td></tr>";
    }
    echo "</table>";

    // 2. Check their assigned groups in radusergroup and radgroupreply
    echo "<h3>2. radusergroup & radgroupreply:</h3>";
    $stmt2 = $pdo->prepare("SELECT g.username, g.groupname, gr.attribute, gr.op, gr.value 
                            FROM radusergroup g 
                            LEFT JOIN radgroupreply gr ON g.groupname = gr.groupname 
                            WHERE g.username IN ($in)");
    $stmt2->execute($sample_users);
    $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Username</th><th>Group Name</th><th>Attribute</th><th>OP</th><th>Value</th></tr>";
    foreach ($rows2 as $r) {
        echo "<tr><td>{$r['username']}</td><td>{$r['groupname']}</td><td>{$r['attribute']}</td><td>{$r['op']}</td><td><strong>{$r['value']}</strong></td></tr>";
    }
    echo "</table>";

    // 3. Search the ENTIRE radreply and radgroupreply for any '100M'
    echo "<h3>3. Searching ALL rows in radreply containing '100M':</h3>";
    $stmt3 = $pdo->query("SELECT * FROM radreply WHERE value LIKE '%100M%' LIMIT 10");
    $rows3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($rows3, true) . "</pre>";

    echo "<h3>4. Searching ALL rows in radgroupreply containing '100M':</h3>";
    $stmt4 = $pdo->query("SELECT * FROM radgroupreply WHERE value LIKE '%100M%' LIMIT 10");
    $rows4 = $stmt4->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($rows4, true) . "</pre>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
