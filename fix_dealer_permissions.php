<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

$columns = [
    "ALTER TABLE `dealers` ADD COLUMN `perm_create_user` tinyint(1) DEFAULT 1",
    "ALTER TABLE `dealers` ADD COLUMN `perm_delete_user` tinyint(1) DEFAULT 1",
    "ALTER TABLE `dealers` ADD COLUMN `perm_custom_expiry` tinyint(1) DEFAULT 1",
    "ALTER TABLE `dealers` ADD COLUMN `perm_disable_user` tinyint(1) DEFAULT 1"
];

foreach ($columns as $col) {
    try {
        $pdo->exec($col);
        echo "Successfully executed: $col\n";
    } catch (Exception $e) {
        echo "Skipped: $col (Reason: " . $e->getMessage() . ")\n";
    }
}

$dealer_packages = "CREATE TABLE IF NOT EXISTS `dealer_packages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dealer_id` int(11) NOT NULL,
  `package_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `dealer_id` (`dealer_id`),
  KEY `package_id` (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

try {
    $pdo->exec($dealer_packages);
    echo "Created table 'dealer_packages'\n";
} catch (Exception $e) {
    echo "Error creating dealer_packages: " . $e->getMessage() . "\n";
}
?>
