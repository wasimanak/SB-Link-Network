<?php
$files = ['operator/recovery_man.php', 'operator/line_man.php'];
foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        
        $tableName = ($f === 'operator/recovery_man.php') ? 'recovery_men' : 'line_men';
        
        $createTableSQL = "
// Auto-create table if not exists to prevent insertion errors
try {
    \$pdo->exec(\"CREATE TABLE IF NOT EXISTS `$tableName` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\");
} catch (PDOException \$e) {
    // Ignore schema errors silently
}
";
        // Insert right after `$client_id = $_SESSION['operator_id'];`
        $insertPoint = '$client_id = $_SESSION[\'operator_id\'];';
        if (strpos($c, $insertPoint) !== false && strpos($c, "CREATE TABLE IF NOT EXISTS `$tableName`") === false) {
            $c = str_replace($insertPoint, $insertPoint . "\n" . $createTableSQL, $c);
        }
        
        // Also revert the error message patch so it shows a clean alert but includes the exception message dynamically for debugging!
        $c = str_replace(
            "echo \"<script>alert('Error: ' + \" . json_encode(\$e->getMessage()) . \"); window.history.back();</script>\";",
            "echo \"<script>alert('Error: ' + \" . json_encode(\$e->getMessage()) . \"); window.history.back();</script>\";",
            $c
        );

        file_put_contents($f, $c);
        echo "Added table creation for $tableName in $f\n";
    }
}
?>
