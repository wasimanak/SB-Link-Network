<?php
require 'config/db.php';
$stmt=$pdo->prepare("SELECT acctstarttime, acctstoptime, acctsessiontime FROM radacct WHERE username='1122' ORDER BY radacctid DESC LIMIT 5");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
