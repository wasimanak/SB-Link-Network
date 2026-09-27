<?php
try {
    $pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS dealers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        full_name VARCHAR(100) NOT NULL,
        username VARCHAR(50) NOT NULL,
        password VARCHAR(255) NOT NULL,
        admin_name VARCHAR(50) DEFAULT 'admin',
        franchise VARCHAR(100),
        area VARCHAR(100),
        balance DECIMAL(10,2) DEFAULT 0.00,
        last_login DATETIME,
        photo VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Check if dealer_id exists in subscribers, if not add it
    $stmt = $pdo->query("SHOW COLUMNS FROM subscribers LIKE 'dealer_id'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE subscribers ADD COLUMN dealer_id INT DEFAULT NULL");
    }
    echo "Success";
} catch (Exception $e) {
    echo $e->getMessage();
}
