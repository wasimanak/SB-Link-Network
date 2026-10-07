<?php
require 'config/db.php';

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM dealers LIKE 'status'");
    if ($stmt->rowCount() > 0) {
        echo "Column 'status' EXISTS in dealers table.\n";
    } else {
        echo "Column 'status' DOES NOT EXIST.\n";
        echo "Attempting to create it...\n";
        $pdo->exec("ALTER TABLE dealers ADD COLUMN status ENUM('active', 'disabled') DEFAULT 'active'");
        echo "Created successfully!\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
