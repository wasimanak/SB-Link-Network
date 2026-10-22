<?php
require_once 'config/db.php';

echo "<h2>Checking newly created user: rodihaiderkhan</h2>";
$username = 'rodihaiderkhan';

echo "<h3>radcheck table:</h3>";
$stmt = $pdo->query("SELECT * FROM radcheck WHERE username = '$username'");
$radcheck = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($radcheck, true) . "</pre>";

echo "<h3>radreply table:</h3>";
$stmt = $pdo->query("SELECT * FROM radreply WHERE username = '$username'");
$radreply = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($radreply, true) . "</pre>";

echo "<h3>subscribers table:</h3>";
$stmt = $pdo->query("SELECT id, username, status, expiry_date, package_id FROM subscribers WHERE username = '$username'");
$sub = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($sub, true) . "</pre>";

echo "<h3>Checking all users failing to connect...</h3>";
$stmt = $pdo->query("SELECT username, attribute, value FROM radcheck WHERE username IN ('rodihaiderkhan', 'rodiamirmugal', 'zahidiqbalwawna19ml', 'jwwaris')");
$failing = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($failing, true) . "</pre>";
?>
