<?php
require 'config/db.php';
$acts = $pdo->query("SELECT by_user, activity FROM activity_logs ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$ledgers = $pdo->query("SELECT description FROM user_ledger ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

echo "--- ACTIVITY LOGS ---\n";
print_r($acts);
echo "\n--- LEDGERS ---\n";
print_r($ledgers);
?>
