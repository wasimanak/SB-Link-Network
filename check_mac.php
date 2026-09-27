<?php
require 'config/db.php';
$stmt = $pdo->query("SELECT username, framedipaddress, callingstationid FROM radacct LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
