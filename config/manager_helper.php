<?php
/**
 * Manager Role & Access Control Helper
 * SB Link Network / NetBee
 */

require_once __DIR__ . '/db.php';

/**
 * Ensure managers table and activity_logs schema exist
 */
function ensure_manager_schema($pdo) {
    static $schema_checked = false;
    if ($schema_checked) return;
    $schema_checked = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `managers` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `client_id` INT NOT NULL,
              `full_name` VARCHAR(100) NOT NULL,
              `username` VARCHAR(50) NOT NULL,
              `password` VARCHAR(255) NOT NULL,
              `phone` VARCHAR(25) DEFAULT NULL,
              `email` VARCHAR(100) DEFAULT NULL,
              `address` TEXT DEFAULT NULL,
              `city` VARCHAR(50) DEFAULT NULL,
              `status` ENUM('active', 'inactive') DEFAULT 'active',
              `can_manage_packages` ENUM('yes', 'no') DEFAULT 'no',
              `can_collect_recovery` ENUM('yes', 'no') DEFAULT 'no',
              `permissions` TEXT DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `uk_manager_username` (`username`),
              INDEX (`client_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e) {}

    // Add manager_id to activity_logs if missing
    try {
        $pdo->exec("ALTER TABLE `activity_logs` ADD COLUMN `manager_id` INT DEFAULT NULL");
    } catch (Exception $e) {}

    // Auto-stamp trigger for all activity log inserts
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS `trg_activity_logs_manager`");
        $pdo->exec("
            CREATE TRIGGER `trg_activity_logs_manager` 
            BEFORE INSERT ON `activity_logs` 
            FOR EACH ROW 
            BEGIN 
                IF NEW.manager_id IS NULL AND @current_manager_id IS NOT NULL THEN 
                    SET NEW.manager_id = @current_manager_id; 
                    IF @current_manager_name IS NOT NULL THEN 
                        SET NEW.by_user = @current_manager_name; 
                    END IF; 
                    SET NEW.by_role = 'Manager'; 
                END IF; 
            END;
        ");
    } catch (Exception $e) {}
}

/**
 * Check if the currently logged-in user is a Manager
 */
function is_manager() {
    return !empty($_SESSION['is_manager']);
}

/**
 * Check if user can manage packages (Operator always can, Manager needs permission)
 */
function can_manage_packages() {
    if (!is_manager()) return true;
    return !empty($_SESSION['can_manage_packages']);
}

/**
 * Check if user can collect recovery cash (Operator always can, Manager needs permission)
 */
function can_collect_recovery() {
    if (!is_manager()) return true;
    return !empty($_SESSION['can_collect_recovery']);
}

/**
 * Log user/manager activity with automatic role detection
 */
function log_staff_activity($pdo, $activity, $against_to = 'System', $against_role = 'System', $dealer_id = null) {
    $client_id = (int)($_SESSION['operator_id'] ?? ($_SESSION['client_id'] ?? 0));
    if ($client_id <= 0) return;

    $is_mgr = is_manager();
    $manager_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
    $by_user = $is_mgr 
        ? ($_SESSION['manager_name'] ?? ($_SESSION['manager_username'] ?? 'Manager')) 
        : ($_SESSION['operator_company'] ?? ($_SESSION['operator_name'] ?? 'Operator'));
    $by_role = $is_mgr ? 'Manager' : 'Operator';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (client_id, manager_id, dealer_id, by_user, by_role, against_to, against_role, activity, station_ip) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $client_id,
            $manager_id,
            $dealer_id,
            $by_user,
            $by_role,
            $against_to,
            $against_role,
            $activity,
            $ip
        ]);
    } catch (Exception $e) {}
}
