<?php
$username = 'zahidiqbalwawna19ml';
$password = '1234';
$secret = '123456'; // The secret for localhost might be different, let's try 'testing123' first, then '123456'

echo "<h2>Testing User Authentication Internally</h2>";

// Try radtest with testing123 (default localhost secret)
echo "<h3>Test 1: localhost / testing123</h3>";
$output1 = shell_exec("radtest $username $password 127.0.0.1 0 testing123 2>&1");
echo "<pre style='background: #111; color: #0f0; padding: 10px;'>" . htmlspecialchars($output1) . "</pre>";

// Try radtest with 123456 (if localhost secret was changed)
echo "<h3>Test 2: localhost / 123456</h3>";
$output2 = shell_exec("radtest $username $password 127.0.0.1 0 123456 2>&1");
echo "<pre style='background: #111; color: #0f0; padding: 10px;'>" . htmlspecialchars($output2) . "</pre>";

// Remove Expiration temporarily to see if Expiration is the culprit
require_once 'config/db.php';
echo "<h3>Test 3: Testing WITHOUT Expiration attribute</h3>";
$pdo->query("DELETE FROM radcheck WHERE username = '$username' AND attribute = 'Expiration'");
$output3 = shell_exec("radtest $username $password 127.0.0.1 0 testing123 2>&1");
echo "<pre style='background: #111; color: #0f0; padding: 10px;'>" . htmlspecialchars($output3) . "</pre>";

// Put Expiration back
$pdo->query("INSERT INTO radcheck (username, attribute, op, value) VALUES ('$username', 'Expiration', ':=', '11 Oct 2026 00:00:00')");
?>
