<?php
try {
    $p = new PDO('mysql:host=10.133.13.69;dbname=radius_admin','syncuser','admin123');
    $p->exec("UPDATE nas SET nasname='10.133.13.104'");
    echo "Done";
} catch (Exception $e) {
    echo $e->getMessage();
}
