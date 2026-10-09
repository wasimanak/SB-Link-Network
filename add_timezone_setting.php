<?php
require_once 'config/db.php';
try {
    $stmt = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'system_timezone'");
    $tz = $stmt->fetchColumn();
    if (!$tz) {
        $pdo->exec("INSERT INTO global_settings (setting_key, setting_value) VALUES ('system_timezone', 'Asia/Karachi')");
        echo "Inserted system_timezone into global_settings.\n";
    } else {
        echo "system_timezone already exists: $tz\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
