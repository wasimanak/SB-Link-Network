<?php
require_once __DIR__ . '/db.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS global_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE,
    setting_value TEXT
)");

$pdo->exec("INSERT IGNORE INTO global_settings (setting_key, setting_value) VALUES 
    ('app_name', 'SB Link Network'), 
    ('timezone', 'Asia/Karachi'), 
    ('currency', 'PKR')
");

echo "Tables created.";
