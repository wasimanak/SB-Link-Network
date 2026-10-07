<?php
require 'config/db.php';
$stmt = $pdo->query("SELECT id, username, assigned_routers FROM dealers");
$dealers = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($dealers);
?>
