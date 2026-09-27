<?php
try {
    $pdo = new PDO('mysql:host=10.133.13.68;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');
    
    // Add status column to dealers if not exists
    $stmt = $pdo->query("SHOW COLUMNS FROM dealers LIKE 'status'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE dealers ADD COLUMN status ENUM('active','disabled') DEFAULT 'active'");
    }
    echo "Dealers schema updated.";
} catch (Exception $e) {
    echo $e->getMessage();
}
