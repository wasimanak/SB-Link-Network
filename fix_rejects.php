<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

// Delete rogue radreply entries
$pdo->exec("DELETE FROM radreply WHERE attribute='Mikrotik-Rate-Limit' AND value LIKE '%Unlimited%'");

// Check radcheck for Reject
$rejects = $pdo->query("SELECT * FROM radcheck WHERE attribute='Auth-Type' AND value='Reject'")->fetchAll(PDO::FETCH_ASSOC);
echo "Rejects:\n";
print_r($rejects);
?>
