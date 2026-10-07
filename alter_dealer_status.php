<?php
require 'config/db.php';
try {
    $pdo->exec("ALTER TABLE dealers ADD COLUMN status ENUM('active', 'disabled') DEFAULT 'active'");
    echo "Added 'status' column to dealers table.\n";
} catch (Exception $e) {
    echo "Column might already exist or error: " . $e->getMessage() . "\n";
}
?>
