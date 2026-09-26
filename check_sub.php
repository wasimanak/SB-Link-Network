<?php
try {
    $p = new PDO('mysql:host=10.133.13.69;dbname=radius_admin','syncuser','admin123');
    $stmt = $p->query("DESCRIBE subscribers");
    print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch(Exception $e) {
    echo $e->getMessage();
}
