<?php
require_once 'header.php';
require_once __DIR__ . '/../config/radius_kick_helper.php';

// Helper to get dealer price for a package
function getDealerPkgPrice($pdo, $dealer_id, $pkg_id) {
    $dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
    $dpStmt->execute([$dealer_id, $pkg_id]);
    $price = $dpStmt->fetchColumn();
    if ($price === false || $price === null) {
        $pStmt = $pdo->prepare("SELECT price FROM packages WHERE id = ?");
        $pStmt->execute([$pkg_id]);
        $price = $pStmt->fetchColumn() ?: 0;
    }
    return (float)$price;
}

// Fetch Dealer Permissions
$can_custom_expiry = (bool)($current_dealer['perm_custom_expiry'] ?? 0);

// Refresh current dealer balance from DB
$dBalStmt = $pdo->prepare("SELECT balance, username FROM dealers WHERE id = ?");
$dBalStmt->execute([$dealer_id]);
$dData = $dBalStmt->fetch(PDO::FETCH_ASSOC);
$current_dealer_balance = (float)($dData['balance'] ?? 0);

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD PAYMENT (Dealer wallet balance -> Customer account balance)
    if ($action === 'add_payment') {
        $sub_id = (int)($_POST['sub_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');

        if ($amount <= 0) {
            echo "<script>alert('Please enter a valid amount.'); window.location='dashboard.php#reportsSection';</script>";
            exit;
        }

        if ($current_dealer_balance < $amount) {
            echo "<script>alert('Insufficient balance in your wallet! Available: Rs. " . number_format($current_dealer_balance, 2) . ", Required: Rs. " . number_format($amount, 2) . "'); window.location='dashboard.php#reportsSection';</script>";
            exit;
        }

        // Verify subscriber belongs to this dealer
        $uStmt = $pdo->prepare("SELECT username, balance FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$sub_id, $dealer_id]);
        $sub = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            echo "<script>alert('Customer not found or unauthorized.'); window.location='dashboard.php#reportsSection';</script>";
            exit;
        }

        $u = $sub['username'];

        try {
            $pdo->beginTransaction();

            // Deduct from Dealer
            $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$amount, $dealer_id]);
            $new_dealer_bal = $current_dealer_balance - $amount;

            // Credit to Subscriber
            $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ? AND dealer_id = ?")->execute([$amount, $sub_id, $dealer_id]);
            $new_sub_bal = (float)$sub['balance'] + $amount;

            // Dealer Note
            $note_text = "Added Payment Rs. " . number_format($amount, 2) . " to customer " . $u . (!empty($desc) ? " ($desc)" : "") . ". Remaining wallet: Rs. " . number_format($new_dealer_bal, 2);
            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note_text]);

            // User Ledger
            $ledger_desc = "Payment added by Dealer " . $current_dealer['username'] . (!empty($desc) ? " - $desc" : "");
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $u, $amount, $new_sub_bal, $ledger_desc]);

            // Activity Log
            $actStr = "Added Payment: Rs. " . number_format($amount, 2) . " by Dealer " . $current_dealer['username'];
            $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'User', ?)")
                ->execute([$client_id, $dealer_id, $current_dealer['username'], $u, $actStr]);

            $pdo->commit();
            echo "<script>alert('Payment of Rs. " . number_format($amount, 2) . " successfully added to user " . addslashes($u) . "!'); window.location='dashboard.php#reportsSection';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error processing payment: " . addslashes($e->getMessage()) . "'); window.location='dashboard.php#reportsSection';</script>";
            exit;
        }
    }

    // 2. RENEW USER
    if ($action === 'renew_user') {
        $id = (int)($_POST['id'] ?? 0);

        $cd = check_renewal_cooldown($pdo, $id, 600);
        if (!$cd['allowed']) {
            $rem_mins = $cd['remaining_minutes'];
            echo "<script>alert('Duplicate Renewal Blocked!\\n\\nIs customer ka package abhi renew ho chuka hai.\\nDubara renew karne k liye {$rem_mins} minute intezar karein.'); window.location='dashboard.php#reportsSection';</script>";
            exit;
        }
        $package_id = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
        $expiry_date = $_POST['expiry_date'] ?? null;

        $uStmt = $pdo->prepare("SELECT username, package_id as old_package_id, expiry_date as old_expiry FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$id, $dealer_id]);
        $subData = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$subData) {
            echo "<script>alert('Customer not found or unauthorized.'); window.location='dashboard.php#reportsSection';</script>";
            exit;
        }

        $u = $subData['username'];
        $target_pkg_id = ($package_id > 0) ? $package_id : (int)$subData['old_package_id'];

        $p = $pdo->prepare("SELECT name, rate_limit, price, validity_days FROM packages WHERE id = ?");
        $p->execute([$target_pkg_id]);
        $pkg = $p->fetch(PDO::FETCH_ASSOC);

        if (!$pkg) {
            echo "<script>alert('Invalid package selected.'); window.location='dashboard.php#reportsSection';</script>";
            exit;
        }

        try {
            $pdo->beginTransaction();
            $now = time();
            $old_expiry = (!empty($subData['old_expiry']) && $subData['old_expiry'] !== '0000-00-00 00:00:00') ? strtotime($subData['old_expiry']) : 0;
            $val_days = (int)(($pkg['validity_days'] ?? 0) > 0 ? $pkg['validity_days'] : 30);

            // Compute New Expiry
            if ($can_custom_expiry && !empty($expiry_date)) {
                $new_expiry_time = strtotime($expiry_date);
            } else {
                if ($old_expiry > $now) {
                    $new_expiry_time = strtotime("+$val_days days", $old_expiry);
                } else {
                    $new_expiry_time = strtotime("+$val_days days", $now);
                }
            }

            $dp_price = getDealerPkgPrice($pdo, $dealer_id, $target_pkg_id);
            $deduction = $dp_price;
            if ($can_custom_expiry && !empty($expiry_date)) {
                $seconds = $new_expiry_time - $now;
                if ($seconds > 0) {
                    $days = $seconds / 86400;
                    $deduction = round($days * ($dp_price / 30), 2);
                }
            }

            if ($deduction > $current_dealer_balance) {
                $pdo->rollBack();
                echo "<script>alert('Insufficient dealer wallet balance! Required: Rs. $deduction, Available: Rs. $current_dealer_balance'); window.location='dashboard.php#reportsSection';</script>";
                exit;
            }

            if ($deduction > 0) {
                $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$deduction, $dealer_id]);
                $note = "Package Renewal: {$pkg['name']} for $u. Deducted: Rs. $deduction";
                $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
            }

            $new_expiry_str = date('Y-m-d H:i:s', $new_expiry_time);
            $status = ($new_expiry_time > $now) ? 'active' : 'expired';
            $now_dt = date('Y-m-d H:i:s');

            $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = ?, last_renewed_at = ? WHERE id = ? AND dealer_id = ?")
                ->execute([$target_pkg_id, $new_expiry_str, $status, $now_dt, $id, $dealer_id]);

            // User Ledger
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, 0, ?)")
                ->execute([$client_id, $u, $deduction, "Package Renewed: {$pkg['name']} by Dealer {$current_dealer['username']}"]);

            // FreeRADIUS Sync
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
            if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
            }
            $formatted_expiry = date('d M Y H:i:s', $new_expiry_time);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);

            $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Package Renewed by Dealer')")
                ->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);

            $pdo->commit();
            kick_subscriber_session($pdo, $u, $client_id);
            echo "<script>alert('User renewed successfully!'); window.location='dashboard.php#reportsSection';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error renewing user: " . addslashes($e->getMessage()) . "'); window.location='dashboard.php#reportsSection';</script>";
            exit;
        }
    }
}

// Fetch Dealer Packages for Renew Modal
$dpStmt = $pdo->prepare("SELECT p.id, p.name, p.rate_limit, dp.dealer_price FROM dealer_packages dp JOIN packages p ON dp.package_id = p.id WHERE dp.dealer_id = ? ORDER BY p.name ASC");
$dpStmt->execute([$dealer_id]);
$dealer_packages = $dpStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($dealer_packages)) {
    $dpStmt = $pdo->prepare("SELECT id, name, rate_limit, price as dealer_price FROM packages WHERE client_id = ? ORDER BY name ASC");
    $dpStmt->execute([$client_id]);
    $dealer_packages = $dpStmt->fetchAll(PDO::FETCH_ASSOC);
}

// User Reports Filter Logic
$report_filter = $_GET['report'] ?? 'all';
$filter_sql = "";
if ($report_filter == 'expired') {
    $filter_sql = " AND (s.status = 'expired' OR (s.expiry_date IS NOT NULL AND s.expiry_date < NOW())) AND s.status != 'disabled'";
} elseif ($report_filter == 'expiring_1') {
    $filter_sql = " AND s.status = 'active' AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)";
} elseif ($report_filter == 'expiring_3') {
    $filter_sql = " AND s.status = 'active' AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)";
} elseif ($report_filter == 'expiring_1w') {
    $filter_sql = " AND s.status = 'active' AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
} elseif ($report_filter == 'expiring_2w') {
    $filter_sql = " AND s.status = 'active' AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)";
} elseif ($report_filter == 'disabled') {
    $filter_sql = " AND s.status = 'disabled'";
}

$subsStmt = $pdo->prepare("
    SELECT s.*, p.name as package_name,
    (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
    (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s 
    LEFT JOIN packages p ON s.package_id = p.id 
    WHERE s.dealer_id = ? $filter_sql 
    ORDER BY s.id DESC
");
$subsStmt->execute([$dealer_id]);
$subs = $subsStmt->fetchAll(PDO::FETCH_ASSOC);

// Dealer Stats Scope
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN s.status = 'active' AND (s.expiry_date IS NULL OR s.expiry_date > NOW()) THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN (s.status = 'expired' OR (s.expiry_date IS NOT NULL AND s.expiry_date < NOW())) AND s.status != 'disabled' THEN 1 ELSE 0 END) as expired_users,
        SUM(CASE WHEN s.status = 'disabled' THEN 1 ELSE 0 END) as disabled_users,
        SUM(CASE WHEN s.service_type = 'pppoe' THEN 1 ELSE 0 END) as pppoe_users,
        SUM(CASE WHEN s.service_type = 'hotspot' THEN 1 ELSE 0 END) as hotspot_users,
        SUM(CASE WHEN s.status = 'active' AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as expiring_1d,
        SUM(CASE WHEN s.status = 'active' AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) as expiring_3d,
        SUM(CASE WHEN s.status = 'active' AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as expiring_1w,
        SUM(CASE WHEN s.status = 'active' AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as expiring_2w
    FROM subscribers s 
    WHERE s.dealer_id = ?
");
$statsStmt->execute([$dealer_id]);
$s = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Online Users
$onStmt = $pdo->prepare("SELECT COUNT(DISTINCT r.username) FROM radacct r JOIN subscribers sub ON r.username = sub.username WHERE sub.dealer_id = ? AND r.acctstoptime IS NULL");
$onStmt->execute([$dealer_id]);
$online_users = $onStmt->fetchColumn();

// Variables for ease
$total_users = $s['total_users'] ?: 0;
$active_users = $s['active_users'] ?: 0;
$expired = $s['expired_users'] ?: 0;
$disabled_users = $s['disabled_users'] ?: 0;
$pppoe_users = $s['pppoe_users'] ?: 0;
$hotspot_users = $s['hotspot_users'] ?: 0;
$expiring_1d = $s['expiring_1d'] ?: 0;
$expiring_3d = $s['expiring_3d'] ?: 0;
$expiring_1w = $s['expiring_1w'] ?: 0;
$expiring_2w = $s['expiring_2w'] ?: 0;
$offline_users = max(0, $total_users - $online_users);
$balance = $current_dealer_balance;

// Chart Data
$chart_online = $online_users;
$chart_active = max(0, $active_users - $online_users);
$chart_expired = $expired;
$chart_disabled = $disabled_users;
$chart_others = max(0, $total_users - ($chart_online + $chart_active + $chart_expired + $chart_disabled));

$pct = function($val) use ($total_users) {
    if ($total_users == 0) return "0.00%";
    return number_format(($val / $total_users) * 100, 2) . "%";
};

// Recent Activity
$alogsStmt = $pdo->prepare("SELECT activity, against_to, created_at FROM activity_logs WHERE dealer_id = ? ORDER BY id DESC LIMIT 10");
$alogsStmt->execute([$dealer_id]);
$dealer_activities = $alogsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    .card-ui { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; margin-bottom: 20px; }
    
    /* Top Quick Actions */
    .quick-btn { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px 25px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: #475569; min-width: 110px; transition: 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .quick-btn i { font-size: 1.5rem; color: #3b82f6; margin-bottom: 8px; }
    .quick-btn span { font-size: 0.8rem; font-weight: 500; }
    .quick-btn:hover { border-color: #cbd5e1; transform: translateY(-2px); color: #1e293b; }

    /* Stats Grid */
    .stat-tabs { display: flex; gap: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px; margin-bottom: 20px; }
    .stat-tab { padding: 8px 16px; border-radius: 8px; text-decoration: none; color: #64748b; font-weight: 600; font-size: 0.9rem; transition: 0.2s; border: 1px solid transparent; }
    .stat-tab:hover { background: #f8fafc; color: #0f172a; }
    .stat-tab.active { background: #f0f9ff; color: #0284c7; border-color: #bae6fd; }
    
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
    .stat-box { padding: 15px; border-radius: 10px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    .stat-box .title { font-size: 0.85rem; font-weight: 600; margin-bottom: 5px; opacity: 0.9; }
    .stat-box .value { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; justify-content: space-between; }
    .stat-box .pct { font-size: 0.75rem; background: rgba(0,0,0,0.2); padding: 2px 6px; border-radius: 4px; font-weight: 600; }
    
    .bg-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
    .bg-green { background: linear-gradient(135deg, #22c55e, #16a34a); }
    .bg-yellow { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .bg-red { background: linear-gradient(135deg, #ef4444, #dc2626); }
    .bg-grey { background: linear-gradient(135deg, #64748b, #475569); }

    /* Reports Tabs */
    .report-tabs { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
    .report-tab { padding: 8px 15px; border-radius: 30px; font-size: 0.85rem; font-weight: 600; text-decoration: none; color: #475569; background: #f8fafc; border: 1px solid #e2e8f0; transition: 0.2s; }
    .report-tab:hover { background: #e2e8f0; color: #0f172a; }
    .report-tab.active { background: #0f172a; color: #fff; border-color: #0f172a; }
    .table-custom-ui th { background: #f8fafc; font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding: 15px; }
    .table-custom-ui td { padding: 15px; font-size: 0.9rem; color: #334155; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
    .avatar-circle { width: 35px; height: 35px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 14px; }
    .badge-soft-success { background-color: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
    .badge-soft-secondary { background-color: rgba(148, 163, 184, 0.15); color: #64748b; border: 1px solid rgba(148, 163, 184, 0.2); }
    .badge-soft-primary { background-color: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.2); }
    .badge-soft-warning { background-color: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.2); }
    .badge-soft-danger { background-color: rgba(239, 68, 68, 0.15); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.2); }
    .dt-buttons .btn { background-color: #475569; border: none; color: #fff; border-radius: 20px; padding: 4px 14px; font-size: 0.85rem; margin-right: 4px; }
    .dataTables_filter input { background-color: #ffffff; border: 1px solid #ced4da; border-radius: 20px; padding: 4px 15px; }
</style>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0 text-dark">Dealer Dashboard</h4>
    <div class="d-flex align-items-center gap-3">
        <div class="bg-white px-4 py-2 rounded-pill shadow-sm border border-success border-opacity-25">
            <span class="text-secondary fw-bold small me-2">Wallet Balance:</span>
            <span class="text-success fw-bold fs-5">Rs. <?= number_format($balance, 2) ?></span>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="d-flex gap-3 mb-4 overflow-auto pb-2">
    <?php if($current_dealer['perm_create_user']): ?>
    <a href="users.php" class="quick-btn">
        <i class="fa-solid fa-user-plus"></i>
        <span>Add User</span>
    </a>
    <?php endif; ?>
    <a href="users.php" class="quick-btn">
        <i class="fa-solid fa-users"></i>
        <span>My Users</span>
    </a>
    <a href="support.php" class="quick-btn">
        <i class="fa-solid fa-headset"></i>
        <span>Support</span>
    </a>
</div>

<!-- Main Stats Card -->
<div class="card-ui p-4">
    <h6 class="fw-bold text-secondary mb-4"><i class="fa-solid fa-chart-pie me-2 text-primary"></i> Reports & Statistics</h6>
    
    <div class="stat-tabs">
        <a href="javascript:void(0)" class="stat-tab active"><i class="fa-solid fa-user me-1"></i> User Statistics</a>
    </div>

    <!-- User Tab Content -->
    <div id="stat-content-user" class="row">
        <!-- Donut Chart -->
        <div class="col-lg-3 col-md-4 mb-4 text-center">
            <h6 class="text-start fw-bold text-secondary mb-3 border-start border-4 border-primary ps-2">User Distribution</h6>
            <div style="position: relative; height: 180px; width: 100%; display: flex; justify-content: center;">
                <canvas id="usersDonut"></canvas>
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                    <h4 class="mb-0 fw-bold text-dark"><?= number_format($total_users) ?></h4>
                    <span class="small text-muted fw-bold">Total</span>
                </div>
            </div>
            <div class="mt-3 text-start small fw-bold d-flex flex-column gap-2 px-2">
                <span class="d-flex justify-content-between"><span><i class="fa-solid fa-circle text-info" style="font-size: 0.6rem;"></i> Online</span> <?= $chart_online ?></span>
                <span class="d-flex justify-content-between"><span><i class="fa-solid fa-circle text-success" style="font-size: 0.6rem;"></i> Active</span> <?= $chart_active ?></span>
                <span class="d-flex justify-content-between"><span><i class="fa-solid fa-circle text-danger" style="font-size: 0.6rem;"></i> Expired</span> <?= $chart_expired ?></span>
                <span class="d-flex justify-content-between"><span><i class="fa-solid fa-circle text-warning" style="font-size: 0.6rem;"></i> Disabled</span> <?= $chart_disabled ?></span>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="col-lg-9 col-md-8">
            <div class="stats-grid">
                <div class="stat-box bg-blue">
                    <div class="title"><i class="fa-solid fa-users"></i> Users</div>
                    <div class="value"><?= number_format($total_users) ?> <span class="pct">100.00%</span></div>
                </div>
                <div class="stat-box bg-green">
                    <div class="title"><i class="fa-solid fa-circle-check"></i> Active</div>
                    <div class="value"><?= number_format($active_users) ?> <span class="pct"><?= $pct($active_users) ?></span></div>
                </div>
                <div class="stat-box bg-green" style="opacity: 0.8;">
                    <div class="title"><i class="fa-solid fa-wifi"></i> Online</div>
                    <div class="value"><?= number_format($online_users) ?> <span class="pct"><?= $pct($online_users) ?></span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-user-large-slash"></i> Offline</div>
                    <div class="value"><?= number_format($offline_users) ?> <span class="pct"><?= $pct($offline_users) ?></span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-user-slash"></i> Disabled</div>
                    <div class="value"><?= number_format($disabled_users) ?> <span class="pct"><?= $pct($disabled_users) ?></span></div>
                </div>
                <div class="stat-box bg-red">
                    <div class="title"><i class="fa-solid fa-user-xmark"></i> Expired</div>
                    <div class="value"><?= number_format($expired) ?> <span class="pct"><?= $pct($expired) ?></span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-network-wired"></i> PPPoE</div>
                    <div class="value"><?= number_format($pppoe_users) ?> <span class="pct"><?= $pct($pppoe_users) ?></span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-wifi"></i> Hotspot</div>
                    <div class="value"><?= number_format($hotspot_users) ?> <span class="pct"><?= $pct($hotspot_users) ?></span></div>
                </div>
                <div class="stat-box bg-yellow">
                    <div class="title"><i class="fa-solid fa-hourglass-end"></i> Expiring (1 Day)</div>
                    <div class="value"><?= number_format($expiring_1d) ?> <span class="pct"><?= $pct($expiring_1d) ?></span></div>
                </div>
                <div class="stat-box bg-yellow">
                    <div class="title"><i class="fa-solid fa-calendar-week"></i> Expiring (1 Week)</div>
                    <div class="value"><?= number_format($expiring_1w) ?> <span class="pct"><?= $pct($expiring_1w) ?></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- User Reports Table -->
<div class="card-ui p-4 mt-4" id="reportsSection">
    <h6 class="fw-bold text-secondary mb-4"><i class="fa-solid fa-list-ul me-2 text-primary"></i> User Reports</h6>
    
    <div class="report-tabs">
        <a href="?report=all#reportsSection" class="report-tab <?= $report_filter=='all'?'active':'' ?>"><i class="fa-solid fa-users"></i> All Users</a>
        <a href="?report=expired#reportsSection" class="report-tab <?= $report_filter=='expired'?'active':'' ?>"><i class="fa-solid fa-user-xmark"></i> Expired Users</a>
        <a href="?report=expiring_1#reportsSection" class="report-tab <?= $report_filter=='expiring_1'?'active':'' ?>"><i class="fa-solid fa-hourglass-end"></i> Expiring (1 Days)</a>
        <a href="?report=expiring_3#reportsSection" class="report-tab <?= $report_filter=='expiring_3'?'active':'' ?>"><i class="fa-solid fa-hourglass-half"></i> Expiring (3 Days)</a>
        <a href="?report=expiring_1w#reportsSection" class="report-tab <?= $report_filter=='expiring_1w'?'active':'' ?>"><i class="fa-solid fa-calendar-week"></i> Expiring (1 week)</a>
        <a href="?report=expiring_2w#reportsSection" class="report-tab <?= $report_filter=='expiring_2w'?'active':'' ?>"><i class="fa-solid fa-calendar-days"></i> Expiring (2 weeks)</a>
        <a href="?report=disabled#reportsSection" class="report-tab <?= $report_filter=='disabled'?'active':'' ?>"><i class="fa-solid fa-user-slash"></i> Disabled Users</a>
    </div>

    <div class="table-responsive">
        <table id="reportsTable" class="table table-hover table-custom-ui table-borderless w-100">
            <thead>
                <tr>
                    <th>#ID</th>
                    <th>Photo</th>
                    <th>Username</th>
                    <th>Phone</th>
                    <th>Package</th>
                    <th>Seller</th>
                    <th>Balance</th>
                    <th>Service</th>
                    <th>On/Off</th>
                    <th>Expiry</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($subs as $s): ?>
                <tr>
                    <td><?= $s['id'] ?></td>
                    <td><div class="avatar-circle"><i class="fa-solid fa-user"></i></div></td>
                    <td data-search="<?= htmlspecialchars($s['username']) ?>" data-sort="<?= htmlspecialchars($s['username']) ?>">
                        <div class="d-inline-flex align-items-center gap-1">
                            <a href="subscriber_view.php?id=<?= $s['id'] ?>" class="text-decoration-none">
                                <span class="badge rounded-pill badge-soft-success px-3 py-2 fw-bold">
                                    <i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($s['username']) ?>
                                </span>
                            </a>
                            <button type="button" 
                                    class="copy-user-btn" 
                                    onclick="copyToClipboard(event, '<?= addslashes(htmlspecialchars($s['username'])) ?>', this)" 
                                    title="Copy Username">
                                <i class="fa-regular fa-copy" style="font-size: 0.82rem;"></i>
                            </button>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($s['mobile'] ?: ($s['phone'] ?: 'N/A')) ?></td>
                    <td><?= htmlspecialchars($s['package_name'] ?? 'N/A') ?></td>
                    <td><span class="badge rounded-pill bg-light text-dark border px-2 py-1 small"><?= htmlspecialchars($current_dealer['username']) ?></span></td>
                    <td><span class="badge rounded-pill badge-soft-warning px-3 py-2"><?= number_format($s['balance']) ?></span></td>
                    <td><span class="badge rounded-pill badge-soft-primary px-3 py-2 fw-bold"><?= strtoupper(htmlspecialchars($s['service_type'])) ?></span></td>
                    <td>
                        <?php if($s['is_online'] > 0): ?>
                            <div class="d-flex flex-column align-items-center gap-1">
                                <span class="badge rounded-pill badge-soft-success px-3 py-1">Online</span>
                                <small class="text-muted font-monospace" style="font-size: 0.75rem;"><?= htmlspecialchars($s['live_ip']) ?></small>
                            </div>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-2">Offline</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($s['expiry_date']): ?>
                            <?php if(strtotime($s['expiry_date']) < time()): ?>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <span class="badge rounded-pill bg-danger text-white fw-bold px-3 py-1 text-uppercase" style="letter-spacing: 0.5px;">Expired</span>
                                    <small class="text-muted font-monospace" style="font-size: 0.70rem;"><?= date('d M Y', strtotime($s['expiry_date'])) ?></small>
                                </div>
                            <?php else: ?>
                                <span class="badge rounded-pill badge-soft-success px-3 py-2"><?= date('d M Y H:i:s', strtotime($s['expiry_date'])) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-2">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-column gap-1">
                            <a href="#" onclick="openPaymentModal(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['username'])) ?>', <?= (float)$s['balance'] ?>); return false;" class="badge rounded-pill badge-soft-primary text-decoration-none px-3 py-2"><i class="fa-brands fa-paypal"></i> Payment</a>
                            <a href="#" onclick="openRenewModal(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['username'])) ?>', '<?= addslashes(htmlspecialchars($s['full_name'] ?? '')) ?>', <?= (int)$s['package_id'] ?>, '<?= addslashes(htmlspecialchars($s['package_name'] ?? '')) ?>', '<?= $s['expiry_date'] ?? '' ?>'); return false;" class="badge rounded-pill badge-soft-success text-decoration-none px-3 py-2"><i class="fa-solid fa-rotate"></i> Renew</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal for Payment -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
      <div class="modal-header bg-primary text-white p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-money-bill-wave me-2"></i> Add Payment to Customer</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" onsubmit="var btn=this.querySelector('button[type=submit]'); if(this.submitted){return false;} this.submitted=true; if(btn){btn.disabled=true; btn.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i> Processing Renewal... Please wait...';} return true;">
        <input type="hidden" name="action" value="add_payment">
        <input type="hidden" name="sub_id" id="pay_sub_id">
        <div class="modal-body p-4 bg-light">
            <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Customer:</span>
                    <span class="badge bg-primary font-monospace" id="pay_username_display"></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Current Customer Balance:</span>
                    <span class="text-success fw-bold" id="pay_cur_balance">Rs. 0</span>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                    <span class="text-secondary small fw-bold">Your Wallet Balance:</span>
                    <span class="text-primary fw-bold">Rs. <?= number_format($balance, 2) ?></span>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label text-dark fw-bold small text-uppercase">Payment Amount (Rs.) <span class="text-danger">*</span></label>
                <div class="input-group input-group-lg shadow-sm">
                    <span class="input-group-text bg-white fw-bold text-muted">Rs.</span>
                    <input type="number" step="0.01" min="1" max="<?= $balance ?>" name="amount" class="form-control fw-bold text-primary" placeholder="e.g. 1000" required>
                </div>
                <small class="text-muted">This amount will be added to the customer's balance and deducted from your dealer wallet.</small>
            </div>

            <div class="mb-3">
                <label class="form-label text-dark fw-bold small text-uppercase">Remarks / Note (Optional)</label>
                <input type="text" name="description" class="form-control" placeholder="e.g. Cash collected from subscriber">
            </div>
        </div>
        <div class="modal-footer bg-white border-top px-4 py-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" onclick="return confirm('Deduct this amount from your dealer wallet and credit customer balance?');">
              <i class="fa-solid fa-check me-1"></i> Add Payment
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal for Renew -->
<div class="modal fade" id="renewModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
      <div class="modal-header bg-success text-white p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-rotate me-2"></i> Renew Subscriber Package</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" onsubmit="var btn=this.querySelector('button[type=submit]'); if(this.submitted){return false;} this.submitted=true; if(btn){btn.disabled=true; btn.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i> Processing Renewal... Please wait...';} return true;">
        <input type="hidden" name="action" value="renew_user">
        <input type="hidden" name="id" id="renew_user_id">
        <div class="modal-body p-4 bg-light">
            <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Customer:</span>
                    <span class="badge bg-success font-monospace" id="renew_username_display"></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Full Name:</span>
                    <span class="fw-bold text-dark" id="renew_fullname_display"></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Current Package:</span>
                    <span class="fw-bold text-dark" id="renew_cur_pkg"></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Current Expiry:</span>
                    <span class="fw-bold text-dark" id="renew_cur_expiry"></span>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                    <span class="text-secondary small fw-bold">Your Wallet Balance:</span>
                    <span class="text-primary fw-bold">Rs. <?= number_format($balance, 2) ?></span>
                </div>
            </div>

            <div class="mb-3 text-start">
                <label class="form-label text-dark fw-bold small text-uppercase">Select Package <span class="text-danger">*</span></label>
                <select name="package_id" id="renew_pkg_id" class="form-select form-select-lg fw-bold text-primary border-success shadow-sm" required onchange="updateRenewLivePrice()">
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p['id'] ?>" data-price="<?= $p['dealer_price'] ?>">
                            <?= htmlspecialchars($p['name']) ?> &nbsp;|&nbsp; Dealer Rs. <?= number_format($p['dealer_price']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($can_custom_expiry): ?>
            <div class="mb-3 text-start">
                <label class="form-label text-secondary small fw-bold">Custom Expiry (Optional)</label>
                <input type="datetime-local" name="expiry_date" id="renew_expiry_input" class="form-control" onchange="updateRenewLivePrice()">
                <small class="text-muted">Leave blank for standard 30-day package renewal.</small>
            </div>
            <?php endif; ?>

            <div class="p-3 bg-white border rounded shadow-sm d-flex justify-content-between align-items-center">
                <div class="text-secondary fw-bold small text-uppercase">Wallet Deduction</div>
                <h4 class="mb-0 fw-bold text-success" id="renew_deduction_display">Rs. 0</h4>
            </div>
        </div>
        <div class="modal-footer bg-white border-top px-4 py-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm" onclick="return confirm('Confirm renewal? Dealer package price will be deducted from your wallet.');">
              <i class="fa-solid fa-check me-1"></i> Confirm Renewal
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mt-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h6 class="fw-bold text-secondary mb-0"><i class="fa-solid fa-clock-rotate-left me-2"></i> Recent Activity</h6>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover table-borderless align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($dealer_activities as $act): ?>
                    <tr class="border-bottom">
                        <td class="text-secondary small"><?= date('d M Y, h:i A', strtotime($act['created_at'])) ?></td>
                        <td class="fw-bold text-primary"><?= htmlspecialchars($act['against_to'] ?: 'N/A') ?></td>
                        <td><?= htmlspecialchars($act['activity']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($dealer_activities)): ?>
                    <tr><td colspan="3" class="text-center text-muted">No recent activity.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('usersDonut').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Online', 'Active (Offline)', 'Expired', 'Disabled', 'Others'],
            datasets: [{
                data: [<?= $chart_online ?>, <?= $chart_active ?>, <?= $chart_expired ?>, <?= $chart_disabled ?>, <?= $chart_others ?>],
                backgroundColor: ['#0ea5e9', '#22c55e', '#ef4444', '#f59e0b', '#94a3b8'],
                borderWidth: 0,
                cutout: '75%'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: true }
            }
        }
    });
});
</script>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
const pkgPriceMap = {
    <?php foreach($dealer_packages as $p) echo $p['id'] . ': ' . (float)$p['dealer_price'] . ','; ?>
};

function openPaymentModal(subId, username, curBalance) {
    document.getElementById('pay_sub_id').value = subId;
    document.getElementById('pay_username_display').innerText = username;
    document.getElementById('pay_cur_balance').innerText = 'Rs. ' + Number(curBalance || 0).toLocaleString();
    var modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

function openRenewModal(id, username, fullname, pkgId, pkgName, expiry) {
    document.getElementById('renew_user_id').value = id;
    document.getElementById('renew_username_display').innerText = username;
    document.getElementById('renew_fullname_display').innerText = fullname || username;
    document.getElementById('renew_cur_pkg').innerText = pkgName || 'Current Package';
    
    if (expiry && expiry !== '0000-00-00 00:00:00') {
        let d = new Date(expiry);
        document.getElementById('renew_cur_expiry').innerText = isNaN(d.getTime()) ? expiry : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    } else {
        document.getElementById('renew_cur_expiry').innerText = 'Expired';
    }

    const sel = document.getElementById('renew_pkg_id');
    if (sel && pkgId) {
        sel.value = pkgId;
    }
    updateRenewLivePrice();
    var modal = new bootstrap.Modal(document.getElementById('renewModal'));
    modal.show();
}

function updateRenewLivePrice() {
    const sel = document.getElementById('renew_pkg_id');
    if (!sel) return;
    const pkgId = sel.value;
    const price = parseFloat(pkgPriceMap[pkgId] || 0);

    const customExpEl = document.getElementById('renew_expiry_input');
    const customExpVal = customExpEl ? customExpEl.value : '';
    const nowSec = Math.floor(Date.now() / 1000);

    if (customExpVal) {
        const targetTs = Math.floor(new Date(customExpVal).getTime() / 1000);
        if (targetTs > nowSec) {
            const days = Math.ceil((targetTs - nowSec) / 86400);
            const deduction = days * (price / 30);
            document.getElementById('renew_deduction_display').innerText = 'Rs. ' + deduction.toFixed(2);
            return;
        }
    }
    document.getElementById('renew_deduction_display').innerText = 'Rs. ' + price.toLocaleString();
}

$(document).ready(function() {
    $('#reportsTable').DataTable({
        dom: '<"row align-items-center"<"col-md-2"l><"col-md-6 dt-buttons"B><"col-md-4"f>>rtip',
        buttons: [
            { extend: 'print', text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'pdf', text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'excel', text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'csv', text: '<i class="fa-solid fa-file-csv"></i> CSV' }
        ],
        order: [[0, 'desc']],
        pageLength: 10,
        scrollX: true,
        language: { search: "Search:", searchPlaceholder: "Type & Submit" }
    });
});
</script>

<?php require_once 'footer.php'; ?>
