<?php
$p = new PDO('mysql:host=10.133.13.69;dbname=radius_admin', 'syncuser', 'admin123');
print_r($p->query('SELECT attribute, value FROM radcheck LIMIT 10')->fetchAll(PDO::FETCH_ASSOC));
?>
