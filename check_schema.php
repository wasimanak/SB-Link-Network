<?php
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = 'localhost';
require 'config/db.php';
$stmt = $pdo->query("SHOW CREATE TABLE dealer_packages");
print_r($stmt->fetch());
?>
