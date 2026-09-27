<?php
$pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
print_r($pdo->query('SHOW COLUMNS FROM user_ledger')->fetchAll(PDO::FETCH_ASSOC));
?>
