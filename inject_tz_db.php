<?php
$f = 'config/db.php';
$c = file_get_contents($f);

$tz_logic = <<<'PHP'
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
PHP;

$c = str_replace('$pdo = new PDO($dsn, $user, $pass, $options);', $tz_logic, $c);

file_put_contents($f, $c);
echo "Injected timezone logic into config/db.php\n";
?>
