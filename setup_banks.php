<?php
require 'config/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS operator_bank_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        bank_name VARCHAR(100) NOT NULL,
        account_title VARCHAR(150) NOT NULL,
        account_number VARCHAR(100) NOT NULL,
        iban VARCHAR(100) DEFAULT NULL,
        status ENUM('active', 'disabled') DEFAULT 'disabled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "Table operator_bank_accounts created successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
