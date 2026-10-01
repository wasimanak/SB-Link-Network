<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

$columns = [
    "ALTER TABLE `dealers` ADD COLUMN `photo` varchar(255) DEFAULT NULL",
    "ALTER TABLE `dealers` ADD COLUMN `admin_name` varchar(150) DEFAULT NULL",
    "ALTER TABLE `dealers` ADD COLUMN `last_login` timestamp NULL DEFAULT NULL"
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
