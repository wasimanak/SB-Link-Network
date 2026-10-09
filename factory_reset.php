<?php
require_once 'config/db.php';

echo "<h2>Factory Reset Database</h2>";

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // 1. Flush Custom Dashboard Tables
    $tables_to_truncate = [
        'clients',      // Operators
        'nas',          // Routers (This will remove the 2 hidden wildcards)
        'subscribers',  // Users created in dashboard
        'packages'      // Operator Packages
    ];

    foreach ($tables_to_truncate as $table) {
        $pdo->exec("TRUNCATE TABLE `$table`");
        echo "Cleared table: <b>$table</b><br>";
    }

    // 2. Flush FreeRADIUS Tables
    $radius_tables = [
        'radcheck',      // Passwords
        'radreply',      // User specific attributes (speed)
        'radusergroup',  // User to package mapping
        'radgroupreply', // Package specific attributes
        'radgroupcheck',
        'radacct',       // Live and Historical Sessions (This resets Live Users to 0)
        'radpostauth'    // Auth logs
    ];

    foreach ($radius_tables as $table) {
        $pdo->exec("TRUNCATE TABLE `$table`");
        echo "Cleared FreeRADIUS table: <b>$table</b><br>";
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "<br><h3 style='color:green;'>Success! Database has been completely flushed.</h3>";
    echo "<p>All operators, routers, users, and active sessions have been wiped. You can now start fresh.</p>";
    echo "<p><b>Important:</b> Because we deleted the Routers from the database, please restart your VPS (or FreeRADIUS service) so it clears its memory. After that, add your Operators and Routers from scratch.</p>";

} catch (Exception $e) {
    echo "<h3 style='color:red;'>Error:</h3>" . $e->getMessage();
}
?>
