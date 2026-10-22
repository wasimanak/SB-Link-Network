<?php
if (php_sapi_name() === 'cli' && empty($_SERVER['REMOTE_ADDR'])) {
    // Only default to localhost when run from command line on local PC
    if (gethostname() !== 'radius-vps') {
        // Allow local testing
    }
}
require 'config/db.php';
require_once 'config/security.php';
guard_maintenance_script('RADIUS Load-Balance Sync Tool');

echo "<h2>Syncing RADIUS to Match MikroTik Multi-WAN Load Balancing</h2>";

try {
    $pdo->beginTransaction();

    // 1. Fetch all packages with their assigned pool and WAN
    $pkgs = $pdo->query("SELECT p.*, pl.pool_name 
                         FROM packages p 
                         LEFT JOIN ip_pools pl ON p.pool_id = pl.id")->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1' cellpadding='8' style='border-collapse:collapse; font-family:monospace;'>";
    echo "<tr style='background:#0d6efd; color:#fff;'><th>Package ID</th><th>Package Name</th><th>Assigned Pool (Mikrotik-Group)</th><th>WAN Route (Address-List)</th><th>Speed</th></tr>";

    $sync_count = 0;
    foreach ($pkgs as $p) {
        $pkg_name = $p['name'];
        $pool_name = !empty($p['pool_name']) ? $p['pool_name'] : $pkg_name;
        // Clean pool_name if it has extra chars (e.g. '4Mbps')
        if (preg_match('/^(\d+[mM][bB]ps|\d+[mM][bB])/i', $pkg_name, $m)) {
            if (empty($p['pool_name'])) $pool_name = $m[1];
        }
        $wan = !empty($p['wan_provider']) ? $p['wan_provider'] : 'PTCL';
        $rate = $p['rate_limit'] ?: 'No Limit';

        echo "<tr><td>{$p['id']}</td><td><strong>$pkg_name</strong></td><td style='color:green; font-weight:bold;'>$pool_name</td><td style='color:blue; font-weight:bold;'>$wan</td><td>$rate</td></tr>";

        // A. If radgroupreply table exists, sync it
        try {
            $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ? AND attribute IN ('Mikrotik-Group', 'Framed-Pool', 'Mikrotik-Address-List', 'Mikrotik-Rate-Limit')")->execute([$pkg_name]);
            if (!empty($pool_name)) {
                $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$pkg_name, $pool_name]);
                $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Framed-Pool', ':=', ?)")->execute([$pkg_name, $pool_name]);
            }
            if (!empty($wan)) {
                $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Address-List', '=', ?)")->execute([$pkg_name, $wan]);
            }
            if (!empty($rate) && $rate !== 'Unlimited' && $rate !== 'No Limit') {
                $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)")->execute([$pkg_name, $rate]);
            }
        } catch (Exception $e) {}

        // B. Update subscribers on this package in radreply
        $subs = $pdo->prepare("SELECT username FROM subscribers WHERE package_id = ?");
        $subs->execute([$p['id']]);
        $sub_users = $subs->fetchAll(PDO::FETCH_COLUMN);

        foreach ($sub_users as $u) {
            // Delete Mikrotik-Address-List and Framed-Pool (Subnet-based load balancing is used)
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$u]);
            
            // Insert correct Mikrotik-Group so router selects the profile
            $target_profile = !empty($pool_name) ? $pool_name : '';
            if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg_name, $m_prof)) {
                    $target_profile = $m_prof[1] . 'Mbps';
                }
            }

            if (!empty($target_profile)) {
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$u]);
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$u, $target_profile]);
            }
            
            $sync_count++;
        }
    }

    $pdo->commit();
    echo "</table>";
    echo "<h3 style='color:green;'>SUCCESS: Synced $sync_count subscribers in radreply and all packages!</h3>";
    echo "<p>Ab FreeRADIUS router ko:<br>
    1. <strong>Mikrotik-Group = (e.g. 4Mbps)</strong> bhejega taake router apna Profile assign kare.<br>
    2. Router apne profile ke mutabiq local gateway aur pool (10.4.0.x) se IP dega.<br>
    3. MikroTik Address Lists me 700 dynamic entries banne ke bajaye sirf 15 static package subnets honge!</p>";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
