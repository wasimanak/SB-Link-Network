<?php
require_once __DIR__ . '/branding.php';

$host = 'localhost';
$charset = 'utf8mb4';

// Automatically detect if we are on Local PC (XAMPP) or Live VPS
$is_localhost = (DIRECTORY_SEPARATOR === '\\' || (isset($_SERVER['REMOTE_ADDR']) && in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) || (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] === 'localhost'));

if ($is_localhost) {
    // XAMPP (Local) Credentials
    $db   = 'radius_admin'; // Aapke local XAMPP ka database
    $user = 'root';
    $pass = ''; // XAMPP mein password nahi hota
} else {
    // VPS (Live Server) Credentials
    $db   = 'radius'; 
    $user = 'radius';
    $pass = 'Wasi1234';
}

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Auto Timezone Sync
    try {
        $tzStmt = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'system_timezone'");
        $system_tz = $tzStmt->fetchColumn();
        
        if (!$system_tz) {
            // Default to Pakistan if not set
            $system_tz = 'Asia/Karachi';
            $pdo->exec("INSERT IGNORE INTO global_settings (setting_key, setting_value) VALUES ('system_timezone', '$system_tz')");
        }
        
        // Apply to PHP
        date_default_timezone_set($system_tz);
        
        // Apply to MySQL (Try named timezone, fallback to offset if mysql timezones are not loaded)
        $offset = date('P'); // Gets offset like '+05:00'
        $pdo->exec("SET time_zone = '$offset'");
        
        // Auto-update app_name in global_settings if it's still SB Link
        try {
            $pdo->exec("UPDATE global_settings SET setting_value = 'NetBee' WHERE setting_key = 'app_name' AND setting_value LIKE '%SB%Link%'");
        } catch (Exception $e) {}

        // Auto-upgrade schema for Team Staff Salaries & Ledger
        try {
            $pdo->exec("ALTER TABLE `recovery_men` ADD COLUMN IF NOT EXISTS `monthly_salary` INT NOT NULL DEFAULT 0");
        } catch (Exception $e) {
            try { $pdo->exec("ALTER TABLE `recovery_men` ADD COLUMN `monthly_salary` INT NOT NULL DEFAULT 0"); } catch (Exception $e2) {}
        }
        try {
            $pdo->exec("ALTER TABLE `linemen` ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'disabled') DEFAULT 'active'");
        } catch (Exception $e) {
            try { $pdo->exec("ALTER TABLE `linemen` ADD COLUMN `status` ENUM('active', 'disabled') DEFAULT 'active'"); } catch (Exception $e2) {}
        }
        try {
            $pdo->exec("ALTER TABLE `linemen` ADD COLUMN IF NOT EXISTS `can_create_users` TINYINT(1) DEFAULT 1");
        } catch (Exception $e) {
            try { $pdo->exec("ALTER TABLE `linemen` ADD COLUMN `can_create_users` TINYINT(1) DEFAULT 1"); } catch (Exception $e2) {}
        }
        try {
            $pdo->exec("ALTER TABLE `linemen` ADD COLUMN IF NOT EXISTS `monthly_salary` INT NOT NULL DEFAULT 0");
        } catch (Exception $e) {
            try { $pdo->exec("ALTER TABLE `linemen` ADD COLUMN `monthly_salary` INT NOT NULL DEFAULT 0"); } catch (Exception $e2) {}
        }
        try {
            $pdo->exec("ALTER TABLE `managers` ADD COLUMN IF NOT EXISTS `monthly_salary` INT NOT NULL DEFAULT 0");
        } catch (Exception $e) {
            try { $pdo->exec("ALTER TABLE `managers` ADD COLUMN `monthly_salary` INT NOT NULL DEFAULT 0"); } catch (Exception $e2) {}
        }
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
        } catch (Exception $e) {}
        
    } catch (Exception $e) {
        // Fallback silently if table doesn't exist yet
    }
} catch (\PDOException $e) {
    if ($is_localhost) {
        die("Database connection failed. Please check your database configuration. Error: " . $e->getMessage());
    } else {
        error_log("Database connection error: " . $e->getMessage());
        die("Database connection error. Please contact system administrator.");
    }
}
