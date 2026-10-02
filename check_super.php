<?php
$p = new PDO('mysql:host=192.168.20.100;dbname=radius_admin', 'syncuser', 'admin123');
print_r($p->query('DESCRIBE super_admins')->fetchAll(PDO::FETCH_ASSOC));
print_r($p->query('SELECT * FROM super_admins')->fetchAll(PDO::FETCH_ASSOC));
?>
