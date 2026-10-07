<?php
require 'config/db.php';
echo "<h3>Dealer 'mmm' Status</h3>";
$stmt = $pdo->query("SELECT id, username, assigned_routers FROM dealers WHERE username = 'mmm'");
$dealer = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($dealer);
echo "</pre>";

echo "<h3>All NAS (Routers) in System</h3>";
$stmt = $pdo->query("SELECT id, nasname, shortname FROM nas");
$routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($routers);
echo "</pre>";

echo "<h3>Test Query for dealer/users.php</h3>";
if($dealer && !empty($dealer['assigned_routers'])) {
    $assigned_nas_ips = explode(',', $dealer['assigned_routers']);
    $in = str_repeat('?,', count($assigned_nas_ips) - 1) . '?';
    $rStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE id IN ($in)");
    $rStmt->execute($assigned_nas_ips);
    $res = $rStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found routers for dealer:<br><pre>";
    print_r($res);
    echo "</pre>";
} else {
    echo "Dealer has no assigned routers in DB.";
}
?>
