<?php
try {
    $p = new PDO('mysql:host=10.133.13.69;dbname=radius_admin','syncuser','admin123');
    $p->exec("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description, created_at) VALUES (2, '1122', 'debit', 500, 1415, 'Package Renewed (Simulated)', '2026-09-26 21:40:00')");
    echo "inserted";
} catch(Exception $e) {
    echo $e->getMessage();
}
