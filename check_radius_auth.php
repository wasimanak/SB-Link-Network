<?php
require 'config/db.php';
$users = ['adrees16', 'nadeemkhan', 'sirabbas', 'hajiahmed', 'shehzadawan'];
$in  = str_repeat('?,', count($users) - 1) . '?';

echo "--- RADCHECK TABLE ---\n";
$stmt = $pdo->prepare("SELECT * FROM radcheck WHERE username IN ($in)");
$stmt->execute($users);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- SUBSCRIBERS TABLE ---\n";
$stmt = $pdo->prepare("SELECT username, status, expiry_date FROM subscribers WHERE username IN ($in)");
$stmt->execute($users);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
