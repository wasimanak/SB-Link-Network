<?php
require "config/db.php";
$stmt = $pdo->query("SELECT id, assigned_routers FROM dealers WHERE assigned_routers != ''");
while($row = $stmt->fetch()) {
    $routers = explode(',', $row['assigned_routers']);
    $new_routers = [];
    foreach($routers as $r) {
        $r = trim($r);
        // If it looks like an IP address, we null it out to force the user to re-select
        if (filter_var($r, FILTER_VALIDATE_IP)) {
            continue; 
        }
        $new_routers[] = $r;
    }
    $new_val = implode(',', $new_routers);
    if ($new_val !== $row['assigned_routers']) {
        $pdo->prepare("UPDATE dealers SET assigned_routers = ? WHERE id = ?")->execute([$new_val, $row['id']]);
    }
}
echo "Database cleaned of old IP-based assignments.";
?>