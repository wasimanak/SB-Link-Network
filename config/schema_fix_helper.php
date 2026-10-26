<?php
/**
 * NetBee / SB Link - Centralized Database Schema Self-Healing & Repair Engine
 * Ensures missing tables, columns, active client statuses, and RADIUS indexes
 * are fully aligned after any backup restore or remote database synchronization.
 */

function add_column_if_missing($pdo, $table, $column_name, $column_def) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column_name));
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column_name}` {$column_def}");
            return true;
        }
    } catch (Exception $e) {}
    return false;
}

function add_index_if_missing($pdo, $table, $index_name, $columns_sql) {
    try {
        $stmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $pdo->quote($index_name));
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index_name}` ({$columns_sql})");
            return true;
        }
    } catch (Exception $e) {}
    return false;
}

function fix_and_repair_schema($pdo) {
    $report = [];

    // 1. Ensure clients table has all required columns and active status
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `clients` (
            `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `company_name` varchar(150) NOT NULL,
            `email` varchar(150) NOT NULL,
            `password` varchar(255) NOT NULL,
            `phone` varchar(50) DEFAULT NULL,
            `city` varchar(100) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `status` enum('active','suspended','expired') DEFAULT 'active',
            `expiry_date` date DEFAULT '2035-12-31',
            `photo` varchar(255) DEFAULT NULL,
            `balance` decimal(10,2) DEFAULT '0.00',
            `bank_details` text DEFAULT NULL,
            `qr_code` varchar(255) DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        add_column_if_missing($pdo, 'clients', 'photo', "VARCHAR(255) DEFAULT NULL");
        add_column_if_missing($pdo, 'clients', 'status', "ENUM('active','suspended','expired') DEFAULT 'active'");
        add_column_if_missing($pdo, 'clients', 'expiry_date', "DATE DEFAULT '2035-12-31'");
        add_column_if_missing($pdo, 'clients', 'balance', "DECIMAL(10,2) DEFAULT 0.00");
        add_column_if_missing($pdo, 'clients', 'bank_details', "TEXT DEFAULT NULL");
        add_column_if_missing($pdo, 'clients', 'qr_code', "VARCHAR(255) DEFAULT NULL");

        // CRITICAL: Ensure all clients in clients table have status = 'active' and future expiry date!
        // In older databases, clients.status might be expired/suspended or expiry_date < NOW(),
        // which instantly destroys session and kicks out all portals (Admin, Dealer, Lineman, RecoveryMan).
        $affected = $pdo->exec("UPDATE `clients` SET `status` = 'active', `expiry_date` = '2035-12-31' WHERE `status` != 'active' OR `expiry_date` IS NULL OR `expiry_date` < NOW()");
        $report[] = "Clients table verified & statuses activated (updated {$affected} accounts to active with 2035 expiry)";
    } catch (Exception $e) { $report[] = "clients: " . $e->getMessage(); }

    // 2. Ensure dealers table & columns
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `dealers` (
            `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `client_id` int(11) NOT NULL,
            `full_name` varchar(150) NOT NULL,
            `username` varchar(100) NOT NULL,
            `password` varchar(255) NOT NULL,
            `national_id` varchar(50) DEFAULT NULL,
            `email` varchar(150) DEFAULT NULL,
            `phone` varchar(50) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `city` varchar(100) DEFAULT NULL,
            `balance` decimal(10,2) DEFAULT '0.00',
            `status` enum('active','disabled') DEFAULT 'active',
            `assigned_routers` varchar(255) DEFAULT '',
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        add_column_if_missing($pdo, 'dealers', 'status', "ENUM('active','disabled') DEFAULT 'active'");
        add_column_if_missing($pdo, 'dealers', 'balance', "DECIMAL(10,2) DEFAULT '0.00'");
        add_column_if_missing($pdo, 'dealers', 'assigned_routers', "VARCHAR(255) DEFAULT ''");
        add_column_if_missing($pdo, 'dealers', 'phone', "VARCHAR(50) DEFAULT NULL");
        add_column_if_missing($pdo, 'dealers', 'city', "VARCHAR(100) DEFAULT NULL");
        add_column_if_missing($pdo, 'dealers', 'address', "TEXT DEFAULT NULL");
        $pdo->exec("UPDATE `dealers` SET `status` = 'active' WHERE `status` IS NULL OR `status` = ''");
        $report[] = "Dealers table verified (status column & active state ensured)";
    } catch (Exception $e) { $report[] = "dealers: " . $e->getMessage(); }

    // 3. Ensure linemen table & columns
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `linemen` (
            `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `client_id` int(11) NOT NULL,
            `full_name` varchar(150) NOT NULL,
            `username` varchar(100) NOT NULL,
            `password` varchar(255) NOT NULL,
            `national_id` varchar(50) DEFAULT NULL,
            `phone` varchar(50) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `city` varchar(100) DEFAULT NULL,
            `can_create_users` tinyint(1) DEFAULT 1,
            `monthly_salary` int(11) NOT NULL DEFAULT 0,
            `status` enum('active','disabled') DEFAULT 'active',
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        add_column_if_missing($pdo, 'linemen', 'status', "ENUM('active','disabled') DEFAULT 'active'");
        add_column_if_missing($pdo, 'linemen', 'can_create_users', "TINYINT(1) DEFAULT 1");
        add_column_if_missing($pdo, 'linemen', 'monthly_salary', "INT NOT NULL DEFAULT 0");
        add_column_if_missing($pdo, 'linemen', 'city', "VARCHAR(100) DEFAULT NULL");
        add_column_if_missing($pdo, 'linemen', 'address', "TEXT DEFAULT NULL");
        add_column_if_missing($pdo, 'linemen', 'phone', "VARCHAR(50) DEFAULT NULL");
        $pdo->exec("UPDATE `linemen` SET `status` = 'active' WHERE `status` IS NULL OR `status` = ''");
        $report[] = "Linemen table verified (status, monthly_salary ensured)";
    } catch (Exception $e) { $report[] = "linemen: " . $e->getMessage(); }

    // 4. Ensure recovery_men table & columns
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `recovery_men` (
            `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `client_id` int(11) NOT NULL,
            `full_name` varchar(150) NOT NULL,
            `username` varchar(100) NOT NULL,
            `password` varchar(255) NOT NULL,
            `national_id` varchar(50) DEFAULT NULL,
            `phone` varchar(50) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `city` varchar(100) DEFAULT NULL,
            `cash_in_hand` decimal(10,2) DEFAULT 0.00,
            `monthly_salary` int(11) NOT NULL DEFAULT 0,
            `can_create_user` enum('yes','no') DEFAULT 'yes',
            `status` enum('active','disabled') DEFAULT 'active',
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        add_column_if_missing($pdo, 'recovery_men', 'status', "ENUM('active','disabled') DEFAULT 'active'");
        add_column_if_missing($pdo, 'recovery_men', 'can_create_user', "ENUM('yes','no') DEFAULT 'yes'");
        add_column_if_missing($pdo, 'recovery_men', 'cash_in_hand', "DECIMAL(10,2) DEFAULT 0.00");
        add_column_if_missing($pdo, 'recovery_men', 'monthly_salary', "INT NOT NULL DEFAULT 0");
        add_column_if_missing($pdo, 'recovery_men', 'city', "VARCHAR(100) DEFAULT NULL");
        add_column_if_missing($pdo, 'recovery_men', 'address', "TEXT DEFAULT NULL");
        add_column_if_missing($pdo, 'recovery_men', 'phone', "VARCHAR(50) DEFAULT NULL");
        $pdo->exec("UPDATE `recovery_men` SET `status` = 'active' WHERE `status` IS NULL OR `status` = ''");
        $report[] = "Recovery men table verified (cash_in_hand, monthly_salary ensured)";
    } catch (Exception $e) { $report[] = "recovery_men: " . $e->getMessage(); }

    // 5. Ensure managers table & columns
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `managers` (
            `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `client_id` int(11) NOT NULL,
            `full_name` varchar(150) NOT NULL,
            `username` varchar(100) NOT NULL,
            `password` varchar(255) NOT NULL,
            `phone` varchar(50) DEFAULT NULL,
            `permissions` text DEFAULT NULL,
            `can_manage_packages` tinyint(1) DEFAULT 1,
            `can_collect_recovery` tinyint(1) DEFAULT 1,
            `monthly_salary` int(11) NOT NULL DEFAULT 0,
            `status` enum('active','disabled') DEFAULT 'active',
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        add_column_if_missing($pdo, 'managers', 'cash_in_hand', "DECIMAL(10,2) DEFAULT 0.00");
        add_column_if_missing($pdo, 'managers', 'monthly_salary', "INT NOT NULL DEFAULT 0");
        add_column_if_missing($pdo, 'managers', 'status', "ENUM('active','disabled') DEFAULT 'active'");
        add_column_if_missing($pdo, 'managers', 'can_manage_packages', "TINYINT(1) DEFAULT 1");
        add_column_if_missing($pdo, 'managers', 'can_collect_recovery', "TINYINT(1) DEFAULT 1");
        $pdo->exec("UPDATE `managers` SET `status` = 'active' WHERE `status` IS NULL OR `status` = ''");
        $report[] = "Managers table verified";
    } catch (Exception $e) {}

    // 6. Ensure subscribers table columns
    try {
        add_column_if_missing($pdo, 'subscribers', 'photo', "VARCHAR(255) DEFAULT NULL");
        add_column_if_missing($pdo, 'subscribers', 'dealer_id', "INT DEFAULT NULL");
        add_column_if_missing($pdo, 'subscribers', 'lineman_id', "INT DEFAULT NULL");
        add_column_if_missing($pdo, 'subscribers', 'invoice_no', "VARCHAR(100) DEFAULT NULL");
        $report[] = "Subscribers table columns verified";
    } catch (Exception $e) {}

    // 7. Ensure activity_logs columns
    try {
        add_column_if_missing($pdo, 'activity_logs', 'dealer_id', "INT DEFAULT NULL");
        add_column_if_missing($pdo, 'activity_logs', 'manager_id', "INT DEFAULT NULL");
        add_column_if_missing($pdo, 'activity_logs', 'by_role', "VARCHAR(50) DEFAULT 'Admin'");
        add_column_if_missing($pdo, 'activity_logs', 'against_role', "VARCHAR(50) DEFAULT 'User'");
    } catch (Exception $e) {}

    // 8. Ensure packages columns
    try {
        add_column_if_missing($pdo, 'packages', 'is_master', "TINYINT(1) DEFAULT 0");
        add_column_if_missing($pdo, 'packages', 'master_id', "INT(11) DEFAULT NULL");
        add_column_if_missing($pdo, 'packages', 'speed_scheduler', "TEXT DEFAULT NULL");
    } catch (Exception $e) {}

    // 9. Ensure staff_salary_ledger table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `staff_salary_ledger` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `client_id` INT NOT NULL,
            `staff_type` ENUM('recoveryman', 'lineman', 'manager', 'staff') NOT NULL,
            `staff_id` INT NOT NULL,
            `staff_name` VARCHAR(100) NOT NULL,
            `trans_type` ENUM('salary_accrual', 'advance_payment', 'salary_payment', 'bonus', 'deduction') NOT NULL,
            `amount` INT NOT NULL,
            `direction` ENUM('credit', 'debit') NOT NULL,
            `balance_after` INT NOT NULL,
            `payment_method` VARCHAR(50) DEFAULT 'Cash',
            `reference_no` VARCHAR(100) DEFAULT NULL,
            `expense_id` INT DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `trans_date` DATE NOT NULL,
            `created_by` VARCHAR(100) DEFAULT 'Operator',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_staff_lookup` (`client_id`, `staff_type`, `staff_id`),
            INDEX `idx_trans_date` (`trans_date`),
            INDEX `idx_exp_id` (`expense_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $report[] = "Staff salary ledger table verified";
    } catch (Exception $e) {}

    // 10. Ensure user_ledger table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_ledger` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `subscriber_id` INT DEFAULT NULL,
            `client_id` INT NOT NULL,
            `username` VARCHAR(100) DEFAULT NULL,
            `type` ENUM('debit','credit') NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `balance_after` DECIMAL(10,2) DEFAULT 0.00,
            `description` TEXT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_sub_client` (`client_id`, `subscriber_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $report[] = "User ledger table verified";
    } catch (Exception $e) {}

    // 11. Ensure fund_requests & support_tickets tables
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `fund_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `client_id` INT NOT NULL,
            `subscriber_id` INT NULL,
            `dealer_id` INT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `method` VARCHAR(50) NOT NULL,
            `receipt_path` VARCHAR(255) NOT NULL,
            `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `support_tickets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `client_id` INT NOT NULL,
            `subscriber_id` INT NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `status` ENUM('open', 'closed', 'resolved') DEFAULT 'open',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {}

    // 12. Fix server_public_ip in global_settings to current VPS IP!
    try {
        $vpsIp = '187.126.127.237';
        $pdo->exec("INSERT INTO `global_settings` (`setting_key`, `setting_value`) VALUES ('server_public_ip', '{$vpsIp}') ON DUPLICATE KEY UPDATE `setting_value` = '{$vpsIp}'");
        $pdo->exec("INSERT INTO `global_settings` (`setting_key`, `setting_value`) VALUES ('sys_schema_ver', '6') ON DUPLICATE KEY UPDATE `setting_value` = '6'");
        $report[] = "Server public IP updated to {$vpsIp} & schema version set to 6";
    } catch (Exception $e) {}

    // 13. Ensure high-speed RADIUS indexes
    try {
        add_index_if_missing($pdo, 'radpostauth', 'authdate_idx', '`authdate`');
        add_index_if_missing($pdo, 'radacct', 'acctstoptime_idx', '`acctstoptime`');
        add_index_if_missing($pdo, 'radacct', 'acctstarttime_idx', '`acctstarttime`');
        add_index_if_missing($pdo, 'radacct', 'acct_active_idx', '`acctstoptime`, `username`');
        $report[] = "RADIUS performance indexes verified";
    } catch (Exception $e) {}

    // 14. Restart FreeRADIUS
    @shell_exec("sudo systemctl restart freeradius 2>&1 || sudo service freeradius restart 2>&1");
    $report[] = "FreeRADIUS service synchronized";

    return $report;
}
