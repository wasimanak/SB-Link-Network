<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

try {
    $pdo->exec("ALTER TABLE `dealer_packages` ADD COLUMN `dealer_profit` decimal(10,2) DEFAULT '0.00' AFTER `dealer_price`");
    echo "Added 'dealer_profit' to 'dealer_packages' successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
