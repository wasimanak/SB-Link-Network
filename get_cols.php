<?php
require_once 'config/db.php';
$cols = $pdo->query('DESCRIBE radacct')->fetchAll(PDO::FETCH_COLUMN);
print_r($cols);
?>
