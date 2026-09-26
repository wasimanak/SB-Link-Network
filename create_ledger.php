<?php
try {
    $p = new PDO('mysql:host=10.133.13.69;dbname=radius_admin','syncuser','admin123');
    $p->exec("CREATE TABLE IF NOT EXISTS user_ledger (
        id INT AUTO_INCREMENT PRIMARY KEY, 
        client_id INT, 
        username VARCHAR(100), 
        type ENUM('credit', 'debit'), 
        amount DECIMAL(10,2), 
        balance_after DECIMAL(10,2), 
        description VARCHAR(255), 
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Table created";
} catch(Exception $e) {
    echo $e->getMessage();
}
