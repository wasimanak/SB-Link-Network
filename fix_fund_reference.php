<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

try {
    $pdo->exec("ALTER TABLE `fund_requests` ADD COLUMN `payment_reference` varchar(255) DEFAULT NULL AFTER `amount`");
    echo "Added 'payment_reference' to 'fund_requests' successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
