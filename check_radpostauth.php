<?php
require 'config/db.php';
$count = $pdo->query('SELECT COUNT(*) FROM radpostauth')->fetchColumn();
echo "Total rows in radpostauth: " . $count . "\n";
?>
