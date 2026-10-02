<?php
require_once 'config/db.php';

echo "<h2>Fixing NAS Table Columns...</h2>";

$columns = [
    "ALTER TABLE `nas` ADD COLUMN `client_id` int(11) NOT NULL DEFAULT 0;",
    "ALTER TABLE `nas` ADD COLUMN `api_port` int(11) DEFAULT 8728;",
    "ALTER TABLE `nas` ADD COLUMN `api_user` varchar(50) DEFAULT NULL;",
    "ALTER TABLE `nas` ADD COLUMN `api_password` varchar(255) DEFAULT NULL;",
    "ALTER TABLE `nas` ADD COLUMN `coa_port` int(11) DEFAULT 3799;",
    "ALTER TABLE `nas` ADD COLUMN `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP;"
];

$success = true;
foreach ($columns as $query) {
    try {
        $pdo->exec($query);
        echo "<p style='color:green;'>✔️ Executed: $query</p>";
    } catch (PDOException $e) {
        // If it fails, it usually means the column already exists, which is fine
        echo "<p style='color:orange;'>Info (Skipping): " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<h3>Update Complete!</h3>";
echo "<p><a href='superadmin/routers.php'>Go back to Routers Page</a></p>";
?>
