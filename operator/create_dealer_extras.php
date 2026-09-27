<?php
try {
    $pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS dealer_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dealer_id INT NOT NULL,
        note TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dealer_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dealer_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    echo "Tables created successfully.";
} catch (Exception $e) {
    echo $e->getMessage();
}
