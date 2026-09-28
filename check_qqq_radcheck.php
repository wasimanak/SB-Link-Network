<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
echo "RadCheck:\n";
print_r($pdo->query("SELECT * FROM radcheck WHERE username='qqq'")->fetchAll(PDO::FETCH_ASSOC));
?>
