<?php
require_once 'config/db.php';

echo "<h2>Analyzing Session Authentication Source</h2>";

$stmt = $pdo->query("SELECT r.radacctid, r.username, r.acctstarttime, r.callingstationid, 
    (SELECT authdate FROM radpostauth p WHERE p.username = r.username AND p.reply = 'Access-Accept' AND p.authdate <= r.acctstarttime ORDER BY authdate DESC LIMIT 1) as last_auth_time
    FROM radacct r 
    WHERE r.acctstoptime IS NULL ORDER BY r.acctstarttime DESC LIMIT 20");

$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse; text-align: left;'>";
echo "<tr style='background: #eee;'><th>Acct ID</th><th>Username</th><th>Session Start Time</th><th>MAC Address</th><th>New RADIUS Auth Time</th><th>Authenticated By</th></tr>";
foreach($sessions as $s) {
    $authBy = "Old RADIUS (No matching auth found)";
    if ($s['last_auth_time']) {
        // If auth happened within 60 seconds of session start, it's definitely this RADIUS
        $timeDiff = strtotime($s['acctstarttime']) - strtotime($s['last_auth_time']);
        if ($timeDiff >= 0 && $timeDiff <= 120) {
            $authBy = "<span style='color: green; font-weight: bold;'>New RADIUS</span>";
        }
    }
    
    echo "<tr>";
    echo "<td>" . $s['radacctid'] . "</td>";
    echo "<td>" . $s['username'] . "</td>";
    echo "<td>" . $s['acctstarttime'] . "</td>";
    echo "<td>" . $s['callingstationid'] . "</td>";
    echo "<td>" . ($s['last_auth_time'] ?: 'Never') . "</td>";
    echo "<td>" . $authBy . "</td>";
    echo "</tr>";
}
echo "</table>";
?>
