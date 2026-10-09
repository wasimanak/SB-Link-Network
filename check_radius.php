<?php
require_once 'config/db.php';
$stmt = $pdo->query("SELECT * FROM nas");
$routers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Configured RADIUS Routers (NAS Table)</h3>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>NAS Name/IP</th><th>Shortname</th><th>Secret</th><th>Description</th></tr>";
foreach ($routers as $r) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($r['nasname']) . "</td>";
    echo "<td>" . htmlspecialchars($r['shortname']) . "</td>";
    echo "<td>" . htmlspecialchars($r['secret']) . "</td>";
    echo "<td>" . htmlspecialchars($r['description']) . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Check FreeRADIUS Service Status (Linux VPS only)</h3>";
$status = shell_exec("sudo systemctl status freeradius 2>&1 || sudo service freeradius status 2>&1");
echo "<pre>$status</pre>";

echo "<h3>Check FreeRADIUS Logs for Errors (Last 20 lines)</h3>";
$logs = shell_exec("sudo tail -n 20 /var/log/freeradius/radius.log 2>&1");
echo "<pre>$logs</pre>";
?>
