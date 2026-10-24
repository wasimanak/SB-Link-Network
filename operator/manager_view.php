<?php
require_once 'header.php';
require_once __DIR__ . '/staff_salary_helper.php';
init_staff_salary_schema($pdo);

// Access Control: Only Operator can view manager profiles & activity logs
if (!empty($_SESSION['is_manager'])) {
    echo "<div class='container py-5'>
            <div class='alert alert-danger shadow-sm'>
                <i class='fa-solid fa-shield-halved me-2'></i> Access Denied: Managers are not authorized to view manager audit logs.
            </div>
            <a href='dashboard' class='btn btn-outline-secondary'><i class='fa-solid fa-arrow-left me-1'></i> Return to Dashboard</a>
          </div>";
    require_once 'footer.php';
    exit;
}

$client_id = (int)$_SESSION['operator_id'];
$manager_id = (int)($_GET['id'] ?? 0);

if ($manager_id <= 0) {
    echo "<div class='container py-5'><div class='alert alert-warning'>Invalid Manager ID specified.</div><a href='managers' class='btn btn-primary'>Back to Managers</a></div>";
    require_once 'footer.php';
    exit;
}

// Handle Direct Quick Actions (Toggle permissions, profile edit, or reset password)
$flash_msg = '';
$flash_type = 'success';
$activeTabToOpen = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['record_staff_payment', 'delete_staff_transaction', 'edit_manager_profile'])) {
        $activeTabToOpen = 'salary';
    }

    if ($action === 'edit_manager_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $monthly_salary = (int)($_POST['monthly_salary'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (!empty($full_name)) {
            $upd = $pdo->prepare("
                UPDATE managers 
                SET full_name = ?, phone = ?, email = ?, city = ?, address = ?, monthly_salary = ?, status = ?
                WHERE id = ? AND client_id = ?
            ");
            $upd->execute([$full_name, $phone, $email, $city, $address, $monthly_salary, $status, $manager_id, $client_id]);

            sync_staff_monthly_salary($pdo, $client_id, 'manager', $manager_id, $full_name, $monthly_salary);

            log_staff_activity($pdo, "Updated Profile & Salary (Rs. {$monthly_salary}) for Manager #{$manager_id}", $full_name, "Manager");
            $flash_msg = "Manager profile and monthly salary updated successfully!";
            $flash_type = "success";
        } else {
            $flash_msg = "Full Name cannot be empty.";
            $flash_type = "danger";
        }
    }

    if ($action === 'toggle_permission') {
        $field = $_POST['permission'] ?? '';
        $val = ($_POST['value'] ?? '') === 'yes' ? 'yes' : 'no';

        if (in_array($field, ['can_manage_packages', 'can_collect_recovery'])) {
            $pdo->prepare("UPDATE managers SET {$field} = ? WHERE id = ? AND client_id = ?")->execute([$val, $manager_id, $client_id]);
            $permLabel = ($field === 'can_manage_packages') ? 'Package Management' : 'Recovery Collection';
            log_staff_activity($pdo, "Changed {$permLabel} permission to '{$val}' for Manager #{$manager_id}", "Manager #{$manager_id}", "Manager");
            $flash_msg = "Permission updated successfully!";
        }
    }

    if ($action === 'toggle_status') {
        $new_status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
        $pdo->prepare("UPDATE managers SET status = ? WHERE id = ? AND client_id = ?")->execute([$new_status, $manager_id, $client_id]);
        log_staff_activity($pdo, "Changed status to '{$new_status}' for Manager #{$manager_id}", "Manager #{$manager_id}", "Manager");
        $flash_msg = "Account status updated to {$new_status}!";
    }

    if ($action === 'change_password') {
        $new_pwd = trim($_POST['new_password'] ?? '');
        if (!empty($new_pwd)) {
            $hash = password_hash($new_pwd, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE managers SET password = ? WHERE id = ? AND client_id = ?")->execute([$hash, $manager_id, $client_id]);
            log_staff_activity($pdo, "Reset password for Manager #{$manager_id}", "Manager #{$manager_id}", "Manager");
            $flash_msg = "Password reset successfully!";
        }
    }
}

// Fetch Manager Details
$stmt = $pdo->prepare("SELECT * FROM managers WHERE id = ? AND client_id = ?");
$stmt->execute([$manager_id, $client_id]);
$mgr = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$mgr) {
    echo "<div class='container py-5'><div class='alert alert-danger'>Manager not found or access unauthorized.</div><a href='managers' class='btn btn-primary'>Back to Managers</a></div>";
    require_once 'footer.php';
    exit;
}

// 1. Maintain activity retention:
// Login activities: strictly 7 days retention
// All other actions (renewals, billing, edits, financial events): 2 Years (730 days) retention
try {
    $pdo->exec("DELETE FROM activity_logs 
                WHERE (activity LIKE '%Login%' OR activity LIKE '%Logged in%' OR against_role = 'Login') 
                  AND created_at < NOW() - INTERVAL 7 DAY");
    $pdo->exec("DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL 730 DAY");
} catch (Exception $e) {}

// Fetch Manager Activity History:
// Login activities: retained for 7 Days only.
// Administrative, Financial & Audit actions: retained for 2 Years (730 Days).
$actStmt = $pdo->prepare("
    SELECT * 
    FROM activity_logs 
    WHERE client_id = ? 
      AND (manager_id = ? OR by_user = ? OR by_user = ?)
      AND (
          ((activity LIKE '%Login%' OR activity LIKE '%Logged in%' OR against_role = 'Login') AND created_at >= NOW() - INTERVAL 7 DAY)
          OR
          ((activity NOT LIKE '%Login%' AND activity NOT LIKE '%Logged in%' AND (against_role != 'Login' OR against_role IS NULL)) AND created_at >= NOW() - INTERVAL 730 DAY)
      )
    ORDER BY id DESC
");
$actStmt->execute([$client_id, $manager_id, $mgr['username'], $mgr['full_name']]);
$activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);

// Activity Breakdown stats
$total_acts = count($activities);
$renew_count = 0;
$user_create_count = 0;
$login_count = 0;

foreach ($activities as $act) {
    $txt = strtolower($act['activity']);
    if (strpos($txt, 'renew') !== false) $renew_count++;
    if (strpos($txt, 'created new user') !== false || strpos($txt, 'create user') !== false) $user_create_count++;
    if (strpos($txt, 'login') !== false || strpos($txt, 'logged in') !== false) $login_count++;
}

// Fetch All Recoveries, Renewals & Payment Collections for this Manager (Up to 2 Years)
$recStmt = $pdo->prepare("
    SELECT 
        a.id as act_id,
        a.created_at,
        a.activity,
        a.against_to as username,
        a.against_role,
        a.station_ip,
        s.id as sub_id,
        s.full_name as sub_fullname,
        s.mobile as sub_mobile,
        s.phone as sub_phone,
        s.balance as sub_balance,
        s.status as sub_status,
        s.expiry_date as sub_expiry,
        p.id as package_id,
        p.name as package_name,
        p.price as package_price,
        p.rate_limit as package_speed
    FROM activity_logs a
    LEFT JOIN subscribers s ON a.against_to = s.username COLLATE utf8mb4_general_ci AND s.client_id = a.client_id
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE a.client_id = ?
      AND (
          a.manager_id = ? 
          OR a.by_user = ? 
          OR a.by_user = ? 
          OR a.by_user = ?
      )
      AND a.created_at >= NOW() - INTERVAL 730 DAY
      AND (
          a.activity LIKE '%Renew%'
          OR a.activity LIKE '%Collected%'
          OR a.activity LIKE '%Balance%'
          OR a.activity LIKE '%Payment%'
          OR a.activity LIKE '%Charged%'
      )
    ORDER BY a.created_at DESC
");
$recStmt->execute([
    $client_id, 
    $manager_id, 
    $mgr['username'], 
    $mgr['full_name'],
    'Manager: ' . $mgr['username']
]);
$raw_recoveries = $recStmt->fetchAll(PDO::FETCH_ASSOC);

// Also fetch user_ledger records if manager name or username is in description
$ledStmt = $pdo->prepare("
    SELECT 
        l.id as ledger_id,
        l.created_at,
        l.description,
        l.username,
        l.type,
        l.amount,
        l.balance_after,
        s.id as sub_id,
        s.full_name as sub_fullname,
        s.mobile as sub_mobile,
        s.phone as sub_phone,
        s.balance as sub_balance,
        s.status as sub_status,
        s.expiry_date as sub_expiry,
        p.id as package_id,
        p.name as package_name,
        p.price as package_price,
        p.rate_limit as package_speed
    FROM user_ledger l
    LEFT JOIN subscribers s ON l.username = s.username COLLATE utf8mb4_general_ci AND s.client_id = l.client_id
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE l.client_id = ?
      AND l.created_at >= NOW() - INTERVAL 730 DAY
      AND (
          l.description LIKE ? 
          OR l.description LIKE ? 
          OR l.description LIKE ?
      )
    ORDER BY l.created_at DESC
");
$ledStmt->execute([
    $client_id,
    "%{$mgr['full_name']}%",
    "%{$mgr['username']}%",
    "%Manager #{$manager_id}%"
]);
$raw_ledgers = $ledStmt->fetchAll(PDO::FETCH_ASSOC);

// Build Unified All Recoveries List
$all_recoveries = [];
$total_recovered_amount = 0;
$total_renewals_count = 0;
$total_collections_count = 0;
$unique_subs = [];

foreach ($raw_recoveries as $rec) {
    $act_text = $rec['activity'];
    $sub_username = $rec['username'] ?: 'N/A';
    if ($sub_username !== 'N/A') {
        $unique_subs[$sub_username] = true;
    }

    $charged = 0;
    $collected = 0;
    $type = 'other';

    if (stripos($act_text, 'Renew') !== false) {
        $type = 'renewal';
        $total_renewals_count++;
        $charged = (float)($rec['package_price'] ?? 0);
    } elseif (stripos($act_text, 'Collected') !== false || stripos($act_text, 'Payment') !== false) {
        $type = 'collection';
        $total_collections_count++;
    } elseif (stripos($act_text, 'Added Balance') !== false || stripos($act_text, 'Funds added') !== false) {
        $type = 'balance_add';
        $total_collections_count++;
    }

    if (preg_match('/Charged(?:: Rs\.| Rs\.)\s*([\d,.]+)/i', $act_text, $m)) {
        $charged = (float)str_replace(',', '', $m[1]);
    }
    if (preg_match('/(?:Collected|Added Balance)(?:: Rs\.| Rs\.)\s*([\d,.]+)/i', $act_text, $m)) {
        $collected = (float)str_replace(',', '', $m[1]);
    }

    if ($type === 'renewal' && $collected == 0 && $charged > 0) {
        $collected = $charged;
    }

    $total_recovered_amount += $collected;

    $all_recoveries[] = [
        'id'               => $rec['act_id'],
        'created_at'       => $rec['created_at'],
        'username'         => $sub_username,
        'sub_id'           => $rec['sub_id'],
        'full_name'        => $rec['sub_fullname'] ?: $sub_username,
        'mobile'           => $rec['sub_mobile'] ?: ($rec['sub_phone'] ?: 'N/A'),
        'package_name'     => $rec['package_name'] ?: 'N/A',
        'package_price'    => (float)($rec['package_price'] ?? 0),
        'package_speed'    => $rec['package_speed'] ?: 'N/A',
        'expiry_date'      => $rec['sub_expiry'] ?: '',
        'current_balance'  => (float)($rec['sub_balance'] ?? 0),
        'charged_amount'   => $charged,
        'collected_amount' => $collected,
        'type'             => $type,
        'activity_text'    => $act_text,
        'station_ip'       => $rec['station_ip'] ?: ''
    ];
}

foreach ($raw_ledgers as $led) {
    $led_time = strtotime($led['created_at']);
    $already = false;
    foreach ($raw_recoveries as $rec) {
        if ($rec['username'] === $led['username'] && abs($led_time - strtotime($rec['created_at'])) <= 30) {
            $already = true;
            break;
        }
    }

    if (!$already) {
        $u = $led['username'] ?: 'N/A';
        if ($u !== 'N/A') $unique_subs[$u] = true;

        $amt = (float)$led['amount'];
        $collected = ($led['type'] === 'credit') ? $amt : 0;
        $charged   = ($led['type'] === 'debit') ? $amt : 0;
        $total_recovered_amount += $collected;
        if ($collected > 0) $total_collections_count++;

        $all_recoveries[] = [
            'id'               => 'L' . $led['ledger_id'],
            'created_at'       => $led['created_at'],
            'username'         => $u,
            'sub_id'           => $led['sub_id'],
            'full_name'        => $led['sub_fullname'] ?: $u,
            'mobile'           => $led['sub_mobile'] ?: ($led['sub_phone'] ?: 'N/A'),
            'package_name'     => $led['package_name'] ?: 'N/A',
            'package_price'    => (float)($led['package_price'] ?? 0),
            'package_speed'    => $led['package_speed'] ?: 'N/A',
            'expiry_date'      => $led['sub_expiry'] ?: '',
            'current_balance'  => (float)($led['sub_balance'] ?? ($led['balance_after'] ?? 0)),
            'charged_amount'   => $charged,
            'collected_amount' => $collected,
            'type'             => ($led['type'] === 'credit' ? 'collection' : 'renewal'),
            'activity_text'    => $led['description'],
            'station_ip'       => 'System'
        ];
    }
}

usort($all_recoveries, function($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

$unique_subscribers_count = count($unique_subs);
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

<style>
.profile-card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    overflow: hidden;
}
.profile-banner {
    height: 90px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6, #d946ef);
}
.profile-avatar-wrap {
    margin-top: -45px;
    padding: 0 24px;
}
.profile-avatar {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: #fff;
    border: 4px solid #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.2rem;
    font-weight: 800;
    color: #7c3aed;
}
.perm-card {
    background: #f8fafc;
    border-radius: 12px;
    padding: 16px;
    border: 1px solid #e2e8f0;
    height: 100%;
}
.mini-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 14px 18px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    display: flex;
    align-items: center;
    gap: 14px;
}
.mini-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.badge-soft-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge-soft-info { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.badge-soft-warning { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.badge-soft-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
.dt-buttons .btn {
    background: #475569;
    color: #fff;
    border: none;
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 0.8rem;
    margin-right: 4px;
}
.dt-buttons .btn:hover { background: #334155; }
.nav-tabs .nav-link {
    font-weight: 600;
    color: #64748b;
    border: none;
    border-bottom: 3px solid transparent;
    padding: 12px 22px;
    font-size: 0.95rem;
    transition: all 0.2s ease;
}
.nav-tabs .nav-link:hover {
    color: #0f172a;
    border-bottom-color: #cbd5e1;
}
.nav-tabs .nav-link.active {
    color: #2563eb;
    border-bottom-color: #2563eb;
    background: transparent;
}
.table-custom-ui th {
    background-color: #f8fafc !important;
    color: #64748b;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.78rem;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
}
.table-custom-ui td {
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    color: #475569;
    font-size: 0.9rem;
}
.table-custom-ui tbody tr:hover {
    background-color: #f8fafc;
}
</style>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Flash Message -->
    <?php if ($flash_msg): ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i> <?= $flash_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Breadcrumb & Back button -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="managers" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Managers
        </a>
        <div class="text-muted small">
            <i class="fa-solid fa-shield-halved text-primary me-1"></i> Operator Governance Panel
        </div>
    </div>

    <!-- Manager Profile Overview -->
    <div class="profile-card mb-4">
        <div class="profile-banner"></div>
        <div class="profile-avatar-wrap d-flex flex-wrap justify-content-between align-items-end gap-3 pb-3">
            <div class="d-flex align-items-end gap-3">
                <div class="profile-avatar">
                    <?= strtoupper(substr($mgr['full_name'], 0, 1)) ?>
                </div>
                <div>
                    <h4 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($mgr['full_name']) ?></h4>
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <span class="badge bg-purple-subtle text-primary border rounded-pill px-2 py-1 font-monospace" style="background:#ede9fe; color:#7c3aed !important; border-color:#ddd6fe !important;">
                            <i class="fa-solid fa-user-shield me-1"></i> <?= htmlspecialchars($mgr['username']) ?>
                        </span>
                        <?php if ($mgr['status'] === 'active'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1">Inactive</span>
                        <?php endif; ?>
                        <span class="text-muted small ms-2"><i class="fa-regular fa-calendar me-1"></i> Joined <?= date('d M Y', strtotime($mgr['created_at'])) ?></span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <!-- Edit Profile & Salary Button -->
                <button class="btn btn-outline-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#editManagerProfileModal">
                    <i class="fa-solid fa-user-pen me-1"></i> Edit Profile &amp; Salary
                </button>
                <!-- Change Password Button -->
                <button class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    <i class="fa-solid fa-key me-1"></i> Reset Password
                </button>
                <!-- Status Toggle -->
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="status" value="<?= $mgr['status'] === 'active' ? 'inactive' : 'active' ?>">
                    <?php if ($mgr['status'] === 'active'): ?>
                        <button type="submit" class="btn btn-outline-warning btn-sm rounded-pill px-3" onclick="return confirm('Suspend this Manager account?');">
                            <i class="fa-solid fa-pause me-1"></i> Deactivate
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-success btn-sm rounded-pill px-3">
                            <i class="fa-solid fa-play me-1"></i> Activate Account
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="p-4 pt-2 border-top">
            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Monthly Salary</div>
                    <div class="fw-bold text-success mt-1"><i class="fa-solid fa-money-bill-wave me-1 small"></i> Rs. <?= number_format($mgr['monthly_salary'] ?? 0) ?></div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Phone Number</div>
                    <div class="fw-bold text-dark mt-1"><i class="fa-solid fa-phone text-muted me-1 small"></i> <?= htmlspecialchars($mgr['phone'] ?: 'N/A') ?></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Email Address</div>
                    <div class="fw-bold text-dark mt-1"><i class="fa-regular fa-envelope text-muted me-1 small"></i> <?= htmlspecialchars($mgr['email'] ?: 'N/A') ?></div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">City / Region</div>
                    <div class="fw-bold text-dark mt-1"><i class="fa-solid fa-city text-muted me-1 small"></i> <?= htmlspecialchars($mgr['city'] ?: 'N/A') ?></div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Address</div>
                    <div class="fw-bold text-dark mt-1 text-truncate" title="<?= htmlspecialchars($mgr['address'] ?: 'N/A') ?>"><i class="fa-solid fa-location-dot text-muted me-1 small"></i> <?= htmlspecialchars($mgr['address'] ?: 'N/A') ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Operator-Controlled Permissions & Real-time Toggles -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="perm-card d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-1 text-dark">
                        <i class="fa-solid fa-box-open text-primary me-2"></i> Package &amp; Pricing Management
                    </h6>
                    <p class="text-muted small mb-0">Allow manager to create new packages, edit existing packages, and change rates.</p>
                </div>
                <form method="POST" class="ms-3 flex-shrink-0">
                    <input type="hidden" name="action" value="toggle_permission">
                    <input type="hidden" name="permission" value="can_manage_packages">
                    <input type="hidden" name="value" value="<?= $mgr['can_manage_packages'] === 'yes' ? 'no' : 'yes' ?>">
                    <?php if ($mgr['can_manage_packages'] === 'yes'): ?>
                        <button type="submit" class="btn btn-success rounded-pill px-3 py-1 btn-sm fw-semibold shadow-sm" title="Click to Revoke">
                            <i class="fa-solid fa-check-circle me-1"></i> Permitted
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-secondary rounded-pill px-3 py-1 btn-sm fw-semibold" title="Click to Grant Permission">
                            <i class="fa-solid fa-lock me-1"></i> Locked
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-md-6">
            <div class="perm-card d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-1 text-dark">
                        <i class="fa-solid fa-hand-holding-dollar text-warning me-2"></i> Operator Cash Collection
                    </h6>
                    <p class="text-muted small mb-0">Allow manager to receive and collect cash in hand from field Operators.</p>
                </div>
                <form method="POST" class="ms-3 flex-shrink-0">
                    <input type="hidden" name="action" value="toggle_permission">
                    <input type="hidden" name="permission" value="can_collect_recovery">
                    <input type="hidden" name="value" value="<?= $mgr['can_collect_recovery'] === 'yes' ? 'no' : 'yes' ?>">
                    <?php if ($mgr['can_collect_recovery'] === 'yes'): ?>
                        <button type="submit" class="btn btn-success rounded-pill px-3 py-1 btn-sm fw-semibold shadow-sm" title="Click to Revoke">
                            <i class="fa-solid fa-check-circle me-1"></i> Permitted
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-secondary rounded-pill px-3 py-1 btn-sm fw-semibold" title="Click to Grant Permission">
                            <i class="fa-solid fa-lock me-1"></i> Locked
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Activity & Financial Audit Stat Overview -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(99, 102, 241, 0.1); color: #6366f1;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $total_acts ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Total Actions (2-Yr Audit)</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $renew_count ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Package Renewals</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(14, 165, 233, 0.1); color: #0ea5e9;">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $user_create_count ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Users Created</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                    <i class="fa-solid fa-right-to-bracket"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $login_count ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Logins (Last 7 Days)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Manager Governance Tabs Container -->
    <div class="card card-custom bg-white mb-4 border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white border-bottom p-0 pt-3 px-3">
            <ul class="nav nav-tabs border-bottom-0" id="managerTabs" role="tablist">
                <!-- Tab 1: All Recovery & Renewals -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold" id="recovery-tab" data-bs-toggle="tab" data-bs-target="#tab-recovery" type="button" role="tab">
                        <i class="fa-solid fa-hand-holding-dollar text-success me-2"></i> All Recovery &amp; Renewals
                        <span class="badge bg-success ms-1"><?= count($all_recoveries) ?></span>
                    </button>
                </li>
                <!-- Tab 2: Activity Audit Trail -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="activity-tab" data-bs-toggle="tab" data-bs-target="#tab-activity" type="button" role="tab">
                        <i class="fa-solid fa-list-check text-primary me-2"></i> Activity Audit Trail
                        <span class="badge bg-primary ms-1"><?= count($activities) ?></span>
                    </button>
                </li>
                <!-- Tab 3: Salary & Advances -->
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="salary-tab" data-bs-toggle="tab" data-bs-target="#tab-salary" type="button" role="tab">
                        <i class="fa-solid fa-wallet text-warning me-2"></i> Salary &amp; Advances
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body p-3 p-md-4">
            <div class="tab-content" id="managerTabsContent">

                <!-- Tab 1 Content: All Recovery & Renewals -->
                <div class="tab-pane fade show active" id="tab-recovery" role="tabpanel">
                    
                    <!-- Recovery Mini Stat Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 text-center">
                                <div class="text-success small fw-bold text-uppercase">Total Cash Recovered</div>
                                <h3 class="mb-0 fw-bold text-success mt-1">Rs. <?= number_format($total_recovered_amount, 0) ?></h3>
                                <div class="text-muted small mt-1"><i class="fa-solid fa-shield-halved me-1"></i> 2-Year Audit Preserved</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3 text-center">
                                <div class="text-primary small fw-bold text-uppercase">Package Renewals</div>
                                <h3 class="mb-0 fw-bold text-primary mt-1"><?= number_format($total_renewals_count) ?></h3>
                                <div class="text-muted small mt-1">Subscriber Extensions</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-info bg-opacity-10 border border-info border-opacity-25 rounded-3 text-center">
                                <div class="text-info-emphasis small fw-bold text-uppercase">Unique Subscribers</div>
                                <h3 class="mb-0 fw-bold text-dark mt-1"><?= number_format($unique_subscribers_count) ?></h3>
                                <div class="text-muted small mt-1">Customers Served</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-3 bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-3 text-center">
                                <div class="text-warning-emphasis small fw-bold text-uppercase">Total Transactions</div>
                                <h3 class="mb-0 fw-bold text-dark mt-1"><?= number_format(count($all_recoveries)) ?></h3>
                                <div class="text-muted small mt-1">Lifetime Events Logged</div>
                            </div>
                        </div>
                    </div>

                    <?php if (empty($all_recoveries)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-hand-holding-dollar fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                            <h6 class="fw-bold text-dark">No Recovery or Renewal Records Found</h6>
                            <p class="small text-secondary mb-0">Whenever this Manager renews subscriber packages, collects payments, or adds balances, full records with username, package, and expiry will automatically appear here.</p>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table id="managerRecoveryTable" class="table table-hover table-custom-ui align-middle w-100">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Subscriber</th>
                                    <th>Package Detail</th>
                                    <th>Expiry Date</th>
                                    <th>Recovered (Paid)</th>
                                    <th>Fee Charged</th>
                                    <th>Current Balance</th>
                                    <th>Recovery Type &amp; Details</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_recoveries as $rec): 
                                    $exp_text = '<span class="text-muted small">N/A</span>';
                                    if (!empty($rec['expiry_date'])) {
                                        $exp_time = strtotime($rec['expiry_date']);
                                        if ($exp_time > 0) {
                                            $formatted_date = date('d M Y, h:i A', $exp_time);
                                            $diff_days = ceil(($exp_time - time()) / 86400);
                                            if ($diff_days < 0) {
                                                $badge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-0 ms-1 small">Expired</span>';
                                            } elseif ($diff_days <= 3) {
                                                $badge = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-0 ms-1 small">' . $diff_days . 'd left</span>';
                                            } else {
                                                $badge = '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0 ms-1 small">' . $diff_days . 'd left</span>';
                                            }
                                            $exp_text = '<div class="fw-semibold text-dark" style="white-space:nowrap;">' . $formatted_date . '</div>' . $badge;
                                        }
                                    }
                                    $bal = (float)$rec['current_balance'];
                                ?>
                                <tr>
                                    <td class="text-secondary small fw-bold" style="white-space: nowrap;">
                                        <div class="text-dark"><?= date('d M Y', strtotime($rec['created_at'])) ?></div>
                                        <div class="text-muted small font-monospace"><?= date('h:i:s A', strtotime($rec['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($rec['sub_id'])): ?>
                                            <a href="subscriber_view.php?id=<?= $rec['sub_id'] ?>" class="fw-bold text-primary text-decoration-none">
                                                <i class="fa-solid fa-user me-1 small"></i><?= htmlspecialchars($rec['username']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($rec['username']) ?></span>
                                        <?php endif; ?>
                                        <div class="small text-secondary"><?= htmlspecialchars($rec['full_name']) ?></div>
                                        <?php if (!empty($rec['mobile']) && $rec['mobile'] !== 'N/A'): ?>
                                            <div class="small text-muted font-monospace"><i class="fa-solid fa-phone me-1 text-success"></i><?= htmlspecialchars($rec['mobile']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($rec['package_name']) && $rec['package_name'] !== 'N/A'): ?>
                                            <span class="badge bg-light text-dark border px-2 py-1 fw-bold">
                                                <i class="fa-solid fa-box-open text-primary me-1"></i><?= htmlspecialchars($rec['package_name']) ?>
                                            </span>
                                            <?php if ($rec['package_price'] > 0): ?>
                                                <div class="small text-muted mt-1">Rate: Rs. <?= number_format($rec['package_price'], 0) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $exp_text ?>
                                    </td>
                                    <td>
                                        <?php if ($rec['collected_amount'] > 0): ?>
                                            <span class="text-success fw-bold fs-6" style="white-space:nowrap;">
                                                + Rs. <?= number_format($rec['collected_amount'], 0) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted fw-bold">Rs. 0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($rec['charged_amount'] > 0): ?>
                                            <span class="text-dark fw-semibold" style="white-space:nowrap;">
                                                Rs. <?= number_format($rec['charged_amount'], 0) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($bal < 0): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold px-2 py-1" style="white-space:nowrap;">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> - Rs. <?= number_format(abs($bal), 0) ?>
                                            </span>
                                        <?php elseif ($bal > 0): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold px-2 py-1" style="white-space:nowrap;">
                                                <i class="fa-solid fa-check me-1"></i> + Rs. <?= number_format($bal, 0) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-secondary border fw-bold px-2 py-1">
                                                Rs. 0 (Cleared)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small" style="max-width: 250px;">
                                        <?php if ($rec['type'] === 'renewal'): ?>
                                            <span class="badge badge-soft-success rounded-pill px-2 py-1 me-1"><i class="fa-solid fa-rotate me-1"></i>Renew</span>
                                        <?php elseif ($rec['type'] === 'collection'): ?>
                                            <span class="badge badge-soft-info rounded-pill px-2 py-1 me-1"><i class="fa-solid fa-hand-holding-dollar me-1"></i>Collected</span>
                                        <?php elseif ($rec['type'] === 'balance_add'): ?>
                                            <span class="badge badge-soft-warning rounded-pill px-2 py-1 me-1"><i class="fa-solid fa-plus-circle me-1"></i>Funds Added</span>
                                        <?php endif; ?>
                                        <span class="text-muted"><?= htmlspecialchars($rec['activity_text']) ?></span>
                                    </td>
                                    <td class="text-center" style="white-space:nowrap;">
                                        <?php if (!empty($rec['sub_id'])): ?>
                                            <a href="subscriber_view.php?id=<?= $rec['sub_id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3" title="View Full Subscriber Profile">
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
                    <?php endif; ?>

                </div>

                <!-- Tab 2 Content: Activity Audit Trail -->
                <div class="tab-pane fade" id="tab-activity" role="tabpanel">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                        <div>
                            <h6 class="fw-bold mb-1 text-dark">
                                <i class="fa-solid fa-list-check text-primary me-2"></i> Manager Activity Audit Trail
                            </h6>
                            <div class="text-muted small">
                                Login activities are preserved for 7 days, and all other administrative &amp; financial actions are retained for 2 years (730 days).
                            </div>
                        </div>
                        <span class="badge bg-light text-dark border px-3 py-2 rounded-pill font-monospace">
                            <?= count($activities) ?> Recorded Events
                        </span>
                    </div>

                    <?php if (empty($activities)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa-solid fa-circle-check fs-1 text-success opacity-50 mb-3 d-block"></i>
                            <h6 class="fw-bold text-dark">No Activities Recorded Yet</h6>
                            <p class="small text-secondary mb-0">Activities performed by this Manager will appear here automatically.</p>
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table id="managerLogsTable" class="table table-hover table-custom-ui align-middle w-100">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">#ID</th>
                                    <th>Datetime</th>
                                    <th>Activity Description</th>
                                    <th>Against To</th>
                                    <th>Against Role</th>
                                    <th>Station IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activities as $act): ?>
                                    <tr>
                                        <td class="fw-bold text-secondary"><?= $act['id'] ?></td>
                                        <td style="white-space: nowrap;">
                                            <div class="fw-semibold text-dark"><?= date('d M Y', strtotime($act['created_at'])) ?></div>
                                            <div class="text-muted small"><?= date('h:i:s A', strtotime($act['created_at'])) ?></div>
                                        </td>
                                        <td>
                                            <?php 
                                                $act_text = htmlspecialchars($act['activity']);
                                                if (stripos($act_text, 'renew') !== false) {
                                                    echo "<span class='badge badge-soft-success px-2 py-1 rounded-pill me-1'><i class='fa-solid fa-arrows-rotate me-1'></i>Renew</span> " . $act_text;
                                                } elseif (stripos($act_text, 'create') !== false) {
                                                    echo "<span class='badge badge-soft-info px-2 py-1 rounded-pill me-1'><i class='fa-solid fa-plus-circle me-1'></i>Create</span> " . $act_text;
                                                } elseif (stripos($act_text, 'login') !== false) {
                                                    echo "<span class='badge badge-soft-secondary px-2 py-1 rounded-pill me-1'><i class='fa-solid fa-arrow-right-to-bracket me-1'></i>Login</span> " . $act_text;
                                                } else {
                                                    echo "<span class='fw-medium text-dark'>" . $act_text . "</span>";
                                                }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ($act['against_to'] && $act['against_to'] !== 'N/A'): ?>
                                                <span class="badge bg-light text-dark border px-2 py-1 rounded-pill">
                                                    <?= htmlspecialchars($act['against_to']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1">
                                                <?= htmlspecialchars($act['against_role'] ?: 'System') ?>
                                            </span>
                                        </td>
                                        <td class="font-monospace text-muted small">
                                            <?= htmlspecialchars($act['station_ip'] ?: '127.0.0.1') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 3 Content: Salary & Advances -->
                <div class="tab-pane fade" id="tab-salary" role="tabpanel">
                    <?php
                    $staff_type = 'manager';
                    $staff_id = (int)$mgr['id'];
                    $staff_name = $mgr['full_name'];
                    $monthly_sal = (int)($mgr['monthly_salary'] ?? 0);
                    $joined_date = $mgr['created_at'];
                    require __DIR__ . '/staff_salary_view.php';
                    ?>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Edit Manager Profile & Salary Modal -->
<div class="modal fade" id="editManagerProfileModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fa-solid fa-user-pen text-primary me-2"></i> Edit Manager Profile &amp; Salary
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="edit_manager_profile">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($mgr['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Username</label>
                            <input type="text" class="form-control font-monospace bg-light" value="<?= htmlspecialchars($mgr['username']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Monthly Salary (Rs)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                                <input type="number" step="1" min="0" name="monthly_salary" class="form-control" placeholder="e.g. 35000" value="<?= (int)($mgr['monthly_salary'] ?? 0) ?>">
                            </div>
                            <div class="text-muted small mt-1">Updates staff ledger auto-accruals and lifetime ledger balance.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Account Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= ($mgr['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= ($mgr['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive / Suspended</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Phone Number</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($mgr['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Email Address</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($mgr['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">City</label>
                            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($mgr['city'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Office / Physical Address</label>
                            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($mgr['address'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-key text-warning me-2"></i> Reset Manager Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">Enter a new secure password for <strong><?= htmlspecialchars($mgr['full_name']) ?></strong> (<?= htmlspecialchars($mgr['username']) ?>).</p>
                    <label class="form-label fw-semibold text-secondary small">New Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Enter new password" required minlength="6">
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold">Save New Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    if ($('#managerRecoveryTable').length) {
        $('#managerRecoveryTable').DataTable({
            dom: "<'row mb-3'<'col-sm-6'B><'col-sm-6 text-end'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row mt-3'<'col-sm-5'i><'col-sm-7'p>>",
            buttons: [
                { extend: 'excel', text: '<i class="fa-solid fa-file-excel me-1"></i> Excel', className: 'btn btn-sm btn-outline-success' },
                { extend: 'csv', text: '<i class="fa-solid fa-file-csv me-1"></i> CSV', className: 'btn btn-sm btn-outline-primary' },
                { extend: 'print', text: '<i class="fa-solid fa-print me-1"></i> Print', className: 'btn btn-sm btn-outline-dark' }
            ],
            order: [[0, 'desc']],
            pageLength: 25,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search recoveries, username, package..."
            }
        });
    }

    if ($('#managerLogsTable').length) {
        $('#managerLogsTable').DataTable({
            dom: "<'row mb-3'<'col-sm-6'B><'col-sm-6 text-end'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row mt-3'<'col-sm-5'i><'col-sm-7'p>>",
            buttons: [
                { extend: 'excel', text: '<i class="fa-solid fa-file-excel me-1"></i> Excel', className: 'btn btn-sm btn-outline-success' },
                { extend: 'csv', text: '<i class="fa-solid fa-file-csv me-1"></i> CSV', className: 'btn btn-sm btn-outline-primary' },
                { extend: 'print', text: '<i class="fa-solid fa-print me-1"></i> Print', className: 'btn btn-sm btn-outline-dark' }
            ],
            order: [[0, 'desc']],
            pageLength: 25,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search activities..."
            }
        });
    }

    // Auto-adjust DataTables on tab switch
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        $($.fn.dataTable.tables(true)).DataTable().columns.adjust();
    });

    // Handle deep-link and state retention for tabs
    const urlParams = new URLSearchParams(window.location.search);
    const activeTabParam = urlParams.get('tab') || window.location.hash.replace('#', '') || '<?= $activeTabToOpen ?>';
    if (activeTabParam === 'salary' || activeTabParam === 'tab-salary') {
        const triggerEl = document.querySelector('#salary-tab');
        if (triggerEl) {
            bootstrap.Tab.getOrCreateInstance(triggerEl).show();
        }
    } else if (activeTabParam === 'activity' || activeTabParam === 'tab-activity' || activeTabParam === 'activityLogs') {
        const triggerEl = document.querySelector('#activity-tab');
        if (triggerEl) {
            bootstrap.Tab.getOrCreateInstance(triggerEl).show();
        }
    }
});
</script>

<?php require_once 'footer.php'; ?>
