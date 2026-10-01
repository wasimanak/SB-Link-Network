<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

try {
    $pdo->exec("ALTER TABLE `user_ledger` ADD COLUMN `username` varchar(100) DEFAULT NULL AFTER `subscriber_id`");
    echo "Added 'username' to 'user_ledger' successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
