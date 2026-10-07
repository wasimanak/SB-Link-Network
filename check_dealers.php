<?php
require 'config/db.php';
$stmt = $pdo->query("DESCRIBE dealers");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
?>
