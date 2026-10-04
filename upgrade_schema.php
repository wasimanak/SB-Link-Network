<?php
$files = ['operator/recovery_man.php', 'operator/line_man.php'];

foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        
        $tableName = ($f === 'operator/recovery_man.php') ? 'recovery_men' : 'line_men';
        
        $alterTableSQL = "
// Auto-upgrade schema if columns are missing
try {
    \$pdo->exec(\"ALTER TABLE `$tableName` ADD COLUMN `city` varchar(50) DEFAULT NULL\");
} catch (PDOException \$e) {
    // Column already exists or other error, ignore silently
}
try {
    \$pdo->exec(\"ALTER TABLE `$tableName` ADD COLUMN `phone` varchar(20) DEFAULT NULL\");
} catch (PDOException \$e) {
}
try {
    \$pdo->exec(\"ALTER TABLE `$tableName` ADD COLUMN `address` text DEFAULT NULL\");
} catch (PDOException \$e) {
}
";
        
        // Find the spot where I added the table creation logic
        $insertPoint = "// Auto-create table if not exists";
        
        if (strpos($c, $insertPoint) !== false && strpos($c, "ALTER TABLE `$tableName` ADD COLUMN `city`") === false) {
            $c = str_replace($insertPoint, $alterTableSQL . "\n" . $insertPoint, $c);
            file_put_contents($f, $c);
            echo "Added ALTER TABLE for $tableName in $f\n";
        }
    }
}
?>
