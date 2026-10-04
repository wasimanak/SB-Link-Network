<?php
require 'config/db.php';
try {
    $pdo->exec("ALTER TABLE subscribers ADD COLUMN photo VARCHAR(255) DEFAULT NULL");
    echo "Column 'photo' added successfully.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column 'photo' already exists.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>
