<?php
require 'config/db.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Database Fixer</h2>";

$tables = ['recovery_men', 'line_men'];

foreach ($tables as $t) {
    echo "<h3>Checking table: $t</h3>";
    
    // 1. Create if not exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$t` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `client_id` int(11) NOT NULL,
            `full_name` varchar(100) NOT NULL,
            `username` varchar(50) NOT NULL,
            `password` varchar(255) NOT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `city` varchar(50) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        echo "<p style='color:green;'>Table $t exists or created.</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red;'>Failed to create table $t: " . $e->getMessage() . "</p>";
    }

    // 2. Try to add columns if they are missing
    $cols = ['phone' => 'varchar(20)', 'address' => 'text', 'city' => 'varchar(50)'];
    foreach ($cols as $col => $type) {
        try {
            // Check if column exists
            $stmt = $pdo->query("SHOW COLUMNS FROM `$t` LIKE '$col'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$col` $type DEFAULT NULL");
                echo "<p style='color:blue;'>Added column $col to $t.</p>";
            } else {
                echo "<p style='color:gray;'>Column $col already exists in $t.</p>";
            }
        } catch (PDOException $e) {
            echo "<p style='color:red;'>Error altering column $col in $t: " . $e->getMessage() . "</p>";
        }
    }
}
echo "<hr><p>Done.</p>";
?>
