<?php
require_once 'config/db.php';

echo "<h2>Resetting All Users and Data...</h2>";

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $tables_to_truncate = [
        'radcheck',
        'radusergroup',
        'radreply',
        'radacct',
        'radpostauth',
        'subscribers',
        'package_requests',
        'support_tickets'
    ];

    foreach ($tables_to_truncate as $table) {
        try {
            $pdo->exec("TRUNCATE TABLE `$table`");
            echo "<p style='color: green;'>Cleared table: $table</p>";
        } catch (\PDOException $e) {
            echo "<p style='color: orange;'>Skipped table: $table (Doesn't exist or error)</p>";
        }
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "<h1 style='color: green;'>✅ All Users and their Data have been completely wiped!</h1>";
    echo "<p>The database is now fresh and ready for testing.</p>";
    echo "<p><b>Next Step:</b> Go to <a href='http://sblink.tech/superadmin/login.php'>http://sblink.tech/superadmin/login.php</a>, create exactly ONE test user, and try connecting from MikroTik.</p>";

} catch (\PDOException $e) {
    echo "<h1 style='color: red;'>❌ Error Clearing Database</h1>";
    echo "<p><strong>Message:</strong> " . $e->getMessage() . "</p>";
}
?>
