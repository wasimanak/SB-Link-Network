<?php
try {
    $p = new PDO('mysql:host=10.133.13.69;dbname=radius_admin','syncuser','admin123');
    print_r($p->query('DESCRIBE packages')->fetchAll(PDO::FETCH_COLUMN));
} catch(Exception $e) { echo $e->getMessage(); }
