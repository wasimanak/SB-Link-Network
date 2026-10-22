<?php
require 'config/db.php';

echo "<h2>Syncing Package Speed Limits to FreeRADIUS (radgroupreply)</h2>";

try {
    $pdo->beginTransaction();

    // Fetch all packages with valid rate_limit
    $stmt = $pdo->query("SELECT id, name, rate_limit FROM packages WHERE rate_limit IS NOT NULL AND rate_limit != '' AND rate_limit != 'Unlimited'");
    $pkgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Package ID</th><th>Package Name (Group)</th><th>Rate Limit</th><th>Status</th></tr>";

    foreach ($pkgs as $p) {
        $groupname = $p['name'];
        $rate = $p['rate_limit'];

        // 1. Delete any empty or invalid rows for this group in radgroupreply
        $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ? AND (attribute = 'Mikrotik-Rate-Limit' OR attribute IS NULL OR attribute = '')")->execute([$groupname]);

        // 2. Insert clean Mikrotik-Rate-Limit in radgroupreply
        $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)")
            ->execute([$groupname, $rate]);

        echo "<tr><td>{$p['id']}</td><td><strong>$groupname</strong></td><td style='color:green; font-weight:bold;'>$rate</td><td>Synced to FreeRADIUS!</td></tr>";
    }

    $pdo->commit();
    echo "</table>";
    echo "<h3 style='color:green;'>SUCCESS: All Package Speed Limits are now 100% saved in FreeRADIUS!</h3>";
    echo "<p>Ab FreeRADIUS router ko exact package ki speed (25M/25M) khud bhejega. Jab user reconnect karega, Simple Queue 25M/25M banegi!</p>";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>
