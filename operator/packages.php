<?php
require_once 'header.php';
require_once '../config/routeros_api.class.php';

$client_id = $_SESSION['operator_id'];

// --- 1. Auto-create/alter database columns & pre-seed tables ---
try {
    // Column checks on packages table
    $pkg_cols = $pdo->query("SHOW COLUMNS FROM packages")->fetchAll(PDO::FETCH_COLUMN);
    $pkg_col_map = array_flip($pkg_cols);
    if (!isset($pkg_col_map['pool_id'])) {
        $pdo->exec("ALTER TABLE packages ADD COLUMN pool_id INT DEFAULT NULL");
    }
    if (!isset($pkg_col_map['wan_provider'])) {
        $pdo->exec("ALTER TABLE packages ADD COLUMN wan_provider VARCHAR(100) DEFAULT 'PTCL'");
    }
    if (!isset($pkg_col_map['wan_interface'])) {
        $pdo->exec("ALTER TABLE packages ADD COLUMN wan_interface VARCHAR(100) DEFAULT 'PTCL'");
    }
    if (!isset($pkg_col_map['routing_mark'])) {
        $pdo->exec("ALTER TABLE packages ADD COLUMN routing_mark VARCHAR(64) DEFAULT 'to-PTCL'");
    }

    // Ensure router_wans table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `router_wans` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `client_id` INT NOT NULL,
      `nas_id` INT DEFAULT NULL,
      `wan_name` VARCHAR(100) NOT NULL,
      `interface_name` VARCHAR(100) DEFAULT NULL,
      `routing_mark` VARCHAR(100) DEFAULT NULL,
      `status` ENUM('active', 'inactive') DEFAULT 'active',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY `uk_client_wan` (`client_id`, `wan_name`),
      INDEX (`client_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Seed default WAN ONLY if operator has zero WANs
    $has_wans = $pdo->prepare("SELECT COUNT(*) FROM router_wans WHERE client_id = ?");
    $has_wans->execute([$client_id]);
    if ($has_wans->fetchColumn() == 0) {
        $pdo->prepare("INSERT IGNORE INTO router_wans (client_id, wan_name, routing_mark) VALUES (?, 'PTCL', 'to-PTCL')")
            ->execute([$client_id]);
    }

    // Auto-clean any dynamic client sessions or hardware ports from router_wans
    $pdo->exec("DELETE FROM router_wans WHERE 
        wan_name LIKE 'ether%' 
        OR wan_name LIKE 'Ether%' 
        OR wan_name LIKE 'sfp%' 
        OR wan_name LIKE '<%' 
        OR wan_name LIKE '%>%' 
        OR wan_name LIKE '%pppoe%' 
        OR wan_name LIKE '%bandwidth%'
        OR wan_name LIKE '%ipt%'
        OR wan_name LIKE '%vlan%'
        OR wan_name LIKE '%VLAN%'");

    // Auto-Assign pools to ANY existing packages that currently have no pool assigned
    $chk_unassigned = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE client_id = ? AND (pool_id IS NULL OR pool_id = 0)");
    $chk_unassigned->execute([$client_id]);
    if ($chk_unassigned->fetchColumn() > 0) {
        $avail_pools = $pdo->prepare("SELECT id, pool_name, subnet_cidr, ip_range, wan_provider_name FROM ip_pools WHERE client_id = ? ORDER BY id ASC");
        $avail_pools->execute([$client_id]);
        $all_p = $avail_pools->fetchAll();

        if (!empty($all_p)) {
            $unassigned_pkgs = $pdo->prepare("SELECT id, name FROM packages WHERE client_id = ? AND (pool_id IS NULL OR pool_id = 0)");
            $unassigned_pkgs->execute([$client_id]);
            foreach ($unassigned_pkgs->fetchAll() as $upk) {
                $matched_pool = null;
                $pkg_n = $upk['name'];
                preg_match('/(\d+)\s*(mbps|mb|m)/i', $pkg_n, $m_sp);
                $sp_num = $m_sp[1] ?? '';

                // 1. Exact match on pool name
                foreach ($all_p as $pl) {
                    if (strcasecmp($pl['pool_name'], $pkg_n) === 0) {
                        $matched_pool = $pl;
                        break;
                    }
                }
                // 2. Speed number match (e.g. 10mb Package -> 10Mbps pool)
                if (!$matched_pool && $sp_num !== '') {
                    foreach ($all_p as $pl) {
                        preg_match('/(\d+)\s*(mbps|mb|m)/i', $pl['pool_name'], $pm);
                        if (!empty($pm[1]) && $pm[1] == $sp_num) {
                            $matched_pool = $pl;
                            break;
                        }
                    }
                }
                // 3. Fallback to PPPoE Pool
                if (!$matched_pool) {
                    foreach ($all_p as $pl) {
                        if (stripos($pl['pool_name'], 'pppoe') !== false) {
                            $matched_pool = $pl;
                            break;
                        }
                    }
                }
                // 4. Default to first pool
                if (!$matched_pool) $matched_pool = $all_p[0];

                $wan = !empty($matched_pool['wan_provider_name']) ? $matched_pool['wan_provider_name'] : 'PTCL';
                $pdo->prepare("UPDATE packages SET pool_id = ?, wan_provider = ?, wan_interface = ?, routing_mark = ? WHERE id = ?")
                    ->execute([$matched_pool['id'], $wan, $wan, 'to-' . $wan, $upk['id']]);
            }
        }
    }
} catch (Exception $e) {}

// --- 2. Live MikroTik Router Outbound WAN Shifter Function ---

// --- 1.5 Sync FreeRADIUS radgroupreply & radreply for Multi-WAN (Tenant Isolated) ---
function sync_freeradius_package_and_users($pdo, $package_id, $pkg_name, $rate_limit, $pool_name, $wan_provider, $client_id) {
    if (empty($wan_provider)) $wan_provider = "PTCL";

    // Determine target profile / pool name for Mikrotik-Group (e.g. '4Mbps')
    $target_profile = !empty($pool_name) ? $pool_name : '';
    if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
        if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg_name, $m_prof)) {
            $target_profile = $m_prof[1] . 'Mbps';
        } else {
            $target_profile = $pkg_name;
        }
    }

    // A. Sync radgroupreply for package group (ONLY Mikrotik-Rate-Limit belongs in shared group table)
    try {
        $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ? AND attribute IN ('Mikrotik-Group', 'Framed-Pool', 'Mikrotik-Address-List')")->execute([$pkg_name]);
        if (!empty($rate_limit) && $rate_limit !== 'Unlimited' && $rate_limit !== 'No Limit') {
            $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$pkg_name]);
            $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)")->execute([$pkg_name, $rate_limit]);
        }
    } catch (Exception $e) {}

    // B. Sync existing subscribers in radreply STRICTLY for THIS operator (client_id)
    try {
        $sub_stmt = $pdo->prepare("SELECT username FROM subscribers WHERE package_id = ? AND client_id = ?");
        $sub_stmt->execute([$package_id, $client_id]);
        $subscribers = $sub_stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($subscribers as $u) {
            // NEVER inject Mikrotik-Address-List (prevents 700 dynamic entries and WAN hijacking)
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$u]);
            
            // Set Mikrotik-Group so router assigns user to their package PPP profile and pool
            if (!empty($target_profile)) {
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$u]);
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$u, $target_profile]);
            }
        }
    } catch (Exception $e) {}
}

function sync_mikrotik_package_wan($pdo, $client_id, $target_wan, $pool_name, $subnet_cidr = '') {
    if (empty($target_wan)) return '';

    // Permanently record WAN in router_wans
    try {
        $pdo->prepare("INSERT INTO router_wans (client_id, wan_name, routing_mark) VALUES (?, ?, ?) 
                       ON DUPLICATE KEY UPDATE status = 'active'")
            ->execute([$client_id, $target_wan, 'to-' . $target_wan]);
    } catch (Exception $e) {}

    $nas_stmt = $pdo->prepare("SELECT id, nasname, api_port, api_user, api_password FROM nas WHERE client_id = ? ORDER BY id ASC LIMIT 1");
    $nas_stmt->execute([$client_id]);
    $nas = $nas_stmt->fetch();
    if (!$nas) return ' (Router not configured)';

    $API = new RouterosAPI();
    $API->timeout = 3;
    $API->attempts = 1;
    $API->delay = 1;
    error_reporting(0);
    $port = $nas['api_port'] ?: 8728;

    if (!$API->connect($nas['nasname'], $nas['api_user'] ?: 'admin', $nas['api_password'] ?: '', $port)) {
        return ' (Router unreachable - saved in database)';
    }

    try {
        $all_al = $API->comm('/ip/firewall/address-list/print');
        $target_comment = trim($pool_name);
        $target_subnet = trim($subnet_cidr);

        $matching_entries = [];
        if (is_array($all_al)) {
            foreach ($all_al as $entry) {
                $e_id = $entry['.id'] ?? '';
                $e_comment = trim($entry['comment'] ?? '');
                $e_addr = trim($entry['address'] ?? '');

                $is_match = false;
                if (!empty($e_comment) && strcasecmp($e_comment, $target_comment) === 0) {
                    $is_match = true;
                }
                if (!$is_match && !empty($target_subnet) && strcasecmp($e_addr, $target_subnet) === 0) {
                    $is_match = true;
                }
                if (!$is_match && !empty($target_subnet)) {
                    $pref = substr($target_subnet, 0, strrpos($target_subnet, '.'));
                    if (!empty($pref) && strpos($e_addr, $pref) === 0) {
                        $is_match = true;
                    }
                }

                if ($is_match && !empty($e_id)) {
                    $matching_entries[] = ['.id' => $e_id, 'address' => $e_addr];
                }
            }
        }

        if (!empty($matching_entries)) {
            // Update primary entry in-place
            $primary = $matching_entries[0];
            $API->comm('/ip/firewall/address-list/set', [
                '.id' => $primary['.id'],
                'list' => $target_wan,
                'comment' => $target_comment
            ]);
            // Automatically purge duplicate entries on router to prevent duplicates
            for ($i = 1; $i < count($matching_entries); $i++) {
                $API->comm('/ip/firewall/address-list/remove', [
                    '.id' => $matching_entries[$i]['.id']
                ]);
            }
            $API->disconnect();
            return " & MikroTik Address List updated live: '{$target_comment}' shifted to {$target_wan}!";
        } else if (!empty($target_subnet)) {
            $API->comm('/ip/firewall/address-list/add', [
                'list' => $target_wan,
                'address' => $target_subnet,
                'comment' => $target_comment
            ]);
            $API->disconnect();
            return " & MikroTik Address List created: '{$target_comment}' ({$target_subnet}) in {$target_wan}!";
        }

        $API->disconnect();
        return " (Updated in database)";
    } catch (Exception $e) {
        $API->disconnect();
        return " (Router communication error: " . $e->getMessage() . ")";
    }
}

// --- 3. Handle Form Actions (Create, Edit, Quick Shift, Delete) ---
$flash_msg = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A. CREATE OR EDIT PACKAGE
    if ($action === 'create' || $action === 'edit') {
        $id = (int)($_POST['package_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $validity_days = (int)($_POST['validity_days'] ?? 30);
        $data_limit_gb = (int)($_POST['data_limit_gb'] ?? 0);
        $pool_id = (int)($_POST['pool_id'] ?? 0);
        $wan_provider = trim($_POST['wan_provider'] ?? '');

        $rate_up = trim($_POST['rate_up'] ?? '');
        $rate_down = trim($_POST['rate_down'] ?? '');
        if (strcasecmp($rate_up, 'Unlimited') === 0 || empty($rate_up) || empty($rate_down)) {
            $rate_limit = 'Unlimited';
        } else {
            $rate_limit = $rate_up . '/' . $rate_down;
        }

        // Night / Speed Scheduler
        $scheduler = null;
        if (!empty($_POST['sched_start']) && !empty($_POST['sched_end']) && (!empty($_POST['sched_up']) || !empty($_POST['sched_down']))) {
            $scheduler = json_encode([
                'start_time' => $_POST['sched_start'],
                'end_time'   => $_POST['sched_end'],
                'speed'      => trim($_POST['sched_up'] ?? '') . '/' . trim($_POST['sched_down'] ?? '')
            ]);
        }

        // Fetch selected pool details
        $pool = null;
        if (!empty($pool_id)) {
            $p_stmt = $pdo->prepare("SELECT * FROM ip_pools WHERE id = ? AND client_id = ?");
            $p_stmt->execute([$pool_id, $client_id]);
            $pool = $p_stmt->fetch();
        }

        // Check if operator has pools in ip_pools
        $has_pools = count($all_pools) > 0;

        if ($has_pools && !empty($pool_id) && !$pool) {
            $flash_msg = "Please select a valid MikroTik IP Pool for this package.";
            $flash_type = 'danger';
        } else {
            $pool_name = $pool ? $pool['pool_name'] : null;
            $subnet_cidr = $pool ? $pool['subnet_cidr'] : null;
            $pool_id_val = $pool ? (int)$pool['id'] : null;

            if (empty($wan_provider)) {
                $wan_provider = $pool ? ($pool['wan_provider_name'] ?: 'PTCL') : 'PTCL';
            }
            $routing_mark = 'to-' . $wan_provider;

            if ($action === 'create') {
                $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE name = ? AND client_id = ?");
                $chkStmt->execute([$name, $client_id]);
                if ($chkStmt->fetchColumn() > 0) {
                    $flash_msg = "Error: A package with the name '<strong>" . htmlspecialchars($name) . "</strong>' already exists!";
                    $flash_type = 'danger';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb, speed_scheduler, pool_id, wan_provider, wan_interface, routing_mark) 
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$client_id, $name, $rate_limit, $validity_days, $price, $data_limit_gb, $scheduler, $pool_id_val, $wan_provider, $wan_provider, $routing_mark]);

                    $new_pkg_id = $pdo->lastInsertId();
                    sync_freeradius_package_and_users($pdo, $new_pkg_id, $name, $rate_limit, $pool_name, $wan_provider, $client_id);

                    // Live sync MikroTik Router Address List if pool has a subnet
                    $router_fb = '';
                    if (!empty($pool_name) && !empty($subnet_cidr)) {
                        $router_fb = sync_mikrotik_package_wan($pdo, $client_id, $wan_provider, $pool_name, $subnet_cidr);
                    }

                    $pool_display = $pool_name ? "Assigned to pool '<strong>" . htmlspecialchars($pool_name) . "</strong>' on WAN '<strong>" . htmlspecialchars($wan_provider) . "</strong>'" : "Configured with standard router pool";
                    $flash_msg = "Package '<strong>" . htmlspecialchars($name) . "</strong>' created successfully! {$pool_display}{$router_fb}";
                    $flash_type = 'success';
                }
            } else { // edit
                $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE name = ? AND client_id = ? AND id != ?");
                $chkStmt->execute([$name, $client_id, $id]);
                if ($chkStmt->fetchColumn() > 0) {
                    $flash_msg = "Error: Another package with the name '<strong>" . htmlspecialchars($name) . "</strong>' already exists!";
                    $flash_type = 'danger';
                } else {
                    $stmt = $pdo->prepare("UPDATE packages SET name=?, rate_limit=?, validity_days=?, price=?, data_limit_gb=?, speed_scheduler=?, pool_id=?, wan_provider=?, wan_interface=?, routing_mark=? WHERE id=? AND client_id=?");
                    $stmt->execute([$name, $rate_limit, $validity_days, $price, $data_limit_gb, $scheduler, $pool_id_val, $wan_provider, $wan_provider, $routing_mark, $id, $client_id]);

                    // Keep ip_pools table WAN status synchronized
                    if ($pool_id_val) {
                        $pdo->prepare("UPDATE ip_pools SET wan_provider_name = ?, wan_interface = ?, routing_mark = ? WHERE id = ? AND client_id = ?")
                            ->execute([$wan_provider, $wan_provider, $routing_mark, $pool_id_val, $client_id]);
                    }

                    // Sync package subscribers using $id (NOT lastInsertId!)
                    sync_freeradius_package_and_users($pdo, $id, $name, $rate_limit, $pool_name, $wan_provider, $client_id);

                    // Shift MikroTik Address List live! All users on this package immediately switch to the selected WAN port
                    $router_fb = '';
                    if (!empty($pool_name) && !empty($subnet_cidr)) {
                        $router_fb = sync_mikrotik_package_wan($pdo, $client_id, $wan_provider, $pool_name, $subnet_cidr);
                    }

                    $pool_display = $pool_name ? "Pool: <strong>" . htmlspecialchars($pool_name) . "</strong> | Outbound WAN: <strong>" . htmlspecialchars($wan_provider) . "</strong>" : "Standard Router Pool";
                    $flash_msg = "Package '<strong>" . htmlspecialchars($name) . "</strong>' updated successfully! {$pool_display}{$router_fb}";
                    $flash_type = 'success';
                }
            }
        }
    }

    // B. QUICK 1-CLICK WAN SHIFT
    if ($action === 'shift_wan_quick') {
        $id = (int)($_POST['package_id'] ?? 0);
        $target_wan = trim($_POST['target_wan'] ?? '');

        $pkg_stmt = $pdo->prepare("SELECT p.*, pl.pool_name, pl.subnet_cidr FROM packages p LEFT JOIN ip_pools pl ON p.pool_id = pl.id WHERE p.id = ? AND p.client_id = ?");
        $pkg_stmt->execute([$id, $client_id]);
        $pkg = $pkg_stmt->fetch();

        if ($pkg && !empty($target_wan)) {
            $routing_mark = 'to-' . $target_wan;
            $pdo->prepare("UPDATE packages SET wan_provider = ?, wan_interface = ?, routing_mark = ? WHERE id = ? AND client_id = ?")
                ->execute([$target_wan, $target_wan, $routing_mark, $id, $client_id]);

            if (!empty($pkg['pool_id'])) {
                $pdo->prepare("UPDATE ip_pools SET wan_provider_name = ?, wan_interface = ?, routing_mark = ? WHERE id = ? AND client_id = ?")
                    ->execute([$target_wan, $target_wan, $routing_mark, $pkg['pool_id'], $client_id]);
            }

            sync_freeradius_package_and_users($pdo, $id, $pkg['name'], $pkg['rate_limit'], $pkg['pool_name'] ?: $pkg['name'], $target_wan, $client_id);
            $router_fb = sync_mikrotik_package_wan($pdo, $client_id, $target_wan, $pkg['pool_name'] ?: $pkg['name'], $pkg['subnet_cidr'] ?: '');

            $flash_msg = "Package '<strong>" . htmlspecialchars($pkg['name']) . "</strong>' shifted to <strong>" . htmlspecialchars($target_wan) . "</strong>{$router_fb}";
            $flash_type = 'success';
        }
    }

    // C. DELETE PACKAGE
    if ($action === 'delete') {
        $id = (int)$_POST['package_id'];

        $sub_count = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE package_id = ? AND client_id = ?");
        $sub_count->execute([$id, $client_id]);
        $sc = $sub_count->fetchColumn();

        if ($sc > 0) {
            $flash_msg = "Cannot delete package: {$sc} subscriber(s) are currently assigned to this package. Please reassign them to another package first.";
            $flash_type = 'warning';
        } else {
            $p_name_stmt = $pdo->prepare("SELECT name FROM packages WHERE id = ? AND client_id = ?");
            $p_name_stmt->execute([$id, $client_id]);
            $del_name = $p_name_stmt->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM packages WHERE id=? AND client_id=?");
            $stmt->execute([$id, $client_id]);

            if ($del_name) {
                try {
                    $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ?")->execute([$del_name]);
                } catch (Exception $e) {}
            }

            $flash_msg = "Package '<strong>" . htmlspecialchars($del_name) . "</strong>' deleted successfully.";
            $flash_type = 'success';
        }
    }
}

// --- 4. Fetch Data for UI Rendering ---
// 1. Fetch all IP Pools
$pools_stmt = $pdo->prepare("SELECT id, pool_name, subnet_cidr, ip_range, wan_provider_name, total_ips, used_ips FROM ip_pools WHERE client_id = ? ORDER BY id ASC");
$pools_stmt->execute([$client_id]);
$all_pools = $pools_stmt->fetchAll();

// 2. Fetch all Active WANs
$wans_stmt = $pdo->prepare("SELECT wan_name FROM router_wans WHERE client_id = ? AND status = 'active' ORDER BY id ASC");
$wans_stmt->execute([$client_id]);
$available_wans = $wans_stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($available_wans)) {
    $available_wans = ['PTCL'];
}

// 3. Fetch Packages with joined Pool & Subscriber stats
$pkgs_stmt = $pdo->prepare("SELECT p.*, 
                                   pl.pool_name, 
                                   pl.subnet_cidr, 
                                   pl.ip_range,
                                   COUNT(DISTINCT s.id) as subscriber_count,
                                   COUNT(DISTINCT CASE WHEN r.acctstoptime IS NULL AND r.radacctid IS NOT NULL THEN s.id END) as live_online_count 
                            FROM packages p 
                            LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
                            LEFT JOIN subscribers s ON p.id = s.package_id 
                            LEFT JOIN radacct r ON s.username = r.username AND r.acctstoptime IS NULL
                            WHERE p.client_id = ? 
                            GROUP BY p.id 
                            ORDER BY p.id DESC");
$pkgs_stmt->execute([$client_id]);
$packages = $pkgs_stmt->fetchAll();

// Summary counters
$total_pkgs = count($packages);
$total_subs = 0;
$total_online = 0;
foreach ($packages as $pkg_row) {
    $total_subs += (int)$pkg_row['subscriber_count'];
    $total_online += (int)$pkg_row['live_online_count'];
}

function get_wan_badge($wan) {
    $w_lower = strtolower(trim($wan));
    if (strpos($w_lower, 'ptcl') !== false) {
        return ['class' => 'badge-ptcl', 'icon' => 'fa-solid fa-satellite-dish', 'name' => $wan];
    } elseif (strpos($w_lower, 'telenor') !== false) {
        return ['class' => 'badge-telenor', 'icon' => 'fa-solid fa-tower-broadcast', 'name' => $wan];
    } elseif (strpos($w_lower, 'zong') !== false) {
        return ['class' => 'badge-zong', 'icon' => 'fa-solid fa-signal', 'name' => $wan];
    } elseif (strpos($w_lower, 'stormfiber') !== false) {
        return ['class' => 'badge-stormfiber', 'icon' => 'fa-solid fa-bolt', 'name' => $wan];
    } elseif (strpos($w_lower, 'nayatel') !== false) {
        return ['class' => 'badge-nayatel', 'icon' => 'fa-solid fa-globe', 'name' => $wan];
    } elseif (strpos($w_lower, 'jazz') !== false) {
        return ['class' => 'badge-jazz', 'icon' => 'fa-solid fa-sim-card', 'name' => $wan];
    }
    return ['class' => 'badge-custom', 'icon' => 'fa-solid fa-network-wired', 'name' => $wan ?: 'PTCL'];
}
?>

<style>
    /* Professional Multi-WAN & Pool Badges */
    .badge-ptcl { background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; font-weight: 600; }
    .badge-telenor { background-color: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-weight: 600; }
    .badge-zong { background-color: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8; font-weight: 600; }
    .badge-stormfiber { background-color: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 600; }
    .badge-nayatel { background-color: #f0fdfa; color: #0d9488; border: 1px solid #99f6e4; font-weight: 600; }
    .badge-jazz { background-color: #fefce8; color: #ca8a04; border: 1px solid #fef08a; font-weight: 600; }
    .badge-custom { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 600; }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border-radius: 14px;
        padding: 16px 20px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        display: flex;
        align-items: center;
        gap: 16px;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .stat-pill-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .stat-pill-val {
        font-size: 1.45rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.1;
    }
    .stat-pill-lbl {
        font-size: 0.78rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 4px;
    }

    /* Table Styling */
    .table-packages thead th {
        font-size: 0.74rem;
        letter-spacing: 0.6px;
        text-transform: uppercase;
        font-weight: 700;
        color: #475569;
        background: #f8fafc;
        border-bottom: 2px solid #e2e8f0;
        padding: 14px 16px;
    }
    .table-packages tbody td {
        padding: 14px 16px;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
    }
    .card-ui {
        background: #ffffff;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 14px rgba(0,0,0,0.03);
    }
</style>

<div class="container-fluid px-0">

    <!-- Flash Message -->
    <?php if (!empty($flash_msg)): ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show rounded-4 shadow-sm mb-4" role="alert">
            <i class="fa-solid <?= ($flash_type === 'success') ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-2"></i>
            <?= $flash_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header & Action Button -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h3 class="fw-bold mb-1 text-dark" style="letter-spacing: 0.5px;">
                <i class="fa-solid fa-box-open text-primary me-2"></i>Manage Packages &amp; IP Pool Routing
            </h3>
            <div class="text-muted small">
                Har package ko MikroTik IP Pool assign karein aur uska internet traffic apni marzi ki WAN port (PTCL / Telenor) par shift karein.
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="ip_pools.php" class="btn btn-outline-primary rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-network-wired me-1"></i> IP Pools Manager
            </a>
            <button class="btn btn-primary rounded-pill px-4 shadow-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#packageModal" onclick="openCreateModal()">
                <i class="fa-solid fa-plus me-1"></i> Create Package
            </button>
        </div>
    </div>

    <!-- Stat Cards Overview -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(37, 99, 235, 0.12); color: #2563eb;">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <div>
                    <div class="stat-pill-val"><?= $total_pkgs ?></div>
                    <div class="stat-pill-lbl">Total Packages</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(6, 182, 212, 0.12); color: #0891b2;">
                    <i class="fa-solid fa-network-wired"></i>
                </div>
                <div>
                    <div class="stat-pill-val"><?= count($all_pools) ?></div>
                    <div class="stat-pill-lbl">Available IP Pools</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
                    <i class="fa-solid fa-shuffle"></i>
                </div>
                <div>
                    <div class="stat-pill-val" style="font-size: 1.15rem; word-break: break-word;">
                        <?= htmlspecialchars(implode(' / ', $available_wans)) ?>
                    </div>
                    <div class="stat-pill-lbl">Active WAN Ports</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(245, 158, 11, 0.12); color: #d97706;">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <div class="stat-pill-val">
                        <?= $total_subs ?>
                        <?php if ($total_online > 0): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle fs-7 ms-1 fw-bold"><?= $total_online ?> Online</span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-pill-lbl">Active Subscribers</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Packages Table Card -->
    <div class="card-ui p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div>
                <h5 class="fw-bold mb-1 text-dark">Package Profiles &amp; Pool Assignment</h5>
                <div class="text-muted small">Har package ka assigned IP Pool aur active internet WAN route yahan show ho raha hai.</div>
            </div>
            <div style="min-width: 250px;">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                    <input type="text" id="packageFilter" class="form-control border-start-0" placeholder="Filter packages or WAN..." onkeyup="filterPackages()">
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-packages" id="packagesMainTable">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Package Name</th>
                        <th>Speed Cap</th>
                        <th>Assigned IP Pool</th>
                        <th>Outbound WAN Route</th>
                        <th>Price (PKR)</th>
                        <th>Validity</th>
                        <th>Subscribers</th>
                        <th class="text-end" style="width: 110px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($packages)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-box-open fa-3x mb-3 opacity-25"></i><br>
                                No packages found. Click "Create Package" to add your first plan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($packages as $idx => $p): 
                            $sched = json_decode($p['speed_scheduler'], true);
                            $w_badge = get_wan_badge($p['wan_provider'] ?: 'PTCL');
                        ?>
                        <tr>
                            <td class="fw-bold text-muted"><?= $idx + 1 ?></td>
                            <td>
                                <div class="fw-bold text-dark fs-6">
                                    <i class="fa-solid fa-box text-primary me-1"></i>
                                    <?= htmlspecialchars($p['name']) ?>
                                </div>
                                <?php if ($sched): ?>
                                    <div class="text-info mt-1" style="font-size: 0.72rem;">
                                        <i class="fa-regular fa-clock me-1"></i> Night: <?= htmlspecialchars($sched['start_time']) ?>-<?= htmlspecialchars($sched['end_time']) ?> (<?= htmlspecialchars($sched['speed']) ?>)
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['rate_limit'] === 'Unlimited' || empty($p['rate_limit'])): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill">
                                        <i class="fa-solid fa-infinity me-1"></i> Unlimited
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace px-2 py-1 rounded-pill">
                                        <i class="fa-solid fa-gauge-high me-1"></i> <?= htmlspecialchars($p['rate_limit']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['pool_name'])): ?>
                                    <span class="badge bg-light text-dark border px-2 py-1 fw-bold">
                                        <i class="fa-solid fa-network-wired text-info me-1"></i>
                                        <?= htmlspecialchars($p['pool_name']) ?>
                                    </span>
                                    <?php if (!empty($p['subnet_cidr'])): ?>
                                        <div class="font-monospace text-muted mt-1" style="font-size: 0.74rem;">
                                            <?= htmlspecialchars($p['subnet_cidr']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1 rounded-pill">
                                        <i class="fa-solid fa-triangle-exclamation me-1"></i> No Pool Assigned
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge <?= $w_badge['class'] ?> px-3 py-1 rounded-pill fs-7">
                                        <i class="<?= $w_badge['icon'] ?> me-1"></i><?= htmlspecialchars($w_badge['name']) ?>
                                    </span>

                                    <!-- Quick 1-Click WAN Switch Dropdown -->
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border p-0 rounded-circle" type="button" data-bs-toggle="dropdown" style="width: 24px; height: 24px;" title="Quick Switch WAN Port">
                                            <i class="fa-solid fa-shuffle text-muted" style="font-size: 0.65rem;"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border py-1" style="font-size: 0.82rem; min-width: 160px;">
                                            <li class="dropdown-header text-uppercase text-muted" style="font-size: 0.7rem; letter-spacing: 0.5px;">Shift Outbound WAN</li>
                                            <?php foreach ($available_wans as $w): ?>
                                                <li>
                                                    <form method="POST" class="m-0">
                                                        <input type="hidden" name="action" value="shift_wan_quick">
                                                        <input type="hidden" name="package_id" value="<?= $p['id'] ?>">
                                                        <input type="hidden" name="target_wan" value="<?= htmlspecialchars($w) ?>">
                                                        <button type="submit" class="dropdown-item d-flex align-items-center justify-content-between py-2 <?= (strcasecmp($p['wan_provider'], $w) === 0) ? 'active fw-bold' : '' ?>" onclick="return confirm('Shift package \'<?= htmlspecialchars(addslashes($p['name'])) ?>\' to <?= htmlspecialchars($w) ?> now?');">
                                                            <span><i class="fa-solid fa-satellite-dish me-2"></i><?= htmlspecialchars($w) ?></span>
                                                            <?php if (strcasecmp($p['wan_provider'], $w) === 0): ?>
                                                                <i class="fa-solid fa-check ms-2"></i>
                                                            <?php endif; ?>
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                            <td class="text-dark fw-bold">
                                <small class="text-muted fw-normal me-1">Rs</small><?= number_format($p['price'], 0) ?>
                            </td>
                            <td>
                                <span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1">
                                    <i class="fa-regular fa-calendar me-1"></i> <?= $p['validity_days'] ?> Days
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill fw-bold" title="Total registered subscribers">
                                    <i class="fa-solid fa-users me-1"></i> <?= $p['subscriber_count'] ?> Total
                                </span>
                                <?php if ($p['live_online_count'] > 0): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill fw-bold ms-1" title="Currently active online sessions">
                                        <i class="fa-solid fa-circle text-success me-1" style="font-size: 0.5rem;"></i><?= $p['live_online_count'] ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary rounded-circle me-1" style="width: 32px; height: 32px; padding: 0;" onclick='openEditModal(<?= json_encode($p) ?>)' title="Edit Package & Pool Assignment">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete package \'<?= htmlspecialchars(addslashes($p['name'])) ?>\'?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="package_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle" style="width: 32px; height: 32px; padding: 0;" title="Delete Package">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- PACKAGE MODAL (CREATE & EDIT WITH POOL & WAN DROPDOWNS)       -->
<!-- ============================================================== -->
<div class="modal fade" id="packageModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-white border-0 shadow-lg text-dark" style="border-radius: 16px; overflow: hidden;">
      <div class="modal-header border-bottom p-4" style="background: #f8fafc;">
        <h5 class="modal-title fw-bold" id="modalTitle">
          <i class="fa-solid fa-box text-primary me-2"></i> Create Package
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="packageForm">
        <input type="hidden" name="action" id="modalAction" value="create">
        <input type="hidden" name="package_id" id="modalPackageId" value="">
        <div class="modal-body p-4">
            
            <!-- Section 1: Basic Information -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1" style="letter-spacing: 0.5px;">Package Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="pkg_name" class="form-control px-3 py-2 fw-semibold" required placeholder="e.g. 20Mbps Unlimited">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1" style="letter-spacing: 0.5px;">Price (PKR) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border text-muted">Rs</span>
                        <input type="number" step="1" name="price" id="pkg_price" class="form-control px-3 py-2 fw-semibold" required placeholder="e.g. 2000">
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1" style="letter-spacing: 0.5px;">Validity (Days) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border text-muted"><i class="fa-regular fa-calendar"></i></span>
                        <input type="number" name="validity_days" id="pkg_validity" class="form-control px-3 py-2" required placeholder="e.g. 30" value="30">
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1" style="letter-spacing: 0.5px;">Data Limit (GB)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border text-muted"><i class="fa-solid fa-database"></i></span>
                        <input type="number" name="data_limit_gb" id="pkg_data" class="form-control px-3 py-2" placeholder="e.g. 0" value="0">
                    </div>
                    <small class="text-muted" style="font-size: 0.73rem;">Enter <strong class="text-success">0</strong> for Unlimited Data</small>
                </div>
            </div>

            <!-- Section 2: Bandwidth Speed Caps -->
            <div class="p-3 rounded border mb-3" style="background: #f8fafc;">
                <label class="form-label fw-bold text-dark mb-2 small text-uppercase" style="letter-spacing: 0.5px;">
                    <i class="fa-solid fa-gauge-high text-primary me-2"></i>Speed Rate Limit
                </label>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Upload Speed</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border text-danger"><i class="fa-solid fa-upload"></i></span>
                            <input type="text" name="rate_up" id="pkg_rate_up" class="form-control font-monospace" placeholder="e.g. 20M, 512k" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Download Speed</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border text-success"><i class="fa-solid fa-download"></i></span>
                            <input type="text" name="rate_down" id="pkg_rate_down" class="form-control font-monospace" placeholder="e.g. 20M, 512k" required>
                        </div>
                    </div>
                </div>
                <small class="text-muted d-block mt-2" style="font-size: 0.73rem;">Type <strong class="text-info">Unlimited</strong> in any field to remove speed caps.</small>
            </div>

            <!-- Section 3: MikroTik IP Pool & Outbound WAN Routing -->
            <div class="p-3 rounded border mb-3" style="background: #eff6ff; border-color: #bfdbfe !important;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="form-label fw-bold text-primary mb-0 small text-uppercase" style="letter-spacing: 0.5px;">
                        <i class="fa-solid fa-network-wired me-2"></i>MikroTik IP Pool &amp; Outbound WAN Routing
                    </label>
                    <span class="badge bg-primary text-white px-2 py-1" style="font-size: 0.7rem;">Live Shifter</span>
                </div>
                <p class="text-muted small mb-3" style="font-size: 0.8rem;">
                    Subscribers ko is Pool ka IP assign hoga aur unka internet traffic select shuda WAN port (PTCL / Telenor) par chalega.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-dark mb-1">
                            Assign MikroTik IP Pool <?= !empty($all_pools) ? '<span class="text-danger">*</span>' : '' ?>
                        </label>
                        <select name="pool_id" id="pkg_pool_id" class="form-select fw-semibold" <?= !empty($all_pools) ? 'required' : '' ?> onchange="onPoolChange(this)">
                            <option value="">-- <?= !empty($all_pools) ? 'Select MikroTik IP Pool' : 'Standard Router Pool (No Custom Pool)' ?> --</option>
                            <?php foreach ($all_pools as $pl): ?>
                                <option value="<?= $pl['id'] ?>" data-wan="<?= htmlspecialchars($pl['wan_provider_name'] ?: 'PTCL') ?>" data-subnet="<?= htmlspecialchars($pl['subnet_cidr'] ?: $pl['ip_range']) ?>">
                                    <?= htmlspecialchars($pl['pool_name']) ?> (<?= htmlspecialchars($pl['subnet_cidr'] ?: $pl['ip_range']) ?>) [<?= htmlspecialchars($pl['wan_provider_name'] ?: 'PTCL') ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">MikroTik <code>IP &gt; Pool</code> se fetched pools</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-dark mb-1">
                            Outbound WAN Port (Internet Port) <span class="text-danger">*</span>
                        </label>
                        <select name="wan_provider" id="pkg_wan_provider" class="form-select fw-semibold" required>
                            <?php foreach ($available_wans as $w): ?>
                                <option value="<?= htmlspecialchars($w) ?>"><?= htmlspecialchars($w) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Jab ye port change kren gy, router Address List update ho kr saray users shift ho jaengy.</small>
                    </div>
                </div>
            </div>

            <!-- Section 4: Night / Speed Scheduler -->
            <div class="p-3 rounded border" style="background: #f8fafc;">
                <h6 class="text-secondary mb-2 fw-bold small text-uppercase" style="letter-spacing: 0.5px;">
                    <i class="fa-regular fa-clock me-2 text-info"></i> Night / Speed Scheduler (Optional)
                </h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Start Time</label>
                        <input type="time" name="sched_start" id="sched_start" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">End Time</label>
                        <input type="time" name="sched_end" id="sched_end" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Night Upload Speed</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border text-danger"><i class="fa-solid fa-upload"></i></span>
                            <input type="text" name="sched_up" id="sched_up" class="form-control" placeholder="e.g. 20M">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Night Download Speed</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border text-success"><i class="fa-solid fa-download"></i></span>
                            <input type="text" name="sched_down" id="sched_down" class="form-control" placeholder="e.g. 20M">
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer border-top p-3 bg-light">
          <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 rounded-pill fw-bold" id="modalBtn">
            <i class="fa-solid fa-floppy-disk me-1"></i> Save Package
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-box text-primary me-2"></i> Create Package';
    document.getElementById('modalAction').value = 'create';
    document.getElementById('modalPackageId').value = '';
    document.getElementById('packageForm').reset();
    document.getElementById('pkg_validity').value = 30;
    document.getElementById('pkg_data').value = 0;
    document.getElementById('modalBtn').innerHTML = '<i class="fa-solid fa-plus me-1"></i> Create Package';

    // Auto-select first pool if present
    let poolSelect = document.getElementById('pkg_pool_id');
    if (poolSelect && poolSelect.options.length > 1) {
        poolSelect.selectedIndex = 1;
        onPoolChange(poolSelect);
    }
}

function openEditModal(pkg) {
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen text-primary me-2"></i> Edit Package: ' + (pkg.name || '');
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalPackageId').value = pkg.id;
    
    document.getElementById('pkg_name').value = pkg.name || '';
    document.getElementById('pkg_price').value = pkg.price || '';
    document.getElementById('pkg_validity').value = pkg.validity_days || 30;
    document.getElementById('pkg_data').value = pkg.data_limit_gb || 0;
    
    let rate = pkg.rate_limit || 'Unlimited';
    if (rate.toLowerCase() === 'unlimited' || !rate.includes('/')) {
        document.getElementById('pkg_rate_up').value = 'Unlimited';
        document.getElementById('pkg_rate_down').value = 'Unlimited';
    } else {
        let parts = rate.split('/');
        document.getElementById('pkg_rate_up').value = parts[0] || '';
        document.getElementById('pkg_rate_down').value = parts[1] || '';
    }

    // Set Assigned Pool & WAN
    let poolSelect = document.getElementById('pkg_pool_id');
    if (poolSelect) {
        poolSelect.value = pkg.pool_id || '';
    }
    let wanSelect = document.getElementById('pkg_wan_provider');
    if (wanSelect) {
        wanSelect.value = pkg.wan_provider || 'PTCL';
    }
    
    if (pkg.speed_scheduler) {
        try {
            let sched = JSON.parse(pkg.speed_scheduler);
            document.getElementById('sched_start').value = sched.start_time || '';
            document.getElementById('sched_end').value = sched.end_time || '';
            if (sched.speed && sched.speed.includes('/')) {
                let sparts = sched.speed.split('/');
                document.getElementById('sched_up').value = sparts[0] || '';
                document.getElementById('sched_down').value = sparts[1] || '';
            }
        } catch(e) {}
    } else {
        document.getElementById('sched_start').value = '';
        document.getElementById('sched_end').value = '';
        document.getElementById('sched_up').value = '';
        document.getElementById('sched_down').value = '';
    }
    
    document.getElementById('modalBtn').innerHTML = '<i class="fa-solid fa-check me-1"></i> Update Package';
    var myModal = new bootstrap.Modal(document.getElementById('packageModal'));
    myModal.show();
}

function onPoolChange(selectElem) {
    let opt = selectElem.options[selectElem.selectedIndex];
    if (opt && opt.dataset.wan) {
        let wanSel = document.getElementById('pkg_wan_provider');
        if (wanSel) {
            wanSel.value = opt.dataset.wan;
        }
    }
}

function filterPackages() {
    let input = document.getElementById('packageFilter');
    let filter = input.value.toLowerCase();
    let table = document.getElementById('packagesMainTable');
    let trs = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

    for (let i = 0; i < trs.length; i++) {
        let text = trs[i].textContent || trs[i].innerText;
        if (text.toLowerCase().indexOf(filter) > -1) {
            trs[i].style.display = '';
        } else {
            trs[i].style.display = 'none';
        }
    }
}
</script>

<?php require_once 'footer.php'; ?>
