<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

try {
    $pdo->exec("ALTER TABLE `fund_requests` ADD COLUMN `subscriber_id` int(11) DEFAULT NULL AFTER `dealer_id`");
    echo "Added 'subscriber_id' to 'fund_requests' successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("ALTER TABLE `fund_requests` MODIFY `dealer_id` int(11) DEFAULT NULL");
    echo "Modified 'dealer_id' to allow NULL in 'fund_requests' successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Check if user_ledger has 'balance_after'
try {
    $pdo->exec("ALTER TABLE `user_ledger` ADD COLUMN `balance_after` decimal(10,2) DEFAULT '0.00' AFTER `amount`");
    echo "Added 'balance_after' to 'user_ledger' successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
