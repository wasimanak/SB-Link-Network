<?php
$pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

// 1. Update linemen table
try {
    $pdo->exec("ALTER TABLE linemen ADD COLUMN address VARCHAR(255) DEFAULT NULL, ADD COLUMN city VARCHAR(100) DEFAULT NULL");
} catch(Exception $e) {}

// 2. Create recovery_men table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS recovery_men (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        username VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(150),
        phone VARCHAR(20),
        address VARCHAR(255),
        city VARCHAR(100),
        status VARCHAR(20) DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch(Exception $e) {}

echo "Database updated for Line Man and Recovery Man.\n";
?>
