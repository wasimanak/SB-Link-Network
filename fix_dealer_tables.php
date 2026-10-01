<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

$columns = [
    // Add missing columns to activity_logs
    "ALTER TABLE `activity_logs` ADD COLUMN `dealer_id` int(11) DEFAULT NULL",
    "ALTER TABLE `activity_logs` ADD COLUMN `lineman_id` int(11) DEFAULT NULL",
    "ALTER TABLE `activity_logs` ADD COLUMN `recovery_id` int(11) DEFAULT NULL",
    "ALTER TABLE `activity_logs` ADD COLUMN `customer_id` int(11) DEFAULT NULL",
    
    // Add missing columns to dealers
    "ALTER TABLE `dealers` ADD COLUMN `franchise` varchar(150) DEFAULT NULL",
    
    // Add missing columns to fund_requests (if any)
    "ALTER TABLE `fund_requests` ADD COLUMN `notes` text DEFAULT NULL"
];

foreach ($columns as $col) {
    try {
        $pdo->exec($col);
        echo "Successfully executed: $col\n";
    } catch (Exception $e) {
        echo "Skipped: $col (Reason: " . $e->getMessage() . ")\n";
    }
}
?>
