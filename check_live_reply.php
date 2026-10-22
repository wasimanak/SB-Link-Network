<?php
require 'config/db.php';

echo "<h2>Live Status for 'shafaaqat16'</h2>";

try {
    // 1. Get the last 3 attempts in radpostauth
    $stmt = $pdo->prepare("SELECT * FROM radpostauth WHERE username LIKE 'shafaaqat16%' ORDER BY id DESC LIMIT 5");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>Recent radpostauth Entries:</h3><table border='1' cellpadding='8'>";
    echo "<tr><th>ID</th><th>Username</th><th>Pass</th><th>Reply</th><th>Auth Date</th></tr>";
    foreach ($rows as $r) {
        $color = ($r['reply'] == 'Access-Accept') ? 'green' : 'red';
        echo "<tr><td>{$r['id']}</td><td>'{$r['username']}'</td><td>'{$r['pass']}'</td><td style='color:$color; font-weight:bold;'>{$r['reply']}</td><td>{$r['authdate']}</td></tr>";
    }
    echo "</table>";

    // 2. If it's Access-Accept, then FreeRADIUS is ACCEPTING it, and MikroTik is dropping it!
    if (!empty($rows) && $rows[0]['reply'] == 'Access-Accept') {
        echo "<h3 style='color:green;'>REVOLUTIONARY DISCOVERY: FreeRADIUS ne 'Access-Accept' (Pass) kar diya hai!</h3>";
        echo "<p style='font-size:16px;'>Yani RADIUS user ko allow kar raha hai, lekin <strong>MikroTik Router</strong> usay disconnect kar raha hai!</p>";
    } elseif (!empty($rows) && $rows[0]['reply'] == 'Access-Reject') {
        echo "<h3 style='color:red;'>FreeRADIUS abhi bhi 'Access-Reject' bhej raha hai.</h3>";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
