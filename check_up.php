<?php
require 'config/db.php';
$stmt=$pdo->prepare("SELECT acctstarttime FROM radacct WHERE username='1122' AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1");
$stmt->execute();
print_r($stmt->fetch(PDO::FETCH_ASSOC));
echo "\nCurrent PHP Time: " . date('Y-m-d H:i:s');
