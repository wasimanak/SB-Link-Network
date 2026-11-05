<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];
$operator_name = $_SESSION['operator_username'] ?? 'Operator';

if (!isset($_GET['id'])) {
    echo "<script>window.location='recovery_man.php';</script>";
    exit;
}
$rm_id = (int)$_GET['id'];

// Fetch RM details
$stmt = $pdo->prepare("SELECT * FROM recovery_men WHERE id = ? AND client_id = ?");
$stmt->execute([$rm_id, $client_id]);
$rm = $stmt->fetch();

if (!$rm) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Operator not found.</div></div>";
    exit;
}

// Handle Cash Collection from RM
$can_collect_recovery = can_collect_recovery();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_cash') {
    if (!$can_collect_recovery) {
        echo "<script>alert('Permission Denied: You do not have permission from the Admin to collect cash from Operators.'); window.location='recoveryman_profile.php?id=$rm_id';</script>";
        exit;
    }
    $amount = (float)$_POST['amount'];
    
    if ($amount > 0 && $amount <= $rm['cash_in_hand']) {
        try {
            $pdo->beginTransaction();
            
            // Deduct cash from RM
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand - ? WHERE id = ?")->execute([$amount, $rm_id]);
            
            $is_mgr = !empty($_SESSION['is_manager']);
            $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
            $collector_name = $is_mgr ? ($_SESSION['manager_name'] ?? 'Manager') : $operator_name;
            $collector_role = $is_mgr ? 'Manager' : 'Admin';

            // If a Manager collected the cash from RM, add it to Manager's cash_in_hand
            if ($is_mgr && $mgr_id > 0) {
                try {
                    $pdo->prepare("UPDATE managers SET cash_in_hand = cash_in_hand + ? WHERE id = ? AND client_id = ?")
                        ->execute([$amount, $mgr_id, $client_id]);
                } catch (Exception $e) {}
            }

            // Log the activity
            $log_msg = "Collected Cash Rs. " . number_format($amount, 0) . " from Operator";
            $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'RecoveryMan', ?)")
                ->execute([$client_id, $mgr_id, $collector_name, $collector_role, $rm['full_name'], $log_msg]);
                
            $pdo->commit();
            echo "<script>alert('Cash collected successfully!'); window.location='recoveryman_profile.php?id=$rm_id';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error collecting cash.');</script>";
        }
    } else {
        echo "<script>alert('Invalid amount. Must be greater than 0 and not exceed Cash in Hand.');</script>";
    }
}

// Date Range Filter (From Date - To Date)
$from_date = !empty($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date = !empty($_GET['to_date']) ? trim($_GET['to_date']) : '';

if (!empty($from_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
    $from_date = '';
}
if (!empty($to_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
    $to_date = '';
}

$from_sql = !empty($from_date) ? "{$from_date} 00:00:00" : null;
$to_sql = !empty($to_date) ? "{$to_date} 23:59:59" : null;
$has_date_filter = (!empty($from_sql) || !empty($to_sql));

// Fetch User Collections by this RM
$rm_ledger_sql = "
    SELECT l.*, s.id as sub_id, s.full_name as sub_fullname, s.balance as sub_balance, s.mobile as sub_mobile
    FROM user_ledger l
    LEFT JOIN subscribers s ON l.username = s.username COLLATE utf8mb4_general_ci AND s.client_id = l.client_id
    WHERE l.client_id = ? AND (
        l.description LIKE ? 
        OR l.description LIKE ? 
        OR l.description LIKE ? 
        OR l.description LIKE ?
    )
";
$rm_ledger_params = [
    $client_id, 
    "Cash collected by {$rm['full_name']}%",
    "Payment collected by {$rm['full_name']}%",
    "Initial Payment Collected by RM: {$rm['username']}%",
    "%RM: {$rm['username']}%"
];
if (!empty($from_sql)) {
    $rm_ledger_sql .= " AND l.created_at >= ? ";
    $rm_ledger_params[] = $from_sql;
}
if (!empty($to_sql)) {
    $rm_ledger_sql .= " AND l.created_at <= ? ";
    $rm_ledger_params[] = $to_sql;
}
$rm_ledger_sql .= " ORDER BY l.created_at DESC ";
if (!$has_date_filter) {
    $rm_ledger_sql .= " LIMIT 200 ";
}
$userLedgerStmt = $pdo->prepare($rm_ledger_sql);
$userLedgerStmt->execute($rm_ledger_params);
$user_collections = $userLedgerStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Activities (Renewals, Registrations, Logs)
$rm_act_sql = "
    SELECT 
        a.*,
        s.id as sub_id,
        s.full_name as sub_fullname,
        s.mobile as sub_mobile,
        s.phone as sub_phone,
        s.balance as sub_balance,
        s.status as sub_status,
        s.expiry_date as sub_expiry,
        p.name as package_name,
        p.price as package_price
    FROM activity_logs a
    LEFT JOIN subscribers s ON a.against_to = s.username COLLATE utf8mb4_general_ci AND s.client_id = a.client_id
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE a.client_id = ? 
      AND a.by_role = 'RecoveryMan' 
      AND (a.by_user = ? OR a.by_user = ? OR a.by_user = ?)
";
$rm_act_params = [
    $client_id, 
    $rm['full_name'], 
    'RM: ' . $rm['username'], 
    $rm['username']
];
if (!empty($from_sql)) {
    $rm_act_sql .= " AND a.created_at >= ? ";
    $rm_act_params[] = $from_sql;
}
if (!empty($to_sql)) {
    $rm_act_sql .= " AND a.created_at <= ? ";
    $rm_act_params[] = $to_sql;
}
$rm_act_sql .= " ORDER BY a.created_at DESC ";
if (!$has_date_filter) {
    $rm_act_sql .= " LIMIT 250 ";
}
$activityStmt = $pdo->prepare($rm_act_sql);
$activityStmt->execute($rm_act_params);
$raw_activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

// Build Unified All Activities List
$all_activities = [];
$total_renewals_count = 0;
$total_creations_count = 0;
$total_collected_in_activities = 0;

foreach ($raw_activities as $act) {
    $item = [
        'created_at' => $act['created_at'],
        'username' => $act['against_to'],
        'sub_id' => $act['sub_id'],
        'full_name' => $act['sub_fullname'] ?: $act['against_to'],
        'mobile' => $act['sub_mobile'] ?: $act['sub_phone'],
        'package_name' => $act['package_name'],
        'current_balance' => (float)($act['sub_balance'] ?? 0),
        'status' => $act['sub_status'] ?? '',
        'expiry_date' => $act['sub_expiry'] ?? '',
        'activity_text' => $act['activity'],
        'type' => 'other',
        'charged_amount' => 0,
        'collected_amount' => 0,
    ];

    if (stripos($act['activity'], 'Renewed Package') !== false) {
        $item['type'] = 'renewal';
        $total_renewals_count++;
    } elseif (stripos($act['activity'], 'Created New User') !== false) {
        $item['type'] = 'create_user';
        $total_creations_count++;
    }

    // Extract Charged
    if (preg_match('/Charged(?:: Rs\.| Rs\.)\s*([\d,.]+)/i', $act['activity'], $m)) {
        $item['charged_amount'] = (float)str_replace(',', '', $m[1]);
    }
    // Extract Collected
    if (preg_match('/Collected(?:: Rs\.| Rs\.)\s*([\d,.]+)/i', $act['activity'], $m)) {
        $item['collected_amount'] = (float)str_replace(',', '', $m[1]);
        $total_collected_in_activities += $item['collected_amount'];
    }

    $all_activities[] = $item;
}

// Also integrate pure collections from user_ledger if not timestamp-matched with renewals
foreach ($user_collections as $led) {
    $already_covered = false;
    $led_time = strtotime($led['created_at']);
    foreach ($raw_activities as $act) {
        if ($act['against_to'] === $led['username']) {
            $act_time = strtotime($act['created_at']);
            if (abs($led_time - $act_time) <= 15) {
                $already_covered = true;
                break;
            }
        }
    }

    if (!$already_covered) {
        $all_activities[] = [
            'created_at' => $led['created_at'],
            'username' => $led['username'],
            'sub_id' => $led['sub_id'],
            'full_name' => $led['sub_fullname'] ?: $led['username'],
            'mobile' => $led['sub_mobile'] ?? '',
            'package_name' => 'N/A',
            'current_balance' => (float)($led['sub_balance'] ?? 0),
            'status' => '',
            'expiry_date' => '',
            'activity_text' => $led['description'],
            'type' => 'collection',
            'charged_amount' => 0,
            'collected_amount' => (float)$led['amount'],
        ];
        $total_collected_in_activities += (float)$led['amount'];
    }
}

// Sort newest first
usort($all_activities, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Fetch Cash Submitted to Operator
$rm_sub_sql = "
    SELECT * FROM activity_logs 
    WHERE client_id = ? AND against_role = 'RecoveryMan' AND (against_to = ? OR against_to = ?) AND activity LIKE 'Collected Cash Rs.%'
";
$rm_sub_params = [
    $client_id, 
    $rm['full_name'],
    $rm['username']
];
if (!empty($from_sql)) {
    $rm_sub_sql .= " AND created_at >= ? ";
    $rm_sub_params[] = $from_sql;
}
if (!empty($to_sql)) {
    $rm_sub_sql .= " AND created_at <= ? ";
    $rm_sub_params[] = $to_sql;
}
$rm_sub_sql .= " ORDER BY created_at DESC ";
if (!$has_date_filter) {
    $rm_sub_sql .= " LIMIT 100 ";
}
$submissionStmt = $pdo->prepare($rm_sub_sql);
$submissionStmt->execute($rm_sub_params);
$submissions = $submissionStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Operator Branding Info for Printable Statement
$client_info = [];
try {
    $cStmt = $pdo->prepare("SELECT name, company_name, phone, email, address, city FROM clients WHERE id = ? LIMIT 1");
    $cStmt->execute([$client_id]);
    $client_info = $cStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $eC) {}
$company_display_name = !empty($client_info['company_name']) ? $client_info['company_name'] : (!empty($client_info['name']) ? $client_info['name'] : (defined('BRAND_NAME') ? BRAND_NAME : 'Internet Network'));

// ==========================================
// 4. PREPARE BANK-STYLE STATEMENT DATA
// ==========================================
$opening_balance = 0.0;
if ($has_date_filter && !empty($from_sql)) {
    // A. Prior collections before from_sql
    try {
        $prior_col_stmt = $pdo->prepare("
            SELECT COALESCE(SUM(l.amount), 0)
            FROM user_ledger l
            WHERE l.client_id = ? AND (
                l.description LIKE ? 
                OR l.description LIKE ? 
                OR l.description LIKE ? 
                OR l.description LIKE ?
                OR l.description LIKE ?
                OR l.description LIKE ?
                OR l.description LIKE ?
            )
            AND l.created_at < ?
        ");
        $prior_col_stmt->execute([
            $client_id,
            "Cash collected by {$rm['full_name']}%",
            "Payment collected by {$rm['full_name']}%",
            "Initial Payment Collected by RM: {$rm['username']}%",
            "%RM: {$rm['username']}%",
            "%RM: {$rm['full_name']}%",
            "%by {$rm['full_name']} RM%",
            "%by {$rm['username']} RM%",
            $from_sql
        ]);
        $prior_col_amt = (float)$prior_col_stmt->fetchColumn();
    } catch (Exception $ePC) {
        $prior_col_amt = 0.0;
    }

    // B. Prior submissions before from_sql
    try {
        $prior_sub_stmt = $pdo->prepare("
            SELECT activity FROM activity_logs 
            WHERE client_id = ? AND against_role = 'RecoveryMan' 
              AND (against_to = ? OR against_to = ?) 
              AND activity LIKE 'Collected Cash Rs.%'
              AND created_at < ?
        ");
        $prior_sub_stmt->execute([$client_id, $rm['full_name'], $rm['username'], $from_sql]);
        $prior_sub_rows = $prior_sub_stmt->fetchAll(PDO::FETCH_ASSOC);
        $prior_sub_amt = 0.0;
        foreach ($prior_sub_rows as $psr) {
            if (preg_match('/Rs\.\s*([\d,.]+)/i', $psr['activity'], $pm)) {
                $prior_sub_amt += (float)str_replace(',', '', $pm[1]);
            }
        }
    } catch (Exception $ePS) {
        $prior_sub_amt = 0.0;
    }

    $opening_balance = round($prior_col_amt - $prior_sub_amt, 2);
}

// Fetch Collections for Statement
$stmt_col_sql = "
    SELECT l.*, s.id as sub_id, s.full_name as sub_fullname, s.mobile as sub_mobile
    FROM user_ledger l
    LEFT JOIN subscribers s ON l.username = s.username COLLATE utf8mb4_general_ci AND s.client_id = l.client_id
    WHERE l.client_id = ? AND (
        l.description LIKE ? 
        OR l.description LIKE ? 
        OR l.description LIKE ? 
        OR l.description LIKE ?
        OR l.description LIKE ?
        OR l.description LIKE ?
        OR l.description LIKE ?
    )
";
$stmt_col_params = [
    $client_id, 
    "Cash collected by {$rm['full_name']}%",
    "Payment collected by {$rm['full_name']}%",
    "Initial Payment Collected by RM: {$rm['username']}%",
    "%RM: {$rm['username']}%",
    "%RM: {$rm['full_name']}%",
    "%by {$rm['full_name']} RM%",
    "%by {$rm['username']} RM%"
];
if (!empty($from_sql)) {
    $stmt_col_sql .= " AND l.created_at >= ? ";
    $stmt_col_params[] = $from_sql;
}
if (!empty($to_sql)) {
    $stmt_col_sql .= " AND l.created_at <= ? ";
    $stmt_col_params[] = $to_sql;
}
$stmt_col_sql .= " ORDER BY l.created_at ASC ";
if (!$has_date_filter) {
    $stmt_col_sql .= " LIMIT 1500 ";
}
$stmtColStmt = $pdo->prepare($stmt_col_sql);
$stmtColStmt->execute($stmt_col_params);
$raw_stmt_collections = $stmtColStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Submissions for Statement
$stmt_sub_sql = "
    SELECT * FROM activity_logs 
    WHERE client_id = ? AND against_role = 'RecoveryMan' AND (against_to = ? OR against_to = ?) AND activity LIKE 'Collected Cash Rs.%'
";
$stmt_sub_params = [
    $client_id, 
    $rm['full_name'],
    $rm['username']
];
if (!empty($from_sql)) {
    $stmt_sub_sql .= " AND created_at >= ? ";
    $stmt_sub_params[] = $from_sql;
}
if (!empty($to_sql)) {
    $stmt_sub_sql .= " AND created_at <= ? ";
    $stmt_sub_params[] = $to_sql;
}
$stmt_sub_sql .= " ORDER BY created_at ASC ";
if (!$has_date_filter) {
    $stmt_sub_sql .= " LIMIT 500 ";
}
$stmtSubStmt = $pdo->prepare($stmt_sub_sql);
$stmtSubStmt->execute($stmt_sub_params);
$raw_stmt_submissions = $stmtSubStmt->fetchAll(PDO::FETCH_ASSOC);

// Combine into unified chronologically sorted timeline
$statement_entries = [];

foreach ($raw_stmt_collections as $col) {
    $statement_entries[] = [
        'id' => 'col_' . $col['id'],
        'created_at' => $col['created_at'],
        'timestamp' => strtotime($col['created_at']),
        'type' => 'credit',
        'type_label' => 'Collection',
        'party_username' => $col['username'] ?? 'User',
        'party_name' => !empty($col['sub_fullname']) ? $col['sub_fullname'] : ($col['username'] ?? ''),
        'party_role' => 'Subscriber',
        'sub_id' => $col['sub_id'] ?? null,
        'sub_mobile' => $col['sub_mobile'] ?? '',
        'remarks' => $col['description'] ?? '',
        'credit' => (float)$col['amount'],
        'debit' => 0.0,
    ];
}

foreach ($raw_stmt_submissions as $sub) {
    $sub_amt = 0.0;
    if (preg_match('/Rs\.\s*([\d,.]+)/i', $sub['activity'], $m)) {
        $sub_amt = (float)str_replace(',', '', $m[1]);
    }
    if ($sub_amt > 0) {
        $statement_entries[] = [
            'id' => 'sub_' . $sub['id'],
            'created_at' => $sub['created_at'],
            'timestamp' => strtotime($sub['created_at']),
            'type' => 'debit',
            'type_label' => 'Submission',
            'party_username' => $sub['by_user'] ?? 'Admin',
            'party_name' => $sub['by_user'] ?? 'Admin',
            'party_role' => !empty($sub['by_role']) ? $sub['by_role'] : 'Admin',
            'sub_id' => null,
            'sub_mobile' => '',
            'remarks' => $sub['activity'] ?? '',
            'credit' => 0.0,
            'debit' => $sub_amt,
        ];
    }
}

// Sort Ascending (Oldest First) to compute running balance
usort($statement_entries, function($a, $b) {
    if ($a['timestamp'] === $b['timestamp']) {
        return ($a['type'] === 'credit') ? -1 : 1;
    }
    return $a['timestamp'] - $b['timestamp'];
});

// Compute Running Balance row by row
$curr_run_bal = $opening_balance;
$total_period_credit = 0.0;
$total_period_debit = 0.0;
$statement_credit_count = 0;
$statement_debit_count = 0;

foreach ($statement_entries as &$st) {
    if ($st['type'] === 'credit') {
        $total_period_credit += $st['credit'];
        $statement_credit_count++;
    } else {
        $total_period_debit += $st['debit'];
        $statement_debit_count++;
    }
    $curr_run_bal = round($curr_run_bal + $st['credit'] - $st['debit'], 2);
    $st['running_balance'] = $curr_run_bal;
}
unset($st);

$closing_balance = $curr_run_bal;

// Reverse array so newest transactions appear on top in the table
$statement_entries_desc = array_reverse($statement_entries);



?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<style>
    .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
    .nav-tabs .nav-link { font-weight: 600; color: #64748b; border: none; border-bottom: 3px solid transparent; padding: 12px 20px; }
    .nav-tabs .nav-link:hover { color: #0f172a; }
    .nav-tabs .nav-link.active { color: #3b82f6; border-bottom-color: #3b82f6; background: transparent; }
    .table-custom-ui th { background-color: #f8fafc !important; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.78rem; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; }
    .table-custom-ui td { vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: #475569; font-size: 0.9rem; }
    .table-custom-ui tbody tr:hover { background-color: #f8fafc; }
    @media print {
        body * { visibility: hidden !important; }
        #printableRecoveryStatement, #printableRecoveryStatement * { visibility: visible !important; }
        #printableRecoveryStatement {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 15px !important;
            display: block !important;
            background: #ffffff !important;
            color: #000000 !important;
        }
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="recovery_man.php" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i> Back to List</a>
        <h4 class="mb-0 fw-bold mt-1"><i class="fa-solid fa-user-circle text-primary me-2"></i> <?= htmlspecialchars($rm['full_name']) ?>'s Profile</h4>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Profile Info -->
    <div class="col-md-4">
        <div class="card card-custom h-100 bg-white">
            <div class="card-body p-4 text-center">
                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                    <i class="fa-solid fa-motorcycle fs-1"></i>
                </div>
                <h5 class="fw-bold mb-1"><?= htmlspecialchars($rm['full_name']) ?></h5>
                <span class="badge bg-secondary mb-3">@<?= htmlspecialchars($rm['username']) ?></span>
                
                <div class="text-start mt-4 border-top pt-3">
                    <div class="mb-2"><i class="fa-solid fa-phone text-muted me-2 w-15px"></i> <?= htmlspecialchars($rm['phone'] ?: 'N/A') ?></div>
                    <div class="mb-2"><i class="fa-solid fa-city text-muted me-2 w-15px"></i> <?= htmlspecialchars($rm['city'] ?: 'N/A') ?></div>
                    <div class="mb-2"><i class="fa-solid fa-location-dot text-muted me-2 w-15px"></i> <?= htmlspecialchars($rm['address'] ?: 'N/A') ?></div>
                    <div class="mb-2"><i class="fa-solid fa-calendar text-muted me-2 w-15px"></i> Joined <?= date('d M Y', strtotime($rm['created_at'])) ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Balance & Collect -->
    <div class="col-md-8">
        <div class="card card-custom h-100 <?= ($rm['cash_in_hand'] > 0) ? 'bg-success bg-opacity-10 border border-success border-opacity-25' : 'bg-light' ?>">
            <div class="card-body p-4 d-flex flex-column justify-content-center align-items-center text-center">
                <h6 class="fw-bold text-uppercase text-muted tracking-wider mb-2">Pending Cash in Hand</h6>
                <h1 class="display-3 fw-bold <?= ($rm['cash_in_hand'] > 0) ? 'text-success' : 'text-dark' ?> mb-4">
                    Rs. <?= number_format($rm['cash_in_hand'], 0) ?>
                </h1>
                
                <?php if($rm['cash_in_hand'] > 0): ?>
                    <?php if ($can_collect_recovery): ?>
                        <button class="btn btn-success btn-lg rounded-pill px-5 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#collectModal">
                            <i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Cash
                        </button>
                    <?php else: ?>
                        <div class="badge bg-secondary-subtle text-secondary border p-2 px-3 rounded-pill"><i class="fa-solid fa-lock me-1"></i> Cash Collection Restricted (Admin Only)</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="badge bg-secondary p-2 px-3 rounded-pill"><i class="fa-solid fa-check-circle me-1"></i> Accounts Cleared</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

    <!-- Salary & Lifetime Advance Ledger Section -->
    <div class="mb-4">
        <?php
        $staff_type = 'recoveryman';
        $staff_id = (int)$rm['id'];
        $staff_name = $rm['full_name'];
        $monthly_sal = (int)($rm['monthly_salary'] ?? 0);
        $joined_date = $rm['created_at'];
        require __DIR__ . '/staff_salary_view.php';
        ?>
    </div>

<!-- Date Range Filter Card -->
<div class="card card-custom bg-white mb-4 shadow-sm rounded-4">
    <div class="card-body p-3 p-md-4">
        <form method="GET" class="row g-2 align-items-end" id="rmDateFilterForm">
            <input type="hidden" name="id" value="<?= $rm_id ?>">
            <input type="hidden" name="tab" id="rm_filter_active_tab" value="<?= htmlspecialchars($_GET['tab'] ?? '') ?>">
            
            <div class="col-12 col-md-auto mb-1 mb-md-0 d-flex align-items-center">
                <span class="fw-bold text-dark fs-6 me-2">
                    <i class="fa-solid fa-calendar-days text-primary me-1"></i> Date Filter:
                </span>
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label text-secondary small fw-semibold mb-1">From Date</label>
                <input type="date" name="from_date" id="rm_filter_from_date" class="form-control form-control-sm" value="<?= htmlspecialchars($from_date) ?>" max="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label text-secondary small fw-semibold mb-1">To Date</label>
                <input type="date" name="to_date" id="rm_filter_to_date" class="form-control form-control-sm" value="<?= htmlspecialchars($to_date) ?>" max="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm fw-semibold">
                    <i class="fa-solid fa-filter me-1"></i> Apply Filter
                </button>
                <?php if ($has_date_filter): ?>
                    <a href="?id=<?= $rm_id ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                        <i class="fa-solid fa-rotate-left me-1"></i> Reset
                    </a>
                <?php endif; ?>
            </div>

            <!-- Quick Presets -->
            <div class="col-12 mt-2 pt-2 border-top d-flex flex-wrap align-items-center gap-1">
                <span class="text-muted small me-2"><i class="fa-solid fa-bolt text-warning me-1"></i> Quick Presets:</span>
                <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 text-secondary" style="font-size: 11px;" onclick="applyRmDatePreset('today')">Today</button>
                <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 text-secondary" style="font-size: 11px;" onclick="applyRmDatePreset('yesterday')">Yesterday</button>
                <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 text-secondary" style="font-size: 11px;" onclick="applyRmDatePreset('last7')">Last 7 Days</button>
                <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 text-secondary" style="font-size: 11px;" onclick="applyRmDatePreset('this_month')">This Month</button>
                <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 text-secondary" style="font-size: 11px;" onclick="applyRmDatePreset('last_month')">Last Month</button>

                <?php if ($has_date_filter): ?>
                    <div class="ms-auto d-flex align-items-center">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1 small">
                            <i class="fa-solid fa-circle-check me-1"></i> Active Range: <?= !empty($from_date) ? date('d M Y', strtotime($from_date)) : 'Start' ?> &rarr; <?= !empty($to_date) ? date('d M Y', strtotime($to_date)) : 'Today' ?>
                        </span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1 small ms-1">
                            Collected: Rs. <?= number_format($total_collected_in_activities, 0) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
function applyRmDatePreset(preset) {
    const fromInput = document.getElementById('rm_filter_from_date');
    const toInput = document.getElementById('rm_filter_to_date');
    if (!fromInput || !toInput) return;

    const today = new Date();
    const formatDate = (d) => {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    };

    if (preset === 'today') {
        fromInput.value = formatDate(today);
        toInput.value = formatDate(today);
    } else if (preset === 'yesterday') {
        const yest = new Date();
        yest.setDate(yest.getDate() - 1);
        fromInput.value = formatDate(yest);
        toInput.value = formatDate(yest);
    } else if (preset === 'last7') {
        const past7 = new Date();
        past7.setDate(past7.getDate() - 7);
        fromInput.value = formatDate(past7);
        toInput.value = formatDate(today);
    } else if (preset === 'this_month') {
        const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        fromInput.value = formatDate(firstDay);
        toInput.value = formatDate(today);
    } else if (preset === 'last_month') {
        const firstDay = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        const lastDay = new Date(today.getFullYear(), today.getMonth(), 0);
        fromInput.value = formatDate(firstDay);
        toInput.value = formatDate(lastDay);
    }
    document.getElementById('rmDateFilterForm').submit();
}
</script>

<!-- Statement Tabs -->
<div class="card card-custom bg-white">
    <div class="card-header bg-transparent border-bottom-0 p-0 pt-2 px-3">
        <ul class="nav nav-tabs border-bottom" id="statementTabs" role="tablist">
            <!-- Tab on Left Side: All Operator Activities & Renewals -->
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="activities-tab" data-bs-toggle="tab" data-bs-target="#activities" type="button" role="tab">
                    <i class="fa-solid fa-bolt me-1 text-warning"></i> Operator Activities & Renewals
                    <span class="badge bg-primary ms-1"><?= count($all_activities) ?></span>
                </button>
            </li>
            <!-- Existing Tab: Collections from Users -->
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">
                    <i class="fa-solid fa-money-bill-wave me-1 text-success"></i> Collections from Users
                    <span class="badge bg-secondary ms-1"><?= count($user_collections) ?></span>
                </button>
            </li>
            <!-- Existing Tab: Submissions to Admin -->
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="operator-tab" data-bs-toggle="tab" data-bs-target="#operator" type="button" role="tab">
                    <i class="fa-solid fa-building-columns me-1 text-danger"></i> Submissions to Admin
                    <span class="badge bg-secondary ms-1"><?= count($submissions) ?></span>
                </button>
            </li>
        
            <!-- New Tab: Statement (Bank-style Ledger) -->
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="statement-tab" data-bs-toggle="tab" data-bs-target="#statement" type="button" role="tab">
                    <i class="fa-solid fa-file-invoice-dollar me-1 text-primary"></i> Statement
                    <span class="badge bg-primary ms-1"><?= count($statement_entries) ?></span>
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="tab-content p-4" id="statementTabsContent">
            
            <!-- Tab 1 (Leftmost): All Activities & Renewals -->
            <div class="tab-pane fade show active" id="activities" role="tabpanel">
                
                <!-- Quick Mini Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light border rounded text-center">
                            <div class="text-muted small fw-bold text-uppercase">Renewals Done</div>
                            <h4 class="mb-0 fw-bold text-primary mt-1"><?= number_format($total_renewals_count) ?></h4>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light border rounded text-center">
                            <div class="text-muted small fw-bold text-uppercase">New Users</div>
                            <h4 class="mb-0 fw-bold text-info mt-1"><?= number_format($total_creations_count) ?></h4>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light border rounded text-center">
                            <div class="text-muted small fw-bold text-uppercase">Total Activities</div>
                            <h4 class="mb-0 fw-bold text-dark mt-1"><?= number_format(count($all_activities)) ?></h4>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-success bg-opacity-10 border border-success border-opacity-25 rounded text-center">
                            <div class="text-success small fw-bold text-uppercase">Total Recovered</div>
                            <h4 class="mb-0 fw-bold text-success mt-1">Rs. <?= number_format($total_collected_in_activities) ?></h4>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="activitiesTable" class="table table-hover table-custom-ui table-borderless align-middle w-100">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Subscriber</th>
                                <th>Activity Type</th>
                                <th>Package</th>
                                <th>Fee Charged</th>
                                <th>Amount Collected</th>
                                <th>Pending Dues (Balance)</th>
                                <th>Activity Note</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($all_activities as $act): 
                                $bal = $act['current_balance'];
                            ?>
                            <tr>
                                <td class="text-secondary small fw-bold" style="white-space: nowrap;">
                                    <?= date('d M Y, h:i A', strtotime($act['created_at'])) ?>
                                </td>
                                <td>
                                    <?php if ($act['sub_id']): ?>
                                        <a href="subscriber_view.php?id=<?= $act['sub_id'] ?>" class="fw-bold text-primary text-decoration-none">
                                            <?= htmlspecialchars($act['username']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="fw-bold text-primary"><?= htmlspecialchars($act['username']) ?></span>
                                    <?php endif; ?>
                                    <div class="small text-muted"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($act['full_name']) ?></div>
                                    <?php if(!empty($act['mobile'])): ?>
                                        <div class="small text-muted font-monospace"><i class="fa-solid fa-phone me-1 text-success"></i> <?= htmlspecialchars($act['mobile']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($act['type'] === 'renewal'): ?>
                                        <span class="badge bg-primary px-2 py-1"><i class="fa-solid fa-rotate me-1"></i> Package Renewed</span>
                                    <?php elseif ($act['type'] === 'create_user'): ?>
                                        <span class="badge bg-info text-dark px-2 py-1"><i class="fa-solid fa-user-plus me-1"></i> New User</span>
                                    <?php elseif ($act['type'] === 'collection'): ?>
                                        <span class="badge bg-success px-2 py-1"><i class="fa-solid fa-hand-holding-dollar me-1"></i> Payment Received</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary px-2 py-1"><i class="fa-solid fa-circle-info me-1"></i> Other Action</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($act['package_name']) && $act['package_name'] !== 'N/A'): ?>
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($act['package_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($act['charged_amount'] > 0): ?>
                                        <span class="fw-bold text-dark">Rs. <?= number_format($act['charged_amount']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($act['collected_amount'] > 0): ?>
                                        <span class="text-success fw-bold fs-6">+ Rs. <?= number_format($act['collected_amount']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted fw-bold">Rs. 0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($bal < 0): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold fs-6 px-2 py-1">
                                            <i class="fa-solid fa-triangle-exclamation me-1"></i> - Rs. <?= number_format(abs($bal)) ?>
                                        </span>
                                    <?php elseif ($bal > 0): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold px-2 py-1">
                                            <i class="fa-solid fa-check me-1"></i> + Rs. <?= number_format($bal) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border fw-bold px-2 py-1">
                                            Rs. 0 (Cleared)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted" style="max-width: 260px;">
                                    <?= htmlspecialchars($act['activity_text']) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($act['sub_id']): ?>
                                        <a href="subscriber_view.php?id=<?= $act['sub_id'] ?>" class="btn btn-xs btn-outline-primary fw-bold" title="View Subscriber Profile">
                                            <i class="fa-solid fa-eye me-1"></i> View
                                        </a>
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

            <!-- Tab 2: User Collections -->
            <div class="tab-pane fade" id="users" role="tabpanel">
                <div class="table-responsive">
                    <table id="collectionsTable" class="table table-hover table-custom-ui table-borderless align-middle w-100">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Subscriber Username</th>
                                <th>Amount Collected</th>
                                <th>Subscriber Balance</th>
                                <th>Details</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($user_collections as $uc): ?>
                            <tr>
                                <td class="text-secondary small fw-bold"><?= date('d M Y, h:i A', strtotime($uc['created_at'])) ?></td>
                                <td>
                                    <?php if (!empty($uc['sub_id'])): ?>
                                        <a href="subscriber_view.php?id=<?= $uc['sub_id'] ?>" class="fw-bold text-primary text-decoration-none">
                                            <?= htmlspecialchars($uc['username']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-primary"><?= htmlspecialchars($uc['username']) ?></span>
                                    <?php endif; ?>
                                    <?php if(!empty($uc['sub_fullname'])): ?>
                                        <div class="small text-muted"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($uc['sub_fullname']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-success fw-bold fs-6">Rs. <?= number_format($uc['amount'], 0) ?></td>
                                <td>
                                    <?php if (isset($uc['sub_balance']) && (float)$uc['sub_balance'] < 0): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold">
                                            - Rs. <?= number_format(abs((float)$uc['sub_balance'])) ?> Pending
                                        </span>
                                    <?php elseif (isset($uc['sub_balance']) && (float)$uc['sub_balance'] > 0): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">
                                            + Rs. <?= number_format((float)$uc['sub_balance']) ?> Advance
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border">Rs. 0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($uc['description']) ?></td>
                                <td class="text-center">
                                    <?php if (!empty($uc['sub_id'])): ?>
                                        <a href="subscriber_view.php?id=<?= $uc['sub_id'] ?>" class="btn btn-xs btn-outline-primary fw-bold">
                                            <i class="fa-solid fa-eye me-1"></i> View
                                        </a>
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
            
            <!-- Tab 3: Submissions to Operator -->
            <div class="tab-pane fade" id="operator" role="tabpanel">
                <div class="table-responsive">
                    <table id="submissionsTable" class="table table-hover table-custom-ui table-borderless align-middle w-100">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Collected By</th>
                                <th>Amount Submitted</th>
                                <th>Activity Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($submissions as $sub): ?>
                            <?php 
                                $amount_text = "N/A";
                                if (preg_match('/Rs\.\s*([\d,.]+)/', $sub['activity'], $m)) {
                                    $amount_text = $m[1];
                                }
                            ?>
                            <tr>
                                <td class="text-secondary small fw-bold"><?= date('d M Y, h:i A', strtotime($sub['created_at'])) ?></td>
                                <td><span class="badge bg-dark"><?= htmlspecialchars($sub['by_user']) ?> (Operator)</span></td>
                                <td class="text-danger fw-bold fs-6">Rs. <?= $amount_text ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($sub['activity']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab 4: Account Statement (Bank-style Ledger) -->
            <div class="tab-pane fade" id="statement" role="tabpanel">

                <!-- Statement Top Header Bar -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                    <div>
                        <h5 class="fw-bold mb-1 text-dark">
                            <i class="fa-solid fa-file-invoice-dollar text-primary me-2"></i> Recovery Cash Statement
                        </h5>
                        <div class="text-muted small">
                            Bank-style ledger statement showing cash collected from subscribers (Credit) and submissions to Admin (Debit)
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" onclick="printRecoveryStatement()" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm fw-bold">
                            <i class="fa-solid fa-print me-1"></i> Print Statement
                        </button>
                    </div>
                </div>

                <!-- 4 Bank Statement Summary Metric Cards -->
                <div class="row g-3 mb-4">
                    <!-- 1. Opening Balance -->
                    <div class="col-6 col-lg-3">
                        <div class="p-3 bg-light border rounded-3 text-center h-100 d-flex flex-column justify-content-center">
                            <div class="text-muted small fw-bold text-uppercase">Opening Balance (B/F)</div>
                            <h4 class="mb-0 fw-bold <?= $opening_balance >= 0 ? 'text-secondary' : 'text-danger' ?> mt-1">
                                Rs. <?= number_format($opening_balance, 0) ?>
                            </h4>
                            <div class="text-muted" style="font-size: 0.72rem;">Prior to selected date</div>
                        </div>
                    </div>

                    <!-- 2. Total Collections (Credit) -->
                    <div class="col-6 col-lg-3">
                        <div class="p-3 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 text-center h-100 d-flex flex-column justify-content-center">
                            <div class="text-success small fw-bold text-uppercase"><i class="fa-solid fa-arrow-down-left me-1"></i> Total Collections (Credit)</div>
                            <h4 class="mb-0 fw-bold text-success mt-1">
                                + Rs. <?= number_format($total_period_credit, 0) ?>
                            </h4>
                            <div class="text-muted" style="font-size: 0.72rem;"><?= number_format($statement_credit_count) ?> Receipts from users</div>
                        </div>
                    </div>

                    <!-- 3. Total Submissions (Debit) -->
                    <div class="col-6 col-lg-3">
                        <div class="p-3 bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded-3 text-center h-100 d-flex flex-column justify-content-center">
                            <div class="text-danger small fw-bold text-uppercase"><i class="fa-solid fa-arrow-up-right me-1"></i> Submitted to Admin (Debit)</div>
                            <h4 class="mb-0 fw-bold text-danger mt-1">
                                - Rs. <?= number_format($total_period_debit, 0) ?>
                            </h4>
                            <div class="text-muted" style="font-size: 0.72rem;"><?= number_format($statement_debit_count) ?> Handover transactions</div>
                        </div>
                    </div>

                    <!-- 4. Closing Balance / Cash in Hand -->
                    <div class="col-6 col-lg-3">
                        <div class="p-3 bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3 text-center h-100 d-flex flex-column justify-content-center">
                            <div class="text-primary small fw-bold text-uppercase">Closing / Net Balance</div>
                            <h4 class="mb-0 fw-bold <?= $closing_balance > 0 ? 'text-primary' : ($closing_balance < 0 ? 'text-danger' : 'text-dark') ?> mt-1">
                                Rs. <?= number_format($closing_balance, 0) ?>
                            </h4>
                            <div class="text-muted" style="font-size: 0.72rem;">Current Wallet: Rs. <?= number_format($rm['cash_in_hand'], 0) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Statement Table -->
                <div class="table-responsive">
                    <table id="statementTable" class="table table-hover table-custom-ui table-borderless align-middle w-100">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 140px;">Date &amp; Time</th>
                                <th style="width: 120px;">Type</th>
                                <th>Particulars / Description</th>
                                <th class="text-end" style="width: 130px;">Credit (Inflow)</th>
                                <th class="text-end" style="width: 130px;">Debit (Outflow)</th>
                                <th class="text-end" style="width: 140px;">Balance (PKR)</th>
                                <th class="text-center" style="width: 80px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($has_date_filter && $opening_balance != 0): ?>
                            <tr class="table-light fst-italic">
                                <td class="text-secondary small fw-bold"><?= date('d M Y, h:i A', strtotime($from_sql)) ?></td>
                                <td><span class="badge bg-secondary-subtle text-secondary border">Opening B/F</span></td>
                                <td class="fw-semibold text-muted">Opening Balance brought forward from previous transactions</td>
                                <td class="text-end text-muted">-</td>
                                <td class="text-end text-muted">-</td>
                                <td class="text-end fw-bold text-dark fs-6">Rs. <?= number_format($opening_balance, 0) ?></td>
                                <td class="text-center text-muted small">-</td>
                            </tr>
                            <?php endif; ?>

                            <?php foreach($statement_entries_desc as $st): ?>
                            <tr>
                                <td class="text-secondary small fw-bold" style="white-space: nowrap;">
                                    <?= date('d M Y, h:i A', strtotime($st['created_at'])) ?>
                                </td>
                                <td>
                                    <?php if ($st['type'] === 'credit'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                            <i class="fa-solid fa-arrow-down-left me-1"></i> Collection
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                            <i class="fa-solid fa-arrow-up-right me-1"></i> Submission
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($st['type'] === 'credit'): ?>
                                        <div class="d-flex align-items-center">
                                            <div>
                                                <div class="fw-bold text-primary">
                                                    <?php if (!empty($st['sub_id'])): ?>
                                                        <a href="subscriber_view.php?id=<?= $st['sub_id'] ?>" class="text-decoration-none text-primary">
                                                            <i class="fa-solid fa-user me-1 small"></i> <?= htmlspecialchars($st['party_username']) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <i class="fa-solid fa-user me-1 small"></i> <?= htmlspecialchars($st['party_username']) ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($st['party_name']) && $st['party_name'] !== $st['party_username']): ?>
                                                        <span class="text-dark small fw-normal ms-1">(<?= htmlspecialchars($st['party_name']) ?>)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-muted" style="font-size: 0.78rem;">
                                                    <?= htmlspecialchars($st['remarks']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div>
                                            <div class="fw-bold text-dark">
                                                <i class="fa-solid fa-building-columns me-1 text-danger small"></i>
                                                Submitted to <?= htmlspecialchars($st['party_role']) ?>:
                                                <span class="badge bg-dark"><?= htmlspecialchars($st['party_username']) ?></span>
                                            </div>
                                            <div class="small text-muted" style="font-size: 0.78rem;">
                                                <?= htmlspecialchars($st['remarks']) ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($st['credit'] > 0): ?>
                                        <span class="text-success fw-bold fs-6">+ Rs. <?= number_format($st['credit'], 0) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($st['debit'] > 0): ?>
                                        <span class="text-danger fw-bold fs-6">- Rs. <?= number_format($st['debit'], 0) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <span class="fw-bold fs-6 <?= $st['running_balance'] > 0 ? 'text-primary' : ($st['running_balance'] < 0 ? 'text-danger' : 'text-dark') ?>">
                                        Rs. <?= number_format($st['running_balance'], 0) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($st['type'] === 'credit' && !empty($st['sub_id'])): ?>
                                        <a href="subscriber_view.php?id=<?= $st['sub_id'] ?>" class="btn btn-xs btn-outline-primary fw-bold" title="View Subscriber">
                                            <i class="fa-solid fa-eye me-1"></i> View
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-secondary border"><i class="fa-solid fa-check"></i></span>
                                    <?php endif; ?>
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

<!-- Hidden Printable Recovery Statement -->
<div id="printableRecoveryStatement" class="d-none d-print-block">
    <div style="border-bottom: 2px solid #334155; padding-bottom: 12px; margin-bottom: 16px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <h2 style="margin: 0; font-weight: 800; color: #0f172a; font-size: 22px; text-transform: uppercase;">
                    <?= htmlspecialchars($company_display_name) ?>
                </h2>
                <div style="color: #64748b; font-size: 13px; margin-top: 4px; font-weight: 600;">
                    OFFICIAL RECOVERY AGENT ACCOUNT STATEMENT
                </div>
            </div>
            <div style="text-align: right; font-size: 11px; color: #475569; line-height: 1.4;">
                <div><strong>Generated At:</strong> <?= date('d M Y, h:i A') ?></div>
                <div><strong>Period:</strong> <?= $has_date_filter ? ((!empty($from_date) ? date('d M Y', strtotime($from_date)) : 'Start') . ' to ' . (!empty($to_date) ? date('d M Y', strtotime($to_date)) : 'Today')) : 'Complete History (All Time)' ?></div>
            </div>
        </div>
    </div>

    <!-- Agent Profile & Summary Grid -->
    <table style="width: 100%; margin-bottom: 16px; border-collapse: collapse; font-size: 12px;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; line-height: 1.5;">
                <div style="font-weight: 700; color: #1e293b; margin-bottom: 4px; text-transform: uppercase;">RECOVERY AGENT DETAILS</div>
                <div><strong>Name:</strong> <?= htmlspecialchars($rm['full_name']) ?> (@<?= htmlspecialchars($rm['username']) ?>)</div>
                <div><strong>Phone:</strong> <?= htmlspecialchars($rm['phone'] ?: 'N/A') ?> | <strong>City:</strong> <?= htmlspecialchars($rm['city'] ?: 'N/A') ?></div>
                <div><strong>Current Cash in Hand:</strong> <span style="font-weight: 800; color: #16a34a;">Rs. <?= number_format($rm['cash_in_hand'], 0) ?></span></div>
            </td>
            <td style="width: 50%; vertical-align: top; padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; line-height: 1.5;">
                <div style="font-weight: 700; color: #1e293b; margin-bottom: 4px; text-transform: uppercase;">STATEMENT SUMMARY</div>
                <div><strong>Opening Balance:</strong> Rs. <?= number_format($opening_balance, 0) ?></div>
                <div><strong>Total Collections (Credit):</strong> <span style="color: #16a34a; font-weight: 700;">+ Rs. <?= number_format($total_period_credit, 0) ?></span> (<?= $statement_credit_count ?> receipts)</div>
                <div><strong>Total Submissions (Debit):</strong> <span style="color: #dc2626; font-weight: 700;">- Rs. <?= number_format($total_period_debit, 0) ?></span> (<?= $statement_debit_count ?> handovers)</div>
                <div><strong>Closing Net Balance:</strong> <span style="font-weight: 800; color: #2563eb;">Rs. <?= number_format($closing_balance, 0) ?></span></div>
            </td>
        </tr>
    </table>

    <!-- Printable Statement Table -->
    <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-bottom: 24px;">
        <thead>
            <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1;">
                <th style="padding: 6px 8px; text-align: left; border: 1px solid #e2e8f0;">Date &amp; Time</th>
                <th style="padding: 6px 8px; text-align: left; border: 1px solid #e2e8f0;">Type</th>
                <th style="padding: 6px 8px; text-align: left; border: 1px solid #e2e8f0;">Particulars / Description</th>
                <th style="padding: 6px 8px; text-align: right; border: 1px solid #e2e8f0;">Credit (+PKR)</th>
                <th style="padding: 6px 8px; text-align: right; border: 1px solid #e2e8f0;">Debit (-PKR)</th>
                <th style="padding: 6px 8px; text-align: right; border: 1px solid #e2e8f0;">Balance (PKR)</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($has_date_filter && $opening_balance != 0): ?>
            <tr style="background: #fafafa; font-style: italic;">
                <td style="padding: 6px 8px; border: 1px solid #e2e8f0;"><?= date('d M Y, h:i A', strtotime($from_sql)) ?></td>
                <td style="padding: 6px 8px; border: 1px solid #e2e8f0;">Opening B/F</td>
                <td style="padding: 6px 8px; border: 1px solid #e2e8f0;">Opening balance brought forward</td>
                <td style="padding: 6px 8px; text-align: right; border: 1px solid #e2e8f0;">-</td>
                <td style="padding: 6px 8px; text-align: right; border: 1px solid #e2e8f0;">-</td>
                <td style="padding: 6px 8px; text-align: right; font-weight: 700; border: 1px solid #e2e8f0;">Rs. <?= number_format($opening_balance, 0) ?></td>
            </tr>
            <?php endif; ?>
            <?php foreach($statement_entries as $pEntry): ?>
            <tr>
                <td style="padding: 5px 8px; border: 1px solid #e2e8f0; white-space: nowrap;"><?= date('d M Y, h:i A', strtotime($pEntry['created_at'])) ?></td>
                <td style="padding: 5px 8px; border: 1px solid #e2e8f0;"><?= $pEntry['type'] === 'credit' ? 'Collection' : 'Submission' ?></td>
                <td style="padding: 5px 8px; border: 1px solid #e2e8f0;">
                    <?php if ($pEntry['type'] === 'credit'): ?>
                        <strong>User: <?= htmlspecialchars($pEntry['party_username']) ?></strong> <?= !empty($pEntry['party_name']) ? '('.htmlspecialchars($pEntry['party_name']).')' : '' ?>
                        <div style="font-size: 10px; color: #64748b;"><?= htmlspecialchars($pEntry['remarks']) ?></div>
                    <?php else: ?>
                        <strong>Handover to <?= htmlspecialchars($pEntry['party_role']) ?>: <?= htmlspecialchars($pEntry['party_username']) ?></strong>
                        <div style="font-size: 10px; color: #64748b;"><?= htmlspecialchars($pEntry['remarks']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="padding: 5px 8px; text-align: right; border: 1px solid #e2e8f0; color: #16a34a; font-weight: 600;">
                    <?= $pEntry['credit'] > 0 ? '+ Rs. ' . number_format($pEntry['credit'], 0) : '-' ?>
                </td>
                <td style="padding: 5px 8px; text-align: right; border: 1px solid #e2e8f0; color: #dc2626; font-weight: 600;">
                    <?= $pEntry['debit'] > 0 ? '- Rs. ' . number_format($pEntry['debit'], 0) : '-' ?>
                </td>
                <td style="padding: 5px 8px; text-align: right; border: 1px solid #e2e8f0; font-weight: 700;">
                    Rs. <?= number_format($pEntry['running_balance'], 0) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background: #f8fafc; font-weight: 700; border-top: 2px solid #cbd5e1;">
                <td colspan="3" style="padding: 6px 8px; text-align: right; border: 1px solid #e2e8f0;">TOTAL PERIOD SUMMARY:</td>
                <td style="padding: 6px 8px; text-align: right; border: 1px solid #e2e8f0; color: #16a34a;">+ Rs. <?= number_format($total_period_credit, 0) ?></td>
                <td style="padding: 6px 8px; text-align: right; border: 1px solid #e2e8f0; color: #dc2626;">- Rs. <?= number_format($total_period_debit, 0) ?></td>
                <td style="padding: 6px 8px; text-align: right; border: 1px solid #e2e8f0; color: #2563eb;">Rs. <?= number_format($closing_balance, 0) ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Signature Footer -->
    <div style="display: flex; justify-content: space-between; margin-top: 40px; padding: 0 20px;">
        <div style="text-align: center; border-top: 1px solid #94a3b8; width: 220px; padding-top: 6px; font-size: 11px; color: #475569;">
            Recovery Agent Signature
        </div>
        <div style="text-align: center; border-top: 1px solid #94a3b8; width: 220px; padding-top: 6px; font-size: 11px; color: #475569;">
            Authorized Admin Signature
        </div>
    </div>
</div>

            
        </div>
    </div>
</div>

<?php if ($can_collect_recovery): ?>
<!-- Collect Cash Modal -->
<div class="modal fade" id="collectModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-white border-0 shadow-lg" style="border-radius: 16px;">
      <div class="modal-header border-bottom p-4 bg-light">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-hand-holding-dollar text-success me-2"></i> Collect Cash from RM</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" onsubmit="return confirm('Confirm cash collection?');">
        <input type="hidden" name="action" value="collect_cash">
        <div class="modal-body p-4 text-center">
            <h6 class="text-muted mb-1">Max Available to Collect</h6>
            <h3 class="fw-bold text-success mb-4">Rs. <?= number_format($rm['cash_in_hand'], 0) ?></h3>
            
            <div class="mb-3 text-start">
                <label class="form-label fw-bold small text-muted">Amount Received (Rs)</label>
                <input type="number" step="1" max="<?= $rm['cash_in_hand'] ?>" name="amount" class="form-control form-control-lg bg-light fw-bold" placeholder="e.g. 5000" required>
            </div>
        </div>
        <div class="modal-footer border-top p-3 bg-light">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm"><i class="fa-solid fa-check me-1"></i> Confirm Received</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {
    $('#activitiesTable').DataTable({
        "order": [[ 0, "desc" ]],
        "pageLength": 25,
        "language": {
            "search": "",
            "searchPlaceholder": "Search activities..."
        }
    });

    $('#collectionsTable').DataTable({
        "order": [[ 0, "desc" ]],
        "pageLength": 25,
        "language": {
            "search": "",
            "searchPlaceholder": "Search collections..."
        }
    });

    $('#submissionsTable').DataTable({
        "order": [[ 0, "desc" ]],
        "pageLength": 25,
        "language": {
            "search": "",
            "searchPlaceholder": "Search submissions..."
        }
    });

    // Auto adjust table layout when switching tabs
        // Statement Table with DataTables Buttons
    $('#statementTable').DataTable({
        "order": [[ 0, "desc" ]],
        "pageLength": 25,
        "dom": '<"row align-items-center mb-3"<"col-md-5"l><"col-md-7 text-md-end dt-buttons"B>>rt<"row align-items-center mt-3"<"col-md-6"i><"col-md-6"p>>',
        "buttons": [
            { extend: 'print', text: '<i class="fa-solid fa-print"></i> Print', className: 'btn btn-sm btn-outline-secondary' },
            { extend: 'excel', text: '<i class="fa-solid fa-file-excel text-success"></i> Excel', className: 'btn btn-sm btn-outline-secondary' },
            { extend: 'csv', text: '<i class="fa-solid fa-file-csv text-primary"></i> CSV', className: 'btn btn-sm btn-outline-secondary' },
            { extend: 'pdf', text: '<i class="fa-solid fa-file-pdf text-danger"></i> PDF', className: 'btn btn-sm btn-outline-secondary' },
            { extend: 'copy', text: '<i class="fa-solid fa-copy"></i> Copy', className: 'btn btn-sm btn-outline-secondary' }
        ],
        "language": {
            "search": "",
            "searchPlaceholder": "Search statement..."
        }
    });

    // Auto adjust table layout when switching tabs & persist tab
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        var targetId = $(e.target).attr('data-bs-target').replace('#', '');
        if (history.replaceState) {
            history.replaceState(null, null, '#' + targetId);
        }
        $('#rm_filter_active_tab').val(targetId);
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });

    // Handle deep-linking to tabs via ?tab= or #hash
    var urlParams = new URLSearchParams(window.location.search);
    var activeTabParam = urlParams.get('tab') || window.location.hash.replace('#', '');
    if (activeTabParam) {
        var targetTabBtn = document.getElementById(activeTabParam + '-tab');
        if (targetTabBtn) {
            var tabTrigger = new bootstrap.Tab(targetTabBtn);
            tabTrigger.show();
        }
    }
});

function printRecoveryStatement() {
    window.print();
}
</script>

<?php require_once 'footer.php'; ?>
