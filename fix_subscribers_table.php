<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

$columns = [
    "ADD COLUMN `dealer_id` int(11) DEFAULT NULL",
    "ADD COLUMN `service_type` enum('pppoe','hotspot') DEFAULT 'pppoe'",
    "ADD COLUMN `mobile` varchar(50) DEFAULT NULL",
    "ADD COLUMN `phone` varchar(50) DEFAULT NULL",
    "ADD COLUMN `email` varchar(150) DEFAULT NULL",
    "ADD COLUMN `national_id` varchar(50) DEFAULT NULL",
    "ADD COLUMN `subarea` varchar(150) DEFAULT NULL",
    "ADD COLUMN `city` varchar(100) DEFAULT NULL",
    "ADD COLUMN `address` text DEFAULT NULL",
    "ADD COLUMN `gps_lat` varchar(50) DEFAULT NULL",
    "ADD COLUMN `gps_lng` varchar(50) DEFAULT NULL",
    "ADD COLUMN `notes` text DEFAULT NULL",
    "ADD COLUMN `balance` decimal(10,2) DEFAULT '0.00'",
    "ADD COLUMN `balance_used` decimal(10,2) DEFAULT '0.00'",
    "ADD COLUMN `last_payment_date` timestamp NULL DEFAULT NULL"
];

foreach ($columns as $col) {
    try {
        $pdo->exec("ALTER TABLE subscribers $col");
        echo "Successfully executed: $col\n";
    } catch (Exception $e) {
        // Ignore if column already exists
        echo "Skipped: $col (Reason: " . $e->getMessage() . ")\n";
    }
}
?>
