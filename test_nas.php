<?php
$p = new PDO('mysql:host=192.168.137.169;dbname=radius_admin','syncuser','admin123');
$stmt = $p->query("SELECT id, nasname, secret FROM nas");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
