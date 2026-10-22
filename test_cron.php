<?php
require_once 'config/db.php';
$stmt = $pdo->query("SELECT s.id, s.username, s.status, s.expiry_date FROM subscribers s WHERE s.expiry_date < NOW() AND s.status = 'active'");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Users that should be expired:\n";
print_r($res);
?>
