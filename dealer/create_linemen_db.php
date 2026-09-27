<?php
$pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

// 1. Create linemen table
$pdo->exec("CREATE TABLE IF NOT EXISTS linemen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150),
    phone VARCHAR(20),
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 2. Add lineman_id to subscribers
try {
    $pdo->exec("ALTER TABLE subscribers ADD COLUMN lineman_id INT DEFAULT NULL");
} catch(Exception $e) {}

// For testing purposes, let's create a dummy lineman for client_id=1 (Assuming Operator is 1, we can just insert one for the operator currently logged in)
// We will do that later or let the user create it. Let's just insert one for client_id=1
try {
    $pdo->exec("INSERT IGNORE INTO linemen (client_id, username, password, full_name, phone) VALUES (1, 'lineman1', 'admin123', 'Asif Line Man', '03001234567')");
} catch(Exception $e) {}

echo "Lineman table created and schema updated.\n";
?>
