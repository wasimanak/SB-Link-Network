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
    echo "<div class='container mt-5'><div class='alert alert-danger'>Recovery Man not found.</div></div>";
    exit;
}

// Handle Cash Collection from RM
$can_collect_recovery = can_collect_recovery();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_cash') {
    if (!$can_collect_recovery) {
        echo "<script>alert('Permission Denied: You do not have permission from the Operator to collect cash from Recovery Men.'); window.location='recoveryman_profile.php?id=$rm_id';</script>";
        exit;
    }
    $amount = (float)$_POST['amount'];
    
    if ($amount > 0 && $amount <= $rm['cash_in_hand']) {
        try {
            $pdo->beginTransaction();
            
            // Deduct cash
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand - ? WHERE id = ?")->execute([$amount, $rm_id]);
            
            // Log the activity
            $log_msg = "Collected Cash Rs. " . number_format($amount, 0) . " from Recovery Man";
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'Operator', ?, 'RecoveryMan', ?)")
                ->execute([$client_id, $operator_name, $rm['full_name'], $log_msg]);
                
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

// Fetch User Collections by this RM
$userLedgerStmt = $pdo->prepare("
    SELECT l.*, s.id as sub_id, s.full_name as sub_fullname, s.balance as sub_balance, s.mobile as sub_mobile
    FROM user_ledger l
    LEFT JOIN subscribers s ON l.username = s.username COLLATE utf8mb4_general_ci AND s.client_id = l.client_id
    WHERE l.client_id = ? AND (
        l.description LIKE ? 
        OR l.description LIKE ? 
        OR l.description LIKE ? 
        OR l.description LIKE ?
    )
    ORDER BY l.created_at DESC 
    LIMIT 200
");
$userLedgerStmt->execute([
    $client_id, 
    "Cash collected by {$rm['full_name']}%",
    "Payment collected by {$rm['full_name']}%",
    "Initial Payment Collected by RM: {$rm['username']}%",
    "%RM: {$rm['username']}%"
]);
$user_collections = $userLedgerStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Activities (Renewals, Registrations, Logs)
$activityStmt = $pdo->prepare("
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
    ORDER BY a.created_at DESC 
    LIMIT 250
");
$activityStmt->execute([
    $client_id, 
    $rm['full_name'], 
    'RM: ' . $rm['username'], 
    $rm['username']
]);
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
$submissionStmt = $pdo->prepare("
    SELECT * FROM activity_logs 
    WHERE client_id = ? AND against_role = 'RecoveryMan' AND against_to = ? AND activity LIKE 'Collected Cash Rs.%'
    ORDER BY created_at DESC 
    LIMIT 100
");
$submissionStmt->execute([$client_id, $rm['full_name']]);
$submissions = $submissionStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<style>
    .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
    .nav-tabs .nav-link { font-weight: 600; color: #64748b; border: none; border-bottom: 3px solid transparent; padding: 12px 20px; }
    .nav-tabs .nav-link:hover { color: #0f172a; }
    .nav-tabs .nav-link.active { color: #3b82f6; border-bottom-color: #3b82f6; background: transparent; }
    .table-custom-ui th { background-color: #f8fafc !important; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.78rem; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; }
    .table-custom-ui td { vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: #475569; font-size: 0.9rem; }
    .table-custom-ui tbody tr:hover { background-color: #f8fafc; }
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
                        <div class="badge bg-secondary-subtle text-secondary border p-2 px-3 rounded-pill"><i class="fa-solid fa-lock me-1"></i> Cash Collection Restricted (Operator Only)</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="badge bg-secondary p-2 px-3 rounded-pill"><i class="fa-solid fa-check-circle me-1"></i> Accounts Cleared</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Statement Tabs -->
<div class="card card-custom bg-white">
    <div class="card-header bg-transparent border-bottom-0 p-0 pt-2 px-3">
        <ul class="nav nav-tabs border-bottom" id="statementTabs" role="tablist">
            <!-- Tab on Left Side: All RM Activities & Renewals -->
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="activities-tab" data-bs-toggle="tab" data-bs-target="#activities" type="button" role="tab">
                    <i class="fa-solid fa-bolt me-1 text-warning"></i> RM Activities & Renewals
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
            <!-- Existing Tab: Submissions to Operator -->
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="operator-tab" data-bs-toggle="tab" data-bs-target="#operator" type="button" role="tab">
                    <i class="fa-solid fa-building-columns me-1 text-danger"></i> Submissions to Operator
                    <span class="badge bg-secondary ms-1"><?= count($submissions) ?></span>
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
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
});
</script>

<?php require_once 'footer.php'; ?>
