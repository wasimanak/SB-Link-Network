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
            `can_enable_disable` enum('yes','no') DEFAULT 'no',
            `status` enum('active','disabled') DEFAULT 'active',
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        add_column_if_missing($pdo, 'recovery_men', 'status', "ENUM('active','disabled') DEFAULT 'active'");
        add_column_if_missing($pdo, 'recovery_men', 'can_create_user', "ENUM('yes','no') DEFAULT 'yes'");
        add_column_if_missing($pdo, 'recovery_men', 'can_enable_disable', "ENUM('yes','no') DEFAULT 'no'");
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
        add_column_if_missing($pdo, 'subscribers', 'created_by', "VARCHAR(100) DEFAULT NULL");
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

    // 12. Fix server_public_ip in global_settings if detected
    try {
        $detected_ip = $_SERVER['SERVER_ADDR'] ?? (isset($_SERVER['HTTP_HOST']) ? explode(':', $_SERVER['HTTP_HOST'])[0] : '');
        if (!empty($detected_ip) && !in_array($detected_ip, ['127.0.0.1', '::1', 'localhost'])) {
            $stmt = $pdo->prepare("INSERT INTO `global_settings` (`setting_key`, `setting_value`) VALUES ('server_public_ip', ?) ON DUPLICATE KEY UPDATE `setting_value` = ?");
            $stmt->execute([$detected_ip, $detected_ip]);
            $report[] = "Server public IP updated to {$detected_ip}";
        }
    } catch (Exception $e) {}

    // 13. Ensure high-speed RADIUS & Query indexes
    try {
        add_index_if_missing($pdo, 'radpostauth', 'authdate_idx', '`authdate`');
        add_index_if_missing($pdo, 'radpostauth', 'idx_rp_username', '`username`');
        add_index_if_missing($pdo, 'radacct', 'acctstoptime_idx', '`acctstoptime`');
        add_index_if_missing($pdo, 'radacct', 'acctstarttime_idx', '`acctstarttime`');
        add_index_if_missing($pdo, 'radacct', 'acct_active_idx', '`acctstoptime`, `username`');
        add_index_if_missing($pdo, 'radacct', 'idx_radacct_u_stop', '`username`, `acctstoptime`');
        add_index_if_missing($pdo, 'radacct', 'idx_radacct_nas_stop', '`nasipaddress`, `acctstoptime`');
        add_index_if_missing($pdo, 'subscribers', 'idx_sub_client_exp', '`client_id`, `expiry_date`');
        add_index_if_missing($pdo, 'subscribers', 'idx_sub_client_status', '`client_id`, `status`');
        add_column_if_missing($pdo, 'subscribers', 'last_renewed_at', 'DATETIME DEFAULT NULL');
        add_column_if_missing($pdo, 'subscribers', 'last_renewal_receipt', 'VARCHAR(255) DEFAULT NULL');
        add_column_if_missing($pdo, 'subscribers', 'last_renewal_receipt_at', 'DATETIME DEFAULT NULL');
        add_column_if_missing($pdo, 'subscribers', 'last_renewal_receipt_by', 'VARCHAR(100) DEFAULT NULL');
        add_column_if_missing($pdo, 'subscribers', 'created_by', 'VARCHAR(100) DEFAULT NULL');
        add_column_if_missing($pdo, 'subscribers', 'nasname', 'VARCHAR(128) DEFAULT NULL');
        add_column_if_missing($pdo, 'subscribers', 'router_id', 'INT DEFAULT NULL');
        add_index_if_missing($pdo, 'subscribers', 'idx_sub_nasname', '`nasname`');
        add_index_if_missing($pdo, 'radcheck', 'idx_radcheck_attr_user', '`username`, `attribute`');
        $report[] = "RADIUS performance indexes & subscriber NAS columns verified";
    } catch (Exception $e) {}

    // 13b. Ensure items & stock_transactions tables & columns
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `client_id` INT NOT NULL DEFAULT 1,
            `name` VARCHAR(255) NOT NULL,
            `category` VARCHAR(100) NULL DEFAULT 'General',
            `unit` VARCHAR(50) NOT NULL DEFAULT 'Nos.',
            `model_code` VARCHAR(100) NULL,
            `current_stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `min_alert_qty` DECIMAL(12,2) NOT NULL DEFAULT 5.00,
            `description` TEXT NULL,
            `status` ENUM('active', 'inactive') DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_items_client` (`client_id`),
            INDEX `idx_items_name` (`name`),
            INDEX `idx_items_category` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        add_column_if_missing($pdo, 'items', 'client_id', "INT NOT NULL DEFAULT 1");
        add_column_if_missing($pdo, 'items', 'current_stock', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
        add_column_if_missing($pdo, 'items', 'unit_price', "DECIMAL(12,2) NOT NULL DEFAULT 0.00");
        add_column_if_missing($pdo, 'items', 'min_alert_qty', "DECIMAL(12,2) NOT NULL DEFAULT 5.00");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `stock_transactions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `client_id` INT NOT NULL DEFAULT 1,
            `item_id` INT NOT NULL,
            `type` ENUM('IN', 'OUT') NOT NULL,
            `quantity` DECIMAL(12,2) NOT NULL,
            `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `balance_before` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `reference_no` VARCHAR(100) NULL,
            `party_name` VARCHAR(255) NULL,
            `issued_to_type` VARCHAR(50) DEFAULT 'general',
            `issued_to_id` INT NULL,
            `remarks` TEXT NULL,
            `created_by` VARCHAR(100) NOT NULL DEFAULT 'Admin',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_st_client` (`client_id`),
            INDEX `idx_st_item` (`item_id`),
            INDEX `idx_st_type` (`type`),
            INDEX `idx_st_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        add_column_if_missing($pdo, 'stock_transactions', 'issued_to_type', "VARCHAR(50) DEFAULT 'general'");
        add_column_if_missing($pdo, 'stock_transactions', 'issued_to_id', "INT NULL");
        $report[] = "Stock & Items tables verified";
        $report[] = "RADIUS performance indexes verified";
    } catch (Exception $e) {}

        // 14. Ensure nas table exists for FreeRADIUS and MikroTik API
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `nas` (
          `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `client_id` int(11) DEFAULT 1,
          `nasname` varchar(128) NOT NULL,
          `shortname` varchar(32) DEFAULT NULL,
          `type` varchar(30) DEFAULT 'other',
          `ports` int(11) DEFAULT NULL,
          `secret` varchar(60) NOT NULL DEFAULT 'testing123',
          `server` varchar(64) DEFAULT NULL,
          `community` varchar(50) DEFAULT NULL,
          `description` varchar(200) DEFAULT NULL,
          `api_port` int(11) DEFAULT 8728,
          `api_user` varchar(50) DEFAULT 'admin',
          `api_password` varchar(255) DEFAULT NULL,
          `coa_port` int(11) DEFAULT 3799,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY `nasname` (`nasname`),
          KEY `client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $report[] = "NAS table verified";
    } catch (Exception $e) {}

    // 15. Ensure FreeRADIUS auxiliary group and logging tables
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `radusergroup` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `username` varchar(64) NOT NULL DEFAULT '',
          `groupname` varchar(64) NOT NULL DEFAULT '',
          `priority` int(11) NOT NULL DEFAULT 1,
          KEY `username` (`username`(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `radgroupreply` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `groupname` varchar(64) NOT NULL DEFAULT '',
          `attribute` varchar(64) NOT NULL DEFAULT '',
          `op` char(2) NOT NULL DEFAULT '=',
          `value` varchar(253) NOT NULL DEFAULT '',
          KEY `groupname` (`groupname`(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `radgroupcheck` (
          `id` int(11) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `groupname` varchar(64) NOT NULL DEFAULT '',
          `attribute` varchar(64) NOT NULL DEFAULT '',
          `op` char(2) NOT NULL DEFAULT '==',
          `value` varchar(253) NOT NULL DEFAULT '',
          KEY `groupname` (`groupname`(32))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `radpostauth` (
          `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `username` varchar(64) NOT NULL DEFAULT '',
          `pass` varchar(64) NOT NULL DEFAULT '',
          `reply` varchar(32) NOT NULL DEFAULT '',
          `authdate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `class` varchar(64) DEFAULT NULL,
          KEY `username` (`username`),
          KEY `authdate` (`authdate`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $report[] = "FreeRADIUS auxiliary tables verified (radusergroup, radgroupreply, radgroupcheck, radpostauth)";
    } catch (Exception $e) {}

    // 16. Install Multi-Tenant NAS Isolation Triggers and Synchronize Subscribers
    try {
        $nas_res = sync_all_subscribers_nas($pdo);
        $report[] = "Multi-Tenant NAS Security: Synced {$nas_res['locked']} subscribers across {$nas_res['operators']} operators to their assigned MikroTik routers";
    } catch (Exception $e) {
        $report[] = "Multi-Tenant NAS Security Warning: " . $e->getMessage();
    }

    return $report;
}

/**
 * Ensures database triggers exist to automatically enforce NAS router binding
 * and synchronizes all existing subscribers to their operator's MikroTik router.
 */
function sync_all_subscribers_nas($pdo) {
    // 1. Ensure columns exist
    add_column_if_missing($pdo, 'subscribers', 'nasname', 'VARCHAR(128) DEFAULT NULL');
    add_column_if_missing($pdo, 'subscribers', 'router_id', 'INT DEFAULT NULL');
    add_index_if_missing($pdo, 'subscribers', 'idx_sub_nasname', '`nasname`');

    // 2. Install / Refresh Triggers
    try {
        // Trigger 1: Before Insert - automatically assign operator's default router if not specified
        $pdo->exec("DROP TRIGGER IF EXISTS ensure_subscriber_nas_before_insert");
        $pdo->exec("CREATE TRIGGER ensure_subscriber_nas_before_insert 
            BEFORE INSERT ON subscribers FOR EACH ROW 
            BEGIN 
                IF NEW.nasname IS NULL OR NEW.nasname = '' THEN 
                    SET NEW.nasname = (SELECT nasname FROM nas WHERE client_id = NEW.client_id ORDER BY id ASC LIMIT 1); 
                END IF; 
            END");

        // Trigger 2: After Insert - insert NAS-IP-Address check into radcheck
        $pdo->exec("DROP TRIGGER IF EXISTS restrict_nas_ip_insert");
        $pdo->exec("CREATE TRIGGER restrict_nas_ip_insert 
            AFTER INSERT ON subscribers FOR EACH ROW 
            BEGIN 
                IF NEW.nasname IS NOT NULL AND NEW.nasname != '' THEN 
                    DELETE FROM radcheck WHERE username = NEW.username AND attribute = 'NAS-IP-Address'; 
                    INSERT INTO radcheck (username, attribute, op, value) VALUES (NEW.username, 'NAS-IP-Address', '==', NEW.nasname); 
                END IF; 
            END");

        // Trigger 3: After Update - update NAS-IP-Address check in radcheck
        $pdo->exec("DROP TRIGGER IF EXISTS restrict_nas_ip_update");
        $pdo->exec("CREATE TRIGGER restrict_nas_ip_update 
            AFTER UPDATE ON subscribers FOR EACH ROW 
            BEGIN 
                IF NEW.nasname IS NOT NULL AND NEW.nasname != '' AND (OLD.nasname IS NULL OR OLD.nasname != NEW.nasname OR OLD.username != NEW.username) THEN 
                    DELETE FROM radcheck WHERE username = OLD.username AND attribute = 'NAS-IP-Address'; 
                    DELETE FROM radcheck WHERE username = NEW.username AND attribute = 'NAS-IP-Address'; 
                    INSERT INTO radcheck (username, attribute, op, value) VALUES (NEW.username, 'NAS-IP-Address', '==', NEW.nasname); 
                END IF; 
            END");

        // Trigger 4: After Delete - cleanup radcheck
        $pdo->exec("DROP TRIGGER IF EXISTS cleanup_nas_ip_delete");
        $pdo->exec("CREATE TRIGGER cleanup_nas_ip_delete 
            AFTER DELETE ON subscribers FOR EACH ROW 
            BEGIN 
                DELETE FROM radcheck WHERE username = OLD.username; 
            END");
    } catch (Exception $eTrig) {
        // Triggers might fail if MySQL user lacks TRIGGER privilege; PHP manual sync will still protect
    }

    // 3. Map all routers by client_id
    $nasRows = $pdo->query("SELECT id, client_id, nasname, shortname FROM nas ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $client_routers = [];
    foreach ($nasRows as $nr) {
        $cid = (int)$nr['client_id'];
        $client_routers[$cid][] = $nr;
    }

    // 4. Fetch all subscribers
    $subsStmt = $pdo->query("SELECT id, client_id, username, nasname FROM subscribers");
    $subs = $subsStmt->fetchAll(PDO::FETCH_ASSOC);

    $locked_count = 0;
    $updated_sub_count = 0;

    foreach ($subs as $s) {
        $cid = (int)$s['client_id'];
        $u = trim($s['username']);
        $nasname = trim($s['nasname'] ?? '');

        // If nasname is empty, try to resolve it
        if (empty($nasname)) {
            // Check recent session in radacct
            try {
                $acctStmt = $pdo->prepare("SELECT nasipaddress FROM radacct WHERE username = ? ORDER BY radacctid DESC LIMIT 1");
                $acctStmt->execute([$u]);
                $recent_nas = trim($acctStmt->fetchColumn() ?: '');

                // Verify if recent_nas belongs to this client
                if (!empty($recent_nas) && !empty($client_routers[$cid])) {
                    foreach ($client_routers[$cid] as $cr) {
                        if ($cr['nasname'] === $recent_nas) {
                            $nasname = $recent_nas;
                            break;
                        }
                    }
                }
            } catch (Exception $eA) {}

            // Fallback to client's primary router
            if (empty($nasname) && !empty($client_routers[$cid])) {
                $nasname = $client_routers[$cid][0]['nasname'];
            }

            if (!empty($nasname)) {
                $pdo->prepare("UPDATE subscribers SET nasname = ? WHERE id = ?")->execute([$nasname, $s['id']]);
                $updated_sub_count++;
            }
        }

        // If we have a nasname, enforce it in radcheck
        if (!empty($nasname)) {
            // Check if already correctly present
            $chk = $pdo->prepare("SELECT id, value FROM radcheck WHERE username = ? AND attribute = 'NAS-IP-Address'");
            $chk->execute([$u]);
            $existing_rc = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$existing_rc) {
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'NAS-IP-Address', '==', ?)")->execute([$u, $nasname]);
                $locked_count++;
            } elseif ($existing_rc['value'] !== $nasname) {
                $pdo->prepare("UPDATE radcheck SET value = ?, op = '==' WHERE id = ?")->execute([$nasname, $existing_rc['id']]);
                $locked_count++;
            }
        }
    }

    return [
        'operators' => count($client_routers),
        'subscribers_updated' => $updated_sub_count,
        'locked' => $locked_count
    ];
}
