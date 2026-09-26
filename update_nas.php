<?php
$p = new PDO('mysql:host=192.168.137.169;dbname=radius_admin','syncuser','admin123');
$p->exec("UPDATE nas SET nasname='0.0.0.0/0' WHERE id=4");
echo 'Updated';
