<?php
require_once 'config/db.php';

echo "<h3>Detailed Diagnostic: Online Users Breakdown</h3>";

// Count total online in radacct
$q1 = $pdo->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL")->fetchColumn();
echo "Total unique users online in radacct: <b>$q1</b> <br><br>";

// Detailed Breakdown
$query = "
    SELECT 
        s.client_id, 
        c.company_name as operator_name,
        r.nasipaddress as router_ip,
        n.shortname as router_name,
        COUNT(DISTINCT r.username) as online_count
    FROM radacct r
    LEFT JOIN subscribers s ON r.username = s.username
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN nas n ON r.nasipaddress = n.nasname
    WHERE r.acctstoptime IS NULL
    GROUP BY s.client_id, r.nasipaddress
    ORDER BY c.company_name, r.nasipaddress
";

$res = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='8' style='border-collapse:collapse; width: 100%; text-align: left;'>";
echo "<tr style='background: #f1f5f9;'>
        <th>Operator ID</th>
        <th>Operator Name</th>
        <th>Router IP</th>
        <th>Router Name</th>
        <th>Online Users</th>
      </tr>";

foreach($res as $row) {
    $op_id = $row['client_id'] ?: '<span style="color:red">Missing/Ghost</span>';
    $op_name = $row['operator_name'] ?: '<span style="color:red">Unknown Operator</span>';
    $router_ip = $row['router_ip'] ?: 'Unknown';
    $router_name = $row['router_name'] ?: 'Unknown Router';
    
    echo "<tr>
            <td>{$op_id}</td>
            <td><b>{$op_name}</b></td>
            <td>{$router_ip}</td>
            <td>{$router_name}</td>
            <td style='font-size: 1.2rem; font-weight: bold;'>{$row['online_count']}</td>
          </tr>";
}
echo "</table>";

// Check if there are users in radacct NOT in subscribers
$q3 = $pdo->query("SELECT COUNT(DISTINCT r.username) FROM radacct r LEFT JOIN subscribers s ON r.username = s.username WHERE r.acctstoptime IS NULL AND s.username IS NULL")->fetchColumn();
echo "<br>Online users in radacct that are MISSING from subscribers table entirely: <b style='color:red;'>$q3</b>";

?>
