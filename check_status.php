<?php
require_once 'config/db.php';
$stmt = $pdo->query("SELECT DISTINCT status FROM subscribers");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
