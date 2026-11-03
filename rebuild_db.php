<?php
// Script to safely create all required tables and structures
require_once __DIR__ . '/config/security.php';
if (function_exists('guard_maintenance_script')) {
    guard_maintenance_script('Rebuild Database');
}

require_once __DIR__ . '/config/db.php';

$sql_file = __DIR__ . '/database_schema.sql';

if (file_exists($sql_file)) {
    $sql = file_get_contents($sql_file);
    
    try {
        $pdo->exec($sql);
        echo "<h2 style='color:green;'>Success! Database Structure Rebuilt on VPS.</h2>";
        echo "<p>All tables, columns, and triggers have been successfully created or verified.</p>";
        echo "<p><a href='superadmin/login.php'>Go to Superadmin Login</a></p>";
    } catch (PDOException $e) {
        echo "<h2 style='color:red;'>Error!</h2>";
        echo "<p>" . $e->getMessage() . "</p>";
    }
} else {
    echo "Error: database_schema.sql not found!";
}
?>