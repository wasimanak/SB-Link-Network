<?php
try {
    $pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dealer_packages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dealer_id INT NOT NULL,
            package_id INT NOT NULL,
            dealer_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            dealer_profit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY dealer_pkg (dealer_id, package_id)
        )
    ");
    echo "Table created successfully.";
} catch (Exception $e) {
    echo $e->getMessage();
}
