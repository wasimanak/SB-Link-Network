<?php
require 'config/db.php';

echo "<h2>Live Status & Complete Sync</h2>";

try {
    // 1. Ensure BOTH variations (with space AND without space) exist in radcheck
    $targets = ['shafaaqat16', 'shafaaqat16 '];
    foreach ($targets as $u) {
        $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', '1234')")->execute([$u]);
        
        $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$u]);
        $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, '4Mbps (Liaqatabad)', 1)")->execute([$u]);
    }
    echo "<p style='color:green;'>1. Both 'shafaaqat16' and 'shafaaqat16 ' are 100% active in radcheck and radusergroup.</p>";

    // 2. Check the very latest attempts in radpostauth
    echo "<h3>Latest 5 Attempts for shafaaqat16 in radpostauth:</h3>";
    $stmt = $pdo->query("SELECT * FROM radpostauth WHERE username LIKE 'shafaaqat16%' ORDER BY id DESC LIMIT 5");
    $rows = $stmt->fetchAll();
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>ID</th><th>Username</th><th>Reply</th><th>Auth Date</th></tr>";
    foreach ($rows as $r) {
        $color = ($r['reply'] == 'Access-Accept') ? 'green' : 'red';
        echo "<tr><td>{$r['id']}</td><td>'{$r['username']}'</td><td style='color:$color; font-weight:bold;'>{$r['reply']}</td><td>{$r['authdate']}</td></tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
