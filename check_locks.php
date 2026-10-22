<?php
require_once 'config/db.php';

echo "<h2>MySQL Process List (Checking for Locks/Slow Queries)</h2>";
$stmt = $pdo->query("SHOW FULL PROCESSLIST");
$processes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%; text-align: left;'>";
echo "<tr style='background: #eee;'><th>ID</th><th>User</th><th>Host</th><th>DB</th><th>Command</th><th>Time</th><th>State</th><th>Info</th></tr>";
foreach($processes as $p) {
    // Highlight slow queries or locked states
    $color = '';
    if ($p['Time'] > 2) { $color = 'background: #fff3cd;'; }
    if ($p['State'] == 'Locked' || stripos($p['State'], 'Waiting') !== false) { $color = 'background: #f8d7da;'; }
    
    echo "<tr style='{$color}'>";
    echo "<td>" . $p['Id'] . "</td>";
    echo "<td>" . $p['User'] . "</td>";
    echo "<td>" . $p['Host'] . "</td>";
    echo "<td>" . $p['db'] . "</td>";
    echo "<td>" . $p['Command'] . "</td>";
    echo "<td>" . $p['Time'] . "</td>";
    echo "<td>" . $p['State'] . "</td>";
    echo "<td>" . htmlspecialchars(substr($p['Info'], 0, 150)) . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h2>Checking radacct Table Size</h2>";
$count = $pdo->query("SELECT COUNT(*) FROM radacct")->fetchColumn();
echo "<p>Total Rows in radacct: <b>" . number_format($count) . "</b></p>";
if ($count > 50000) {
    echo "<p style='color: red;'>WARNING: Your radacct table is too large and is likely causing RADIUS timeouts! You must truncate/clean old data.</p>";
}
?>
