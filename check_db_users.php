<?php
$p = new PDO('mysql:host=192.168.20.100;dbname=mysql', 'syncuser', 'admin123');
print_r($p->query('SELECT user, host FROM user')->fetchAll(PDO::FETCH_ASSOC));
?>
