<?php
require_once 'config/db.php';

$username = 'zahidiqbalwawna19ml';

echo "<h2>Checking user: $username</h2>";

echo "<h3>radcheck table:</h3>";
$stmt = $pdo->query("SELECT attribute, value FROM radcheck WHERE username = '$username'");
$radcheck = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%; text-align: left;'>";
echo "<tr style='background: #eee;'><th>Attribute</th><th>Value</th></tr>";
foreach($radcheck as $r) {
    echo "<tr><td>" . htmlspecialchars($r['attribute']) . "</td><td>" . htmlspecialchars($r['value']) . "</td></tr>";
}
echo "</table>";

echo "<h3>subscribers table:</h3>";
$stmt = $pdo->query("SELECT status, expiry_date, package_id FROM subscribers WHERE username = '$username'");
$sub = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%; text-align: left;'>";
echo "<tr style='background: #eee;'><th>Status</th><th>Expiry Date</th><th>Package ID</th></tr>";
foreach($sub as $s) {
    echo "<tr><td>" . htmlspecialchars($s['status']) . "</td><td>" . htmlspecialchars($s['expiry_date']) . "</td><td>" . htmlspecialchars($s['package_id']) . "</td></tr>";
}
echo "</table>";
?>
