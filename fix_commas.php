<?php
require 'config/db.php';

// 1. Fix Database commas
$stmt = $pdo->query("SELECT id, assigned_routers FROM dealers WHERE assigned_routers LIKE '%,%' OR assigned_routers = ','");
$fixed = 0;
while($row = $stmt->fetch()) {
    $arr = explode(',', $row['assigned_routers']);
    $clean = [];
    foreach($arr as $v) {
        $v = trim($v);
        if ($v !== '') $clean[] = $v;
    }
    $new_val = implode(',', $clean);
    if ($new_val !== $row['assigned_routers']) {
        $pdo->prepare("UPDATE dealers SET assigned_routers = ? WHERE id = ?")->execute([$new_val, $row['id']]);
        $fixed++;
    }
}
echo "<p>✅ Database cleaned ($fixed commas fixed).</p>";

// 2. Patch operator/dealers.php
$f1 = 'operator/dealers.php';
if (file_exists($f1)) {
    $c1 = file_get_contents($f1);
    $badCode = '$assigned_routers = isset($_POST[\'assigned_routers\']) ? implode(\',\', $_POST[\'assigned_routers\']) : \'\';';
    $goodCode = '$arr = isset($_POST[\'assigned_routers\']) ? $_POST[\'assigned_routers\'] : [];
        $arr = array_filter($arr, function($v) { return trim($v) !== \'\'; });
        $assigned_routers = implode(\',\', $arr);';
    $c1 = str_replace($badCode, $goodCode, $c1);
    file_put_contents($f1, $c1);
    echo "<p>✅ operator/dealers.php patched.</p>";
}

// 3. Patch operator/dealer_view.php
$f2 = 'operator/dealer_view.php';
if (file_exists($f2)) {
    $c2 = file_get_contents($f2);
    $badCode = '$assigned_routers = isset($_POST[\'assigned_routers\']) ? implode(\',\', $_POST[\'assigned_routers\']) : \'\';';
    $goodCode = '$arr = isset($_POST[\'assigned_routers\']) ? $_POST[\'assigned_routers\'] : [];
      $arr = array_filter($arr, function($v) { return trim($v) !== \'\'; });
      $assigned_routers = implode(\',\', $arr);';
    $c2 = str_replace($badCode, $goodCode, $c2);
    file_put_contents($f2, $c2);
    echo "<p>✅ operator/dealer_view.php patched.</p>";
}

echo "<h3>🎉 All Done! Please edit Dealer 'ww', select the routers, and save again.</h3>";
?>
