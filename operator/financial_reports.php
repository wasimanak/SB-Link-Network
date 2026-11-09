<?php
require_once 'header.php';

$client_id = (int)$_SESSION['operator_id'];
$is_mgr = !empty($_SESSION['is_manager']);

// 1. Month Filter Handling (Defaults to Current Month)
$selected_month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : date('Y-m');
$month_start = $selected_month . '-01 00:00:00';
$month_end   = date('Y-m-t 23:59:59', strtotime($month_start));
$month_label = date('F Y', strtotime($month_start));

$prev_month = date('Y-m', strtotime($selected_month . '-01 -1 month'));
$next_month = date('Y-m', strtotime($selected_month . '-01 +1 month'));
$is_current_month = ($selected_month === date('Y-m'));

// 2. Dealer Filter Handling (Admin Only, isolated for Managers)
$mgr_sub_filter = $is_mgr ? " AND (s.dealer_id IS NULL OR s.dealer_id = 0)" : "";
$dealer_filter_sql = "";
$selected_dealer = isset($_GET['dealer']) ? trim($_GET['dealer']) : 'all';

$dealers = [];
if (!$is_mgr) {
    try {
        $dealers = $pdo->query("SELECT id, full_name, username FROM dealers WHERE client_id = $client_id ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    if ($selected_dealer === 'direct') {
        $dealer_filter_sql = " AND (s.dealer_id IS NULL OR s.dealer_id = 0)";
    } elseif (is_numeric($selected_dealer) && (int)$selected_dealer > 0) {
        $dealer_filter_sql = " AND s.dealer_id = " . (int)$selected_dealer;
    }
}

// 3. Fetch Subscribers with Assigned Packages and Dynamic Dealer Pricing
// Note: Direct admin customers pay package price; Dealer customers pay dealer wholesale + profit
$query = "
    SELECT s.id, s.username, s.full_name, s.mobile, s.phone, s.address, s.subarea,
           s.status, s.expiry_date, s.balance, s.dealer_id, s.package_id,
           s.last_renewed_at,
           p.name as package_name, 
           COALESCE(p.price, 0) as direct_pkg_price,
           dp.dealer_price,
           dp.dealer_profit,
           CASE 
               WHEN dp.dealer_price IS NOT NULL AND (dp.dealer_price + dp.dealer_profit) > 0 THEN (dp.dealer_price + dp.dealer_profit)
               WHEN dp.dealer_price IS NOT NULL AND dp.dealer_price > 0 THEN dp.dealer_price
               ELSE COALESCE(p.price, 0)
           END as effective_package_price,
           d.full_name as dealer_name
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    LEFT JOIN dealer_packages dp ON (s.dealer_id = dp.dealer_id AND s.package_id = dp.package_id)
    LEFT JOIN dealers d ON s.dealer_id = d.id
    WHERE s.client_id = ? $mgr_sub_filter $dealer_filter_sql
    ORDER BY s.username ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$client_id]);
$raw_subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Fetch Payments Received (Credit Entries in user_ledger) for Selected Month
$payments_map = [];
try {
    $payStmt = $pdo->prepare("
        SELECT username, 
               SUM(amount) as total_paid,
               MAX(created_at) as last_payment_date
        FROM user_ledger
        WHERE client_id = ?
          AND type = 'credit'
          AND created_at BETWEEN ? AND ?
        GROUP BY username
    ");
    $payStmt->execute([$client_id, $month_start, $month_end]);
    while ($pRow = $payStmt->fetch(PDO::FETCH_ASSOC)) {
        $u_key = strtolower(trim($pRow['username']));
        $payments_map[$u_key] = [
            'total_paid' => (float)$pRow['total_paid'],
            'last_payment_date' => $pRow['last_payment_date']
        ];
    }
} catch (Exception $e) {}

// 5. Aggregate Financial Metrics & Classify Each Subscriber
$total_expected_recovery  = 0.0;
$total_collected_recovery = 0.0;
$total_remaining_recovery = 0.0;
$total_pending_recovery   = 0.0;

$count_total     = count($raw_subscribers);
$count_collected = 0;
$count_remaining = 0;
$count_pending   = 0;

$subscribers = [];

foreach ($raw_subscribers as $s) {
    $u_key = strtolower(trim($s['username']));
    $pkg_price = (float)($s['effective_package_price'] ?? 0);
    $bal = (float)($s['balance'] ?? 0);

    // Payment collected from this user in this month
    $paid_in_month = isset($payments_map[$u_key]) ? (float)$payments_map[$u_key]['total_paid'] : 0.0;
    $last_pay_date = isset($payments_map[$u_key]) ? $payments_map[$u_key]['last_payment_date'] : null;

    // 1. Total Target: sum of package prices for each subscriber
    $total_expected_recovery += $pkg_price;

    // 2. Collected: user made payment in this month
    $is_collected = ($paid_in_month > 0);
    if ($is_collected) {
        $count_collected++;
        $total_collected_recovery += $paid_in_month;
    }

    // 3. Remaining: package price not fully covered by this month's payments
    $user_uncollected = max(0, $pkg_price - $paid_in_month);
    $is_remaining = ($user_uncollected > 0);
    if ($is_remaining) {
        $count_remaining++;
        $total_remaining_recovery += $user_uncollected;
    }

    // 4. Pending Users: package renewed or active but money not paid (negative balance / udhaar)
    $is_pending = ($bal < 0);
    $pending_due = abs($bal);
    if ($is_pending) {
        $count_pending++;
        $total_pending_recovery += $pending_due;
    }

    // Categories tag for instant client-side filtering
    $categories = ['total'];
    if ($is_collected) $categories[] = 'collected';
    if ($is_remaining) $categories[] = 'remaining';
    if ($is_pending)   $categories[] = 'pending';

    $s['effective_price']   = $pkg_price;
    $s['paid_in_month']     = $paid_in_month;
    $s['user_uncollected']  = $user_uncollected;
    $s['pending_due']       = $pending_due;
    $s['last_payment_date'] = $last_pay_date;
    $s['categories']        = implode(' ', $categories);

    $subscribers[] = $s;
}

$collected_percent = ($total_expected_recovery > 0) ? round(($total_collected_recovery / $total_expected_recovery) * 100, 1) : 0;
$initial_tab = isset($_GET['tab']) && in_array($_GET['tab'], ['total', 'collected', 'remaining', 'pending']) ? $_GET['tab'] : 'total';
?>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    body { background-color: #f8fafc; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    
    .financial-card-box {
        background: #ffffff;
        border-radius: 20px;
        border: 1px solid #eef2f6;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        transition: all 0.25s ease;
    }

    /* Interactive Metric Cards */
    .metric-selection-card {
        background: #ffffff;
        border-radius: 18px;
        border: 2px solid #e2e8f0;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
        padding: 22px 20px;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
        user-select: none;
    }
    .metric-selection-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.09);
        border-color: #cbd5e1;
    }
    .metric-selection-card.active-card {
        transform: translateY(-3px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
    }

    /* Specific Active Styles by Card */
    .metric-selection-card[data-tab="total"].active-card {
        border-color: #8726d1 !important;
        background: linear-gradient(180deg, #ffffff 0%, #faf5ff 100%);
    }
    .metric-selection-card[data-tab="collected"].active-card {
        border-color: #10b981 !important;
        background: linear-gradient(180deg, #ffffff 0%, #f0fdf4 100%);
    }
    .metric-selection-card[data-tab="remaining"].active-card {
        border-color: #f59e0b !important;
        background: linear-gradient(180deg, #ffffff 0%, #fffbeb 100%);
    }
    .metric-selection-card[data-tab="pending"].active-card {
        border-color: #ef4444 !important;
        background: linear-gradient(180deg, #ffffff 0%, #fef2f2 100%);
    }

    .metric-selection-card .active-pill-badge {
        display: none;
        position: absolute;
        top: 12px;
        right: 12px;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 3px 8px;
        border-radius: 30px;
    }
    .metric-selection-card.active-card .active-pill-badge {
        display: inline-block;
    }

    .metric-icon-wrap {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        margin-bottom: 14px;
    }

    .badge-soft-primary { background: rgba(135, 38, 209, 0.1); color: #8726d1; }
    .badge-soft-success { background: rgba(16, 185, 129, 0.1); color: #10b981; }
    .badge-soft-warning { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
    .badge-soft-danger  { background: rgba(239, 68, 68, 0.1); color: #ef4444; }

    /* Copy Button */
    .copy-user-btn {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        color: #64748b;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .copy-user-btn:hover {
        background: #e2e8f0;
        color: #0f172a;
    }
    .copy-user-btn.copied {
        background: #10b981 !important;
        color: #ffffff !important;
        border-color: #10b981 !important;
    }

    /* Table styling */
    .table-custom-financial th {
        background: #f8fafc;
        font-weight: 700;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #475569;
        border-bottom: 2px solid #e2e8f0;
        padding: 12px 14px;
    }
    .table-custom-financial td {
        padding: 12px 14px;
        vertical-align: middle;
        font-size: 0.88rem;
    }
    .table-custom-financial tr:hover td {
        background-color: #f8fafc;
    }
</style>

<div class="container-fluid py-4 px-3 px-md-4">

    <!-- Top Header & Controls -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary-subtle px-2 py-1 rounded-pill small fw-bold">
                    <i class="fa-solid fa-chart-pie me-1"></i> Financial Recovery Intelligence
                </span>
                <?php if ($is_current_month): ?>
                    <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle px-2 py-1 rounded-pill small fw-bold">
                        <i class="fa-solid fa-circle text-success me-1" style="font-size: 0.45rem;"></i> Active Billing Cycle
                    </span>
                <?php endif; ?>
            </div>
            <h2 class="fw-bolder text-dark mb-0" style="letter-spacing: -0.5px;">Financial Recovery Reports</h2>
            <p class="text-secondary small mb-0">Track expected recoveries, collected payments, remaining targets, and pending renewed subscribers.</p>
        </div>

        <!-- Month Navigation & Controls -->
        <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Dealer Filter (Admin Only) -->
            <?php if (!$is_mgr && !empty($dealers)): ?>
                <div class="dropdown">
                    <select class="form-select form-select-sm fw-semibold shadow-sm" style="border-radius: 10px;" onchange="changeDealer(this.value)">
                        <option value="all" <?= ($selected_dealer === 'all') ? 'selected' : '' ?>>🏢 All Subscribers (Admin + Dealers)</option>
                        <option value="direct" <?= ($selected_dealer === 'direct') ? 'selected' : '' ?>>👤 Direct Admin Customers Only</option>
                        <optgroup label="Dealers">
                            <?php foreach ($dealers as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= ($selected_dealer == $d['id']) ? 'selected' : '' ?>>🤝 <?= htmlspecialchars($d['full_name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
            <?php endif; ?>

            <!-- Month Navigator -->
            <div class="btn-group shadow-sm" style="border-radius: 12px; overflow: hidden;">
                <a href="?month=<?= $prev_month ?><?= ($selected_dealer !== 'all' ? '&dealer=' . urlencode($selected_dealer) : '') ?><?= ($initial_tab !== 'total' ? '&tab=' . $initial_tab : '') ?>" 
                   class="btn btn-sm btn-white border px-3" title="Previous Month">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
                <span class="btn btn-sm btn-white border-top border-bottom px-3 fw-bold text-dark d-flex align-items-center gap-2">
                    <i class="fa-regular fa-calendar-days text-primary"></i> <?= $month_label ?>
                </span>
                <a href="?month=<?= $next_month ?><?= ($selected_dealer !== 'all' ? '&dealer=' . urlencode($selected_dealer) : '') ?><?= ($initial_tab !== 'total' ? '&tab=' . $initial_tab : '') ?>" 
                   class="btn btn-sm btn-white border px-3" title="Next Month">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
            </div>

            <!-- Jump to Current Month -->
            <?php if (!$is_current_month): ?>
                <a href="?month=<?= date('Y-m') ?><?= ($selected_dealer !== 'all' ? '&dealer=' . urlencode($selected_dealer) : '') ?>" class="btn btn-sm btn-outline-primary shadow-sm" style="border-radius: 10px;">
                    <i class="fa-solid fa-rotate-left me-1"></i> Current Month
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- 4 Interactive Clickable Metric Cards -->
    <div class="row g-3 mb-4">

        <!-- Card 1: Total Expected Monthly Recovery -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="metric-selection-card <?= ($initial_tab === 'total') ? 'active-card' : '' ?>" data-tab="total" onclick="selectReportCard('total')">
                <span class="active-pill-badge bg-primary text-white">Selected</span>
                <div class="d-flex justify-content-between align-items-start">
                    <div class="metric-icon-wrap" style="background: rgba(135, 38, 209, 0.12); color: #8726d1;">
                        <i class="fa-solid fa-coins"></i>
                    </div>
                    <span class="badge badge-soft-primary px-2 py-1 rounded-pill fw-bold" style="font-size: 0.72rem;">
                        <i class="fa-solid fa-users me-1"></i> <?= number_format($count_total) ?> Total Users
                    </span>
                </div>
                <div class="text-secondary small fw-bold text-uppercase mb-1" style="letter-spacing: 0.6px; font-size: 0.75rem;">
                    1. Expected Recovery
                </div>
                <h3 class="fw-bolder mb-1 text-dark" style="letter-spacing: -0.5px;">
                    Rs. <?= number_format($total_expected_recovery, 0) ?>
                </h3>
                <div class="d-flex align-items-center justify-content-between text-muted small mt-2 pt-2 border-top">
                    <span>Full Month Target</span>
                    <span class="fw-bold text-primary" style="font-size: 0.75rem;"><i class="fa-solid fa-arrow-pointer me-1"></i> View All</span>
                </div>
            </div>
        </div>

        <!-- Card 2: Collected Recovery -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="metric-selection-card <?= ($initial_tab === 'collected') ? 'active-card' : '' ?>" data-tab="collected" onclick="selectReportCard('collected')">
                <span class="active-pill-badge bg-success text-white">Selected</span>
                <div class="d-flex justify-content-between align-items-start">
                    <div class="metric-icon-wrap" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <span class="badge badge-soft-success px-2 py-1 rounded-pill fw-bold" style="font-size: 0.72rem;">
                        <i class="fa-solid fa-check me-1"></i> <?= number_format($count_collected) ?> Users Paid
                    </span>
                </div>
                <div class="text-secondary small fw-bold text-uppercase mb-1" style="letter-spacing: 0.6px; font-size: 0.75rem;">
                    2. Collected Recovery
                </div>
                <h3 class="fw-bolder mb-1 text-success" style="letter-spacing: -0.5px;">
                    Rs. <?= number_format($total_collected_recovery, 0) ?>
                </h3>
                <div class="d-flex align-items-center justify-content-between text-muted small mt-2 pt-2 border-top">
                    <span><?= $collected_percent ?>% of Target Paid</span>
                    <span class="fw-bold text-success" style="font-size: 0.75rem;"><i class="fa-solid fa-arrow-pointer me-1"></i> View Paid</span>
                </div>
            </div>
        </div>

        <!-- Card 3: Remaining Recovery -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="metric-selection-card <?= ($initial_tab === 'remaining') ? 'active-card' : '' ?>" data-tab="remaining" onclick="selectReportCard('remaining')">
                <span class="active-pill-badge bg-warning text-dark">Selected</span>
                <div class="d-flex justify-content-between align-items-start">
                    <div class="metric-icon-wrap" style="background: rgba(245, 158, 11, 0.12); color: #f59e0b;">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                    <span class="badge badge-soft-warning px-2 py-1 rounded-pill fw-bold" style="font-size: 0.72rem;">
                        <i class="fa-solid fa-user-clock me-1"></i> <?= number_format($count_remaining) ?> Remaining
                    </span>
                </div>
                <div class="text-secondary small fw-bold text-uppercase mb-1" style="letter-spacing: 0.6px; font-size: 0.75rem;">
                    3. Remaining Target
                </div>
                <h3 class="fw-bolder mb-1 text-warning" style="letter-spacing: -0.5px;">
                    Rs. <?= number_format($total_remaining_recovery, 0) ?>
                </h3>
                <div class="d-flex align-items-center justify-content-between text-muted small mt-2 pt-2 border-top">
                    <span>Yet to be Collected</span>
                    <span class="fw-bold text-warning" style="font-size: 0.75rem;"><i class="fa-solid fa-arrow-pointer me-1"></i> View Unpaid</span>
                </div>
            </div>
        </div>

        <!-- Card 4: Pending Users (Package Renewed but Money Not Submitted / Minus Balance) -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="metric-selection-card <?= ($initial_tab === 'pending') ? 'active-card' : '' ?>" data-tab="pending" onclick="selectReportCard('pending')">
                <span class="active-pill-badge bg-danger text-white">Selected</span>
                <div class="d-flex justify-content-between align-items-start">
                    <div class="metric-icon-wrap" style="background: rgba(239, 68, 68, 0.12); color: #ef4444;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <span class="badge badge-soft-danger px-2 py-1 rounded-pill fw-bold" style="font-size: 0.72rem;">
                        <i class="fa-solid fa-file-invoice-dollar me-1"></i> <?= number_format($count_pending) ?> Pending Due
                    </span>
                </div>
                <div class="text-secondary small fw-bold text-uppercase mb-1" style="letter-spacing: 0.6px; font-size: 0.75rem;">
                    4. Renewed Unpaid (Minus Bal)
                </div>
                <h3 class="fw-bolder mb-1 text-danger" style="letter-spacing: -0.5px;">
                    Rs. <?= number_format($total_pending_recovery, 0) ?>
                </h3>
                <div class="d-flex align-items-center justify-content-between text-muted small mt-2 pt-2 border-top">
                    <span>Renewed on Udhaar</span>
                    <span class="fw-bold text-danger" style="font-size: 0.75rem;"><i class="fa-solid fa-arrow-pointer me-1"></i> View Pending</span>
                </div>
            </div>
        </div>

    </div>

    <!-- Details Section Container -->
    <div class="financial-card-box p-4">
        
        <!-- Table Header Bar -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 mb-3 border-bottom">
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h5 class="fw-bold mb-0 text-dark" id="selectedSectionTitle">
                        <i class="fa-solid fa-list-check text-primary me-2"></i> All Users: Expected Monthly Recovery
                    </h5>
                    <span class="badge rounded-pill bg-dark text-white px-2 py-1 small" id="selectedSectionCount">
                        <?= number_format($count_total) ?> Users
                    </span>
                </div>
                <div class="text-secondary small mt-1" id="selectedSectionDesc">
                    Showing all registered subscribers with expected monthly recovery based on their package price.
                </div>
            </div>

            <!-- Category Fast Pills -->
            <div class="d-flex align-items-center gap-1 bg-light p-1 rounded-pill border">
                <button type="button" class="btn btn-sm rounded-pill fw-semibold px-3 py-1 tab-pill-btn active" data-tab="total" onclick="selectReportCard('total')">
                    All (<?= $count_total ?>)
                </button>
                <button type="button" class="btn btn-sm rounded-pill fw-semibold px-3 py-1 tab-pill-btn text-success" data-tab="collected" onclick="selectReportCard('collected')">
                    Collected (<?= $count_collected ?>)
                </button>
                <button type="button" class="btn btn-sm rounded-pill fw-semibold px-3 py-1 tab-pill-btn text-warning" data-tab="remaining" onclick="selectReportCard('remaining')">
                    Remaining (<?= $count_remaining ?>)
                </button>
                <button type="button" class="btn btn-sm rounded-pill fw-semibold px-3 py-1 tab-pill-btn text-danger" data-tab="pending" onclick="selectReportCard('pending')">
                    Pending / Udhaar (<?= $count_pending ?>)
                </button>
            </div>
        </div>

        <!-- Subscribers Table -->
        <div class="table-responsive">
            <table id="financialTable" class="table table-custom-financial align-middle w-100">
                <thead>
                    <tr>
                        <th style="width: 45px;">#</th>
                        <th>Subscriber / Username</th>
                        <th>Customer Name</th>
                        <th>Contact / Phone</th>
                        <th>Package Plan</th>
                        <th class="text-end">Package Price</th>
                        <th class="text-end">Month Paid</th>
                        <th class="text-end">Current Balance</th>
                        <th>Expiry Status</th>
                        <th class="text-center" style="width: 90px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $idx = 0;
                    foreach ($subscribers as $s): 
                        $idx++;
                        $clean_mobile = preg_replace('/[^0-9]/', '', $s['mobile'] ?: ($s['phone'] ?: ''));
                        if (substr($clean_mobile, 0, 1) === '0') {
                            $wa_mobile = '92' . substr($clean_mobile, 1);
                        } else {
                            $wa_mobile = $clean_mobile;
                        }

                        $bal = (float)$s['balance'];
                        $is_expired = (!empty($s['expiry_date']) && strtotime($s['expiry_date']) < time());
                    ?>
                    <tr data-categories="<?= $s['categories'] ?>" data-username="<?= htmlspecialchars($s['username']) ?>">
                        <!-- Sr -->
                        <td class="text-muted fw-bold"><?= $idx ?></td>

                        <!-- Username with Profile Link & Copy Button -->
                        <td>
                            <div class="d-inline-flex align-items-center gap-1">
                                <a href="subscriber_view.php?id=<?= $s['id'] ?>" class="text-decoration-none" title="Open Subscriber Profile">
                                    <span class="badge rounded-pill badge-soft-primary px-3 py-2 fw-bold" style="font-size: 0.88rem;">
                                        <i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($s['username']) ?>
                                    </span>
                                </a>
                                <button type="button" 
                                        class="copy-user-btn" 
                                        onclick="copyToClipboard(event, '<?= addslashes(htmlspecialchars($s['username'])) ?>', this)" 
                                        title="Copy Username to Clipboard">
                                    <i class="fa-regular fa-copy" style="font-size: 0.8rem;"></i>
                                </button>
                            </div>
                        </td>

                        <!-- Customer Name & Dealer Tag -->
                        <td>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($s['full_name'] ?: 'N/A') ?></div>
                            <?php if (!empty($s['dealer_name'])): ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary-subtle px-2 py-0" style="font-size: 0.68rem;" title="Dealers Customer">
                                    <i class="fa-solid fa-user-tie me-1"></i> <?= htmlspecialchars($s['dealer_name']) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border px-1 py-0" style="font-size: 0.66rem;">Direct User</span>
                            <?php endif; ?>
                        </td>

                        <!-- Contact / WhatsApp -->
                        <td>
                            <?php if (!empty($s['mobile']) || !empty($s['phone'])): ?>
                                <div class="d-flex align-items-center gap-1">
                                    <a href="tel:<?= htmlspecialchars($s['mobile'] ?: $s['phone']) ?>" class="text-dark fw-semibold text-decoration-none">
                                        <?= htmlspecialchars($s['mobile'] ?: $s['phone']) ?>
                                    </a>
                                    <?php if (!empty($wa_mobile)): ?>
                                        <a href="https://wa.me/<?= $wa_mobile ?>?text=<?= urlencode("Dear " . $s['full_name'] . ",\nYour Internet Package (" . $s['package_name'] . ") Monthly Fee is Rs. " . number_format($s['effective_price']) . ".\nCurrent Balance: Rs. " . number_format($s['balance']) . ".\nPlease submit your bill. Thank you!") ?>" 
                                           target="_blank" 
                                           class="btn btn-sm btn-outline-success border-0 p-0 px-1" 
                                           title="Send WhatsApp Reminder">
                                            <i class="fa-brands fa-whatsapp fs-6"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted small">N/A</span>
                            <?php endif; ?>
                        </td>

                        <!-- Package -->
                        <td>
                            <span class="badge bg-light text-dark border px-2 py-1 fw-bold">
                                <i class="fa-solid fa-box text-primary me-1"></i> <?= htmlspecialchars($s['package_name'] ?? 'N/A') ?>
                            </span>
                        </td>

                        <!-- Package Price -->
                        <td class="text-end fw-bolder text-dark">
                            Rs. <?= number_format($s['effective_price'], 0) ?>
                        </td>

                        <!-- Month Paid -->
                        <td class="text-end">
                            <?php if ($s['paid_in_month'] > 0): ?>
                                <span class="fw-bold text-success">
                                    Rs. <?= number_format($s['paid_in_month'], 0) ?>
                                </span>
                                <?php if (!empty($s['last_payment_date'])): ?>
                                    <div class="text-muted" style="font-size: 0.68rem;">
                                        <?= date('d M', strtotime($s['last_payment_date'])) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Rs. 0</span>
                            <?php endif; ?>
                        </td>

                        <!-- Current Balance -->
                        <td class="text-end">
                            <?php if ($bal < 0): ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-2 py-1 fw-bolder" title="Minus Balance (Package Renewed Unpaid)">
                                    -Rs. <?= number_format(abs($bal), 0) ?>
                                </span>
                            <?php elseif ($bal > 0): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle px-2 py-1 fw-bold">
                                    +Rs. <?= number_format($bal, 0) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">Rs. 0</span>
                            <?php endif; ?>
                        </td>

                        <!-- Expiry Date / Status -->
                        <td>
                            <?php if (!empty($s['expiry_date']) && $s['expiry_date'] !== '0000-00-00 00:00:00'): ?>
                                <div class="fw-semibold <?= $is_expired ? 'text-danger' : 'text-dark' ?>" style="font-size: 0.82rem;">
                                    <?= date('d M Y, h:i A', strtotime($s['expiry_date'])) ?>
                                </div>
                                <?php if ($is_expired): ?>
                                    <span class="badge bg-danger text-white px-2 py-0" style="font-size: 0.65rem;">Expired</span>
                                <?php else: ?>
                                    <span class="badge bg-success text-white px-2 py-0" style="font-size: 0.65rem;">Active</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted small">No Expiry</span>
                            <?php endif; ?>
                        </td>

                        <!-- Action -->
                        <td class="text-center">
                            <a href="subscriber_view.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary rounded-circle" style="width: 32px; height: 32px; padding: 0; line-height: 30px;" title="View Subscriber Profile">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

</div>

<!-- DataTables Scripts -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
let currentCategory = '<?= $initial_tab ?>';
let dataTableInstance = null;

const sectionInfo = {
    'total': {
        title: '<i class="fa-solid fa-coins text-primary me-2"></i> 1. All Users: Expected Monthly Recovery',
        count: '<?= number_format($count_total) ?> Users | Target: Rs. <?= number_format($total_expected_recovery) ?>',
        desc: 'Showing all registered subscribers with expected monthly recovery based on their package price.'
    },
    'collected': {
        title: '<i class="fa-solid fa-circle-check text-success me-2"></i> 2. Collected Recovery: Payments Received',
        count: '<?= number_format($count_collected) ?> Users | Collected: Rs. <?= number_format($total_collected_recovery) ?>',
        desc: 'Showing subscribers whose recovery payment has been received in <?= $month_label ?>.'
    },
    'remaining': {
        title: '<i class="fa-solid fa-hourglass-half text-warning me-2"></i> 3. Remaining Recovery: Uncollected Target',
        count: '<?= number_format($count_remaining) ?> Users | Remaining: Rs. <?= number_format($total_remaining_recovery) ?>',
        desc: 'Showing subscribers whose monthly package fee has not yet been collected this month.'
    },
    'pending': {
        title: '<i class="fa-solid fa-triangle-exclamation text-danger me-2"></i> 4. Pending Users: Package Renewed but Unpaid (Minus Balance)',
        count: '<?= number_format($count_pending) ?> Users | Due: Rs. <?= number_format($total_pending_recovery) ?>',
        desc: 'Showing subscribers whose package was renewed without immediate cash payment and currently have a negative balance.'
    }
};

$(document).ready(function() {
    // Custom filter for category matching
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (currentCategory === 'total') return true;
        let rowNode = settings.aoData[dataIndex].nTr;
        if (!rowNode) return true;
        let cats = $(rowNode).attr('data-categories') || '';
        return cats.includes(currentCategory);
    });

    dataTableInstance = $('#financialTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'asc']],
        dom: "<'row'<'col-sm-12 col-md-6 d-flex align-items-center gap-2'B><'col-sm-12 col-md-6'f>>" +
             "<'row'<'col-sm-12'tr>>" +
             "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="fa-solid fa-file-excel me-1"></i> Excel Export',
                className: 'btn btn-sm btn-outline-success',
                title: 'Financial Recovery Report - <?= $month_label ?>'
            },
            {
                extend: 'csvHtml5',
                text: '<i class="fa-solid fa-file-csv me-1"></i> CSV',
                className: 'btn btn-sm btn-outline-secondary'
            },
            {
                extend: 'print',
                text: '<i class="fa-solid fa-print me-1"></i> Print',
                className: 'btn btn-sm btn-outline-dark'
            }
        ],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search user, phone, package..."
        }
    });

    // Apply initial tab
    selectReportCard(currentCategory, false);
});

function selectReportCard(tab, updateUrl = true) {
    currentCategory = tab;

    // Update Card Active Classes
    $('.metric-selection-card').removeClass('active-card');
    $(`.metric-selection-card[data-tab="${tab}"]`).addClass('active-card');

    // Update Pill Buttons
    $('.tab-pill-btn').removeClass('active btn-primary btn-success btn-warning btn-danger');
    let activePill = $(`.tab-pill-btn[data-tab="${tab}"]`);
    activePill.addClass('active');
    if (tab === 'total') activePill.addClass('btn-primary');
    else if (tab === 'collected') activePill.addClass('btn-success');
    else if (tab === 'remaining') activePill.addClass('btn-warning');
    else if (tab === 'pending') activePill.addClass('btn-danger');

    // Update Section Title & Description
    if (sectionInfo[tab]) {
        $('#selectedSectionTitle').html(sectionInfo[tab].title);
        $('#selectedSectionCount').text(sectionInfo[tab].count);
        $('#selectedSectionDesc').text(sectionInfo[tab].desc);
    }

    // Redraw table with filtered category
    if (dataTableInstance) {
        dataTableInstance.draw();
    }

    // Update URL parameter without reload
    if (updateUrl && history.pushState) {
        let url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.pushState({path: url.href}, '', url.href);
    }
}

function changeDealer(dealerId) {
    let url = new URL(window.location.href);
    url.searchParams.set('dealer', dealerId);
    window.location.href = url.href;
}
</script>

<?php require_once 'footer.php'; ?>
