<?php
$p = new PDO('mysql:host=192.168.20.100;dbname=radius_admin', 'syncuser', 'admin123');
$r = $p->query('SELECT secret, LENGTH(secret) as len FROM nas WHERE id=4')->fetch(PDO::FETCH_ASSOC);
print_r($r);
?>
