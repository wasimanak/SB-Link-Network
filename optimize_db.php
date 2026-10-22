<?php
require_once 'config/db.php';

echo "<h2>RADIUS Database Optimization</h2>";

try {
    echo "<b>Step 1: Adding Indexes to radacct...</b><br>";
    $pdo->exec("ALTER TABLE radacct ADD INDEX IF NOT EXISTS username_idx (username)");
    $pdo->exec("ALTER TABLE radacct ADD INDEX IF NOT EXISTS acctstoptime_idx (acctstoptime)");
    $pdo->exec("ALTER TABLE radacct ADD INDEX IF NOT EXISTS acctstarttime_idx (acctstarttime)");
    echo "<span style='color:green;'>Indexes added successfully.</span><br><br>";
} catch (Exception $e) {
    echo "<span style='color:orange;'>Indexes might already exist or skipped: " . $e->getMessage() . "</span><br><br>";
}

try {
    echo "<b>Step 2: Adding Indexes to radcheck...</b><br>";
    $pdo->exec("ALTER TABLE radcheck ADD INDEX IF NOT EXISTS username_idx (username)");
    echo "<span style='color:green;'>Indexes added successfully.</span><br><br>";
} catch (Exception $e) {
    echo "<span style='color:orange;'>Indexes might already exist or skipped: " . $e->getMessage() . "</span><br><br>";
}

try {
    echo "<b>Step 3: Closing Ghost Sessions...</b><br>";
    $affected = $pdo->exec("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset' WHERE acctstoptime IS NULL AND acctstarttime < NOW() - INTERVAL 1 DAY");
    echo "<span style='color:green;'>Closed $affected ghost sessions older than 24 hours.</span><br><br>";
} catch (Exception $e) {
    echo "<span style='color:red;'>Error closing ghost sessions: " . $e->getMessage() . "</span><br><br>";
}

try {
    echo "<b>Step 4: Deleting old RADIUS auth logs (radpostauth)...</b><br>";
    $affected = $pdo->exec("DELETE FROM radpostauth WHERE authdate < NOW() - INTERVAL 7 DAY");
    echo "<span style='color:green;'>Deleted $affected old auth logs.</span><br><br>";
} catch (Exception $e) {
    echo "<span style='color:orange;'>Error cleaning auth logs: " . $e->getMessage() . "</span><br><br>";
}

try {
    echo "<b>Step 5: Optimizing Tables (Defragmentation)...</b><br>";
    $pdo->exec("OPTIMIZE TABLE radacct, radcheck, radpostauth, subscribers");
    echo "<span style='color:green;'>Tables optimized successfully.</span><br><br>";
} catch (Exception $e) {
    echo "<span style='color:red;'>Error optimizing tables: " . $e->getMessage() . "</span><br><br>";
}

echo "<h3>✅ Optimization Complete! Your RADIUS server should no longer timeout.</h3>";
?>
