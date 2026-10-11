<?php
require 'config/db.php';
$stmt = $pdo->query('SHOW CREATE TABLE packages');
print_r($stmt->fetch());
?>
