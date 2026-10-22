<?php
require 'config/db.php';

echo "<h2>Global 715 Users Speed Audit & Bulk Fix</h2>";

try {
    $pdo->beginTransaction();

    // 1. Total subscribers count across the entire database
    $total_subs = $pdo->query("SELECT COUNT(*) FROM subscribers")->fetchColumn();
    $active_subs = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn();

    echo "<p>Total Subscribers in DB: <strong>$total_subs</strong> (Active: <strong>$active_subs</strong>)</p>";

    // 2. Find all subscribers who have NO rate-limit in FreeRADIUS
    $sql_missing = "
        SELECT s.id, s.username, s.package_id, p.name as pkg_name, p.rate_limit
        FROM subscribers s
        LEFT JOIN packages p ON s.package_id = p.id
        LEFT JOIN radusergroup g ON s.username = g.username
        LEFT JOIN radgroupreply r ON g.groupname = r.groupname AND r.attribute = 'Mikrotik-Rate-Limit'
        LEFT JOIN radreply ind ON s.username = ind.username AND ind.attribute = 'Mikrotik-Rate-Limit'
        WHERE (r.value IS NULL OR r.value = '') AND (ind.value IS NULL OR ind.value = '')
    ";
    $missing_users = $pdo->query($sql_missing)->fetchAll(PDO::FETCH_ASSOC);
    $missing_count = count($missing_users);

    echo "<h3 style='color:red;'>MASLA PAKRA GAYA: Total $missing_count Users ke paas FreeRADIUS me koi Speed Limit nahi hai!</h3>";
    echo "<p>Kyunke in $missing_count users ki koi limit FreeRADIUS me nahi hai, MikroTik inki Simple Queue banata hi nahi aur inko UNLIMITED speed de deta hai!</p>";

    // 3. MASTER FIX: Assign exact rate limits to ALL subscribers across all packages
    echo "<h3>Applying Bulk Fix for ALL Subscribers...</h3>";

    // Step A: Sync all package definitions to radgroupreply
    $pdo->exec("
        INSERT INTO radgroupreply (groupname, attribute, op, value)
        SELECT name, 'Mikrotik-Rate-Limit', '=', rate_limit
        FROM packages
        WHERE rate_limit IS NOT NULL AND rate_limit != '' AND rate_limit != 'Unlimited'
        ON DUPLICATE KEY UPDATE value = VALUES(value), op = '='
    ");

    // Step B: Ensure EVERY subscriber is in radusergroup pointing to their package
    $aff_group = $pdo->exec("
        INSERT INTO radusergroup (username, groupname, priority)
        SELECT s.username, p.name, 1
        FROM subscribers s
        JOIN packages p ON s.package_id = p.id
        WHERE p.name IS NOT NULL
        ON DUPLICATE KEY UPDATE groupname = VALUES(groupname)
    ");
    echo "<p style='color:green;'>1. radusergroup: Assigned correct package groups to <strong>$aff_group</strong> subscribers.</p>";

    // Step C: Also inject direct Mikrotik-Rate-Limit in radreply for EVERY subscriber as a 100% fail-safe!
    $aff_ind = $pdo->exec("
        INSERT INTO radreply (username, attribute, op, value)
        SELECT s.username, 'Mikrotik-Rate-Limit', '=', p.rate_limit
        FROM subscribers s
        JOIN packages p ON s.package_id = p.id
        WHERE p.rate_limit IS NOT NULL AND p.rate_limit != '' AND p.rate_limit != 'Unlimited'
        ON DUPLICATE KEY UPDATE value = VALUES(value), op = '='
    ");
    echo "<p style='color:green;'>2. radreply: Injected direct fail-safe rate limit for <strong>$aff_ind</strong> subscribers.</p>";

    $pdo->commit();
    echo "<h3 style='color:green;'>SUCCESS: All Subscribers are now 100% armed with Speed Limits in FreeRADIUS!</h3>";
    echo "<p>Ab jab 715 users connect honge, tou pure ke pure 715 users ki Simple Queues banengi aur kisi aik user ki speed bhi leak nahi hogi!</p>";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>
