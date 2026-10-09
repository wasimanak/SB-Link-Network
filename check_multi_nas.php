<?php
require_once 'config/db.php';

echo "<h2>Checking NAS Table for Secret/Wildcard Clashes</h2>";
$stmt = $pdo->query("SELECT id, nasname, secret, description FROM nas");
$routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse; text-align: left;'>";
echo "<tr style='background: #eee;'><th>ID</th><th>NAS Name / IP</th><th>Secret</th><th>Description</th></tr>";
foreach($routers as $r) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($r['id']) . "</td>";
    echo "<td>" . htmlspecialchars($r['nasname']) . "</td>";
    echo "<td>" . htmlspecialchars($r['secret']) . "</td>";
    echo "<td>" . htmlspecialchars($r['description']) . "</td>";
    echo "</tr>";
}
echo "</table>";
?>
