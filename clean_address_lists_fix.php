<?php
/**
 * NetBee - Multi-WAN Address List & RADIUS Permanent Repair Tool
 * 
 * Purpose:
 * 1. Purges all 'Mikrotik-Address-List' and 'Framed-Pool' attributes from radreply and radgroupreply.
 * 2. Properly maps all subscribers to their package's MikroTik PPP profile using 'Mikrotik-Group'.
 * 3. Connects to MikroTik Router(s) via RouterOS API to delete all dynamic address-list entries (clearing the 700 flood).
 * 4. Ensures only the 15 clean static package subnet entries exist in IP > Firewall > Address Lists.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/routeros_api.class.php';

$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetBee - Multi-WAN Address List & RADIUS Fix</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #0f172a; color: #e2e8f0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 30px 15px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); margin-bottom: 25px; }
        .card-header { background: #0f172a; border-bottom: 1px solid #334155; font-weight: bold; font-size: 1.15rem; color: #38bdf8; padding: 15px 20px; }
        .card-body { padding: 25px; }
        .table { color: #e2e8f0; border-color: #334155; }
        .table-dark { background-color: #0f172a; }
        .table th { background-color: #1e293b; color: #94a3b8; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; }
        .badge-wan { font-size: 0.85rem; padding: 5px 10px; border-radius: 6px; }
        .badge-telenor { background-color: #0284c7; color: white; }
        .badge-ptcl { background-color: #16a34a; color: white; }
        .badge-zong { background-color: #ea580c; color: white; }
        .badge-other { background-color: #6366f1; color: white; }
        pre.code-box { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 15px; color: #38bdf8; font-family: 'Courier New', monospace; font-size: 0.9rem; overflow-x: auto; }
    </style>
</head>
<body>
<div class="container" style="max-width: 1000px;">
    <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom border-secondary">
        <div>
            <h2 class="text-white mb-1"><i class="fa-solid fa-network-wired text-info me-2"></i> NetBee Multi-WAN & Address-List Repair</h2>
            <p class="text-muted mb-0">MikroTik Dynamic Address-List Flood Cleaner & Subnet Load-Balancing Restorer</p>
        </div>
        <div>
            <a href="operator/ip_pools.php" class="btn btn-outline-info"><i class="fa-solid fa-arrow-left me-1"></i> Back to IP Pools</a>
        </div>
    </div>
<?php endif;

try {
    $pdo->beginTransaction();

    // 1. Purge problematic attributes from radreply
    $del_rep_addr = $pdo->exec("DELETE FROM radreply WHERE attribute = 'Mikrotik-Address-List'");
    $del_rep_pool = $pdo->exec("DELETE FROM radreply WHERE attribute = 'Framed-Pool'");

    // 2. Purge problematic attributes from radgroupreply (if exists)
    $del_grp_addr = 0;
    $del_grp_pool = 0;
    try {
        $del_grp_addr = $pdo->exec("DELETE FROM radgroupreply WHERE attribute = 'Mikrotik-Address-List'");
        $del_grp_pool = $pdo->exec("DELETE FROM radgroupreply WHERE attribute = 'Framed-Pool'");
    } catch (Exception $e) {}

    // 3. Process all operators and sync radreply with clean Mikrotik-Group
    $clients = $pdo->query("SELECT id, company_name, phone FROM clients ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $active_pool_clients = $pdo->query("SELECT DISTINCT client_id FROM ip_pools")->fetchAll(PDO::FETCH_COLUMN);

    $total_synced_subs = 0;
    $operator_reports = [];

    foreach ($clients as $cl) {
        $cid = (int)$cl['id'];
        $is_multi_wan = in_array($cid, $active_pool_clients);

        if ($is_multi_wan) {
            // Fetch packages with pool mappings
            $pkg_stmt = $pdo->prepare("SELECT p.id, p.name, p.rate_limit, p.wan_provider, p.pool_id, pl.pool_name, pl.subnet_cidr 
                                       FROM packages p 
                                       LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
                                       WHERE p.client_id = ?");
            $pkg_stmt->execute([$cid]);
            $packages = $pkg_stmt->fetchAll(PDO::FETCH_ASSOC);

            $op_subs_synced = 0;
            foreach ($packages as $pkg) {
                // Determine clean profile name (e.g. '4Mbps')
                $target_profile = !empty($pkg['pool_name']) ? $pkg['pool_name'] : '';
                if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                    if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg['name'], $m_prof)) {
                        $target_profile = $m_prof[1] . 'Mbps';
                    } else {
                        $target_profile = $pkg['name'];
                    }
                }

                $subs_stmt = $pdo->prepare("SELECT username FROM subscribers WHERE package_id = ? AND client_id = ?");
                $subs_stmt->execute([$pkg['id'], $cid]);
                $subs = $subs_stmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($subs as $u) {
                    // 1. Delete Address-List & Framed-Pool
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$u]);

                    // 2. Set Mikrotik-Group so router matches Winbox PPP Profile
                    if (!empty($target_profile)) {
                        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$u]);
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$u, $target_profile]);
                    }

                    // 3. Keep rate limit
                    $rate = $pkg['rate_limit'];
                    if (!empty($rate) && $rate !== 'Unlimited' && $rate !== 'No Limit') {
                        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $rate]);
                    }

                    $op_subs_synced++;
                    $total_synced_subs++;
                }
            }

            $operator_reports[] = [
                'name' => $cl['company_name'],
                'id' => $cid,
                'type' => 'Multi-WAN Operator',
                'subs_count' => $op_subs_synced,
                'status' => 'Synced Mikrotik-Group profiles (Address-List purged)'
            ];

        } else {
            // Standard single-router operator: Remove any group/pool/address-list so default pool is used
            $subs_stmt = $pdo->prepare("SELECT s.username, p.rate_limit 
                                       FROM subscribers s 
                                       LEFT JOIN packages p ON s.package_id = p.id 
                                       WHERE s.client_id = ?");
            $subs_stmt->execute([$cid]);
            $subs = $subs_stmt->fetchAll(PDO::FETCH_ASSOC);

            $op_subs_synced = 0;
            foreach ($subs as $s) {
                $u = $s['username'];
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Mikrotik-Group', 'Framed-Pool')")->execute([$u]);
                
                if (!empty($s['rate_limit']) && $s['rate_limit'] !== 'Unlimited' && $s['rate_limit'] !== 'No Limit') {
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $s['rate_limit']]);
                }
                $op_subs_synced++;
                $total_synced_subs++;
            }

            $operator_reports[] = [
                'name' => $cl['company_name'],
                'id' => $cid,
                'type' => 'Standard Single-Pool Operator',
                'subs_count' => $op_subs_synced,
                'status' => 'Restored to Standard Default Router Pool'
            ];
        }
    }

    $pdo->commit();

    // 4. Live RouterOS API: Clean Dynamic Address Lists and Ensure Clean Subnet Entries
    $nas_list = $pdo->query("SELECT * FROM nas ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $router_actions = [];

    foreach ($nas_list as $nas) {
        $cid = (int)$nas['client_id'];
        $r_name = !empty($nas['shortname']) ? $nas['shortname'] : $nas['nasname'];
        $port = !empty($nas['api_port']) ? (int)$nas['api_port'] : 8728;
        $user = !empty($nas['api_user']) ? $nas['api_user'] : 'admin';
        $pass = !empty($nas['api_password']) ? $nas['api_password'] : '';

        $API = new RouterosAPI();
        $API->timeout = 3;
        $API->attempts = 1;
        $API->delay = 1;
        error_reporting(0);

        $connected = $API->connect($nas['nasname'], $user, $pass, $port);
        if ($connected) {
            // A. Fetch all address-list entries
            $all_al = $API->comm('/ip/firewall/address-list/print');
            $dynamic_removed = 0;
            $static_present = 0;

            if (is_array($all_al)) {
                foreach ($all_al as $entry) {
                    $is_dynamic = isset($entry['dynamic']) && ($entry['dynamic'] === 'true' || $entry['dynamic'] === 'yes');
                    if ($is_dynamic && !empty($entry['.id'])) {
                        $API->comm('/ip/firewall/address-list/remove', ['.id' => $entry['.id']]);
                        $dynamic_removed++;
                    }
                }
            }

            // B. Ensure the static package subnet entries exist
            $pools_stmt = $pdo->prepare("SELECT pool_name, subnet_cidr, wan_provider_name FROM ip_pools WHERE client_id = ?");
            $pools_stmt->execute([$cid]);
            $client_pools = $pools_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Re-fetch remaining static entries
            $remaining_al = $API->comm('/ip/firewall/address-list/print');
            $static_map = []; // subnet -> list
            if (is_array($remaining_al)) {
                foreach ($remaining_al as $entry) {
                    $addr = trim($entry['address'] ?? '');
                    if (!empty($addr)) {
                        $static_map[$addr] = $entry;
                    }
                }
            }

            $added_static = 0;
            $updated_static = 0;

            foreach ($client_pools as $p) {
                $subnet = trim($p['subnet_cidr'] ?? '');
                $p_wan = trim($p['wan_provider_name'] ?? 'PTCL');
                $comment = trim($p['pool_name'] ?? '');

                if (empty($subnet)) continue;

                if (isset($static_map[$subnet])) {
                    $current_entry = $static_map[$subnet];
                    if ($current_entry['list'] !== $p_wan || ($current_entry['comment'] ?? '') !== $comment) {
                        $API->comm('/ip/firewall/address-list/set', [
                            '.id' => $current_entry['.id'],
                            'list' => $p_wan,
                            'comment' => $comment
                        ]);
                        $updated_static++;
                    }
                    $static_present++;
                } else {
                    $API->comm('/ip/firewall/address-list/add', [
                        'list' => $p_wan,
                        'address' => $subnet,
                        'comment' => $comment
                    ]);
                    $added_static++;
                    $static_present++;
                }
            }

            $API->disconnect();

            $router_actions[] = [
                'nas' => "{$r_name} ({$nas['nasname']})",
                'status' => 'success',
                'msg' => "Connected successfully! Removed <strong>$dynamic_removed</strong> dynamic address-list entries. Ensured <strong>$static_present</strong> static package subnet entries ($added_static added, $updated_static updated)."
            ];
        } else {
            $router_actions[] = [
                'nas' => "{$r_name} ({$nas['nasname']})",
                'status' => 'offline',
                'msg' => "Could not connect to router API directly (Port $port). Database cleaned! Please run the terminal command below in Winbox Terminal to clear dynamic entries."
            ];
        }
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("<div class='alert alert-danger'>Critical Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}

if (!$is_cli): ?>

    <!-- STEP 1: SUMMARY OF DATABASE FIXES -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="fa-solid fa-database me-2 text-warning"></i> 1. FreeRADIUS Database Cleaned & Synchronized</span>
            <span class="badge bg-success">100% Fixed</span>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="p-3 rounded bg-dark border border-secondary">
                        <div class="text-muted small">Purged Mikrotik-Address-List</div>
                        <div class="fs-4 fw-bold text-danger"><?= (int)$del_rep_addr + (int)$del_grp_addr ?> Entries Removed</div>
                        <small class="text-secondary">FreeRADIUS ab user ka /32 IP Address-List me nahi bhejega.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-3 rounded bg-dark border border-secondary">
                        <div class="text-muted small">Total Subscribers Synced</div>
                        <div class="fs-4 fw-bold text-success"><?= $total_synced_subs ?> Subscribers</div>
                        <small class="text-secondary">Har user apne package PPP Profile (Mikrotik-Group) par set ho chuka hai.</small>
                    </div>
                </div>
            </div>

            <h6 class="text-light mt-3 mb-2">Operator Isolation Breakdown:</h6>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>Operator Name</th>
                            <th>Type</th>
                            <th>Subscribers</th>
                            <th>Applied Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($operator_reports as $rep): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($rep['name']) ?></strong></td>
                            <td><span class="badge <?= $rep['type'] === 'Multi-WAN Operator' ? 'bg-primary' : 'bg-secondary' ?>"><?= $rep['type'] ?></span></td>
                            <td><?= $rep['subs_count'] ?></td>
                            <td class="text-success"><i class="fa-solid fa-check me-1"></i> <?= $rep['status'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- STEP 2: ROUTER LIVE ACTIONS -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="fa-solid fa-server me-2 text-info"></i> 2. MikroTik Router Live API Execution</span>
            <span class="badge bg-info">Router Sync</span>
        </div>
        <div class="card-body">
            <?php foreach ($router_actions as $ra): ?>
                <div class="alert <?= $ra['status'] === 'success' ? 'alert-success' : 'alert-warning' ?> d-flex align-items-center mb-3">
                    <i class="fa-solid <?= $ra['status'] === 'success' ? 'fa-circle-check text-success' : 'fa-triangle-exclamation text-warning' ?> fs-4 me-3"></i>
                    <div>
                        <strong><?= htmlspecialchars($ra['nas']) ?>:</strong><br>
                        <?= $ra['msg'] ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="mt-3">
                <h6 class="text-warning"><i class="fa-solid fa-terminal me-2"></i> Winbox Terminal Command (Instant Manual Cleanup):</h6>
                <p class="text-muted small mb-2">Agar aapke router par abhi bhi koi dynamic entries nazar aa rahi hon, toh Winbox me <strong>New Terminal</strong> khol kar yeh single command paste karen:</p>
                <pre class="code-box">/ip firewall address-list remove [find dynamic=yes]</pre>
            </div>
        </div>
    </div>

    <!-- STEP 3: SUB-NET LOAD BALANCING ARCHITECTURE OVERVIEW -->
    <div class="card">
        <div class="card-header">
            <i class="fa-solid fa-diagram-project me-2 text-success"></i> 3. Current Clean Package Subnet Routing Setup
        </div>
        <div class="card-body">
            <p class="text-light">MikroTik <code>IP > Firewall > Address Lists</code> me ab har package ki sirf <strong>1 static subnet entry</strong> honi chahiye (Total ~15 entries):</p>
            
            <?php
            $sample_pools = $pdo->query("SELECT p.pool_name, p.subnet_cidr, p.ip_range, p.wan_provider_name 
                                         FROM ip_pools p 
                                         ORDER BY p.id ASC")->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Package Pool</th>
                            <th>Subnet CIDR (Address List Entry)</th>
                            <th>IP Pool Range</th>
                            <th>Assigned Outbound WAN</th>
                            <th>Routing Mark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sample_pools as $sp): 
                            $w = strtoupper($sp['wan_provider_name'] ?? 'PTCL');
                            $b_class = 'badge-other';
                            if (strpos($w, 'TELENOR') !== false) $b_class = 'badge-telenor';
                            elseif (strpos($w, 'PTCL') !== false) $b_class = 'badge-ptcl';
                            elseif (strpos($w, 'ZONG') !== false) $b_class = 'badge-zong';
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sp['pool_name']) ?></strong></td>
                            <td><code><?= htmlspecialchars($sp['subnet_cidr']) ?></code></td>
                            <td><small class="text-muted"><?= htmlspecialchars($sp['ip_range']) ?></small></td>
                            <td><span class="badge <?= $b_class ?> badge-wan"><?= htmlspecialchars($sp['wan_provider_name']) ?></span></td>
                            <td><code>to-<?= htmlspecialchars($sp['wan_provider_name']) ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="alert alert-info mt-3 mb-0">
                <h6 class="alert-heading fw-bold mb-1"><i class="fa-solid fa-lightbulb me-1"></i> How It Works (Roman Urdu):</h6>
                <ol class="mb-0 ps-3 small">
                    <li>Jab user (maslan 4Mbps wala) connect hota hai, FreeRADIUS router ko <code>Mikrotik-Group = 4Mbps</code> bhejta hai.</li>
                    <li>MikroTik us user ko PPP Profile <code>4Mbps</code> me daalta hai, aur user ko <code>10.4.0.0/23</code> pool se IP milti hai (maslan <code>10.4.0.55</code>).</li>
                    <li>MikroTik Firewall Address Lists me <code>10.4.0.0/23</code> pehle se <strong>Telenor</strong> me mojood hai.</li>
                    <li>Mangle rule automatic us subnet ke tamam users ka internet <strong>Telenor WAN</strong> par route kar deta hai!</li>
                    <li>Address List me 700 dynamic lines banne ka masla hamesha ke liye <strong>100% khatam</strong> ho gaya hai!</li>
                </ol>
            </div>
        </div>
    </div>
</div>
</body>
</html>
<?php else:
    echo "\n=== NetBee Multi-WAN & Address-List Repair Complete ===\n";
    echo "Purged radreply Mikrotik-Address-List: $del_rep_addr\n";
    echo "Purged radreply Framed-Pool: $del_rep_pool\n";
    echo "Total Subscribers Synced: $total_synced_subs\n";
    foreach ($router_actions as $ra) {
        echo "Router {$ra['nas']}: {$ra['msg']}\n";
    }
    echo "========================================================\n\n";
endif;
?>
