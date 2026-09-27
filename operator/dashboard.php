<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// --- Handle CSV Restore ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_backup') {
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['backup_file']['tmp_name'];
        if (($file = fopen($tmpName, 'r')) !== FALSE) {
            fgetcsv($file); // skip header row
            $pdo->beginTransaction();
            try {
                while (($data = fgetcsv($file)) !== FALSE) {
                    // ID = 0, Username = 1, Full Name = 2, Package ID = 3, Status = 4, Expiry Date = 5
                    if (count($data) >= 6) {
                        $id = (int)$data[0];
                        $username = trim($data[1]);
                        $expiry = trim($data[5]);
                        
                        if (!empty($expiry) && $expiry !== 'N/A') {
                            $parsed_time = strtotime($expiry);
                            if ($parsed_time !== false) {
                                $db_expiry = date('Y-m-d H:i:s', $parsed_time); // Strictly for MySQL
                                $formatted_expiry = date('d M Y H:i:s', $parsed_time); // Strictly for FreeRADIUS
                                
                                $pdo->prepare("UPDATE subscribers SET expiry_date = ?, status = 'active' WHERE id = ? AND client_id = ?")->execute([$db_expiry, $id, $client_id]);
                                
                                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$username]);
                                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_expiry]);
                            }
                        }
                    }
                }
                $pdo->commit();
                echo "<script>alert('Expiry Dates Restored Successfully!'); window.location='dashboard.php';</script>";
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error parsing CSV file.');</script>";
            }
            fclose($file);
        }
    }
}

// --- ADD USER LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $package_id = (int)$_POST['package_id'];
    $service_type = $_POST['service_type'];
    $national_id = trim($_POST['national_id'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $subarea = trim($_POST['subarea'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');

    $clientStmt = $pdo->prepare("SELECT max_subscribers FROM clients WHERE id = ?");
    $clientStmt->execute([$client_id]);
    $max_sub = $clientStmt->fetchColumn();

    $curStmt = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ?");
    $curStmt->execute([$client_id]);
    
    if ($curStmt->fetchColumn() >= $max_sub) {
        echo "<script>alert('Subscriber Quota Exceeded!');</script>";
    } else {
        $p = $pdo->prepare("SELECT rate_limit FROM packages WHERE id = ? AND client_id = ?");
        $p->execute([$package_id, $client_id]);
        $pkg = $p->fetch();

        if ($pkg) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, service_type, full_name, national_id, mobile, phone, email, address, subarea, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$client_id, $package_id, $username, $password, $service_type, $full_name, $national_id, $mobile, $phone, $email, $address, $subarea, $latitude, $longitude]);
                
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")
                    ->execute([$username, $password]);
                
                if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")
                        ->execute([$username, $pkg['rate_limit']]);
                }
                
                $pdo->commit();
                echo "<script>alert('User created successfully!'); window.location='dashboard.php';</script>";
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo "<script>alert('Error: Username might already exist.');</script>";
            }
        }
    }
}

// --- ADD BALANCE LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_balance_multi') {
    $sub_id = (int)$_POST['sub_id'];
    $amount = (float)$_POST['amount'];
    if ($amount > 0) {
        $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
        $uStmt->execute([$sub_id, $client_id]);
        $sub_user = $uStmt->fetchColumn();

        if ($sub_user) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);
                
                // Get new balance
                $newBal = $pdo->prepare("SELECT balance FROM subscribers WHERE id=?");
                $newBal->execute([$sub_id]);
                $finalBal = $newBal->fetchColumn();

                $pdo->prepare("INSERT INTO user_ledger (username, type, amount, description, balance_after) VALUES (?, 'credit', ?, 'Manual Balance Added', ?)")
                    ->execute([$sub_user, $amount, $finalBal]);
                
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', ?)")
                    ->execute([$client_id, $sub_user, "Added Balance: Rs. $amount"]);
                
                $pdo->commit();
                echo "<script>alert('Balance added successfully!'); window.location='dashboard.php';</script>";
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error adding balance.');</script>";
            }
        }
    }
}

// Fetch packages for the dropdown
$packages = $pdo->query("SELECT id, name, rate_limit FROM packages WHERE client_id = $client_id")->fetchAll();

// Fetch total balance of all users
$total_balance_all = $pdo->query("SELECT SUM(balance) FROM subscribers WHERE client_id = $client_id")->fetchColumn() ?: 0;

// --- Data Fetching for Metrics ---
$total_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id")->fetchColumn();
$active_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND status = 'active'")->fetchColumn();
$disabled_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND status = 'disabled'")->fetchColumn();

// Online Users
$online_users = $pdo->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL AND username IN (SELECT username FROM subscribers WHERE client_id = $client_id)")->fetchColumn();
$offline_users = max(0, $total_users - $online_users);

// Expirations
$expired = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date < NOW()")->fetchColumn();
$expiring_1d = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)")->fetchColumn();
$expiring_3d = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetchColumn();
$expiring_1w = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$expiring_2w = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)")->fetchColumn();

// Services
$pppoe_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND service_type = 'pppoe'")->fetchColumn();
$hotspot_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND service_type = 'hotspot'")->fetchColumn();

// Helpers
$pct = function($val) use ($total_users) {
    if ($total_users == 0) return "0.00%";
    return number_format(($val / $total_users) * 100, 2) . "%";
};

// --- Data Fetching for Reports Table ---
$report_filter = $_GET['report'] ?? 'all';
$where = "s.client_id = $client_id";
if ($report_filter === 'expired') $where .= " AND s.expiry_date < NOW()";
if ($report_filter === 'expiring_1') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)";
if ($report_filter === 'expiring_3') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)";
if ($report_filter === 'expiring_1w') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
if ($report_filter === 'expiring_2w') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)";
if ($report_filter === 'disabled') $where .= " AND s.status = 'disabled'";

$subs = $pdo->query("SELECT s.*, p.name as package_name, 
    (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
    (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s 
    LEFT JOIN packages p ON s.package_id = p.id 
    WHERE $where ORDER BY s.id DESC")->fetchAll();

?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    .card-ui { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; margin-bottom: 20px; }
    
    /* Top Quick Actions */
    .quick-btn {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px 25px;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        text-decoration: none; color: #475569; min-width: 110px; transition: 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.02);
    }
    .quick-btn i { font-size: 1.5rem; color: #3b82f6; margin-bottom: 8px; }
    .quick-btn span { font-size: 0.8rem; font-weight: 500; }
    .quick-btn:hover { border-color: #cbd5e1; transform: translateY(-2px); color: #1e293b; }

    /* Search/Token Bars */
    .search-card { padding: 15px 20px; display: flex; align-items: center; gap: 15px; }
    .search-card .form-control, .search-card .form-select { background: #fff; border-color: #e2e8f0; color: #333; }
    .btn-dark-custom { background: #1e293b; color: #fff; border: none; padding: 8px 20px; border-radius: 6px; }

    /* Stats Grid */
    .stat-tabs { display: flex; gap: 10px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9; }
    .stat-tab { padding: 8px 20px; border-radius: 20px; font-weight: 600; font-size: 0.9rem; text-decoration: none; color: #64748b; }
    .stat-tab.active { background: #1e293b; color: #fff; }

    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
    @media(max-width: 991px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
    @media(max-width: 575px) { .stats-grid { grid-template-columns: 1fr; } }
    
    .stat-box { padding: 15px; border-radius: 8px; display: flex; flex-direction: column; }
    .stat-box .title { font-size: 0.8rem; font-weight: 600; margin-bottom: 5px; display: flex; align-items: center; gap: 8px; }
    .stat-box .value { font-size: 1.3rem; font-weight: 700; display: flex; align-items: baseline; gap: 10px; }
    .stat-box .pct { font-size: 0.75rem; font-weight: 500; opacity: 0.7; }

    /* Colors matching screenshot */
    .bg-blue { background: #e0f2fe; color: #0369a1; }
    .bg-green { background: #dcfce7; color: #15803d; }
    .bg-yellow { background: #fef9c3; color: #a16207; }
    .bg-grey { background: #f1f5f9; color: #475569; }
    .bg-red { background: #fee2e2; color: #b91c1c; }

    /* Donut Chart */
    .donut-container { position: relative; width: 100%; max-width: 280px; margin: 0 auto; }
    .donut-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
    .donut-center h3 { margin: 0; font-weight: 700; font-size: 2rem; color: #1e293b; }
    .donut-center p { margin: 0; font-size: 0.8rem; color: #64748b; }

    /* Reports Tabs */
    .report-tabs { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
    .report-tab { text-decoration: none; color: #64748b; font-weight: 500; font-size: 0.9rem; padding: 6px 16px; border-radius: 20px; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
    .report-tab:hover { background: #f8fafc; color: #1e293b; }
    .report-tab.active { background: #1e293b; color: #fff; }

    /* Datatable customizations (from subscribers.php) */
    .table-custom-ui { border-radius: 8px; overflow: hidden; }
    .table-custom-ui thead th { background-color: #f8f9fa; color: #64748b; border-bottom: 2px solid #e5e7eb; font-weight: 600; font-size: 0.9rem; white-space: nowrap; }
    .table-custom-ui tbody td { vertical-align: middle; border-bottom: 1px solid #e5e7eb; color: #334155; font-size: 0.9rem; }
    .dt-buttons .btn { background-color: #475569; border: none; color: #fff; border-radius: 20px; padding: 4px 14px; font-size: 0.85rem; margin-right: 4px; }
    .dataTables_filter input { background-color: #ffffff; border: 1px solid #ced4da; border-radius: 20px; padding: 4px 15px; }
    .avatar-circle { width: 40px; height: 40px; background-color: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 1.2rem; }
    
    .badge-soft-success { background-color: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
    .badge-soft-secondary { background-color: rgba(148, 163, 184, 0.15); color: #64748b; border: 1px solid rgba(148, 163, 184, 0.2); }
    .badge-soft-primary { background-color: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.2); }
    .badge-soft-warning { background-color: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.2); }
</style>

<!-- Dashboard Header with Clock -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <h4 class="m-0 text-secondary fw-bold mb-3 mb-md-0"><i class="fa-solid fa-gauge-high text-primary me-2"></i> Operator Dashboard</h4>
    <div class="card-ui px-4 py-2 mb-0 d-flex align-items-center gap-3" style="border-left: 4px solid #3b82f6;">
        <div class="text-end">
            <div class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Router Network Time</div>
            <div class="fw-bold text-dark" style="font-size: 1.1rem; font-family: monospace;" id="live_router_time">
                <i class="fa-solid fa-circle-notch fa-spin text-muted fs-6"></i>
            </div>
            <div class="text-muted" style="font-size: 0.7rem;" id="live_router_date">Fetching...</div>
        </div>
        <i class="fa-regular fa-clock text-primary border-start ps-3 border-2" style="font-size: 2rem;"></i>
    </div>
</div>

<!-- Quick Actions -->
<div class="d-flex flex-wrap gap-3 mb-4">
    <a href="profile.php" class="quick-btn"><i class="fa-solid fa-user"></i><span>My Profile</span></a>
    <a href="#" class="quick-btn" data-bs-toggle="modal" data-bs-target="#addBalanceModal"><i class="fa-solid fa-money-bills"></i><span>Add Payment</span></a>
    <a href="#" class="quick-btn" data-bs-toggle="modal" data-bs-target="#addBalanceModal"><i class="fa-solid fa-coins"></i><span>User Balance</span></a>
    <a href="#" class="quick-btn" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa-solid fa-user-plus"></i><span>Add New User</span></a>
    <a href="mikrotik_sync.php" class="quick-btn"><i class="fa-solid fa-file-import"></i><span>Import Users</span></a>
    <a href="backup_users.php" class="quick-btn"><i class="fa-solid fa-download text-success"></i><span>Backup (CSV)</span></a>
    <a href="#" class="quick-btn" data-bs-toggle="modal" data-bs-target="#restoreModal"><i class="fa-solid fa-upload text-warning"></i><span>Restore Expiry</span></a>
</div>

<!-- Forms Row -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card-ui search-card">
            <i class="fa-solid fa-user text-primary"></i> <strong class="me-auto text-secondary">View Profile</strong>
            <select class="form-select w-auto"><option>Select Profile Type</option></select>
            <select class="form-select w-auto"><option>Select an Option</option></select>
            <button class="btn-dark-custom">View Profile</button>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-ui search-card">
            <i class="fa-solid fa-ticket text-primary"></i> <strong class="me-auto text-secondary">Token</strong>
            <select class="form-select w-auto"><option>Token Type</option></select>
            <input type="text" class="form-control w-auto" placeholder="Token Secret">
            <button class="btn-dark-custom">Status</button>
        </div>
    </div>
</div>

<!-- Reports & Statistics Card -->
<div class="card-ui p-4">
    <h6 class="fw-bold text-secondary mb-4"><i class="fa-solid fa-chart-pie me-2 text-primary"></i> Reports & Statistics</h6>
    
    <div class="stat-tabs">
        <a href="#" class="stat-tab active"><i class="fa-solid fa-user me-1"></i> User</a>
        <a href="packages.php" class="stat-tab"><i class="fa-solid fa-dollar-sign me-1"></i> Accounting</a>
        <a href="activity_logs.php" class="stat-tab"><i class="fa-solid fa-chart-line me-1"></i> Usage</a>
        <a href="#" class="stat-tab"><i class="fa-solid fa-map-location-dot me-1"></i> Map</a>
    </div>

    <div class="row">
        <!-- Donut Chart -->
        <div class="col-lg-3 col-md-4 mb-4 text-center">
            <h6 class="text-start fw-bold text-secondary mb-3 border-start border-3 border-primary ps-2">All Users</h6>
            <div class="donut-container">
                <canvas id="usersDonut"></canvas>
                <div class="donut-center">
                    <h3><?= $total_users ?></h3>
                    <p>All Users</p>
                </div>
            </div>
            <div class="mt-3 d-flex justify-content-center gap-3 text-secondary" style="font-size: 0.8rem;">
                <span><i class="fa-solid fa-circle text-success" style="font-size: 0.6rem;"></i> Active <?= $active_users ?></span>
                <span><i class="fa-solid fa-circle text-danger" style="font-size: 0.6rem;"></i> Expired <?= $expired ?></span>
                <span><i class="fa-solid fa-circle text-secondary" style="font-size: 0.6rem;"></i> Others <?= $disabled_users ?></span>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="col-lg-9 col-md-8">
            <div class="stats-grid">
                <div class="stat-box bg-blue">
                    <div class="title"><i class="fa-solid fa-users"></i> Users</div>
                    <div class="value"><?= number_format($total_users, 2) ?> <span class="pct">100.00%</span></div>
                </div>
                <div class="stat-box bg-green">
                    <div class="title"><i class="fa-solid fa-circle-check"></i> Active</div>
                    <div class="value"><?= number_format($active_users, 2) ?> <span class="pct"><?= $pct($active_users) ?></span></div>
                </div>
                <div class="stat-box bg-green" style="opacity: 0.8;">
                    <div class="title"><i class="fa-solid fa-wifi"></i> Online</div>
                    <div class="value"><?= number_format($online_users, 2) ?> <span class="pct"><?= $pct($online_users) ?></span></div>
                </div>
                
                <div class="stat-box bg-yellow">
                    <div class="title"><i class="fa-solid fa-user-clock"></i> Expired Online</div>
                    <div class="value">0.00 <span class="pct">0.00%</span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-user-large-slash"></i> Offline</div>
                    <div class="value"><?= number_format($offline_users, 2) ?> <span class="pct"><?= $pct($offline_users) ?></span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-user-plus"></i> Registered</div>
                    <div class="value"><?= number_format($total_users, 2) ?> <span class="pct">100.00%</span></div>
                </div>

                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-user-slash"></i> Disable</div>
                    <div class="value"><?= number_format($disabled_users, 2) ?> <span class="pct"><?= $pct($disabled_users) ?></span></div>
                </div>
                <div class="stat-box bg-red">
                    <div class="title"><i class="fa-solid fa-user-xmark"></i> Expired</div>
                    <div class="value"><?= number_format($expired, 2) ?> <span class="pct"><?= $pct($expired) ?></span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-network-wired"></i> PPPoE</div>
                    <div class="value"><?= number_format($pppoe_users, 2) ?> <span class="pct"><?= $pct($pppoe_users) ?></span></div>
                </div>

                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-wifi"></i> Hotspot</div>
                    <div class="value"><?= number_format($hotspot_users, 2) ?> <span class="pct"><?= $pct($hotspot_users) ?></span></div>
                </div>
                <div class="stat-box bg-yellow">
                    <div class="title"><i class="fa-solid fa-hourglass-end"></i> Expiring (1 Day)</div>
                    <div class="value"><?= number_format($expiring_1d, 2) ?> <span class="pct"><?= $pct($expiring_1d) ?></span></div>
                </div>
                <div class="stat-box bg-yellow">
                    <div class="title"><i class="fa-solid fa-hourglass-half"></i> Expiring (3 Days)</div>
                    <div class="value"><?= number_format($expiring_3d, 2) ?> <span class="pct"><?= $pct($expiring_3d) ?></span></div>
                </div>

                <div class="stat-box bg-yellow">
                    <div class="title"><i class="fa-solid fa-calendar-week"></i> Expiring (1 Week)</div>
                    <div class="value"><?= number_format($expiring_1w, 2) ?> <span class="pct"><?= $pct($expiring_1w) ?></span></div>
                </div>
                <div class="stat-box bg-yellow">
                    <div class="title"><i class="fa-solid fa-calendar-days"></i> Expiring (2 Weeks)</div>
                    <div class="value"><?= number_format($expiring_2w, 2) ?> <span class="pct"><?= $pct($expiring_2w) ?></span></div>
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
        <a href="#" class="report-tab"><i class="fa-solid fa-triangle-exclamation"></i> Problematic Users</a>
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
                    <td>
                        <a href="subscriber_view.php?id=<?= $s['id'] ?>">
                            <span class="badge rounded-pill badge-soft-success px-3 py-2"><?= htmlspecialchars($s['username']) ?></span>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($s['mobile'] ?: ($s['phone'] ?: 'N/A')) ?></td>
                    <td><?= htmlspecialchars($s['package_name'] ?? 'N/A') ?></td>
                    <td>shabir1</td> <!-- Placeholder seller -->
                    <td><span class="badge rounded-pill badge-soft-warning px-3 py-2"><?= number_format($s['balance'], 2) ?></span></td>
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
                            <span class="badge rounded-pill badge-soft-success px-3 py-2"><?= date('d M Y H:i:s', strtotime($s['expiry_date'])) ?></span>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-2">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-column gap-1">
                            <a href="subscriber_view.php?id=<?= $s['id'] ?>" class="badge rounded-pill badge-soft-primary text-decoration-none px-3 py-2"><i class="fa-brands fa-paypal"></i> Payment</a>
                            <a href="subscriber_view.php?id=<?= $s['id'] ?>" class="badge rounded-pill badge-soft-success text-decoration-none px-3 py-2"><i class="fa-solid fa-rotate"></i> Renew</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Restore Backup Modal -->
<div class="modal fade" id="restoreModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-upload text-warning"></i> Restore User Expiries (CSV)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="restore_backup">
        <div class="modal-body">
            <div class="alert alert-info">
                <strong>How to use:</strong>
                <ol class="mb-0 ps-3">
                    <li>Click <b>Backup (CSV)</b> to download your users.</li>
                    <li>Open the CSV in Excel and change the <b>Expiry Date</b> column (Format: YYYY-MM-DD HH:MM:SS).</li>
                    <li>Save the CSV and upload it here to apply the changes.</li>
                </ol>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Select Modified CSV File</label>
                <input type="file" name="backup_file" class="form-control" accept=".csv" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark">Restore Dates</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Balance Modal -->
<div class="modal fade" id="addBalanceModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content light-modal">
      <div class="modal-header bg-white border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-coins text-warning me-2"></i> Manage User Balances</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4 pt-0">
          <div class="alert alert-info d-flex align-items-center mb-4 border-0 shadow-sm">
              <i class="fa-solid fa-wallet fs-3 me-3 text-info"></i>
              <div>
                  <h6 class="mb-0 fw-bold">Total Advance / Balance</h6>
                  <span class="fs-4 fw-bold">Rs. <?= number_format($total_balance_all, 2) ?></span>
              </div>
          </div>
          
          <div class="table-responsive">
              <table class="table table-hover table-bordered w-100" id="balanceTable" style="font-size: 0.9rem;">
                  <thead class="table-light">
                      <tr>
                          <th>Username</th>
                          <th>Full Name</th>
                          <th>Current Balance</th>
                          <th style="width: 250px;">Add Balance</th>
                      </tr>
                  </thead>
                  <tbody>
                      <?php foreach($subs as $s): ?>
                      <tr>
                          <td class="align-middle fw-bold text-primary"><?= htmlspecialchars($s['username']) ?></td>
                          <td class="align-middle"><?= htmlspecialchars($s['full_name']) ?></td>
                          <td class="align-middle fw-bold <?= $s['balance'] < 0 ? 'text-danger' : 'text-success' ?>">
                              Rs. <?= number_format($s['balance'], 2) ?>
                          </td>
                          <td class="align-middle">
                              <form method="POST" class="d-flex gap-2 m-0" onsubmit="return confirm('Add balance to this user?');">
                                  <input type="hidden" name="action" value="add_balance_multi">
                                  <input type="hidden" name="sub_id" value="<?= $s['id'] ?>">
                                  <input type="number" step="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" required>
                                  <button type="submit" class="btn btn-sm btn-dark text-nowrap"><i class="fa-solid fa-plus"></i> Add</button>
                              </form>
                          </td>
                      </tr>
                      <?php endforeach; ?>
                  </tbody>
              </table>
          </div>
      </div>
    </div>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content light-modal" style="background-color: #f8fafc;">
      <div class="modal-header bg-white border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus text-primary me-2"></i> Add New User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body p-4 pt-2">
            
            <div class="accordion shadow-sm rounded overflow-hidden" id="addUserAccordion">
                <!-- Account Info -->
                <div class="accordion-item border-0 border-bottom">
                    <h2 class="accordion-header">
                        <button class="accordion-button bg-white text-dark fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAccount">
                            <i class="fa-solid fa-user me-2 text-muted"></i> Account Information
                        </button>
                    </h2>
                    <div id="collapseAccount" class="accordion-collapse collapse show" data-bs-parent="#addUserAccordion">
                        <div class="accordion-body bg-white">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Full Name</label>
                                    <input type="text" name="full_name" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">National ID (CNIC)</label>
                                    <input type="text" name="national_id" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Username <span class="text-danger">*</span></label>
                                    <input type="text" name="username" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Password <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" name="password" id="genPassword" class="form-control" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('genPassword').value = Math.random().toString(36).slice(-8);"><i class="fa-solid fa-shuffle"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Service Info -->
                <div class="accordion-item border-0 border-bottom">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed bg-white text-dark fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseService">
                            <i class="fa-solid fa-wifi me-2 text-muted"></i> Service & Package
                        </button>
                    </h2>
                    <div id="collapseService" class="accordion-collapse collapse" data-bs-parent="#addUserAccordion">
                        <div class="accordion-body bg-white">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Service Type</label>
                                    <select name="service_type" class="form-select form-select-sm">
                                        <option value="pppoe">PPPoE</option>
                                        <option value="hotspot">Hotspot</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Select Package <span class="text-danger">*</span></label>
                                    <select name="package_id" class="form-select form-select-sm" required>
                                        <option value="">Choose...</option>
                                        <?php foreach($packages as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['rate_limit']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact Info -->
                <div class="accordion-item border-0">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed bg-white text-dark fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseContact">
                            <i class="fa-solid fa-address-book me-2 text-muted"></i> Contact & Location
                        </button>
                    </h2>
                    <div id="collapseContact" class="accordion-collapse collapse" data-bs-parent="#addUserAccordion">
                        <div class="accordion-body bg-white">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Mobile</label>
                                    <input type="text" name="mobile" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Phone</label>
                                    <input type="text" name="phone" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Email</label>
                                    <input type="email" name="email" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Area / City</label>
                                    <input type="text" name="subarea" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Full Address</label>
                                    <input type="text" name="address" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Latitude</label>
                                    <input type="text" name="latitude" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold">Longitude</label>
                                    <input type="text" name="longitude" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            
        </div>
        <div class="modal-footer bg-white border-top-0 pt-0">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark px-4"><i class="fa-solid fa-check me-2"></i> Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>

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
$(document).ready(function() {
    // Donut Chart initialization
    const ctx = document.getElementById('usersDonut').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Active', 'Expired', 'Others'],
            datasets: [{
                data: [<?= $active_users ?>, <?= $expired ?>, <?= $disabled_users ?>],
                backgroundColor: ['#22c55e', '#ef4444', '#94a3b8'],
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

    // DataTables Initialization
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

    $('#balanceTable').DataTable({
        pageLength: 10,
        language: { search: "Search User:", searchPlaceholder: "Username / Name" }
    });

    // Router Clock Sync Logic
    let routerTimeOffset = 0; // ms difference between browser and router
    let clockInterval = null;

    function fetchRouterTime() {
        $.getJSON('api_router_time.php', function(data) {
            if (data.success && data.date && data.time) {
                // MikroTik date format is usually 'mmm/dd/yyyy' or 'yyyy-mm-dd'
                // Let's create a valid JS date string. 
                // Replacing mikrotik's 'jan/01/2020' to 'Jan 01 2020'
                let dateStr = data.date.replace(/\//g, ' '); 
                let routerDate = new Date(dateStr + ' ' + data.time);
                
                if (!isNaN(routerDate.getTime())) {
                    routerTimeOffset = routerDate.getTime() - Date.now();
                    
                    if (!clockInterval) {
                        clockInterval = setInterval(updateClockUI, 1000);
                    }
                    updateClockUI();
                    $('#live_router_date').html(data.date + ' &bull; ' + data.time_zone);
                }
            } else {
                $('#live_router_time').html('<span class="text-danger">Offline</span>');
                $('#live_router_date').html('Cannot reach router');
            }
        }).fail(function() {
            $('#live_router_time').html('<span class="text-danger">Error</span>');
            $('#live_router_date').html('API failed');
        });
    }

    function updateClockUI() {
        let now = new Date(Date.now() + routerTimeOffset);
        let h = String(now.getHours()).padStart(2, '0');
        let m = String(now.getMinutes()).padStart(2, '0');
        let s = String(now.getSeconds()).padStart(2, '0');
        $('#live_router_time').text(`${h}:${m}:${s}`);
    }

    // Initial fetch and resync every 60 seconds
    fetchRouterTime();
    setInterval(fetchRouterTime, 60000);
});
</script>

<?php require_once 'footer.php'; ?>
