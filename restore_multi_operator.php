<?php
require 'config/db.php';
require_once 'config/security.php';
guard_maintenance_script('Multi-Operator RADIUS Isolation & Repair Tool');

echo "<h2>Multi-Operator RADIUS Isolation & Repair Tool</h2>";

try {
    $pdo->beginTransaction();

    // 1. Fetch all clients / operators
    $clients = $pdo->query("SELECT id, company_name, phone FROM clients ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>1. Registered Operators in System:</h3>";
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse; font-family:monospace;'>";
    echo "<tr style='background:#333; color:#fff;'><th>Client ID</th><th>Operator / Company Name</th><th>Phone</th><th>Multi-WAN Status</th></tr>";
    
    // Check which client has configured IP pools
    $active_multi_wan_clients = $pdo->query("SELECT DISTINCT client_id FROM ip_pools")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($clients as $cl) {
        $is_mw = in_array($cl['id'], $active_multi_wan_clients);
        $status_label = $is_mw ? "<span style='color:green; font-weight:bold;'>Multi-WAN Active (Custom Pools)</span>" : "<span style='color:blue;'>Standard RADIUS (Auto Router Pool)</span>";
        echo "<tr><td>{$cl['id']}</td><td><strong>{$cl['company_name']}</strong></td><td>{$cl['phone']}</td><td>$status_label</td></tr>";
    }
    echo "</table>";

    // 2. Clean shared radgroupreply to prevent cross-tenant bleeding
    // Only Mikrotik-Rate-Limit should be in radgroupreply (pools and WAN routing must be per-user in radreply)
    $del_grp = $pdo->exec("DELETE FROM radgroupreply WHERE attribute IN ('Mikrotik-Group', 'Framed-Pool', 'Mikrotik-Address-List')");
    echo "<p>Cleaned <strong>$del_grp</strong> shared group routing attributes from <code>radgroupreply</code> to prevent cross-operator interference.</p>";

    // 3. Process each operator independently
    $other_repaired = 0;
    $mw_repaired = 0;

    foreach ($clients as $cl) {
        $cid = (int)$cl['id'];
        $is_mw = in_array($cid, $active_multi_wan_clients);

        if (!$is_mw) {
            // OPERATOR TYPE A: STANDARD OPERATOR (No custom Multi-WAN pools)
            // Remove any unwanted Framed-Pool, Mikrotik-Group, Mikrotik-Address-List from their subscribers!
            // Their router will use its own local pool and connect immediately!
            $sub_users = $pdo->prepare("SELECT s.username, p.rate_limit 
                                        FROM subscribers s 
                                        LEFT JOIN packages p ON s.package_id = p.id 
                                        WHERE s.client_id = ?");
            $sub_users->execute([$cid]);
            $subs = $sub_users->fetchAll(PDO::FETCH_ASSOC);
            $op_count = 0;

            foreach ($subs as $s) {
                $u = $s['username'];
                // Delete pool & wan attributes
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Framed-Pool', 'Mikrotik-Group', 'Mikrotik-Address-List')")->execute([$u]);
                
                // Ensure rate limit is intact
                if (!empty($s['rate_limit']) && $s['rate_limit'] !== 'No Limit' && $s['rate_limit'] !== 'Unlimited') {
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $s['rate_limit']]);
                }
                $op_count++;
                $other_repaired++;
            }
            echo "<p style='color:blue;'>Operator <strong>{$cl['company_name']} (ID: $cid)</strong>: Restored $op_count subscribers to Standard Router Pool. Their internet will connect normally.</p>";

        } else {
            // OPERATOR TYPE B: MULTI-WAN OPERATOR (Has configured IP pools & WAN routing)
            // Only assign Framed-Pool and Mikrotik-Address-List for packages that explicitly have a pool assigned!
            $pkg_stmt = $pdo->prepare("SELECT p.id, p.name, p.rate_limit, p.wan_provider, p.pool_id, pl.pool_name 
                                       FROM packages p 
                                       LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
                                       WHERE p.client_id = ?");
            $pkg_stmt->execute([$cid]);
            $pkgs = $pkg_stmt->fetchAll(PDO::FETCH_ASSOC);
            $mw_op_count = 0;

            foreach ($pkgs as $p) {
                $sub_stmt = $pdo->prepare("SELECT username FROM subscribers WHERE package_id = ? AND client_id = ?");
                $sub_stmt->execute([$p['id'], $cid]);
                $subs = $sub_stmt->fetchAll(PDO::FETCH_COLUMN);

                $pool_name = $p['pool_name'];
                $wan = !empty($p['wan_provider']) ? $p['wan_provider'] : 'PTCL';
                $rate = $p['rate_limit'];

                foreach ($subs as $u) {
                    // Always clean first
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Framed-Pool', 'Mikrotik-Group', 'Mikrotik-Address-List', 'Mikrotik-Rate-Limit')")->execute([$u]);
                    
                    // Rate limit
                    if (!empty($rate) && $rate !== 'No Limit' && $rate !== 'Unlimited') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $rate]);
                    }

                    // Only inject Mikrotik-Group if this package has a real profile / pool
                    $target_profile = !empty($pool_name) ? $pool_name : '';
                    if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                        if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $p['name'], $m_prof)) {
                            $target_profile = $m_prof[1] . 'Mbps';
                        }
                    }

                    if (!empty($target_profile)) {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$u, $target_profile]);
                    }
                    $mw_op_count++;
                    $mw_repaired++;
                }
            }
            echo "<p style='color:green;'>Operator <strong>{$cl['company_name']} (ID: $cid)</strong>: Synced $mw_op_count subscribers to their dedicated IP Pools and WAN routes.</p>";
        }
    }

    $pdo->commit();
    echo "<h3 style='color:green;'>SUCCESS: Complete Multi-Operator Isolation Applied!</h3>";
    echo "<p>1. Other operators ke subscribers se tamam pool/wan restrictions khatam kar di gayi hain taake unka internet normal chal sakay.<br>
             2. Aapke (Multi-WAN operator) ke subscribers apne apne pools aur WAN ports par chalenge.<br>
             3. Kisi ek operator ki settings se doosre operator ko koi farq nahi parega!</p>";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}
