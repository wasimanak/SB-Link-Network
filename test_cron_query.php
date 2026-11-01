<?php
require_once 'config/db.php';

header('Content-Type: text/plain');

echo "=== 1. SUBSCRIBERS BY STATUS & CLIENT ===\n";
$stmt = $pdo->query("SELECT client_id, status, COUNT(*) as cnt FROM subscribers GROUP BY client_id, status");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== 2. SUBSCRIBERS WITH expiry_date <= NOW() ===\n";
$stmt2 = $pdo->query("SELECT client_id, COUNT(*) as cnt FROM subscribers WHERE expiry_date IS NOT NULL AND expiry_date <= NOW() GROUP BY client_id");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== 3. SAMPLE EXPIRED USERS & RADREPLY VALUES ===\n";
$stmt3 = $pdo->query("
    SELECT s.client_id, s.username, s.status, s.expiry_date,
           (SELECT value FROM radreply WHERE username = s.username AND attribute = 'Mikrotik-Rate-Limit' LIMIT 1) as rad_rate,
           (SELECT value FROM radreply WHERE username = s.username AND attribute = 'Mikrotik-Address-List' LIMIT 1) as rad_addr,
           (SELECT groupname FROM radusergroup WHERE username = s.username LIMIT 1) as user_group
    FROM subscribers s
    WHERE s.expiry_date <= NOW() OR s.status = 'expired'
    LIMIT 10
");
print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== 4. CHECK USER chksadeeque DETAILS ===\n";
$stmt4 = $pdo->query("
    SELECT s.id, s.client_id, s.username, s.status, s.expiry_date,
           (SELECT GROUP_CONCAT(attribute, '=', value SEPARATOR ', ') FROM radreply WHERE username = s.username) as radreply_attrs,
           (SELECT groupname FROM radusergroup WHERE username = s.username LIMIT 1) as user_group
    FROM subscribers s
    WHERE s.username = 'chksadeeque'
");
print_r($stmt4->fetchAll(PDO::FETCH_ASSOC));
