<?php
require 'config/db.php';

echo "<h2>Exact Byte-Level Hex Inspection of radcheck</h2>";

try {
    $stmt = $pdo->query("SELECT id, HEX(username) as hex_user, username, attribute, value FROM radcheck WHERE username LIKE 'shafaaqat16%'");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>ID</th><th>Username</th><th>Hex Bytes</th><th>Ends with '20' (Space)?</th><th>Attribute</th><th>Value</th></tr>";
    foreach ($rows as $r) {
        $has_space = (substr($r['hex_user'], -2) === '20') ? "<strong style='color:green;'>YES (0x20 Space)</strong>" : "NO";
        echo "<tr><td>{$r['id']}</td><td>'{$r['username']}'</td><td>{$r['hex_user']}</td><td>$has_space</td><td>{$r['attribute']}</td><td>{$r['value']}</td></tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
