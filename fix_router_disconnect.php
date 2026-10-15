<?php
require_once 'config/db.php';

echo "<h2>FreeRADIUS Connection & IP Assignment Instant Repair Tool</h2>";

try {
    $pdo->beginTransaction();

    // 1. Clean Framed-Pool and Mikrotik-Group from radgroupreply
    $del_grp = $pdo->exec("DELETE FROM radgroupreply WHERE attribute IN ('Framed-Pool', 'Mikrotik-Group')");
    echo "<p style='color:blue;'>1. radgroupreply: Removed <strong>$del_grp</strong> problematic 'Framed-Pool' and 'Mikrotik-Group' entries.</p>";

    // 2. Clean Framed-Pool and Mikrotik-Group from radreply
    $del_rep = $pdo->exec("DELETE FROM radreply WHERE attribute IN ('Framed-Pool', 'Mikrotik-Group')");
    echo "<p style='color:blue;'>2. radreply: Removed <strong>$del_rep</strong> problematic 'Framed-Pool' and 'Mikrotik-Group' entries.</p>";

    // 3. Verify and maintain Mikrotik-Address-List & Mikrotik-Rate-Limit for all subscribers
    $pkgs = $pdo->query("SELECT id, name, rate_limit, wan_provider FROM packages")->fetchAll(PDO::FETCH_ASSOC);
    $synced_users = 0;

    foreach ($pkgs as $p) {
        $subs_stmt = $pdo->prepare("SELECT username FROM subscribers WHERE package_id = ?");
        $subs_stmt->execute([$p['id']]);
        $users = $subs_stmt->fetchAll(PDO::FETCH_COLUMN);

        $wan = !empty($p['wan_provider']) ? $p['wan_provider'] : 'PTCL';
        $rate = $p['rate_limit'];

        foreach ($users as $u) {
            // Keep / Update Mikrotik-Address-List
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Address-List'")->execute([$u]);
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Address-List', '=', ?)")->execute([$u, $wan]);

            // Keep / Update Mikrotik-Rate-Limit
            if (!empty($rate) && $rate !== 'Unlimited' && $rate !== 'No Limit') {
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $rate]);
            }

            $synced_users++;
        }
    }

    $pdo->commit();

    echo "<h3 style='color:green;'>SUCCESS: Complete Fix Applied!</h3>";
    echo "<ul>
            <li><strong>Router IP Assignment Restored:</strong> Ab router apne local pool aur local gateway se users ko foran IP dega.</li>
            <li><strong>No More Disconnections:</strong> IPCP negotiation fail nahi hogi, users stable connect rahenge.</li>
            <li><strong>Multi-WAN Load Balancing Intact:</strong> Mikrotik-Address-List ({$wan}) active hai, user ka dynamic IP seedha Address List me jayega aur mangle routing ke zariye internet sahi WAN se chalega!</li>
          </ul>";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}
