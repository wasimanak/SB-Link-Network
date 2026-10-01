<?php
$pdo = new PDO('mysql:host=192.168.20.100;dbname=radius_admin;charset=utf8mb4', 'syncuser', 'admin123');

$sqls = [
    // Create missing tables
    "CREATE TABLE IF NOT EXISTS `fund_requests` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `client_id` int(11) NOT NULL,
        `dealer_id` int(11) DEFAULT NULL,
        `subscriber_id` int(11) DEFAULT NULL,
        `amount` decimal(10,2) NOT NULL,
        `payment_reference` varchar(255) DEFAULT NULL,
        `proof_image` varchar(255) DEFAULT NULL,
        `status` enum('pending','approved','rejected') DEFAULT 'pending',
        `notes` text DEFAULT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    
    "CREATE TABLE IF NOT EXISTS `dealers` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `client_id` int(11) NOT NULL,
        `full_name` varchar(150) NOT NULL,
        `username` varchar(100) NOT NULL,
        `password` varchar(100) NOT NULL,
        `franchise` varchar(150) DEFAULT NULL,
        `national_id` varchar(50) DEFAULT NULL,
        `email` varchar(150) DEFAULT NULL,
        `phone` varchar(50) DEFAULT NULL,
        `address` text DEFAULT NULL,
        `city` varchar(100) DEFAULT NULL,
        `balance` decimal(10,2) DEFAULT '0.00',
        `photo` varchar(255) DEFAULT NULL,
        `admin_name` varchar(150) DEFAULT NULL,
        `last_login` timestamp NULL DEFAULT NULL,
        `perm_create_user` tinyint(1) DEFAULT 1,
        `perm_delete_user` tinyint(1) DEFAULT 1,
        `perm_custom_expiry` tinyint(1) DEFAULT 1,
        `perm_disable_user` tinyint(1) DEFAULT 1,
        `status` enum('active','disabled') DEFAULT 'active',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `dealer_notes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `dealer_id` int(11) NOT NULL,
        `note` text NOT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `dealer_packages` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `dealer_id` int(11) NOT NULL,
        `package_id` int(11) NOT NULL,
        `dealer_price` decimal(10,2) DEFAULT '0.00',
        `dealer_profit` decimal(10,2) DEFAULT '0.00',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `linemen` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `client_id` int(11) NOT NULL,
        `full_name` varchar(150) NOT NULL,
        `username` varchar(100) NOT NULL,
        `password` varchar(100) NOT NULL,
        `national_id` varchar(50) DEFAULT NULL,
        `phone` varchar(50) DEFAULT NULL,
        `address` text DEFAULT NULL,
        `status` enum('active','disabled') DEFAULT 'active',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `recovery_men` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `client_id` int(11) NOT NULL,
        `full_name` varchar(150) NOT NULL,
        `username` varchar(100) NOT NULL,
        `password` varchar(100) NOT NULL,
        `national_id` varchar(50) DEFAULT NULL,
        `phone` varchar(50) DEFAULT NULL,
        `address` text DEFAULT NULL,
        `status` enum('active','disabled') DEFAULT 'active',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `user_ledger` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `subscriber_id` int(11) DEFAULT NULL,
        `username` varchar(100) DEFAULT NULL,
        `client_id` int(11) NOT NULL,
        `type` enum('debit','credit') NOT NULL,
        `amount` decimal(10,2) NOT NULL,
        `balance_after` decimal(10,2) DEFAULT '0.00',
        `description` text NOT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `payment_gateways` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `client_id` int(11) NOT NULL,
        `gateway_name` varchar(100) NOT NULL,
        `account_name` varchar(150) NOT NULL,
        `merchant_id` varchar(255) DEFAULT NULL,
        `api_key` text DEFAULT NULL,
        `api_secret` text DEFAULT NULL,
        `mode` enum('sandbox','live') DEFAULT 'sandbox',
        `status` enum('active','disabled') DEFAULT 'active',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `support_tickets` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `client_id` int(11) NOT NULL,
        `dealer_id` int(11) DEFAULT NULL,
        `subscriber_id` int(11) DEFAULT NULL,
        `subject` varchar(255) NOT NULL,
        `status` enum('open','answered','closed') DEFAULT 'open',
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `ticket_messages` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `ticket_id` int(11) NOT NULL,
        `sender_type` enum('admin','dealer','customer','operator') NOT NULL,
        `sender_id` int(11) NOT NULL,
        `message` text NOT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

$alters = [
    "ALTER TABLE `subscribers` ADD COLUMN `dealer_id` int(11) DEFAULT NULL",
    "ALTER TABLE `subscribers` ADD COLUMN `city` varchar(100) DEFAULT NULL",
    "ALTER TABLE `subscribers` ADD COLUMN `gps_lat` varchar(50) DEFAULT NULL",
    "ALTER TABLE `subscribers` ADD COLUMN `gps_lng` varchar(50) DEFAULT NULL",
    "ALTER TABLE `subscribers` ADD COLUMN `balance_used` decimal(10,2) DEFAULT '0.00'",
    "ALTER TABLE `subscribers` ADD COLUMN `last_payment_date` timestamp NULL DEFAULT NULL",
    
    "ALTER TABLE `activity_logs` ADD COLUMN `dealer_id` int(11) DEFAULT NULL",
    "ALTER TABLE `activity_logs` ADD COLUMN `lineman_id` int(11) DEFAULT NULL",
    "ALTER TABLE `activity_logs` ADD COLUMN `recovery_id` int(11) DEFAULT NULL",
    "ALTER TABLE `activity_logs` ADD COLUMN `customer_id` int(11) DEFAULT NULL"
];

foreach ($sqls as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) {}
}

foreach ($alters as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) {}
}

echo "All schema fixes applied.\n";
?>
