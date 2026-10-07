<?php
// 1. Remove fallback from operator/dealer_view.php
$f1 = 'operator/dealer_view.php';
$c1 = file_get_contents($f1);

// We replace the fallback back to strict ID matching
$c1 = preg_replace('/in_array\(\$n\[\'id\'\], \$curr_routers\) \|\| in_array\(\$n\[\'nasname\'\], \$curr_routers\)/i', 'in_array($n[\'id\'], $curr_routers)', $c1);
file_put_contents($f1, $c1);

// 2. Remove fallback from dealer/users.php
$f2 = 'dealer/users.php';
$c2 = file_get_contents($f2);

$oldUsersQuery = '$rStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE id IN ($in) OR nasname IN ($in)");
    $params = array_merge($assigned_nas_ips, $assigned_nas_ips);
    $rStmt->execute($params);';
    
$newUsersQuery = '$rStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE id IN ($in)");
    $rStmt->execute($assigned_nas_ips);';

if (strpos($c2, 'OR nasname IN') !== false) {
    $c2 = str_replace($oldUsersQuery, $newUsersQuery, $c2);
    file_put_contents($f2, $c2);
}

// 3. Create a database clean up script
$f3 = 'clean_db.php';
$c3 = '<?php
require "config/db.php";
$stmt = $pdo->query("SELECT id, assigned_routers FROM dealers WHERE assigned_routers != \'\'");
while($row = $stmt->fetch()) {
    $routers = explode(\',\', $row[\'assigned_routers\']);
    $new_routers = [];
    foreach($routers as $r) {
        $r = trim($r);
        // If it looks like an IP address, we null it out to force the user to re-select
        if (filter_var($r, FILTER_VALIDATE_IP)) {
            continue; 
        }
        $new_routers[] = $r;
    }
    $new_val = implode(\',\', $new_routers);
    if ($new_val !== $row[\'assigned_routers\']) {
        $pdo->prepare("UPDATE dealers SET assigned_routers = ? WHERE id = ?")->execute([$new_val, $row[\'id\']]);
    }
}
echo "Database cleaned of old IP-based assignments.";
?>';
file_put_contents($f3, $c3);

echo "Strict ID matching enforced.\n";
?>
