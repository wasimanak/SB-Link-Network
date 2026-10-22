<?php
require 'config/db.php';

// Check package 100 speed_scheduler
$stmt = $pdo->query("SELECT id, name, rate_limit, speed_scheduler FROM packages WHERE id = 100");
$p = $stmt->fetch();

echo "<h2>Package 100 Full Details</h2>";
echo "<pre>";
print_r($p);
echo "</pre>";

// Also check if fiazaraen is in radreply
$stmt2 = $pdo->query("SELECT * FROM radreply WHERE username = 'fiazaraen'");
echo "<h2>fiazaraen in radreply</h2>";
echo "<pre>";
print_r($stmt2->fetchAll());
echo "</pre>";
?>
