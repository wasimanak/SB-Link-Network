<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
echo "RadUserGroup:\n";
print_r($pdo->query("SELECT * FROM radusergroup WHERE username='qqq'")->fetchAll(PDO::FETCH_ASSOC));
echo "RadGroupCheck for qqq's group:\n";
print_r($pdo->query("SELECT r.* FROM radgroupcheck r JOIN radusergroup u ON r.groupname = u.groupname WHERE u.username='qqq'")->fetchAll(PDO::FETCH_ASSOC));
?>
