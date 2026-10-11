<?php
require 'config/db.php';

echo "<h2>Deduplicating & Cleaning radgroupreply</h2>";

try {
    $pdo->beginTransaction();

    // 1. Remove all duplicate Mikrotik-Rate-Limit entries from radgroupreply
    $pdo->exec("DELETE FROM radgroupreply WHERE attribute = 'Mikrotik-Rate-Limit'");

    // 2. Re-insert exactly ONE clean row per package
    $stmt = $pdo->query("SELECT name, rate_limit FROM packages WHERE rate_limit IS NOT NULL AND rate_limit != '' AND rate_limit != 'Unlimited'");
    $pkgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ins = $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)");
    $count = 0;
    foreach ($pkgs as $p) {
        $ins->execute([$p['name'], $p['rate_limit']]);
        $count++;
    }

    $pdo->commit();
    echo "<h3 style='color:green;'>SUCCESS: Cleaned and re-inserted exactly $count package speed rules (NO duplicates)!</h3>";

    // 3. Show current clean radgroupreply
    $chk = $pdo->query("SELECT * FROM radgroupreply WHERE attribute = 'Mikrotik-Rate-Limit'");
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Group Name</th><th>Attribute</th><th>OP</th><th>Value</th></tr>";
    foreach ($chk->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "<tr><td>{$r['groupname']}</td><td>{$r['attribute']}</td><td>{$r['op']}</td><td>{$r['value']}</td></tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>
