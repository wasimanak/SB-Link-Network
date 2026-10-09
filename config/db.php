<?php
$host = 'localhost';
$charset = 'utf8mb4';

// Automatically detect if we are on Local PC (XAMPP) or Live VPS
$is_localhost = ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1' || $_SERVER['SERVER_NAME'] === 'localhost');

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
        
    } catch (Exception $e) {
        // Fallback silently if table doesn't exist yet
    }
} catch (\PDOException $e) {
    die("Database connection failed. Please check your database configuration. Error: " . $e->getMessage());
}