<?php
require_once 'config/db.php';
$rows = $pdo->query("SELECT * FROM radpostauth ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
?>
