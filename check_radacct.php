<?php
require 'config/db.php';
$stmt = $pdo->query("DESCRIBE radacct");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
?>
