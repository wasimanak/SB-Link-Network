<?php
require_once 'header.php';

// Handle Profile Updates & Actions
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. CHANGE PASSWORD
    if ($action === 'change_password') {
        $new_pass = trim($_POST['new_password'] ?? '');
        $confirm_pass = trim($_POST['confirm_password'] ?? '');

        if (empty($new_pass)) {
            $error_msg = "New password cannot be empty.";
        } elseif (strlen($new_pass) < 5) {
            $error_msg = "Password must be at least 5 characters long.";
        } elseif ($new_pass !== $confirm_pass) {
            $error_msg = "New password and Confirm password do not match.";
        } else {
            try {
                $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE dealers SET password = ? WHERE id = ?")->execute([$new_hash, $dealer_id]);

                // Activity log
                $actStr = "Dealer {$current_dealer['username']} changed their portal password.";
                $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'Dealer', ?)")
                    ->execute([$client_id, $dealer_id, $current_dealer['username'], $current_dealer['username'], $actStr]);

                $success_msg = "Password updated successfully!";
            } catch (Exception $e) {
                $error_msg = "Failed to update password: " . $e->getMessage();
            }
        }
    }

    // 2. UPDATE PROFILE CONTACT INFO
    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $address = trim($_POST['address'] ?? '');

        try {
            $pdo->prepare("UPDATE dealers SET full_name = ?, phone = ?, email = ?, city = ?, address = ? WHERE id = ?")
                ->execute([$full_name, $phone, $email, $city, $address, $dealer_id]);

            // Refresh current dealer info
            $stmt = $pdo->prepare("SELECT * FROM dealers WHERE id = ?");
            $stmt->execute([$dealer_id]);
            $current_dealer = $stmt->fetch(PDO::FETCH_ASSOC);

            // Activity log
            $actStr = "Dealer {$current_dealer['username']} updated profile contact information.";
            $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'Dealer', ?)")
                ->execute([$client_id, $dealer_id, $current_dealer['username'], $current_dealer['username'], $actStr]);

            $success_msg = "Profile details updated successfully!";
        } catch (Exception $e) {
            $error_msg = "Failed to update profile: " . $e->getMessage();
        }
    }
}

// Fetch Admin/Operator Details
$admStmt = $pdo->prepare("SELECT company_name, phone, email FROM clients WHERE id = ?");
$admStmt->execute([$client_id]);
$admin_client = $admStmt->fetch(PDO::FETCH_ASSOC);

// Fetch Dealer Subscribers Summary
$subSummaryStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN status = 'active' AND (expiry_date IS NULL OR expiry_date >= NOW()) THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN status = 'expired' OR (expiry_date IS NOT NULL AND expiry_date < NOW()) THEN 1 ELSE 0 END) as expired_users,
        SUM(balance) as total_sub_balance
    FROM subscribers
    WHERE dealer_id = ?
");
$subSummaryStmt->execute([$dealer_id]);
$subSummary = $subSummaryStmt->fetch(PDO::FETCH_ASSOC);

// Online users count
$onStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT r.username) 
    FROM radacct r 
    JOIN subscribers s ON r.username = s.username 
    WHERE s.dealer_id = ? AND r.acctstoptime IS NULL
");
$onStmt->execute([$dealer_id]);
$online_users = (int)$onStmt->fetchColumn();

// Fetch Dealer Assigned Packages
$pkgStmt = $pdo->prepare("
    SELECT dp.package_id, dp.dealer_price, dp.dealer_profit, p.name, p.rate_limit, p.validity_days, p.price as retail_price
    FROM dealer_packages dp
    JOIN packages p ON dp.package_id = p.id
    WHERE dp.dealer_id = ?
    ORDER BY p.name ASC
");
$pkgStmt->execute([$dealer_id]);
$assigned_packages = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Dealer Notes / Transactions
$notesStmt = $pdo->prepare("
    SELECT id, note, created_at 
    FROM dealer_notes 
    WHERE dealer_id = ? 
    ORDER BY id DESC
");
$notesStmt->execute([$dealer_id]);
$dealer_notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);

// Parse Notes for Financial Statement Calculations
$total_inflow = 0;
$total_outflow = 0;
$this_month_outflow = 0;
$this_month_inflow = 0;
$current_month = date('Y-m');

$parsed_transactions = [];
foreach ($dealer_notes as $dn) {
    $note = $dn['note'];
    $amt = 0;
    $type = 'other';
    $is_debit = true; // default outflow

    // Extract amount
    if (preg_match('/Rs\.?\s*([\d,]+(?:\.\d+)?)/i', $note, $m)) {
        $amt = (float)str_replace(',', '', $m[1]);
    }

    $note_lower = strtolower($note);
    if (strpos($note_lower, 'renewal') !== false || strpos($note_lower, 'renewed') !== false) {
        $type = 'renewal';
        $is_debit = true;
    } elseif (strpos($note_lower, 'added payment') !== false || strpos($note_lower, 'payment rs') !== false) {
        $type = 'customer_payment';
        $is_debit = true;
    } elseif (strpos($note_lower, 'created') !== false || strpos($note_lower, 'new user') !== false) {
        $type = 'create_user';
        $is_debit = true;
    } elseif (strpos($note_lower, 'recharge') !== false || strpos($note_lower, 'fund') !== false || strpos($note_lower, 'credited back') !== false || strpos($note_lower, 'refund') !== false || strpos($note_lower, 'reconciliation credit') !== false) {
        $type = 'recharge';
        $is_debit = false;
    } elseif (strpos($note_lower, 'deducted') !== false) {
        $is_debit = true;
    }

    if ($is_debit) {
        $total_outflow += $amt;
        if (strpos($dn['created_at'], $current_month) === 0) {
            $this_month_outflow += $amt;
        }
    } else {
        $total_inflow += $amt;
        if (strpos($dn['created_at'], $current_month) === 0) {
            $this_month_inflow += $amt;
        }
    }

    $parsed_transactions[] = [
        'id' => $dn['id'],
        'note' => $dn['note'],
        'created_at' => $dn['created_at'],
        'amount' => $amt,
        'type' => $type,
        'is_debit' => $is_debit
    ];
}

// Fetch Fund / Recharge Requests
$fundReqStmt = $pdo->prepare("
    SELECT id, amount, payment_reference, status, created_at 
    FROM fund_requests 
    WHERE dealer_id = ? 
    ORDER BY id DESC LIMIT 200
");
$fundReqStmt->execute([$dealer_id]);
$fund_requests = $fundReqStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dealer Activity Logs
$actStmt = $pdo->prepare("
    SELECT id, by_user, by_role, against_to, against_role, activity, created_at 
    FROM activity_logs 
    WHERE dealer_id = ? OR by_user = ? 
    ORDER BY id DESC LIMIT 500
");
$actStmt->execute([$dealer_id, $current_dealer['username']]);
$activity_logs = $actStmt->fetchAll(PDO::FETCH_ASSOC);

// Date filters for statement tab
$stmt_from = $_GET['stmt_from'] ?? date('Y-m-01');
$stmt_to = $_GET['stmt_to'] ?? date('Y-m-d');
?>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
/* Modern Dealer Profile Styles */
.profile-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
    border: 1px solid #e2e8f0;
    overflow: hidden;
}
.profile-header-banner {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    padding: 24px 20px 20px;
    color: #ffffff;
    text-align: center;
    position: relative;
}
.profile-avatar-circle {
    width: 80px;
    height: 80px;
    background: #3b82f6;
    border-radius: 50%;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 34px;
    border: 4px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    margin-bottom: 12px;
}
.profile-info-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.profile-info-list li {
    padding: 12px 18px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.86rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #475569;
}
.profile-info-list li:last-child {
    border-bottom: none;
}
.profile-info-list li .label {
    font-weight: 600;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 8px;
}
.profile-info-list li .label i {
    width: 18px;
    color: #3b82f6;
    text-align: center;
}
.profile-info-list li .val {
    font-weight: 700;
    color: #1e293b;
    text-align: right;
    max-width: 60%;
    word-break: break-word;
}

/* Stat Cards */
.kpi-card {
    background: #ffffff;
    border-radius: 14px;
    padding: 18px 20px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 10px rgba(0,0,0,0.03);
    transition: transform 0.2s, box-shadow 0.2s;
    height: 100%;
}
.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(0,0,0,0.06);
}
.kpi-icon-box {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.kpi-primary { background: #eff6ff; color: #2563eb; }
.kpi-success { background: #f0fdf4; color: #16a34a; }
.kpi-danger  { background: #fef2f2; color: #dc2626; }
.kpi-warning { background: #fffbeb; color: #d97706; }

/* Custom Nav Tabs */
.nav-tabs-custom {
    border-bottom: 2px solid #e2e8f0;
    gap: 8px;
}
.nav-tabs-custom .nav-link {
    border: none;
    color: #64748b;
    font-weight: 600;
    font-size: 0.9rem;
    padding: 10px 18px;
    border-radius: 10px 10px 0 0;
    transition: 0.2s;
    background: transparent;
    display: flex;
    align-items: center;
    gap: 8px;
}
.nav-tabs-custom .nav-link:hover {
    color: #2563eb;
    background: rgba(37, 99, 235, 0.05);
}
.nav-tabs-custom .nav-link.active {
    color: #2563eb;
    background: #ffffff;
    border-bottom: 3px solid #2563eb;
    box-shadow: 0 -2px 10px rgba(0,0,0,0.02);
}

/* DataTables customization */
.dataTables_wrapper .dataTables_filter input {
    border: 1px solid #cbd5e1;
    border-radius: 20px;
    padding: 5px 14px;
    font-size: 0.85rem;
}
.dataTables_wrapper .dataTables_length select {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 4px 10px;
}
.dt-buttons .btn {
    border-radius: 20px !important;
    font-size: 0.78rem !important;
    font-weight: 600 !important;
    padding: 5px 14px !important;
    margin-right: 4px !important;
}
</style>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Top Alert Messages -->
    <?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="fa-solid fa-circle-check fs-5 text-success"></i>
        <div class="fw-semibold"><?= htmlspecialchars($success_msg) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="fa-solid fa-circle-exclamation fs-5 text-danger"></i>
        <div class="fw-semibold"><?= htmlspecialchars($error_msg) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Header Breadcrumb & Actions -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none">Dealer Portal</a></li>
                    <li class="breadcrumb-item active">My Profile</li>
                </ol>
            </nav>
            <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <i class="fa-solid fa-id-badge text-primary"></i>
                <span>My Profile &amp; Financial Statements</span>
            </h4>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-success rounded-pill px-3 py-2 fw-semibold shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addFundsModal">
                <i class="fa-solid fa-wallet"></i> Recharge Wallet
            </button>
            <button type="button" class="btn btn-outline-primary rounded-pill px-3 py-2 fw-semibold d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#passwordModal">
                <i class="fa-solid fa-key"></i> Change Password
            </button>
        </div>
    </div>

    <!-- Top KPI Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Current Wallet Balance -->
        <div class="col-sm-6 col-xl-3">
            <div class="kpi-card d-flex align-items-center justify-content-between border-success-subtle bg-success bg-opacity-10">
                <div>
                    <div class="text-muted small fw-bold mb-1 text-uppercase">Wallet Balance</div>
                    <h3 class="fw-bold text-success mb-0 font-monospace">Rs. <?= number_format($current_dealer['balance'], 2) ?></h3>
                    <div class="small text-muted mt-1">Available for renewals</div>
                </div>
                <div class="kpi-icon-box bg-success text-white shadow-sm">
                    <i class="fa-solid fa-wallet"></i>
                </div>
            </div>
        </div>

        <!-- 2. Total Wallet Inflow -->
        <div class="col-sm-6 col-xl-3">
            <div class="kpi-card d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-bold mb-1 text-uppercase">Total Funds / Inflow</div>
                    <h3 class="fw-bold text-dark mb-0 font-monospace">Rs. <?= number_format($total_inflow, 2) ?></h3>
                    <div class="small text-success mt-1"><i class="fa-solid fa-arrow-down-long me-1"></i>Recharges credited</div>
                </div>
                <div class="kpi-icon-box kpi-primary">
                    <i class="fa-solid fa-money-bill-transfer"></i>
                </div>
            </div>
        </div>

        <!-- 3. Total Spending / Outflow -->
        <div class="col-sm-6 col-xl-3">
            <div class="kpi-card d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-bold mb-1 text-uppercase">Total Wallet Spent</div>
                    <h3 class="fw-bold text-danger mb-0 font-monospace">Rs. <?= number_format($total_outflow, 2) ?></h3>
                    <div class="small text-muted mt-1">This month: <strong>Rs. <?= number_format($this_month_outflow, 2) ?></strong></div>
                </div>
                <div class="kpi-icon-box kpi-danger">
                    <i class="fa-solid fa-receipt"></i>
                </div>
            </div>
        </div>

        <!-- 4. Subscribers Managed -->
        <div class="col-sm-6 col-xl-3">
            <div class="kpi-card d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-bold mb-1 text-uppercase">Managed Customers</div>
                    <h3 class="fw-bold text-primary mb-0 font-monospace"><?= (int)$subSummary['total_users'] ?></h3>
                    <div class="small text-muted mt-1">
                        <span class="text-success fw-bold"><?= $online_users ?> Online</span> &bull; 
                        <span class="text-secondary"><?= (int)$subSummary['active_users'] ?> Active</span>
                    </div>
                </div>
                <div class="kpi-icon-box kpi-warning">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row g-4">

        <!-- Left Column: Dealer Profile Card -->
        <div class="col-lg-4 col-xl-3">
            <div class="profile-card mb-4">
                <div class="profile-header-banner">
                    <div class="profile-avatar-circle">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                    <h5 class="fw-bold text-white mb-0"><?= htmlspecialchars($current_dealer['full_name'] ?: $current_dealer['username']) ?></h5>
                    <div class="small text-white-50 mb-2">@<?= htmlspecialchars($current_dealer['username']) ?> (Dealer)</div>
                    <span class="badge bg-success px-3 py-1 rounded-pill fw-semibold shadow-sm">
                        <i class="fa-solid fa-circle-check me-1"></i> Active Franchise
                    </span>
                </div>

                <ul class="profile-info-list">
                    <li>
                        <span class="label"><i class="fa-solid fa-building"></i> Franchise:</span>
                        <span class="val"><?= htmlspecialchars($current_dealer['franchise'] ?: 'SB Link Network') ?></span>
                    </li>
                    <li>
                        <span class="label"><i class="fa-solid fa-user-shield"></i> Admin:</span>
                        <span class="val"><?= htmlspecialchars($admin_client['company_name'] ?: 'Super Admin') ?></span>
                    </li>
                    <li>
                        <span class="label"><i class="fa-solid fa-id-card"></i> CNIC:</span>
                        <span class="val font-monospace"><?= htmlspecialchars($current_dealer['national_id'] ?: 'N/A') ?></span>
                    </li>
                    <li>
                        <span class="label"><i class="fa-solid fa-phone"></i> Mobile:</span>
                        <span class="val font-monospace"><?= htmlspecialchars($current_dealer['phone'] ?: 'N/A') ?></span>
                    </li>
                    <li>
                        <span class="label"><i class="fa-solid fa-envelope"></i> Email:</span>
                        <span class="val"><?= htmlspecialchars($current_dealer['email'] ?: 'N/A') ?></span>
                    </li>
                    <li>
                        <span class="label"><i class="fa-solid fa-location-dot"></i> City:</span>
                        <span class="val"><?= htmlspecialchars($current_dealer['city'] ?: ($current_dealer['area'] ?: 'N/A')) ?></span>
                    </li>
                    <li>
                        <span class="label"><i class="fa-solid fa-map-location-dot"></i> Address:</span>
                        <span class="val"><?= htmlspecialchars($current_dealer['address'] ?: 'N/A') ?></span>
                    </li>
                    <li>
                        <span class="label"><i class="fa-solid fa-calendar-days"></i> Joined:</span>
                        <span class="val"><?= !empty($current_dealer['created_at']) ? date('d M Y', strtotime($current_dealer['created_at'])) : 'N/A' ?></span>
                    </li>
                    <?php if (!empty($current_dealer['assigned_routers'])): ?>
                    <li>
                        <span class="label"><i class="fa-solid fa-server"></i> Routers:</span>
                        <span class="val">
                            <?php foreach(explode(',', $current_dealer['assigned_routers']) as $r): ?>
                                <span class="badge bg-light text-dark border me-1"><?= htmlspecialchars(trim($r)) ?></span>
                            <?php endforeach; ?>
                        </span>
                    </li>
                    <?php endif; ?>
                </ul>

                <div class="p-3 bg-light border-top d-grid gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill fw-semibold" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                        <i class="fa-solid fa-user-pen me-1"></i> Edit Contact Details
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill fw-semibold" data-bs-toggle="modal" data-bs-target="#passwordModal">
                        <i class="fa-solid fa-lock me-1"></i> Update Password
                    </button>
                </div>
            </div>
        </div>

        <!-- Right Column: Tabs (Financial Statement, Transactions, Activities, Recharges, Packages) -->
        <div class="col-lg-8 col-xl-9">
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white mb-4">
                
                <!-- Nav Tabs -->
                <ul class="nav nav-tabs nav-tabs-custom mb-4" id="profileTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="statement-tab" data-bs-toggle="tab" data-bs-target="#tab-statement" type="button" role="tab">
                            <i class="fa-solid fa-file-invoice-dollar text-primary"></i> Financial Statements
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="transactions-tab" data-bs-toggle="tab" data-bs-target="#tab-transactions" type="button" role="tab">
                            <i class="fa-solid fa-clock-rotate-left text-success"></i> All Transactions (<?= count($dealer_notes) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="activities-tab" data-bs-toggle="tab" data-bs-target="#tab-activities" type="button" role="tab">
                            <i class="fa-solid fa-list-check text-info"></i> Activity Logs (<?= count($activity_logs) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="recharges-tab" data-bs-toggle="tab" data-bs-target="#tab-recharges" type="button" role="tab">
                            <i class="fa-solid fa-money-bill-wave text-warning"></i> Recharge Requests (<?= count($fund_requests) ?>)
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="packages-tab" data-bs-toggle="tab" data-bs-target="#tab-packages" type="button" role="tab">
                            <i class="fa-solid fa-box text-secondary"></i> Assigned Packages (<?= count($assigned_packages) ?>)
                        </button>
                    </li>
                </ul>

                <!-- Tab Contents -->
                <div class="tab-content" id="profileTabsContent">

                    <!-- ================= TAB 1: FINANCIAL STATEMENTS ================= -->
                    <div class="tab-pane fade show active" id="tab-statement" role="tabpanel">
                        
                        <!-- Statement Filter Controls -->
                        <div class="p-3 bg-light rounded-4 mb-4 border">
                            <div class="row g-2 align-items-center">
                                <div class="col-md-3">
                                    <label class="form-label text-secondary small fw-bold mb-1"><i class="fa-solid fa-calendar me-1"></i> From Date</label>
                                    <input type="date" id="filter_date_from" class="form-control form-control-sm rounded-pill" value="<?= $stmt_from ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label text-secondary small fw-bold mb-1"><i class="fa-solid fa-calendar me-1"></i> To Date</label>
                                    <input type="date" id="filter_date_to" class="form-control form-control-sm rounded-pill" value="<?= $stmt_to ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label text-secondary small fw-bold mb-1"><i class="fa-solid fa-filter me-1"></i> Category</label>
                                    <select id="filter_category" class="form-select form-select-sm rounded-pill">
                                        <option value="">All Transactions</option>
                                        <option value="renewal">Package Renewal</option>
                                        <option value="customer_payment">Customer Payment Added</option>
                                        <option value="recharge">Wallet Recharge / Credit</option>
                                        <option value="create_user">New User Created</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end gap-2">
                                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-1 fw-bold w-100" onclick="applyStatementFilter()">
                                        <i class="fa-solid fa-magnifying-glass me-1"></i> Filter
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1" onclick="resetStatementFilter()">
                                        Reset
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Statement Table -->
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="statementTable">
                                <thead class="table-light">
                                    <tr class="small text-secondary">
                                        <th>Date &amp; Time</th>
                                        <th>Type / Category</th>
                                        <th>Description / Particulars</th>
                                        <th class="text-danger text-end">Outflow (Debit)</th>
                                        <th class="text-success text-end">Inflow (Credit)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($parsed_transactions as $pt): ?>
                                    <tr data-date="<?= date('Y-m-d', strtotime($pt['created_at'])) ?>" data-type="<?= $pt['type'] ?>">
                                        <td class="font-mono small text-secondary" style="white-space: nowrap;">
                                            <?= date('d M Y, h:i A', strtotime($pt['created_at'])) ?>
                                        </td>
                                        <td>
                                            <?php if ($pt['type'] === 'renewal'): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1 small">
                                                    <i class="fa-solid fa-rotate me-1"></i> Renewal
                                                </span>
                                            <?php elseif ($pt['type'] === 'recharge'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 small">
                                                    <i class="fa-solid fa-arrow-down me-1"></i> Recharge
                                                </span>
                                            <?php elseif ($pt['type'] === 'customer_payment'): ?>
                                                <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-2 py-1 small">
                                                    <i class="fa-solid fa-money-bill me-1"></i> Customer Cash
                                                </span>
                                            <?php elseif ($pt['type'] === 'create_user'): ?>
                                                <span class="badge bg-warning-subtle text-dark border border-warning-subtle rounded-pill px-2 py-1 small">
                                                    <i class="fa-solid fa-user-plus me-1"></i> User Created
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary border rounded-pill px-2 py-1 small">
                                                    Adjustment
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark small" style="max-width: 380px; word-break: break-word;">
                                                <?= htmlspecialchars($pt['note']) ?>
                                            </div>
                                        </td>
                                        <td class="text-end fw-bold font-mono text-danger">
                                            <?= $pt['is_debit'] && $pt['amount'] > 0 ? '- Rs. ' . number_format($pt['amount'], 2) : '-' ?>
                                        </td>
                                        <td class="text-end fw-bold font-mono text-success">
                                            <?= !$pt['is_debit'] && $pt['amount'] > 0 ? '+ Rs. ' . number_format($pt['amount'], 2) : '-' ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>

                    <!-- ================= TAB 2: ALL TRANSACTIONS ================= -->
                    <div class="tab-pane fade" id="tab-transactions" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="transactionsTable">
                                <thead class="table-light">
                                    <tr class="small text-secondary">
                                        <th>#</th>
                                        <th>Date &amp; Time</th>
                                        <th>Transaction Record / Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dealer_notes as $dn): ?>
                                    <tr>
                                        <td class="font-mono small text-muted">#<?= $dn['id'] ?></td>
                                        <td class="font-mono small text-secondary" style="white-space: nowrap;">
                                            <?= date('d M Y, h:i:s A', strtotime($dn['created_at'])) ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark small">
                                                <?= htmlspecialchars($dn['note']) ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ================= TAB 3: ACTIVITY LOGS ================= -->
                    <div class="tab-pane fade" id="tab-activities" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="activitiesTable">
                                <thead class="table-light">
                                    <tr class="small text-secondary">
                                        <th>Date &amp; Time</th>
                                        <th>Performed By</th>
                                        <th>Activity Action</th>
                                        <th>Target Customer</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activity_logs as $act): ?>
                                    <tr>
                                        <td class="font-mono small text-secondary" style="white-space: nowrap;">
                                            <?= date('d M Y, h:i:s A', strtotime($act['created_at'])) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <i class="fa-solid fa-user me-1 text-primary"></i> <?= htmlspecialchars($act['by_user'] ?: 'System') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold text-dark small">
                                                <?= htmlspecialchars($act['activity']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($act['against_to'])): ?>
                                                <span class="font-mono fw-bold text-primary small">
                                                    <?= htmlspecialchars($act['against_to']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ================= TAB 4: RECHARGE REQUESTS ================= -->
                    <div class="tab-pane fade" id="tab-recharges" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-clock-rotate-left me-1 text-primary"></i> Online Recharge History</h6>
                            <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#addFundsModal">
                                <i class="fa-solid fa-plus me-1"></i> New Recharge
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="rechargesTable">
                                <thead class="table-light">
                                    <tr class="small text-secondary">
                                        <th>ID</th>
                                        <th>Date &amp; Time</th>
                                        <th>Recharge Amount</th>
                                        <th>Payment Reference TID</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fund_requests as $fr): ?>
                                    <tr>
                                        <td class="font-mono small text-muted">#<?= $fr['id'] ?></td>
                                        <td class="font-mono small text-secondary">
                                            <?= date('d M Y, h:i A', strtotime($fr['created_at'])) ?>
                                        </td>
                                        <td class="fw-bold font-mono text-dark">
                                            Rs. <?= number_format((float)$fr['amount'], 2) ?>
                                        </td>
                                        <td class="font-mono small text-primary fw-bold">
                                            <?= htmlspecialchars($fr['payment_reference']) ?>
                                        </td>
                                        <td>
                                            <?php if ($fr['status'] === 'approved'): ?>
                                                <span class="badge bg-success rounded-pill px-3 py-1"><i class="fa-solid fa-check me-1"></i> Approved</span>
                                            <?php elseif ($fr['status'] === 'rejected'): ?>
                                                <span class="badge bg-danger rounded-pill px-3 py-1"><i class="fa-solid fa-xmark me-1"></i> Rejected</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark rounded-pill px-3 py-1"><i class="fa-solid fa-clock me-1"></i> Pending Verification</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ================= TAB 5: ASSIGNED PACKAGES ================= -->
                    <div class="tab-pane fade" id="tab-packages" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle w-100" id="packagesTable">
                                <thead class="table-light">
                                    <tr class="small text-secondary">
                                        <th>Package Name</th>
                                        <th>Speed / Rate Limit</th>
                                        <th>Duration</th>
                                        <th class="text-primary text-end">Your Dealer Cost</th>
                                        <th class="text-secondary text-end">Retail Price</th>
                                        <th class="text-success text-end">Profit Margin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assigned_packages as $ap): 
                                        $d_price = (float)$ap['dealer_price'];
                                        $r_price = (float)($ap['retail_price'] ?: $d_price);
                                        $profit = (float)($ap['dealer_profit'] ?: ($r_price - $d_price));
                                        if ($profit < 0) $profit = 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="avatar bg-primary-subtle text-primary rounded-3 p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                                    <i class="fa-solid fa-box"></i>
                                                </div>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($ap['name']) ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border font-mono">
                                                <?= htmlspecialchars($ap['rate_limit'] ?: 'Unlimited') ?>
                                            </span>
                                        </td>
                                        <td class="small text-secondary">
                                            <?= (int)($ap['validity_days'] ?: 30) ?> Days
                                        </td>
                                        <td class="text-end fw-bold font-mono text-primary fs-6">
                                            Rs. <?= number_format($d_price, 2) ?>
                                        </td>
                                        <td class="text-end fw-semibold font-mono text-secondary">
                                            Rs. <?= number_format($r_price, 2) ?>
                                        </td>
                                        <td class="text-end fw-bold font-mono text-success">
                                            + Rs. <?= number_format($profit, 2) ?>
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
    </div>
</div>

<!-- Modal: Change Password -->
<div class="modal fade" id="passwordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom px-4 py-3">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                    <i class="fa-solid fa-key text-primary"></i> Change Portal Password
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-lock text-muted"></i></span>
                            <input type="password" name="new_password" id="modal_new_pass" class="form-control border-start-0" placeholder="Minimum 5 characters" required minlength="5">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Confirm New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-shield-check text-muted"></i></span>
                            <input type="password" name="confirm_password" id="modal_confirm_pass" class="form-control border-start-0" placeholder="Re-type new password" required minlength="5">
                        </div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="show_pass_toggle" onclick="togglePassVisibility()">
                        <label class="form-check-label small text-secondary" for="show_pass_toggle">
                            Show password in plain text
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm">
                        <i class="fa-solid fa-check me-1"></i> Update Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Profile Details -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom px-4 py-3">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2">
                    <i class="fa-solid fa-user-pen text-primary"></i> Edit Contact Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Full Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($current_dealer['full_name'] ?? '') ?>" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label text-secondary small fw-bold">Mobile / Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($current_dealer['phone'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label text-secondary small fw-bold">City / Area</label>
                            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($current_dealer['city'] ?: ($current_dealer['area'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small fw-bold">Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($current_dealer['email'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label text-secondary small fw-bold">Physical Address</label>
                        <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($current_dealer['address'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables & Export Scripts -->
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
let stmtDataTable;

$(document).ready(function() {
    // Statement Table with Export buttons
    stmtDataTable = $('#statementTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: '<"d-flex flex-wrap justify-content-between align-items-center mb-3"Bf>rt<"d-flex justify-content-between align-items-center mt-3"lip>',
        buttons: [
            { extend: 'copy', className: 'btn btn-outline-secondary', text: '<i class="fa-regular fa-copy me-1"></i> Copy' },
            { extend: 'excel', className: 'btn btn-outline-success', text: '<i class="fa-regular fa-file-excel me-1"></i> Excel', title: 'Dealer_Statement_<?= $current_dealer['username'] ?>' },
            { extend: 'pdf', className: 'btn btn-outline-danger', text: '<i class="fa-regular fa-file-pdf me-1"></i> PDF', title: 'Dealer_Statement_<?= $current_dealer['username'] ?>' },
            { extend: 'print', className: 'btn btn-outline-primary', text: '<i class="fa-solid fa-print me-1"></i> Print' }
        ],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search statements..."
        }
    });

    // Transactions Table
    $('#transactionsTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        language: { searchPlaceholder: "Search transactions..." }
    });

    // Activities Table
    $('#activitiesTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        language: { searchPlaceholder: "Search activities..." }
    });

    // Recharges Table
    $('#rechargesTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'desc']],
        language: { searchPlaceholder: "Search recharges..." }
    });

    // Packages Table
    $('#packagesTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [[0, 'asc']],
        language: { searchPlaceholder: "Search packages..." }
    });

    // Fix DataTables column width on tab change
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
});

// Statement Date & Category Filter
function applyStatementFilter() {
    const from = document.getElementById('filter_date_from').value;
    const to = document.getElementById('filter_date_to').value;
    const cat = document.getElementById('filter_category').value;

    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex, rowData, counter) {
        if (settings.nTable.id !== 'statementTable') return true;

        const rowNode = stmtDataTable.row(dataIndex).node();
        const rowDate = $(rowNode).data('date');
        const rowType = $(rowNode).data('type');

        if (from && rowDate < from) return false;
        if (to && rowDate > to) return false;
        if (cat && rowType !== cat) return false;

        return true;
    });

    stmtDataTable.draw();
    $.fn.dataTable.ext.search.pop();
}

function resetStatementFilter() {
    document.getElementById('filter_date_from').value = '';
    document.getElementById('filter_date_to').value = '';
    document.getElementById('filter_category').value = '';
    stmtDataTable.draw();
}

function togglePassVisibility() {
    const p1 = document.getElementById('modal_new_pass');
    const p2 = document.getElementById('modal_confirm_pass');
    const t = document.getElementById('show_pass_toggle').checked ? 'text' : 'password';
    if (p1) p1.type = t;
    if (p2) p2.type = t;
}
</script>

<?php require_once 'footer.php'; ?>
