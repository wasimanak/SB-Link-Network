<?php
require_once 'header.php';

// Access Control: Block Manager Access
if (!empty($_SESSION['is_manager'])) {
    echo "<div class='container py-5'>
            <div class='alert alert-danger shadow-sm'>
                <i class='fa-solid fa-shield-halved me-2'></i> Access Denied: Managers are not authorized to view or configure MikroTik IP Pools.
            </div>
            <a href='dashboard.php' class='btn btn-outline-secondary'><i class='fa-solid fa-arrow-left me-1'></i> Return to Dashboard</a>
          </div>";
    require_once 'footer.php';
    exit;
}
require_once '../config/routeros_api.class.php';
if (file_exists('../config/mikrotik_sync_helper.php')) {
    require_once '../config/mikrotik_sync_helper.php';
}

if (!function_exists('is_valid_wan_name')) {
    function is_valid_wan_name($name) {
        if (function_exists('is_valid_wan_provider_name')) {
            return is_valid_wan_provider_name($name);
        }
        $name = trim($name ?? '');
        if (empty($name)) return false;
        $clean = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $name));
        $common_known = ['PTCL', 'TELENOR', 'ZONG', 'JAZZ', 'NAYATEL', 'STORM', 'STORMFIBER', 'TRANSPK', 'MULTINET', 'CYBERNET', 'WAN1', 'WAN2', 'WAN3', 'WAN4', 'ISP1', 'ISP2'];
        if (in_array($clean, $common_known)) return true;
        if (preg_match('/^(wan|isp)[-_]?\d+$/i', $name)) return true;
        if (preg_match('/^[A-Za-z0-9_-]{2,20}$/', $name)) return true;
        return false;
    }
}

$client_id = $_SESSION['operator_id'];
if (function_exists('init_operator_network_defaults')) {
    init_operator_network_defaults($pdo, $client_id);
}

$flash_msg = '';
$flash_type = 'success';
$sync_status_badge = '';

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 0. CREATE NEW POOL (LIVE IN MIKROTIK & DATABASE)
    if ($action === 'create_pool') {
        $p_name = trim($_POST['pool_name'] ?? '');
        $p_range = trim($_POST['ip_range'] ?? '');
        $p_wan = trim($_POST['wan_provider'] ?? 'PTCL');
        $p_cidr = trim($_POST['subnet_cidr'] ?? '');
        $p_gateway = trim($_POST['gateway'] ?? '');
        $auto_link = !empty($_POST['auto_link_package']);

        $res = create_mikrotik_pool_and_route($pdo, $client_id, $p_name, $p_range, $p_wan, $p_cidr, $p_gateway);
        if ($res['status']) {
            $flash_msg = $res['message'];
            $flash_type = 'success';

            if ($auto_link && !empty($res['id'])) {
                preg_match('/(\d+)\s*(mbps|mb|m)/i', $p_name, $m_sp);
                $sp_num = $m_sp[1] ?? '';
                $pdo->prepare("UPDATE packages SET pool_id = ?, wan_provider = ?, wan_interface = ?, routing_mark = ? 
                               WHERE client_id = ? AND (pool_id IS NULL OR pool_id = 0) AND (name = ? OR name LIKE ?)")
                    ->execute([$res['id'], $p_wan, $p_wan, 'to-' . $p_wan, $client_id, $p_name, $sp_num ? "{$sp_num}%" : $p_name]);
            }
        } else {
            $flash_msg = $res['message'];
            $flash_type = 'danger';
        }
    }

    // 1. MANUAL RE-SYNC FROM MIKROTIK
    if ($action === 'force_sync') {
        $sync_attempt = sync_operator_mikrotik_pools($pdo, $client_id);
        if ($sync_attempt['status']) {
            $flash_msg = "Successfully refreshed & synced data directly from MikroTik Router! ({$sync_attempt['message']})";
            $flash_type = 'success';
        } else {
            $flash_msg = "Sync Failed: " . htmlspecialchars($sync_attempt['message']);
            $flash_type = 'danger';
        }
    }

    // 2. ADD CUSTOM WAN PORT
    if ($action === 'add_custom_wan') {
        $new_wan = trim($_POST['new_wan_name'] ?? '');
        if (!empty($new_wan)) {
            $pdo->prepare("INSERT INTO router_wans (client_id, wan_name, routing_mark) VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE status = 'active'")
                ->execute([$client_id, $new_wan, 'to-' . $new_wan]);
            $flash_msg = "WAN Port '<strong>" . htmlspecialchars($new_wan) . "</strong>' added successfully! It is now permanently available in the Shift dropdown.";
            $flash_type = 'success';
        }
    }

    // 3. DELETE CUSTOM WAN PORT
    if ($action === 'delete_custom_wan') {
        $del_wan = trim($_POST['del_wan_name'] ?? '');
        if (!empty($del_wan)) {
            $in_use_pkg = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE client_id = ? AND wan_provider = ?");
            $in_use_pkg->execute([$client_id, $del_wan]);
            $pkg_count = (int)$in_use_pkg->fetchColumn();

            $in_use_pool = $pdo->prepare("SELECT COUNT(*) FROM ip_pools WHERE client_id = ? AND wan_provider_name = ?");
            $in_use_pool->execute([$client_id, $del_wan]);
            $pool_count = (int)$in_use_pool->fetchColumn();

            if ($pkg_count > 0 || $pool_count > 0) {
                $flash_msg = "WAN Port '{$del_wan}' cannot be deleted because it is assigned to {$pkg_count} package(s) and {$pool_count} pool(s). Please shift them first.";
                $flash_type = 'warning';
            } else {
                $pdo->prepare("DELETE FROM router_wans WHERE client_id = ? AND wan_name = ?")->execute([$client_id, $del_wan]);
                $flash_msg = "WAN Port '{$del_wan}' removed successfully.";
                $flash_type = 'success';
            }
        }
    }

    // 2. SHIFT PACKAGE WAN (REAL-TIME MIKROTIK ADDRESS-LIST SWITCHER)
    if ($action === 'shift_package_wan') {
        $package_id = (int)($_POST['package_id'] ?? 0);
        $target_wan = trim($_POST['target_wan'] ?? ''); // e.g. "Telenor" or "PTCL"

        $pkg_stmt = $pdo->prepare("SELECT id, name FROM packages WHERE id = ? AND client_id = ?");
        $pkg_stmt->execute([$package_id, $client_id]);
        $pkg = $pkg_stmt->fetch();

        if (!$pkg || empty($target_wan)) {
            $flash_msg = "Please select a valid package and target WAN port.";
            $flash_type = 'danger';
        } else {
            $pkg_name = $pkg['name'];

            // Extract base speed prefix, e.g. "4Mbps (Liaqatabad)" -> "4Mbps"
            // Normalize speed prefix (e.g. "4Mbps (Liaqatabad)" -> "4Mbps", "3MB (Liaqatabad)" -> "3Mbps")
            $speed_prefix = $pkg_name;
            if (preg_match('/^(\d+)\s*(mbps|mb|m)/i', $pkg_name, $m_speed)) {
                $speed_prefix = $m_speed[1] . 'Mbps';
            }

            // Find matching pool in DB
            $pool_check = $pdo->prepare("SELECT pool_name, subnet_cidr FROM ip_pools WHERE (pool_name = ? OR pool_name LIKE ?) AND client_id = ? LIMIT 1");
            $pool_check->execute([$speed_prefix, $speed_prefix . '%', $client_id]);
            $matched_pool = $pool_check->fetch();

            $target_comment = $matched_pool ? $matched_pool['pool_name'] : $speed_prefix;
            $target_subnet = $matched_pool ? $matched_pool['subnet_cidr'] : '';

            // Update Database: packages & ip_pools
            $search_like = (preg_match('/^(\d+)/', $pkg_name, $m_num) ? $m_num[1] : '') . '%';
            $pdo->prepare("UPDATE packages SET wan_provider = ?, wan_interface = ?, routing_mark = ? WHERE (name LIKE ? OR id = ?) AND client_id = ?")
                ->execute([$target_wan, $target_wan, 'to-' . $target_wan, $search_like, $package_id, $client_id]);

            if ($matched_pool) {
                $pdo->prepare("UPDATE ip_pools SET wan_provider_name = ?, wan_interface = ?, routing_mark = ? WHERE pool_name = ? AND client_id = ?")
                    ->execute([$target_wan, $target_wan, 'to-' . $target_wan, $matched_pool['pool_name'], $client_id]);
            }

            // Ensure target WAN is permanently recorded in router_wans table
            $pdo->prepare("INSERT INTO router_wans (client_id, wan_name, routing_mark) VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE status = 'active'")
                ->execute([$client_id, $target_wan, 'to-' . $target_wan]);

            // Connect to MikroTik & live switch Address List (IN-PLACE REPLACEMENT, NO DUPLICATES!)
            $nas_stmt = $pdo->prepare("SELECT id, nasname, api_port, api_user, api_password FROM nas WHERE client_id = ? ORDER BY id ASC LIMIT 1");
            $nas_stmt->execute([$client_id]);
            $nas = $nas_stmt->fetch();

            $router_feedback = "";
            if ($nas) {
                $API = new RouterosAPI();
                $API->timeout = 3;
                $API->attempts = 1;
                $API->delay = 1;
                error_reporting(0);
                $port = $nas['api_port'] ?: 8728;

                if ($API->connect($nas['nasname'], $nas['api_user'] ?: 'admin', $nas['api_password'] ?: '', $port)) {
                    // Fetch ALL address list entries from router
                    $all_al = $API->comm('/ip/firewall/address-list/print');
                    
                    $matching_entries = [];
                    if (is_array($all_al)) {
                        foreach ($all_al as $entry) {
                            $e_id = $entry['.id'] ?? '';
                            $e_comment = trim($entry['comment'] ?? '');
                            $e_addr = trim($entry['address'] ?? '');

                            $is_match = false;
                            // Match by comment (e.g. '4Mbps')
                            if (!empty($e_comment) && strcasecmp($e_comment, $target_comment) === 0) {
                                $is_match = true;
                            }
                            // Match by exact subnet (e.g. '10.4.0.0/23')
                            if (!$is_match && !empty($target_subnet) && strcasecmp($e_addr, $target_subnet) === 0) {
                                $is_match = true;
                            }
                            // Match by subnet IP prefix (e.g. '10.4.0.')
                            if (!$is_match && !empty($target_subnet)) {
                                $pref = substr($target_subnet, 0, strrpos($target_subnet, '.'));
                                if (!empty($pref) && strpos($e_addr, $pref) === 0) {
                                    $is_match = true;
                                }
                            }

                            if ($is_match && !empty($e_id)) {
                                $matching_entries[] = [
                                    '.id' => $e_id,
                                    'address' => $e_addr,
                                    'current_list' => $entry['list'] ?? '',
                                    'comment' => $e_comment
                                ];
                            }
                        }
                    }

                    if (!empty($matching_entries)) {
                        // Found existing entry! Update the first one in-place
                        $primary = $matching_entries[0];
                        $API->comm('/ip/firewall/address-list/set', [
                            '.id' => $primary['.id'],
                            'list' => $target_wan,
                            'comment' => $target_comment
                        ]);

                        // Automatically purge any duplicates on the router!
                        for ($i = 1; $i < count($matching_entries); $i++) {
                            $API->comm('/ip/firewall/address-list/remove', [
                                '.id' => $matching_entries[$i]['.id']
                            ]);
                        }

                        $router_feedback = " & live MikroTik Address List updated in-place: '{$target_comment}' ({$primary['address']}) shifted to {$target_wan} (duplicates cleaned)!";
                    } else {
                        // If no entry exists at all, add one
                        if (!empty($target_subnet)) {
                            $API->comm('/ip/firewall/address-list/add', [
                                'list' => $target_wan,
                                'address' => $target_subnet,
                                'comment' => $target_comment
                            ]);
                            $router_feedback = " & created Address List on MikroTik: {$target_subnet} in {$target_wan}!";
                        }
                    }

                    $API->disconnect();
                } else {
                    $router_feedback = ". (Router connection timed out - changes saved in DB and Winbox command available below)";
                }
            }

            $flash_msg = "Package '<strong>{$pkg_name}</strong>' successfully shifted to <strong>{$target_wan}</strong>{$router_feedback}";
            $flash_type = 'success';
        }
    }
}

// --- FETCH DATA FOR RENDERING ---
// 1. Fetch IP Pools from Database
$pools_stmt = $pdo->prepare("SELECT p.*, n.shortname, n.nasname 
                             FROM ip_pools p 
                             LEFT JOIN nas n ON p.nas_id = n.id 
                             WHERE p.client_id = ? 
                             ORDER BY p.id ASC");
$pools_stmt->execute([$client_id]);
$pools = $pools_stmt->fetchAll();

// 2. Fetch Packages with Subscriber Counts & Live Online Users
$pkgs_stmt = $pdo->prepare("SELECT p.*, 
                                   COUNT(DISTINCT s.id) as subscriber_count,
                                   COUNT(DISTINCT CASE WHEN r.acctstoptime IS NULL AND r.radacctid IS NOT NULL THEN s.id END) as live_online_count 
                            FROM packages p 
                            LEFT JOIN subscribers s ON p.id = s.package_id 
                            LEFT JOIN radacct r ON s.username = r.username AND r.acctstoptime IS NULL
                            WHERE p.client_id = ? 
                            GROUP BY p.id 
                            ORDER BY subscriber_count DESC, p.id ASC");
$pkgs_stmt->execute([$client_id]);
$packages = $pkgs_stmt->fetchAll();

// 3. Extract Real Persistent WAN Lists (from router_wans table + synced MikroTik router)
$wans_stmt = $pdo->prepare("SELECT wan_name FROM router_wans WHERE client_id = ? AND status = 'active' ORDER BY id ASC");
$wans_stmt->execute([$client_id]);
$saved_wans = $wans_stmt->fetchAll(PDO::FETCH_COLUMN);

$detected_wans_map = [];
foreach ($saved_wans as $w) {
    if (!empty($w) && is_valid_wan_name($w)) $detected_wans_map[$w] = true;
}
foreach ($pools as $pl) {
    if (!empty($pl['wan_provider_name']) && is_valid_wan_name($pl['wan_provider_name'])) {
        $detected_wans_map[$pl['wan_provider_name']] = true;
    }
}
foreach ($packages as $pkg) {
    if (!empty($pkg['wan_provider']) && is_valid_wan_name($pkg['wan_provider'])) {
        $detected_wans_map[$pkg['wan_provider']] = true;
    }
}

// Fallback to PTCL ONLY if operator has zero WANs
if (empty($detected_wans_map)) {
    $detected_wans_map['PTCL'] = true;
}

$available_wans = array_values(array_unique(array_filter(array_keys($detected_wans_map))));

// Quick Stats
$total_pools = count($pools);
$total_wans_count = count($available_wans);
$total_pkgs_count = count($packages);
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<style>
    body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .card-ui { background: #ffffff; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; margin-bottom: 24px; }
    
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 18px;
        padding: 18px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(0,0,0,0.05);
    }
    .stat-pill-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        flex-shrink: 0;
    }
    .stat-pill-val { font-size: 1.45rem; font-weight: 700; color: #0f172a; line-height: 1.1; }
    .stat-pill-lbl { font-size: 0.74rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }

    /* Provider Badges */
    .badge-ptcl { background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; font-weight: 600; }
    .badge-telenor { background-color: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-weight: 600; }
    .badge-zong { background-color: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-weight: 600; }
    .badge-stormfiber { background-color: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 600; }
    .badge-nayatel { background-color: #f5f3ff; color: #7c3aed; border: 1px solid #ddd6fe; font-weight: 600; }
    .badge-jazz { background-color: #fefce8; color: #ca8a04; border: 1px solid #fef08a; font-weight: 600; }
    .badge-custom { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 600; }

    /* Nav Tabs */
    .nav-tabs-custom {
        border-bottom: 2px solid #e2e8f0;
        gap: 8px;
    }
    .nav-tabs-custom .nav-link {
        border: none;
        color: #64748b;
        font-weight: 600;
        padding: 12px 24px;
        border-radius: 12px 12px 0 0;
        transition: all 0.2s ease;
        background: transparent;
    }
    .nav-tabs-custom .nav-link:hover {
        color: #0f172a;
        background: #f1f5f9;
    }
    .nav-tabs-custom .nav-link.active {
        color: #2563eb;
        background: #ffffff;
        border-bottom: 3px solid #2563eb;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.02);
    }

    /* Terminal Script Box */
    .terminal-box {
        background: #0f172a;
        color: #38bdf8;
        font-family: 'Consolas', 'Courier New', monospace;
        font-size: 0.82rem;
        border-radius: 12px;
        padding: 16px;
        position: relative;
        overflow-x: auto;
        white-space: pre-wrap;
        line-height: 1.45;
    }
    .copy-terminal-btn {
        position: absolute;
        top: 10px;
        right: 10px;
        background: rgba(255,255,255,0.15);
        color: #ffffff;
        border: none;
        border-radius: 6px;
        padding: 4px 10px;
        font-size: 0.75rem;
        cursor: pointer;
        transition: background 0.2s;
    }
    .copy-terminal-btn:hover {
        background: rgba(255,255,255,0.3);
    }
</style>

<div class="container-fluid px-4 py-3">

    <!-- Flash Alert -->
    <?php if (!empty($flash_msg)): ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center gap-2" role="alert">
            <i class="fa-solid fa-circle-info fs-5"></i>
            <div><?= $flash_msg ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="card-ui p-4 mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div style="width: 52px; height: 52px; border-radius: 16px; background: linear-gradient(135deg, #06b6d4, #0891b2); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; box-shadow: 0 4px 12px rgba(6, 182, 212, 0.25);">
                    <i class="fa-solid fa-network-wired"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <h4 class="fw-bold mb-0 text-dark">IP Pools &amp; MikroTik Address Lists</h4>
                        <?= $sync_status_badge ?>
                    </div>
                    <p class="text-muted mb-0 small">Live synchronized with MikroTik Winbox (IP &gt; Pool &amp; IP &gt; Firewall &gt; Address Lists).</p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary rounded-3 px-3 shadow-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#createPoolModal">
                    <i class="fa-solid fa-plus me-1"></i> Create New Pool
                </button>
                <button type="button" class="btn btn-outline-success rounded-3 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#manageWansModal" title="Manage MikroTik WAN Ports">
                    <i class="fa-solid fa-satellite-dish me-1"></i> WAN Ports (<?= count($available_wans) ?>)
                </button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="force_sync">
                    <button type="submit" class="btn btn-outline-primary rounded-3 px-3 shadow-sm" title="Re-fetch everything from MikroTik">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> Sync from MikroTik
                    </button>
                </form>
                <a href="../clean_address_lists_fix.php" target="_blank" class="btn btn-outline-danger rounded-3 px-3 shadow-sm" title="Purge 700 Dynamic Entries & Ensure Clean Subnets">
                    <i class="fa-solid fa-broom me-1"></i> Clean Address Lists
                </a>
                <a href="dashboard.php" class="btn btn-outline-secondary rounded-3 px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(6, 182, 212, 0.12); color: #0891b2;">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div>
                    <div class="stat-pill-val"><?= $total_pools ?></div>
                    <div class="stat-pill-lbl">MikroTik IP Pools</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
                    <i class="fa-solid fa-shuffle"></i>
                </div>
                <div>
                    <div class="stat-pill-val" style="font-size: 1.15rem; word-break: break-word;"><?= htmlspecialchars(implode(' / ', $available_wans)) ?></div>
                    <div class="stat-pill-lbl"><?= (count($available_wans) > 1) ? '<span class="text-success fw-bold"><i class="fa-solid fa-shuffle me-1"></i>Multi-WAN (' . count($available_wans) . ' Ports)</span>' : '<span class="text-primary fw-bold"><i class="fa-solid fa-check me-1"></i>Single WAN (Standard)</span>' ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(245, 158, 11, 0.12); color: #d97706;">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <div>
                    <div class="stat-pill-val"><?= $total_pkgs_count ?></div>
                    <div class="stat-pill-lbl">Billing Packages</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: rgba(99, 102, 241, 0.12); color: #4f46e5;">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div>
                    <div class="stat-pill-val">Online</div>
                    <div class="stat-pill-lbl">Router Status</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-tabs nav-tabs-custom mb-4" id="networkTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="pools-tab" data-bs-toggle="tab" data-bs-target="#tab-pools" type="button" role="tab">
                <i class="fa-solid fa-network-wired me-2 text-info"></i> MikroTik IP Pools (IP &gt; Pool) <span class="badge bg-info text-white rounded-pill ms-1"><?= $total_pools ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="packages-tab" data-bs-toggle="tab" data-bs-target="#tab-packages" type="button" role="tab">
                <i class="fa-solid fa-shuffle me-2 text-warning"></i> Packages &amp; Outbound WAN Routing <span class="badge bg-warning text-dark rounded-pill ms-1"><?= $total_pkgs_count ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="test-tab" data-bs-toggle="tab" data-bs-target="#tab-test" type="button" role="tab">
                <i class="fa-solid fa-flask-vial me-2 text-success"></i> Test &amp; Verify Guide
            </button>
        </li>
    </ul>

    <div class="tab-content" id="networkTabsContent">

        <!-- ============================================================== -->
        <!-- TAB 1: MIKROTIK IP POOLS (MATCHING WINBOX SCREENSHOT 1) -->
        <!-- ============================================================== -->
        <div class="tab-pane fade show active" id="tab-pools" role="tabpanel">

            <div class="card-ui p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark">MikroTik IP Pools (IP &gt; Pool &gt; Pools)</h5>
                        <div class="text-muted small">MikroTik router par banay gaye pools, used IPs aur assigned Address Lists ka live status.</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#createPoolModal">
                        <i class="fa-solid fa-plus me-1"></i> Add Pool
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="poolsTable">
                        <thead class="table-light text-secondary small text-uppercase">
                            <tr>
                                <th>Pool Name</th>
                                <th>Addresses (IP Range)</th>
                                <th>Subnet CIDR</th>
                                <th>Assigned List (WAN)</th>
                                <th>Total</th>
                                <th>Used</th>
                                <th>Available</th>
                                <th>Usage</th>
                                <th class="text-end">Winbox Command</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pools)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <h6>No IP Pools Found on MikroTik</h6>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pools as $row): ?>
                                    <?php
                                        $p_lower = strtolower($row['wan_provider_name'] ?? '');
                                        $badge_class = 'badge-custom';
                                        if (strpos($p_lower, 'ptcl') !== false) $badge_class = 'badge-ptcl';
                                        elseif (strpos($p_lower, 'telenor') !== false) $badge_class = 'badge-telenor';
                                        elseif (strpos($p_lower, 'zong') !== false) $badge_class = 'badge-zong';
                                        elseif (strpos($p_lower, 'stormfiber') !== false) $badge_class = 'badge-stormfiber';
                                        elseif (strpos($p_lower, 'nayatel') !== false) $badge_class = 'badge-nayatel';

                                        $total = (int)($row['total_ips'] ?: 509);
                                        $used = (int)($row['used_ips'] ?: 0);
                                        $avail = (int)($row['available_ips'] ?: ($total - $used));
                                        $pct = $total > 0 ? round(($used / $total) * 100) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark d-flex align-items-center gap-2">
                                                <i class="fa-solid fa-circle-nodes text-info"></i>
                                                <?= htmlspecialchars($row['pool_name']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="font-monospace fw-bold text-dark small"><?= htmlspecialchars($row['ip_range']) ?></span>
                                        </td>
                                        <td>
                                            <span class="font-monospace text-muted small"><?= htmlspecialchars($row['subnet_cidr'] ?: 'N/A') ?></span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $badge_class ?> px-3 py-1 rounded-pill">
                                                <i class="fa-solid fa-satellite-dish me-1"></i><?= htmlspecialchars($row['wan_provider_name'] ?: 'PTCL') ?>
                                            </span>
                                        </td>
                                        <td><span class="fw-bold"><?= $total ?></span></td>
                                        <td><span class="text-danger fw-bold"><?= $used ?></span></td>
                                        <td><span class="text-success fw-bold"><?= $avail ?></span></td>
                                        <td style="min-width: 120px;">
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $pct ?>%;"></div>
                                            </div>
                                            <div class="text-muted" style="font-size: 0.7rem;"><?= $pct ?>% used</div>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3" 
                                                    onclick="openPoolScriptModal('<?= htmlspecialchars(addslashes($row['pool_name'])) ?>', '<?= htmlspecialchars(addslashes($row['ip_range'])) ?>', '<?= htmlspecialchars(addslashes($row['wan_provider_name'] ?: 'PTCL')) ?>', '<?= htmlspecialchars(addslashes($row['subnet_cidr'])) ?>')">
                                                <i class="fa-solid fa-terminal me-1"></i> Script
                                            </button>
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
        <!-- TAB 2: PACKAGES & OUTBOUND WAN ROUTING (ADDRESS LIST SHIFTER) -->
        <!-- ============================================================== -->
        <div class="tab-pane fade" id="tab-packages" role="tabpanel">

            <div class="card-ui p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark">Packages &amp; Outbound WAN Routing</h5>
                        <div class="text-muted small">Har package ka active WAN port dekhein aur ek click me doosri port par shift karein.</div>
                    </div>
                </div>

                <?php if ($total_wans_count <= 1): ?>
                    <div class="alert alert-info border-0 rounded-4 d-flex align-items-center gap-3 py-3 mb-4" style="background-color: #f0f9ff; border-left: 4px solid #0284c7 !important;">
                        <i class="fa-solid fa-circle-info fa-2x text-info"></i>
                        <div>
                            <div class="fw-bold text-dark">Single WAN Mode (Standard Routing)</div>
                            <div class="small text-secondary">
                                Is router par sirf <strong>1 WAN port (<?= htmlspecialchars($available_wans[0] ?? 'PTCL') ?>)</strong> active hai. Single-WAN mode me load balancing mangle rules ki zaroorat nahi hoti, is liye FreeRADIUS standard connection deta hai taake connection foran aur bina rukawat ke banay. Agar router me 2 ya us se zyada WANs honge to Multi-WAN Load Balancing automatic active ho jayegi.
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success border-0 rounded-4 d-flex align-items-center gap-3 py-3 mb-4" style="background-color: #f0fdf4; border-left: 4px solid #16a34a !important;">
                        <i class="fa-solid fa-shield-halved fa-2x text-success"></i>
                        <div>
                            <div class="fw-bold text-dark">Multi-WAN Load Balancing Active (<?= $total_wans_count ?> Ports: <?= htmlspecialchars(implode(', ', $available_wans)) ?>)</div>
                            <div class="small text-secondary">
                                Users connect hotay waqt FreeRADIUS unka dynamic IP unke package ke mutabiq Address List (<?= htmlspecialchars(implode(' / ', $available_wans)) ?>) me dalay ga, aur MikroTik mangle rules traffic ko barabar balance karengay.
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="packagesTable">
                        <thead class="table-light text-secondary small text-uppercase">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Package Name</th>
                                <th>Speed Limit</th>
                                <th>Active Subscribers</th>
                                <th>MikroTik Address List</th>
                                <th>Current Active WAN</th>
                                <th>Shift Destination WAN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($packages)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <h6>No Packages Found</h6>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($packages as $idx => $pkg): ?>
                                    <?php
                                        $cur_prov = $pkg['wan_provider'] ?: 'PTCL';
                                        $prov_lower = strtolower($cur_prov);
                                        $pkg_badge_class = 'badge-custom';
                                        if (strpos($prov_lower, 'ptcl') !== false) $pkg_badge_class = 'badge-ptcl';
                                        elseif (strpos($prov_lower, 'telenor') !== false) $pkg_badge_class = 'badge-telenor';
                                        elseif (strpos($prov_lower, 'zong') !== false) $pkg_badge_class = 'badge-zong';
                                        elseif (strpos($prov_lower, 'stormfiber') !== false) $pkg_badge_class = 'badge-stormfiber';
                                        elseif (strpos($prov_lower, 'nayatel') !== false) $pkg_badge_class = 'badge-nayatel';
                                        elseif (strpos($prov_lower, 'jazz') !== false) $pkg_badge_class = 'badge-jazz';

                                        // Find matched subnet from pools
                                        preg_match('/^(\d+[mM][bB]ps|\d+[mM][bB])/i', $pkg['name'], $m_sp);
                                        $sp_pref = !empty($m_sp[1]) ? $m_sp[1] : $pkg['name'];
                                        $m_cidr = '';
                                        foreach ($pools as $pl) {
                                            if (strcasecmp($pl['pool_name'], $sp_pref) === 0 || strpos($pl['pool_name'], $sp_pref) === 0) {
                                                $m_cidr = $pl['subnet_cidr'];
                                                break;
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td class="fw-bold text-muted"><?= $idx + 1 ?></td>
                                        <td>
                                            <div class="fw-bold text-dark fs-6">
                                                <i class="fa-solid fa-box text-primary me-1"></i>
                                                <?= htmlspecialchars($pkg['name']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">
                                                <?= htmlspecialchars($pkg['rate_limit'] ?: 'No Limit') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill fw-bold" title="Total registered subscribers">
                                                <i class="fa-solid fa-users me-1"></i> <?= $pkg['subscriber_count'] ?> Total
                                            </span>
                                            <?php if ($pkg['live_online_count'] > 0): ?>
                                                 <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill fw-bold ms-1" title="Currently active online sessions">
                                                    <i class="fa-solid fa-circle text-success me-1" style="font-size: 0.5rem;"></i><?= $pkg['live_online_count'] ?> Online
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-subtle text-secondary border font-monospace px-2 py-1">
                                                <i class="fa-solid fa-list-check me-1"></i><?= htmlspecialchars($m_cidr ?: $pkg['name']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $pkg_badge_class ?> px-3 py-1 rounded-pill fs-7">
                                                <i class="fa-solid fa-satellite-dish me-1"></i><?= htmlspecialchars($cur_prov) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <!-- Inline Shift Form with ONLY Real Detected WANs (No Dummy Data!) -->
                                            <form method="POST" class="d-flex align-items-center gap-2 mb-0" onsubmit="return confirm('Shift package \'<?= htmlspecialchars(addslashes($pkg['name'])) ?>\' to selected WAN now?');">
                                                <input type="hidden" name="action" value="shift_package_wan">
                                                <input type="hidden" name="package_id" value="<?= $pkg['id'] ?>">
                                                
                                                <select class="form-select form-select-sm fw-semibold" name="target_wan" style="min-width: 140px;" required>
                                                    <?php foreach ($available_wans as $w): ?>
                                                        <option value="<?= htmlspecialchars($w) ?>" <?= (strcasecmp($cur_prov, $w) === 0) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($w) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                
                                                <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm text-nowrap" title="Shift Traffic Now">
                                                    <i class="fa-solid fa-shuffle me-1"></i> Shift
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
        <!-- TAB 3: TEST & VERIFY GUIDE (A TO Z TESTING METHODS) -->
        <!-- ============================================================== -->
        <div class="tab-pane fade" id="tab-test" role="tabpanel">

            <div class="card-ui p-4">
                <div class="d-flex align-items-center gap-3 mb-4">
                    <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(16, 185, 129, 0.12); color: #059669; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                        <i class="fa-solid fa-flask-vial"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-1 text-dark">Switch Karne Ke Baad Test &amp; Verify Karne Ka Tareeqa</h5>
                        <p class="text-muted mb-0 small">Aap 3 aasan tareeqon se 100% verify kar sakte hain ke users PTCL se Telenor par shift ho chuke hain.</p>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Method 1: Winbox Address Lists Inspection -->
                    <div class="col-md-4">
                        <div class="p-3 border rounded-4 h-100 bg-light">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-primary rounded-circle px-2">1</span>
                                <h6 class="fw-bold mb-0 text-dark">Winbox Live Verification</h6>
                            </div>
                            <p class="small text-muted mb-2">
                                Winbox me <strong>IP &gt; Firewall &gt; Address Lists</strong> open karein.
                            </p>
                            <div class="alert alert-white border p-2 small mb-0 rounded-3">
                                <strong>Natija:</strong> Jese hi aap website par package ka dropdown change kar ke <strong>Shift</strong> dabayenge, Winbox me us package ki row ka <code>List</code> column <strong>PTCL</strong> se foran <strong>Telenor</strong> ho jayega!
                            </div>
                        </div>
                    </div>

                    <!-- Method 2: Client IP / WhatIsMyIP Test -->
                    <div class="col-md-4">
                        <div class="p-3 border rounded-4 h-100 bg-light">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-success rounded-circle px-2">2</span>
                                <h6 class="fw-bold mb-0 text-dark">Customer PC Public IP Test</h6>
                            </div>
                            <p class="small text-muted mb-2">
                                Us package par chalne wale kisi bhi customer ke PC par browser kholein aur search karein:
                            </p>
                            <div class="alert alert-white border p-2 small mb-0 rounded-3">
                                <a href="https://whatismyipaddress.com" target="_blank" class="text-decoration-none fw-bold">whatismyipaddress.com</a><br>
                                â€¢ Pehle ISP: <strong>PTCL</strong> (PTCL Public IP)<br>
                                â€¢ Shift ke baad ISP: <strong>Telenor Pakistan</strong> (Telenor Public IP)
                            </div>
                        </div>
                    </div>

                    <!-- Method 3: Live Winbox Interface Graph -->
                    <div class="col-md-4">
                        <div class="p-3 border rounded-4 h-100 bg-light">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-warning text-dark rounded-circle px-2">3</span>
                                <h6 class="fw-bold mb-0 text-dark">Live Traffic Graph Test</h6>
                            </div>
                            <p class="small text-muted mb-2">
                                Winbox me <strong>Interfaces</strong> tab open karein aur PTCL aur Telenor dono ke <strong>Traffic Graph</strong> open rakhein.
                            </p>
                            <div class="alert alert-white border p-2 small mb-0 rounded-3">
                                <strong>Natija:</strong> Shift karte hi PTCL ka traffic graph drop hoga aur Telenor interface par foran live traffic speed spike karegi!
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-primary-subtle rounded-3 small text-dark d-flex align-items-center gap-3">
                    <i class="fa-solid fa-circle-check text-primary fs-4"></i>
                    <div>
                        <strong>Kamyabi:</strong> In teeno tareeqon se aap confirm kar sakte hain ke users ka internet port live switch ho chuka hai, aur kisi user ka internet connection drop nahi hua!
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: CREATE NEW MIKROTIK IP POOL -->
<!-- ============================================================== -->
<div class="modal fade" id="createPoolModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom px-4 pt-4 pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(6, 182, 212, 0.15); color: #0891b2; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fa-solid fa-plus"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark">Create New MikroTik IP Pool</h5>
                        <p class="text-muted small mb-0">Database me naya pool add karein aur MikroTik Router me live provision karein</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createPoolForm">
                <input type="hidden" name="action" value="create_pool">
                <div class="modal-body px-4 py-3">
                    <div class="alert alert-info border-0 rounded-3 small mb-3">
                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i>
                        Ye pool banate hi website automatic aapke MikroTik Router par <code>/ip pool</code> create karegi aur <code>IP &gt; Firewall &gt; Address Lists</code> me assigned WAN port (PTCL / Telenor) add kar degi!
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Pool Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="pool_name" class="form-control" placeholder="e.g. 15Mbps, VIP-Pool, 25M" required>
                            <small class="text-muted" style="font-size: 0.72rem;">Ye naam MikroTik Winbox me <code>IP &gt; Pool</code> ka banega.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                IP Range (Addresses) <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="ip_range" id="modal_ip_range" class="form-control font-monospace" placeholder="e.g. 10.15.0.2-10.15.1.254" required oninput="calcSubnetFromRange(this.value)">
                            <small class="text-muted" style="font-size: 0.72rem;">Format: <code>10.X.0.2-10.X.1.254</code> (Subnet CIDR auto-calculate hoga)</small>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Subnet CIDR (Address List)
                            </label>
                            <input type="text" name="subnet_cidr" id="modal_subnet_cidr" class="form-control font-monospace" placeholder="e.g. 10.15.0.0/23">
                            <small class="text-muted" style="font-size: 0.72rem;">MikroTik <code>Address List</code> me add hoga policy routing ke liye.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Outbound WAN Port (Internet Port) <span class="text-danger">*</span>
                            </label>
                            <select name="wan_provider" class="form-select fw-semibold" required>
                                <?php foreach ($available_wans as $w): ?>
                                    <option value="<?= htmlspecialchars($w) ?>" <?= (strcasecmp($w, 'PTCL') === 0) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($w) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" style="font-size: 0.72rem;">Is pool ke users is WAN port se internet use karenge.</small>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Gateway IP (Optional)
                            </label>
                            <input type="text" name="gateway" id="modal_gateway" class="form-control font-monospace" placeholder="e.g. 10.15.0.1">
                        </div>
                        <div class="col-md-6 d-flex align-items-center pt-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="auto_link_package" id="autoLinkPkg" value="1" checked>
                                <label class="form-check-label small fw-bold text-dark" for="autoLinkPkg">
                                    Auto-Link Matching Package
                                </label>
                                <div class="text-muted" style="font-size: 0.72rem;">Agar is speed ka koi package hai to usay ye pool auto-assign ho jaega.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light rounded-bottom-4 justify-content-between">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">
                        <i class="fa-solid fa-save me-1"></i> Create Pool &amp; Push to Router
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: MANAGE MIKROTIK WAN PORTS -->
<!-- ============================================================== -->
<div class="modal fade" id="manageWansModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom px-4 pt-4 pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(16, 185, 129, 0.12); color: #059669; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fa-solid fa-satellite-dish"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark">MikroTik WAN Ports Manager</h5>
                        <p class="text-muted small mb-0">Multi-WAN routing lists &amp; interfaces</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="alert alert-info border-0 rounded-3 small mb-3">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    Ye WAN ports MikroTik ke Address Lists, Mangle Rules aur Interfaces se detect hoti hain. Agar aap tamam packages PTCL par bhi shift kar dein, tab bhi Telenor aur deegar ports dropdown me hamesha mehfooz rahengi!
                </div>

                <h6 class="fw-bold text-dark small text-uppercase mb-2">Configured WAN Ports (<?= count($available_wans) ?>)</h6>
                <div class="list-group mb-4 shadow-sm rounded-3">
                    <?php foreach ($available_wans as $w): ?>
                        <?php 
                            $is_core = in_array(strtoupper($w), ['PTCL', 'TELENOR']);
                            $w_lower = strtolower($w);
                            $b_class = 'badge-custom';
                            if (strpos($w_lower, 'ptcl') !== false) $b_class = 'badge-ptcl';
                            elseif (strpos($w_lower, 'telenor') !== false) $b_class = 'badge-telenor';
                            elseif (strpos($w_lower, 'zong') !== false) $b_class = 'badge-zong';
                            elseif (strpos($w_lower, 'stormfiber') !== false) $b_class = 'badge-stormfiber';
                            elseif (strpos($w_lower, 'nayatel') !== false) $b_class = 'badge-nayatel';
                        ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge <?= $b_class ?> px-3 py-1 rounded-pill">
                                    <i class="fa-solid fa-network-wired me-1"></i><?= htmlspecialchars($w) ?>
                                </span>
                                <span class="text-muted small font-monospace">to-<?= htmlspecialchars($w) ?></span>
                            </div>
                            <div>
                                <?php if (count($available_wans) <= 1): ?>
                                    <span class="badge bg-light text-muted border small">Primary WAN</span>
                                <?php else: ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove WAN Port \'<?= htmlspecialchars(addslashes($w)) ?>\'?');">
                                        <input type="hidden" name="action" value="delete_custom_wan">
                                        <input type="hidden" name="del_wan_name" value="<?= htmlspecialchars($w) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger border-0 p-1 px-2 rounded-pill" title="Delete this WAN Port">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <h6 class="fw-bold text-dark small text-uppercase mb-2">Add New / Custom WAN Port</h6>
                <form method="POST" class="d-flex gap-2">
                    <input type="hidden" name="action" value="add_custom_wan">
                    <input type="text" class="form-control form-control-sm" name="new_wan_name" placeholder="e.g. Zong, StormFiber, Nayatel, ether2" required pattern="[A-Za-z0-9_\-]+" title="Letters, numbers, dash and underscore only">
                    <button type="submit" class="btn btn-sm btn-success rounded-3 px-3 text-nowrap">
                        <i class="fa-solid fa-plus me-1"></i> Add WAN
                    </button>
                </form>
            </div>
            <div class="modal-footer border-top px-4 py-3 bg-light rounded-bottom-4 justify-content-between">
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="force_sync">
                    <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                        <i class="fa-solid fa-arrows-rotate me-1"></i> Re-scan Router
                    </button>
                </form>
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: WINBOX COMMAND VIEWER -->
<!-- ============================================================== -->
<div class="modal fade" id="packageMangleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom px-4 pt-4 pb-3">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: #0f172a; color: #38bdf8; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fa-solid fa-terminal"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark">MikroTik Winbox Address List Command</h5>
                        <p class="text-muted small mb-0">Direct Terminal command executed by the website on MikroTik</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <p class="small text-muted mb-2">Website MikroTik RouterOS API ke zariye ye command chala kar live switch karti hai:</p>
                <div class="terminal-box" style="min-height: 140px;">
                    <button type="button" class="copy-terminal-btn" onclick="copyTerminalCode('pkg_mangle_content', this)">
                        <i class="fa-solid fa-copy me-1"></i> Copy Commands
                    </button>
                    <code id="pkg_mangle_content"></code>
                </div>
            </div>
            <div class="modal-footer border-top px-4 py-3 bg-light rounded-bottom-4">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-dark rounded-pill px-4" onclick="copyTerminalCode('pkg_mangle_content', this)">
                    <i class="fa-solid fa-copy me-1"></i> Copy All
                </button>
            </div>
        </div>
    </div>
</div>

<!-- DataTables Scripts -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#poolsTable').DataTable({
        responsive: true,
        pageLength: 15,
        language: { search: "_INPUT_", searchPlaceholder: "Search pools, IPs, WAN..." }
    });

    $('#packagesTable').DataTable({
        responsive: true,
        pageLength: 15,
        language: { search: "_INPUT_", searchPlaceholder: "Search packages, speeds, WAN..." }
    });
});

function openPackageMangleModal(pkgPrefix, curProv, curCidr) {
    const availableWans = <?= json_encode($available_wans) ?>;
    let switchCmds = '';
    availableWans.forEach(w => {
        if (w.toLowerCase() !== curProv.toLowerCase()) {
            switchCmds += `# Shift to ${w} in Winbox Terminal:\n/ip firewall address-list set [find comment="${pkgPrefix}"] list="${w}"\n\n`;
        }
    });

    const cmd = `# =======================================================
# MIKROTIK WINBOX - SWITCH ADDRESS LIST ROUTING
# Package: "${pkgPrefix}" | Current WAN: ${curProv} | Subnet: ${curCidr}
# =======================================================

${switchCmds}# Verify in Winbox:
/ip firewall address-list print where comment="${pkgPrefix}"`;

    document.getElementById('pkg_mangle_content').textContent = cmd;
    new bootstrap.Modal(document.getElementById('packageMangleModal')).show();
}

function openPoolScriptModal(poolName, range, wan, cidr) {
    const cmd = `# MikroTik IP Pool & Address List Config
/ip pool add name="${poolName}" ranges="${range}"
/ip firewall address-list add list="${wan}" address="${cidr}" comment="${poolName}"`;
    document.getElementById('pkg_mangle_content').textContent = cmd;
    new bootstrap.Modal(document.getElementById('packageMangleModal')).show();
}

function copyTerminalCode(elemId, btnElem) {
    const code = document.getElementById(elemId).textContent;
    navigator.clipboard.writeText(code).then(() => {
        const originalHtml = btnElem.innerHTML;
        btnElem.innerHTML = '<i class="fa-solid fa-check me-1"></i> Copied!';
        btnElem.classList.add('btn-success');
        setTimeout(() => {
            btnElem.innerHTML = originalHtml;
            btnElem.classList.remove('btn-success');
        }, 2000);
    });
}

// Live calculation of Subnet CIDR & Gateway from IP Range
function calcSubnetFromRange(val) {
    val = val.trim();
    const m = val.match(/^(\d+\.\d+\.\d+\.\d+)\s*-\s*(\d+\.\d+\.\d+\.\d+)$/);
    if (m) {
        const p1 = m[1].split('.');
        const p2 = m[2].split('.');
        const c1 = parseInt(p1[2]);
        const c2 = parseInt(p2[2]);
        const cidrEl = document.getElementById('modal_subnet_cidr');
        const gwEl = document.getElementById('modal_gateway');

        if (p1[0] === p2[0] && p1[1] === p2[1]) {
            if (c1 === c2) {
                if (cidrEl) cidrEl.value = p1[0] + '.' + p1[1] + '.' + c1 + '.0/24';
            } else if (Math.abs(c2 - c1) === 1) {
                const baseC = Math.min(c1, c2) - (Math.min(c1, c2) % 2);
                if (cidrEl) cidrEl.value = p1[0] + '.' + p1[1] + '.' + baseC + '.0/23';
            } else if (Math.abs(c2 - c1) <= 3) {
                const baseC = Math.min(c1, c2) - (Math.min(c1, c2) % 4);
                if (cidrEl) cidrEl.value = p1[0] + '.' + p1[1] + '.' + baseC + '.0/22';
            }
            if (gwEl && p1[3] == '2') {
                gwEl.value = p1[0] + '.' + p1[1] + '.' + c1 + '.1';
            }
        }
    }
}

// Background Auto-Poller: Detects newly created pools directly in MikroTik Winbox
(function() {
    let checking = false;
    setInterval(function() {
        if (checking) return;
        checking = true;
        fetch('ajax_sync_pools.php')
            .then(r => r.json())
            .then(data => {
                checking = false;
                if (data && data.status && data.new_pools && data.new_pools > 0) {
                    console.log('Detected ' + data.new_pools + ' new pool(s) from MikroTik! Refreshing...');
                    window.location.reload();
                }
            })
            .catch(() => { checking = false; });
    }, 25000);
})();
</script>

<?php require_once 'footer.php'; ?>
