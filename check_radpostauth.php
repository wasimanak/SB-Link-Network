<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
$rows = $pdo->query("SELECT * FROM radpostauth ORDER BY authdate DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
?>
