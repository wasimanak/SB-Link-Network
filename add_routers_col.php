<?php
require_once 'config/db.php';

try {
    $pdo->exec("ALTER TABLE dealers ADD COLUMN assigned_routers VARCHAR(255) DEFAULT '' AFTER city");
    echo "Added assigned_routers to dealers\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column assigned_routers already exists\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
