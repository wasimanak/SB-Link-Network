<?php
require_once 'config/db.php';

echo "<h2>NAS Table Configuration</h2>";
$stmt = $pdo->query("SELECT id, nasname, shortname, secret, description FROM nas");
$nas = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%; text-align: left;'>";
echo "<tr style='background: #eee;'><th>ID</th><th>NAS IP / Subnet</th><th>Shortname</th><th>Secret</th><th>Description</th></tr>";
foreach($nas as $n) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($n['id']) . "</td>";
    echo "<td>" . htmlspecialchars($n['nasname']) . "</td>";
    echo "<td>" . htmlspecialchars($n['shortname']) . "</td>";
    echo "<td>" . htmlspecialchars($n['secret']) . "</td>";
    echo "<td>" . htmlspecialchars($n['description']) . "</td>";
    echo "</tr>";
}
echo "</table>";
?>
