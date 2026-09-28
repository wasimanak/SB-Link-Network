<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
$pdo->exec("UPDATE nas SET nasname='0.0.0.0/0' WHERE id=6");
echo "Updated NAS IP to 0.0.0.0/0 to allow any source IP.\n";
?>
