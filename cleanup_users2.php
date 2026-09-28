<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

// Delete all subscribers and RADIUS records
$pdo->exec("TRUNCATE TABLE subscribers");
$pdo->exec("TRUNCATE TABLE radcheck");
$pdo->exec("TRUNCATE TABLE radreply");
$pdo->exec("TRUNCATE TABLE radusergroup");

// Delete all packages and RADIUS groups
$pdo->exec("TRUNCATE TABLE packages");
$pdo->exec("TRUNCATE TABLE radgroupcheck");
$pdo->exec("TRUNCATE TABLE radgroupreply");

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

echo "All old users and packages deleted successfully from Database and Radius.\n";
?>
