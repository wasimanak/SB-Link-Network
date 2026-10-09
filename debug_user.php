<?php
require_once 'config/db.php';

$username = 'zahidiqbalwawna19ml';

echo "<h2>Full Debug for: $username</h2>";

$tables = ['radcheck', 'radreply', 'radusergroup', 'radacct'];

foreach($tables as $t) {
    echo "<h3>Table: $t</h3>";
    if ($t == 'radacct') {
        $stmt = $pdo->query("SELECT radacctid, acctstarttime, acctstoptime, acctterminatecause FROM radacct WHERE username = '$username' ORDER BY radacctid DESC LIMIT 5");
    } else {
        $stmt = $pdo->query("SELECT * FROM $t WHERE username = '$username'");
    }
    
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows) == 0) {
        echo "No records found.<br>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; text-align: left;'>";
        // Header
        echo "<tr style='background: #eee;'>";
        foreach(array_keys($rows[0]) as $k) echo "<th>$k</th>";
        echo "</tr>";
        // Data
        foreach($rows as $r) {
            echo "<tr>";
            foreach($r as $v) echo "<td>" . htmlspecialchars($v ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}
?>
