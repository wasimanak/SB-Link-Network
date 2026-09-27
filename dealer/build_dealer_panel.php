<?php
$op_dir = 'C:/xampp/htdocs/SB Link Network/operator';
$dl_dir = 'C:/xampp/htdocs/SB Link Network/dealer';

if (!is_dir($dl_dir)) mkdir($dl_dir, 0777, true);

// 1. Copy necessary CSS/JS/Assets if they rely on relative paths (assuming they use CDN or ../assets).
// Since they use standard structure, we just create the files.

// ---------------------------------------------------------
// HEADER.PHP
// ---------------------------------------------------------
$header = <<<'PHP'
<?php
session_start();
if (!isset($_SESSION['dealer_id'])) {
    header("Location: login.php");
    exit;
}
require_once '../config/db.php';

$dealer_id = $_SESSION['dealer_id'];
$client_id = $_SESSION['client_id'];

// Get Dealer Data & Permissions
$stmt = $pdo->prepare("SELECT * FROM dealers WHERE id = ? AND status = 'active'");
$stmt->execute([$dealer_id]);
$current_dealer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_dealer) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Global Gateway Config for Recharge
$stmt = $pdo->prepare("SELECT gateway_display_name, gateway_account_name FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client_conf = $stmt->fetch();
$gateway_display_name = $client_conf['gateway_display_name'] ?? 'Bank Account';
$gateway_account_name = $client_conf['gateway_account_name'] ?? 'Account Holder';

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dealer Dashboard - SB Link</title>
    <!-- Same UI logic as Operator -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #f8fafc;
            --sidebar-bg: #0f172a;
            --sidebar-hover: #1e293b;
            --sidebar-text: #94a3b8;
            --sidebar-active: #ffffff;
            --accent-color: #3b82f6;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--primary-bg); color: #334155; }
        
        /* Sidebar Styles */
        #sidebar {
            width: 260px;
            background-color: var(--sidebar-bg);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-y: auto;
        }
        .sidebar-brand {
            padding: 1.5rem;
            color: white;
            font-size: 1.25rem;
            font-weight: 700;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .nav-item { margin: 0.25rem 1rem; }
        .nav-link {
            color: var(--sidebar-text);
            padding: 0.75rem 1rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.2s ease;
            font-weight: 500;
        }
        .nav-link:hover, .nav-link.active {
            color: var(--sidebar-active);
            background-color: var(--sidebar-hover);
        }
        .nav-link.active {
            background-color: var(--accent-color);
            box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3);
        }
        .nav-link i { font-size: 1.1rem; width: 24px; text-align: center; }

        /* Main Content */
        #main-content { margin-left: 260px; padding: 2rem; min-height: 100vh; transition: all 0.3s ease; }
        
        /* Top Header */
        .top-header {
            background: white;
            padding: 1rem 2rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dealer-badge {
            background: #f1f5f9;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            border: none;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        .stat-icon {
            width: 60px; height: 60px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem;
        }
        .bg-light-primary { background: #eff6ff; color: #3b82f6; }
        .bg-light-success { background: #f0fdf4; color: #22c55e; }
        .bg-light-warning { background: #fefce8; color: #eab308; }
        .bg-light-danger { background: #fef2f2; color: #ef4444; }
        
        .stat-details h3 { margin: 0; font-size: 1.5rem; font-weight: 700; color: #0f172a; }
        .stat-details p { margin: 0; color: #64748b; font-size: 0.875rem; font-weight: 500; }
    </style>
</head>
<body>

<nav id="sidebar">
    <div class="sidebar-brand">
        <i class="fa-solid fa-network-wired text-primary"></i> SB Link
    </div>
    
    <div class="px-4 py-3 mb-2 border-bottom border-secondary border-opacity-25">
        <div class="text-white fw-bold"><?= htmlspecialchars($current_dealer['franchise'] ?: $current_dealer['full_name']) ?></div>
        <div class="small text-muted"><i class="fa-solid fa-wallet text-success me-1"></i> Rs. <?= number_format($current_dealer['balance'], 2) ?></div>
    </div>

    <ul class="nav flex-column mt-3">
        <li class="nav-item">
            <a class="nav-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
                <i class="fa-solid fa-gauge"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $current_page == 'users.php' ? 'active' : '' ?>" href="users.php">
                <i class="fa-solid fa-users"></i> My Users
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#addFundsModal">
                <i class="fa-solid fa-money-bill-transfer"></i> Online Recharge
            </a>
        </li>
        <li class="nav-item mt-4">
            <a class="nav-link text-danger" href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </li>
    </ul>
</nav>

<div id="main-content">
    <div class="top-header">
        <div>
            <h5 class="mb-0 fw-bold">Dealer Portal</h5>
            <small class="text-muted">Manage your franchise and users</small>
        </div>
        <div class="dealer-badge">
            <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                <i class="fa-solid fa-user"></i>
            </div>
            <?= htmlspecialchars($current_dealer['username']) ?>
        </div>
    </div>
PHP;

file_put_contents("$dl_dir/header.php", $header);

// ---------------------------------------------------------
// DASHBOARD.PHP
// ---------------------------------------------------------
$dashboard = <<<'PHP'
<?php
require_once 'header.php';

// Fetch stats for this dealer only
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN status = 'active' AND (expiry_date IS NULL OR expiry_date > NOW()) THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN expiry_date < NOW() THEN 1 ELSE 0 END) as expired_users,
        (SELECT COUNT(*) FROM radacct r JOIN subscribers s ON r.username = s.username WHERE s.dealer_id = ? AND r.acctstoptime IS NULL) as online_users
    FROM subscribers 
    WHERE dealer_id = ?
");
$statsStmt->execute([$dealer_id, $dealer_id]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Fetch recent activity
$alogsStmt = $pdo->prepare("SELECT activity, against_to, created_at FROM activity_logs WHERE dealer_id = ? ORDER BY id DESC LIMIT 10");
$alogsStmt->execute([$dealer_id]);
$dealer_activities = $alogsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row g-4 mb-4">
    <!-- Total Users -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-light-primary"><i class="fa-solid fa-users"></i></div>
            <div class="stat-details">
                <h3><?= number_format($stats['total_users'] ?? 0) ?></h3>
                <p>Total Users</p>
            </div>
        </div>
    </div>
    <!-- Online Users -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-light-success"><i class="fa-solid fa-wifi"></i></div>
            <div class="stat-details">
                <h3><?= number_format($stats['online_users'] ?? 0) ?></h3>
                <p>Online Users</p>
            </div>
        </div>
    </div>
    <!-- Active Users -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-light-warning"><i class="fa-solid fa-user-check"></i></div>
            <div class="stat-details">
                <h3><?= number_format($stats['active_users'] ?? 0) ?></h3>
                <p>Active Users</p>
            </div>
        </div>
    </div>
    <!-- Expired Users -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-light-danger"><i class="fa-solid fa-user-xmark"></i></div>
            <div class="stat-details">
                <h3><?= number_format($stats['expired_users'] ?? 0) ?></h3>
                <p>Expired Users</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="card border-0 shadow-sm rounded-4">
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
    </div>
</div>

<?php require_once 'footer.php'; ?>
PHP;

file_put_contents("$dl_dir/dashboard.php", $dashboard);

// ---------------------------------------------------------
// USERS.PHP (Similar to subscribers.php but restricted)
// ---------------------------------------------------------
$users = <<<'PHP'
<?php
require_once 'header.php';

// Check Permissions
$can_create = (bool)$current_dealer['perm_create_user'];
$can_delete = (bool)$current_dealer['perm_delete_user'];
$can_custom_expiry = (bool)$current_dealer['perm_custom_expiry'];

// Handle Actions (Add, Renew, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // DELETE
    if ($action === 'delete_id' && isset($_POST['id']) && $can_delete) {
        $del = (int)$_POST['id'];
        $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$del, $dealer_id]);
        $u = $uStmt->fetchColumn();
        if ($u) {
            $pdo->prepare("DELETE FROM subscribers WHERE id = ?")->execute([$del]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
            $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Deleted User')")->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);
            echo "<script>alert('User deleted successfully!'); window.location='users.php';</script>";
            exit;
        }
    }

    // RENEW USER
    if ($action === 'renew_user') {
        $id = (int)$_POST['id'];
        $package_id = (int)$_POST['package_id'];
        $expiry_date = $_POST['expiry_date'] ?? null;
        
        if (!$can_custom_expiry || empty($expiry_date)) {
            // Default 30 days if not allowed to set custom, or if left blank
            $expiry_date = date('Y-m-d\TH:i', strtotime('+30 days'));
        }

        $uStmt = $pdo->prepare("SELECT username, package_id as old_package_id, expiry_date as old_expiry FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$id, $dealer_id]);
        $subData = $uStmt->fetch(PDO::FETCH_ASSOC);

        if ($subData) {
            $u = $subData['username'];
            $p = $pdo->prepare("SELECT rate_limit, price FROM packages WHERE id = ?");
            $p->execute([$package_id]);
            $pkg = $p->fetch();

            if ($pkg) {
                try {
                    $pdo->beginTransaction();
                    
                    // Billing Logic
                    $old_remaining_value = 0;
                    if (!empty($subData['old_expiry']) && strtotime($subData['old_expiry']) > time()) {
                        $old_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                        $old_dpStmt->execute([$dealer_id, $subData['old_package_id']]);
                        $old_dp_price = $old_dpStmt->fetchColumn() ?: ($pkg['price'] ?? 0);
                        
                        $old_seconds = strtotime($subData['old_expiry']) - time();
                        $old_days = $old_seconds / 86400;
                        $old_remaining_value = $old_days * ($old_dp_price / 30);
                    }

                    $new_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                    $new_dpStmt->execute([$dealer_id, $package_id]);
                    $new_dp_price = $new_dpStmt->fetchColumn() ?: ($pkg['price'] ?? 0);
                    
                    $new_total_value = 0;
                    $new_seconds = strtotime($expiry_date) - time();
                    if ($new_seconds > 0) {
                        $new_days = $new_seconds / 86400;
                        $new_total_value = $new_days * ($new_dp_price / 30);
                    }

                    $net_deduction = round($new_total_value - $old_remaining_value, 2);

                    // Check Balance First
                    if ($net_deduction > $current_dealer['balance']) {
                        $pdo->rollBack();
                        echo "<script>alert('Insufficient balance to perform this upgrade/renewal!'); window.location='users.php';</script>";
                        exit;
                    }

                    if ($net_deduction > 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $dealer_id]);
                        $note = "Package Renew/Upgrade for $u. Deducted: Rs. $net_deduction";
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                    }

                    $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active' WHERE id = ?")->execute([$package_id, $expiry_date, $id]);
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
                    }
                    $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
                    
                    $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Package Renewed')")->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);
                    $pdo->commit();
                    echo "<script>alert('User renewed successfully!'); window.location='users.php';</script>";
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo "<script>alert('Error renewing user.');</script>";
                }
            }
        }
    }

    // ADD USER
    if ($action === 'add_user' && $can_create) {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $package_id = (int)$_POST['package_id'];
        $full_name = trim($_POST['full_name']);
        $expiry_date = $_POST['expiry_date'] ?? null;
        
        if (!$can_custom_expiry || empty($expiry_date)) {
            $expiry_date = date('Y-m-d\TH:i', strtotime('+30 days'));
        }

        $p = $pdo->prepare("SELECT rate_limit, price FROM packages WHERE id = ?");
        $p->execute([$package_id]);
        $pkg = $p->fetch();

        if ($pkg) {
            try {
                $pdo->beginTransaction();
                
                // Deduct Balance
                $dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                $dpStmt->execute([$dealer_id, $package_id]);
                $dp_price = $dpStmt->fetchColumn() ?: ($pkg['price'] ?? 0);
                
                $deduction = 0;
                $seconds = strtotime($expiry_date) - time();
                if ($seconds > 0) {
                    $days = ceil($seconds / 86400);
                    $deduction = round($days * ($dp_price / 30), 2);
                }
                
                if ($deduction > $current_dealer['balance']) {
                    $pdo->rollBack();
                    echo "<script>alert('Insufficient balance to create this user!'); window.location='users.php';</script>";
                    exit;
                }
                
                if ($deduction > 0) {
                    $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$deduction, $dealer_id]);
                    $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, "Created user $username. Deducted: Rs. $deduction"]);
                }

                $pdo->prepare("INSERT INTO subscribers (client_id, dealer_id, package_id, username, password, service_type, full_name, expiry_date) VALUES (?, ?, ?, ?, ?, 'pppoe', ?, ?)")
                    ->execute([$client_id, $dealer_id, $package_id, $username, $password, $full_name, $expiry_date]);
                
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$username, $password]);
                
                if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$username, $pkg['rate_limit']]);
                }
                
                $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_expiry]);
                
                $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Created New User')")->execute([$client_id, $dealer_id, $current_dealer['username'], $username]);
                
                $pdo->commit();
                echo "<script>alert('User created successfully!'); window.location='users.php';</script>";
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo "<script>alert('Error: Username might already exist.');</script>";
            }
        }
    }
}

// Fetch Dealer Packages for Modals
$dpStmt = $pdo->prepare("SELECT p.id, p.name, p.rate_limit, dp.dealer_price FROM dealer_packages dp JOIN packages p ON dp.package_id = p.id WHERE dp.dealer_id = ? ORDER BY p.name ASC");
$dpStmt->execute([$dealer_id]);
$dealer_packages = $dpStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dealer's Users
$sql = "SELECT s.*, p.name as package_name, 
        (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online
        FROM subscribers s 
        LEFT JOIN packages p ON s.package_id = p.id 
        WHERE s.dealer_id = ? 
        ORDER BY s.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dealer_id]);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold"><i class="fa-solid fa-users text-primary me-2"></i> My Users</h4>
    <?php if($can_create): ?>
    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa-solid fa-user-plus me-1"></i> Add New User</button>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="table-responsive">
            <table id="usersTable" class="table table-hover table-borderless align-middle w-100">
                <thead class="table-light">
                    <tr>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Package</th>
                        <th>Status</th>
                        <th>Expiry</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($subs as $s): ?>
                    <tr class="border-bottom">
                        <td><span class="badge bg-primary fs-6"><?= htmlspecialchars($s['username']) ?></span></td>
                        <td class="fw-bold"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td><?= htmlspecialchars($s['package_name']) ?></td>
                        <td>
                            <?php if($s['is_online'] > 0): ?>
                                <span class="badge bg-success">Online</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Offline</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($s['expiry_date']): ?>
                                <?php if(strtotime($s['expiry_date']) < time()): ?>
                                    <span class="badge bg-danger">Expired<br><small><?= date('d M Y', strtotime($s['expiry_date'])) ?></small></span>
                                <?php else: ?>
                                    <span class="badge bg-success"><?= date('d M Y H:i', strtotime($s['expiry_date'])) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-secondary">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-success" onclick="openRenewModal(<?= $s['id'] ?>, '<?= addslashes($s['username']) ?>')"><i class="fa-solid fa-rotate"></i> Renew</button>
                                <?php if($can_delete): ?>
                                <form method="POST" onsubmit="return confirm('Delete this user?');" class="m-0">
                                    <input type="hidden" name="action" value="delete_id">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if($can_create): ?>
<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Create User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Username <span class="text-danger">*</span></label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <input type="text" name="password" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Package <span class="text-danger">*</span></label>
                <select name="package_id" class="form-select" required>
                    <option value="">Select Package</option>
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (Rs.<?= $p['dealer_price'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if($can_custom_expiry): ?>
            <div class="mb-3">
                <label class="form-label">Custom Expiry (Optional)</label>
                <input type="datetime-local" name="expiry_date" class="form-control">
                <small class="text-muted">Leave blank for standard 30 days.</small>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Renew Modal -->
<div class="modal fade" id="renewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Renew / Change Package</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="renew_user">
        <input type="hidden" name="id" id="renew_user_id">
        <div class="modal-body p-4">
            <p><strong>Username:</strong> <span id="renew_username_display" class="badge bg-primary"></span></p>
            <div class="mb-3">
                <label class="form-label">New Package <span class="text-danger">*</span></label>
                <select name="package_id" class="form-select" required>
                    <option value="">Select Package</option>
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (Rs.<?= $p['dealer_price'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if($can_custom_expiry): ?>
            <div class="mb-3">
                <label class="form-label">Custom Expiry (Optional)</label>
                <input type="datetime-local" name="expiry_date" class="form-control">
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Renew User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#usersTable').DataTable({
        language: { lengthMenu: "Show _MENU_ entries" }
    });
});
function openRenewModal(id, username) {
    document.getElementById('renew_user_id').value = id;
    document.getElementById('renew_username_display').innerText = username;
    var modal = new bootstrap.Modal(document.getElementById('renewModal'));
    modal.show();
}
// Form Validation
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms).forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
    })
})()
</script>

<?php require_once 'footer.php'; ?>
PHP;

file_put_contents("$dl_dir/users.php", $users);

// ---------------------------------------------------------
// FOOTER.PHP
// ---------------------------------------------------------
$footer = <<<'PHP'
    </div> <!-- End Main Content -->
    
    <!-- Add Funds Modal (Global for Dealer) -->
    <div class="modal fade" id="addFundsModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border border-secondary shadow-lg text-light" style="border-radius: 16px;">
          <div class="modal-header border-bottom border-secondary p-4">
            <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-wallet me-2"></i> Recharge Wallet</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form action="fund_action.php" method="POST">
            <div class="modal-body p-3">
                <div class="text-center mb-3">
                    <h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                    <div class="text-secondary small mb-2">Bank Name: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>

                    <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #10b981;">
                        <img id="fund_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=SB-LINK-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label text-secondary fw-bold small mb-1">Enter Recharge Amount (Rs)</label>
                    <input type="number" name="amount" id="fund_amount" class="form-control form-control-sm bg-dark border-secondary text-light fs-6" placeholder="e.g. 500" required onkeyup="updateFundQR()">
                </div>
                
                <div class="mb-2">
                    <label class="form-label text-secondary fw-bold small mb-1">Transaction Reference ID</label>
                    <input type="text" name="payment_reference" class="form-control form-control-sm bg-dark border-secondary text-light" placeholder="e.g. TID987654321" required>
                </div>
                <div class="text-secondary" style="font-size: 0.75rem;">Scan the QR Code to pay. After paying, submit the request.</div>
            </div>
            <div class="modal-footer border-top border-secondary p-2 d-flex justify-content-between">
              <button type="button" class="btn btn-sm btn-outline-secondary px-3 rounded-pill" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-sm btn-success px-4 rounded-pill fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Submit Recharge</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function updateFundQR() {
        var amount = document.getElementById('fund_amount').value || "0";
        var qrData = encodeURIComponent("FUNDS_DEALER_<?= $current_dealer['id'] ?>_AMT_" + amount + "_TS_" + Date.now());
        var qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" + qrData;
        document.getElementById('fund_qr_code').src = qrUrl;
    }
    </script>
</body>
</html>
PHP;

file_put_contents("$dl_dir/footer.php", $footer);

echo "Fully restricted Dealer Panel created successfully!";
?>
