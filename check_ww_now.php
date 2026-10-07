<?php
require 'config/db.php';
echo "<h3>Current Dealer 'ww' Status</h3>";
$stmt = $pdo->query("SELECT id, username, assigned_routers FROM dealers WHERE username = 'ww'");
$dealer = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<pre>";
print_r($dealer);
echo "</pre>";
?>
