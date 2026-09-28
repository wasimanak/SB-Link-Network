<?php
$pdo = new PDO('mysql:host=10.133.13.69;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

// 1. support_tickets
$pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    subscriber_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// 2. ticket_messages
$pdo->exec("CREATE TABLE IF NOT EXISTS ticket_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    sender_type ENUM('subscriber', 'operator', 'admin') NOT NULL,
    sender_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

echo "Support tickets tables recreated!\n";
?>
