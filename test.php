<?php
require 'config/db.php';
$stmt = $pdo->query("DESCRIBE fund_requests");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
