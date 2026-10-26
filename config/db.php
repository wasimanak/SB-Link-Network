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

        // Reset MySQL session variables so no cross-request state lingers
        try {
            $pdo->exec("SET @current_manager_id = NULL, @current_manager_name = NULL");
        } catch (Exception $e) {}
        
        // Auto-update app_name in global_settings if it's still SB Link
        try {
            $pdo->exec("UPDATE global_settings SET setting_value = 'NetBee' WHERE setting_key = 'app_name' AND setting_value LIKE '%SB%Link%'");
        } catch (Exception $e) {}

        // Auto-upgrade schema & self-healing (Version-guarded to prevent metadata locks on every web hit)
        static $schema_checked = false;
        if (!$schema_checked) {
            $schema_checked = true;
            try {
                $verStmt = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'sys_schema_ver'");
                $ver = $verStmt ? $verStmt->fetchColumn() : null;
                if ($ver !== '6') {
                    if (file_exists(__DIR__ . '/schema_fix_helper.php')) {
                        require_once __DIR__ . '/schema_fix_helper.php';
                        if (function_exists('fix_and_repair_schema')) {
                            fix_and_repair_schema($pdo);
                        }
                    }
                }
            } catch (Exception $e) {}
        }
        
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
