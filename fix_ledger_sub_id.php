<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

try {
    $pdo->exec("ALTER TABLE `user_ledger` MODIFY `subscriber_id` int(11) DEFAULT NULL");
    echo "Modified 'subscriber_id' to allow NULL in 'user_ledger' successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
