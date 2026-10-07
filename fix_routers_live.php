<?php
echo "<h2>SB Link Network - Auto Fixer</h2>";

// 1. Clean Database
require "config/db.php";
$stmt = $pdo->query("SELECT id, assigned_routers FROM dealers WHERE assigned_routers != ''");
$cleaned = 0;
while($row = $stmt->fetch()) {
    $routers = explode(',', $row['assigned_routers']);
    $new_routers = [];
    foreach($routers as $r) {
        $r = trim($r);
        if (filter_var($r, FILTER_VALIDATE_IP)) { continue; } // Remove IPs
        if (!empty($r)) { $new_routers[] = $r; }
    }
    $new_val = implode(',', $new_routers);
    if ($new_val !== $row['assigned_routers']) {
        $pdo->prepare("UPDATE dealers SET assigned_routers = ? WHERE id = ?")->execute([$new_val, $row['id']]);
        $cleaned++;
    }
}
echo "<p>✅ Database cleaned ($cleaned dealers fixed).</p>";

// 2. Fix dealer_view.php (Force Strict ID)
$f1 = 'operator/dealer_view.php';
if (file_exists($f1)) {
    $c1 = file_get_contents($f1);
    // Remove fallback if exists
    $c1 = preg_replace('/in_array\(\$n\[\'id\'\], \$curr_routers\) \|\| in_array\(\$n\[\'nasname\'\], \$curr_routers\)/i', 'in_array($n[\'id\'], $curr_routers)', $c1);
    // Replace IP value with ID value
    $c1 = preg_replace('/value="<\?= \$n\[\'nasname\'\] \?>"/i', 'value="<?= $n[\'id\'] ?>"', $c1);
    file_put_contents($f1, $c1);
    echo "<p>✅ operator/dealer_view.php patched.</p>";
}

// 3. Fix dealers.php (Force Strict ID)
$f2 = 'operator/dealers.php';
if (file_exists($f2)) {
    $c2 = file_get_contents($f2);
    $c2 = preg_replace('/value="<\?= \$n\[\'nasname\'\] \?>"/i', 'value="<?= $n[\'id\'] ?>"', $c2);
    file_put_contents($f2, $c2);
    echo "<p>✅ operator/dealers.php patched.</p>";
}

// 4. Fix dealer/users.php (Force Strict ID)
$f3 = 'dealer/users.php';
if (file_exists($f3)) {
    $c3 = file_get_contents($f3);
    $oldQ = 'SELECT id, nasname, shortname FROM nas WHERE id IN ($in) OR nasname IN ($in)';
    $newQ = 'SELECT id, nasname, shortname FROM nas WHERE id IN ($in)';
    $c3 = str_replace($oldQ, $newQ, $c3);
    
    // Fallback if they still have the oldest query
    $oldestQ = 'SELECT nasname, shortname FROM nas WHERE nasname IN ($in)';
    $c3 = str_replace($oldestQ, $newQ, $c3);
    
    file_put_contents($f3, $c3);
    echo "<p>✅ dealer/users.php patched.</p>";
}

echo "<h3>🎉 All Done! Please do a Hard Refresh (CTRL + F5) in your Operator Panel, check the routers again, and save.</h3>";
?>
