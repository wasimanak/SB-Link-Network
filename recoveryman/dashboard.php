<?php
session_start();
if (!isset($_SESSION['rm_id'])) { header("Location: login.php"); exit; }
require_once '../config/db.php';

// Auto-upgrade recovery_men table for cash_in_hand
try {
    $pdo->exec("ALTER TABLE `recovery_men` ADD COLUMN `cash_in_hand` DECIMAL(10,2) DEFAULT 0.00");
} catch (PDOException $e) {}



$rm_id = $_SESSION['rm_id'];
$client_id = $_SESSION['client_id'];
$rm_name = $_SESSION['rm_name'];


$pkgsStmt = $pdo->prepare("SELECT id, name, price FROM packages WHERE client_id = ? OR client_id = 0 ORDER BY name ASC");
$pkgsStmt->execute([$client_id]);
$all_packages = $pkgsStmt->fetchAll(PDO::FETCH_ASSOC);

// Check RM permissions
$chkStmt = $pdo->prepare("SELECT status, can_create_user FROM recovery_men WHERE id = ?");
$chkStmt->execute([$rm_id]);
$rm_data = $chkStmt->fetch();
if (!$rm_data || $rm_data['status'] === 'disabled') {
    session_destroy();
    header("Location: login.php");
    exit;
}


// Handle Renew & Receive Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_only') {
    $sub_id = (int)$_POST['subscriber_id'];
    $sub_username = trim($_POST['subscriber_username']);
    $amount = (float)$_POST['amount'];
    $note = trim($_POST['note']);
    $payment_date = trim($_POST['payment_date']);
    
    if (empty($payment_date)) {
        $payment_date = date('Y-m-d H:i:s');
    } else {
        $payment_date = date('Y-m-d H:i:s', strtotime($payment_date));
    }

    try {
        $pdo->beginTransaction();

        if ($amount > 0) {
            // Add to User Balance (Adding money increases their balance/wallet)
            $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);
            
            // Fetch updated balance
            $tmpBalStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $tmpBalStmt->execute([$sub_id]);
            $balAfterPayment = $tmpBalStmt->fetchColumn();
            
            // Ledger Entry with custom date
            $desc = "Payment collected by {$rm_name} RM" . ($note ? " - Note: $note" : "");
            
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description, created_at) VALUES (?, ?, 'credit', ?, ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $balAfterPayment, $desc, $payment_date]);
                
            // Update RM Cash in Hand
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?")->execute([$amount, $rm_id]);
            
            // Log Activity
            $act_msg = "Collected Cash Rs. $amount from User" . ($note ? " - Note: $note" : "");
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
                ->execute([$client_id, $rm_name, $sub_username, $act_msg]);
                
            $pdo->commit();
            echo "<script>
    alert('Payment Collected Successfully!');
    var pw = window.open('print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=collect', '_blank');
    if(pw){ window.location='dashboard.php'; } else { window.location='print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=collect'; }
</script>";
            exit;
        } else {
            $pdo->rollBack();
            echo "<script>alert('Error: Amount must be greater than 0.'); window.history.back();</script>";
            exit;
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_payment') {
    $sub_id = (int)$_POST['subscriber_id'];
    $sub_username = $_POST['subscriber_username'];
    $amount = (float)$_POST['amount']; // Can be 0 if just renewing without collecting
    $new_package_id = isset($_POST['new_package_id']) && $_POST['new_package_id'] !== '' ? (int)$_POST['new_package_id'] : 0;
    $note = trim($_POST['note']);

    try {
        $pdo->beginTransaction();
        
        if ($new_package_id > 0) {
            $pdo->prepare("UPDATE subscribers SET package_id = ? WHERE id = ?")->execute([$new_package_id, $sub_id]);
            
            // Sync FreeRADIUS to new package
            $npStmt = $pdo->prepare("SELECT name, rate_limit FROM packages WHERE id = ?");
            $npStmt->execute([$new_package_id]);
            $newPkgInfo = $npStmt->fetch();
            if ($newPkgInfo) {
                $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$sub_username]);
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$sub_username, $newPkgInfo['name']]);
                
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$sub_username]);
                if (!empty($newPkgInfo['rate_limit']) && $newPkgInfo['rate_limit'] !== 'Unlimited') {
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$sub_username, $newPkgInfo['rate_limit']]);
                }
            }
        }
        
        // Fetch current expiry, package price, and balance
        $stmt = $pdo->prepare("SELECT s.expiry_date, s.balance, p.price, p.name as package_name FROM subscribers s LEFT JOIN packages p ON s.package_id = p.id WHERE s.id = ?");
        $stmt->execute([$sub_id]);
        $subData = $stmt->fetch();
        
        $package_price = (float)($subData['price'] ?? 0);
        $package_name = $subData['package_name'] ?? 'Unknown Package';
        $current_expiry = strtotime($subData['expiry_date']);
        $now = time();
        
        // 1. Extend Expiry by 1 Month (30 Days)
        if ($current_expiry && $current_expiry > $now) {
            $new_expiry_time = strtotime('+1 month', $current_expiry);
        } else {
            $new_expiry_time = strtotime('+1 month', $now);
        }
        
        $new_expiry_db = date('Y-m-d H:i:s', $new_expiry_time);
        $new_expiry_rad = date('d M Y H:i:s', $new_expiry_time);

        $pdo->prepare("UPDATE subscribers SET expiry_date = ?, status = 'active' WHERE id = ?")->execute([$new_expiry_db, $sub_id]);
        
        // Update FreeRADIUS radcheck
        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$sub_username]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$sub_username, $new_expiry_rad]);

        // 2. Adjust Balance & Ledger
        // Step A: Charge the Package Price (Debit)
        if ($package_price > 0) {
            $pdo->prepare("UPDATE subscribers SET balance = balance - ? WHERE id = ?")->execute([$package_price, $sub_id]);
            
            $tmpBalStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $tmpBalStmt->execute([$sub_id]);
            $balAfterCharge = $tmpBalStmt->fetchColumn();
            
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, description, balance_after) VALUES (?, ?, 'debit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $package_price, "Package Renewal ($package_name)", $balAfterCharge]);
        }
        
        // Step B: Add the Collected Amount (Credit)
        if ($amount > 0) {
            $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);
            
            $tmpBalStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $tmpBalStmt->execute([$sub_id]);
            $balAfterPayment = $tmpBalStmt->fetchColumn();
            
            $desc = "Cash collected by {$rm_name} RM" . ($note ? " - Note: $note" : "");
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $balAfterPayment, $desc]);
                
            // Update Recovery Man's Cash in Hand
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?")->execute([$amount, $rm_id]);
        }

        // 3. Activity Log
        $activity_msg = "Renewed Package. Charged Rs. $package_price, Collected Rs. $amount. Expiry extended to " . date('d M Y', $new_expiry_time);
        $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
            ->execute([$client_id, $rm_name, $sub_username, $activity_msg]);
            
        $pdo->commit();
        
        $alert_msg = "Package Renewed Successfully!\\nCharged: Rs. $package_price\\nCollected: Rs. $amount\\nExpiry extended to " . date('d M Y', $new_expiry_time);
        $sq = urlencode($_GET['search_query']??'');
echo "<script>
    alert('$alert_msg');
    var pw = window.open('print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=renew', '_blank');
    if(pw){ window.location='dashboard.php?search_query=$sq'; } else { window.location='print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=renew'; }
</script>";
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error processing renewal.'); window.history.back();</script>";
        exit;
    }
}
// Handle Create New User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    if(!isset($rm_data['can_create_user']) || $rm_data['can_create_user'] !== 'yes') { die('Permission Denied.'); }

    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $mobile = trim($_POST['mobile']);
    $address = trim($_POST['address']);
    $package_id = (int)$_POST['package_id'];
    $amount_collected = (float)$_POST['amount_collected'];

    try {
        $pdo->beginTransaction();
        
        // Check if username exists
        $chk = $pdo->prepare("SELECT id FROM subscribers WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            throw new Exception("Username '$username' already exists!");
        }

        // Get Package Details
        $pkgStmt = $pdo->prepare("SELECT name, price, rate_limit FROM packages WHERE id = ?");
        $pkgStmt->execute([$package_id]);
        $pkg = $pkgStmt->fetch();
        if (!$pkg) throw new Exception("Invalid package selected.");
        
        $package_price = (float)$pkg['price'];
        
        // 1 Month Expiry
        $now = time();
        $new_expiry_time = strtotime('+1 month', $now);
        $new_expiry_db = date('Y-m-d H:i:s', $new_expiry_time);
        $new_expiry_rad = date('d M Y H:i:s', $new_expiry_time);
        
        // Calculate Initial Balance (Collected - Package Price)
        $initial_balance = $amount_collected - $package_price;

        // Insert Subscriber
        $insSub = $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, full_name, mobile, address, service_type, status, balance, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, 'pppoe', 'active', ?, ?)");
        $insSub->execute([$client_id, $package_id, $username, $password, $full_name, $mobile, $address, $initial_balance, $new_expiry_db]);
        
        // Insert RADIUS basics
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$username, $password]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $new_expiry_rad]);
        
        if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$username, $pkg['rate_limit']]);
        }
        
        // Ledger Entries
        // A. Package Charge
        $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, description, balance_after) VALUES (?, ?, 'debit', ?, ?, ?)")
            ->execute([$client_id, $username, $package_price, "Account Created. Initial Package Charge ({$pkg['name']})", (0 - $package_price)]);
            
        // B. Amount Collected
        if ($amount_collected > 0) {
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, description, balance_after) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $username, $amount_collected, "Initial Payment Collected by RM: " . $_SESSION['rm_username'], $initial_balance]);
                
            // Update RM Cash
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?")->execute([$amount_collected, $rm_id]);
        }

        // Activity Log
        $activity_msg = "Created New User with {$pkg['name']}. Charged: Rs. $package_price. Collected: Rs. $amount_collected. Expiry: " . date('d M Y', $new_expiry_time);
        $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
            ->execute([$client_id, $rm_name, $username, $activity_msg]);

        $pdo->commit();
        echo "<script>alert('User Created Successfully!\\nExpiry set to 1 Month.\\nBalance: Rs. $initial_balance'); window.location='dashboard.php';</script>";
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    }
}

// Search Logic
$search_results = [];
if (isset($_GET['search_query']) && !empty(trim($_GET['search_query']))) {
    $search = trim($_GET['search_query']);
    $sStmt = $pdo->prepare("
        SELECT s.id, s.username, s.full_name, s.balance, s.status, s.expiry_date, s.phone, s.address, p.name as package_name, p.price as package_price,
               (SELECT SUM(acctinputoctets) FROM radacct r WHERE r.username = s.username) as upload_bytes,
               (SELECT SUM(acctoutputoctets) FROM radacct r WHERE r.username = s.username) as download_bytes,
               (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
        FROM subscribers s
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE (s.username LIKE ? OR s.full_name LIKE ? OR s.phone LIKE ?) AND s.client_id = ?
        LIMIT 20
    ");
    $sStmt->execute(["%$search%", "%$search%", "%$search%", $client_id]);
    $search_results = $sStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Metric Queries
$now = date('Y-m-d H:i:s');
$count_expired = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND (expiry_date < NOW() OR status = 'expired')"); $count_expired->execute([$client_id]); $c_expired = $count_expired->fetchColumn();
$count_1d = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)"); $count_1d->execute([$client_id]); $c_1d = $count_1d->fetchColumn();
$count_3d = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)"); $count_3d->execute([$client_id]); $c_3d = $count_3d->fetchColumn();
$count_1w = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"); $count_1w->execute([$client_id]); $c_1w = $count_1w->fetchColumn();
$count_2w = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)"); $count_2w->execute([$client_id]); $c_2w = $count_2w->fetchColumn();

// Filter Logic for Main Table
$filter = $_GET['filter'] ?? 'expired';
$filter_sql = "AND (s.expiry_date < NOW() OR s.status = 'expired')";
$filter_title = "Expired Users";

if ($filter === 'expiring_1d') {
    $filter_sql = "AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)";
    $filter_title = "Expiring in 1 Day";
} elseif ($filter === 'expiring_3d') {
    $filter_sql = "AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)";
    $filter_title = "Expiring in 3 Days";
} elseif ($filter === 'expiring_1w') {
    $filter_sql = "AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
    $filter_title = "Expiring in 1 Week";
} elseif ($filter === 'expiring_2w') {
    $filter_sql = "AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)";
    $filter_title = "Expiring in 2 Weeks";
}

// Fetch Filtered Users
$filteredStmt = $pdo->prepare("
    SELECT s.id, s.username, s.full_name, s.balance, s.status, s.expiry_date, s.phone, s.address, p.name as package_name, p.price as package_price,
           (SELECT SUM(acctinputoctets) FROM radacct r WHERE r.username = s.username) as upload_bytes,
           (SELECT SUM(acctoutputoctets) FROM radacct r WHERE r.username = s.username) as download_bytes,
           (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE s.client_id = ? $filter_sql
    ORDER BY s.expiry_date ASC
    LIMIT 100
");
$filteredStmt->execute([$client_id]);
$expired_users = $filteredStmt->fetchAll(PDO::FETCH_ASSOC); // Overwrite the expired_users variable so the table renders it

// Fetch Today's Collection for this RM
$collStmt = $pdo->prepare("
    SELECT SUM(amount) FROM user_ledger 
    WHERE client_id = ? AND description LIKE ? AND DATE(created_at) = CURDATE()
");
$collStmt->execute([$client_id, "Cash collected by {$rm_name} RM%"]);
$today_collection = $collStmt->fetchColumn() ?: 0;

// Fetch RM Cash in Hand
$cashStmt = $pdo->prepare("SELECT cash_in_hand FROM recovery_men WHERE id = ?");
$cashStmt->execute([$rm_id]);
$rm_cash_in_hand = $cashStmt->fetchColumn() ?: 0;

// Fetch Pending (Minus Balance) Users Count
$pendingCountStmt = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND balance < 0");
$pendingCountStmt->execute([$client_id]);
$pending_count = (int)$pendingCountStmt->fetchColumn();

function formatBytes($bytes) {
    if ($bytes <= 0) return "0 MB";
    $bytes = $bytes / (1024 * 1024);
    if ($bytes > 1024) return round($bytes/1024, 2) . " GB";
    return round($bytes, 2) . " MB";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= BRAND_NAME ?> - Operator Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f1f5f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .top-navbar { background: #0f172a; color: white; padding: 15px 20px; border-bottom: 3px solid #dc2626; display: flex; justify-content: space-between; align-items: center; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="top-navbar">
    <div class="fw-bold fs-5"><i class="fa-solid fa-motorcycle me-2 text-danger"></i> <?= BRAND_SHORT_NAME ?> Operator Portal</div>
    <div>
        <span class="me-3 small text-light d-none d-md-inline"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($rm_name) ?></span>
        <a href="pending_payments.php" class="btn btn-sm btn-warning text-dark fw-bold me-2 shadow-sm">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Pending Payments
            <?php if ($pending_count > 0): ?>
                <span class="badge bg-danger text-white rounded-pill ms-1"><?= $pending_count ?></span>
            <?php endif; ?>
        </a>
        <a href="history.php" class="btn btn-sm btn-light text-dark fw-bold me-2"><i class="fa-solid fa-clock-rotate-left me-1"></i> History</a>
        <a href="logout.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
</div>

<div class="container mt-4">
    <div class="row">
        <!-- Collection & Balance Stats -->
        <div class="col-6 mb-2">
            <div class="card card-custom bg-danger text-white h-100 shadow-sm mb-0">
                <div class="card-body p-2 text-center">
                    <h6 class="mb-1 text-white-50 fw-bold small">Today's Collection</h6>
                    <h5 class="mb-0 fw-bold">Rs. <?= number_format($today_collection) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-6 mb-2">
            <div class="card card-custom bg-success text-white h-100 shadow-sm mb-0">
                <div class="card-body p-2 text-center">
                    <h6 class="mb-1 text-white-50 fw-bold small">Pending Cash</h6>
                    <h5 class="mb-0 fw-bold">Rs. <?= number_format($rm_cash_in_hand) ?></h5>
                </div>
            </div>
        </div>

        <!-- Search Box -->
        <div class="col-md-12 mb-4">
            <div class="card card-custom">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold m-0"><i class="fa-solid fa-search me-2 text-primary"></i> Find Subscriber</h5>
    <?php if(isset($rm_data["can_create_user"]) && $rm_data["can_create_user"] === "yes"): ?>
<button type="button" class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa-solid fa-user-plus me-1"></i> Add New User</button>
<?php endif; ?>
</div>
<form method="GET" class="d-flex gap-2">
                        <input type="text" name="search_query" class="form-control form-control-lg bg-light" placeholder="Search by Name, Username or Phone..." value="<?= isset($_GET['search_query']) ? htmlspecialchars($_GET['search_query']) : '' ?>" required>
                        <button type="submit" class="btn btn-danger px-4 fw-bold"><i class="fa-solid fa-magnifying-glass me-2"></i> Search</button>
                    </form>
                </div>
            </div>

            

<!-- Search Results -->
            <?php if(isset($_GET['search_query'])): ?>
                <?php if($search_results): ?>
                    <div class="list-group shadow-sm border-0 rounded-4 mb-4">
                        <?php foreach($search_results as $u): ?>
<div class="list-group-item p-2 border border-secondary border-opacity-25 rounded-3 mb-2 shadow-sm">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="fw-bold mb-1 text-dark" style="font-size:0.9rem;"><?= htmlspecialchars($u['full_name']) ?> <span class="badge bg-primary ms-1"><?= htmlspecialchars($u['username']) ?></span></div>
                                    <div class="text-secondary small fw-bold"><i class="fa-solid fa-box me-1"></i> <?= htmlspecialchars($u['package_name']) ?> (Rs. <?= number_format($u['package_price'], 0) ?>)</div>
                                </div>
                                <div>
                                    <?php if($u['live_ip']): ?>
                                        <span class="badge bg-success rounded-pill"><i class="fa-solid fa-wifi me-1"></i> Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill">Offline</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row g-2 text-secondary small mb-3">
                                <div class="col-6">
                                    <i class="fa-solid fa-calendar-xmark text-danger me-1"></i> Expiry: <strong class="text-dark"><?= $u['expiry_date'] ? date('d M Y', strtotime($u['expiry_date'])) : 'N/A' ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-phone text-primary me-1"></i> Phone: <strong class="text-dark"><?= htmlspecialchars($u['phone'] ?: 'N/A') ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-arrow-down text-success me-1"></i> Download: <strong class="text-dark"><?= formatBytes($u['download_bytes']??0) ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-arrow-up text-primary me-1"></i> Upload: <strong class="text-dark"><?= formatBytes($u['upload_bytes']??0) ?></strong>
                                </div>
                                <div class="col-12 mt-1">
                                    <i class="fa-solid fa-location-dot text-warning me-1"></i> Address: <?= htmlspecialchars($u['address'] ?: 'N/A') ?>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded-3 border">
                                <div>
                                    <span class="small fw-bold text-secondary">Current Balance</span><br>
                                    <strong style="font-size:0.9rem;" class="<?= $u['balance'] < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= number_format($u['balance'], 0) ?></strong>
                                </div>
                                <div class="text-end">
                                    <button class="btn btn-success btn-sm px-2 py-1 fw-bold shadow-sm mb-1 d-block w-100" data-bs-toggle="modal" data-bs-target="#collectModal<?= $u['id'] ?>">
                                        <i class="fa-solid fa-hand-holding-dollar"></i> Collect
                                    </button>
                                    <button class="btn btn-danger btn-sm px-2 py-1 fw-bold shadow-sm d-block w-100" onclick="openCollectModal('<?= $u['username'] ?>', <?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= $u['balance'] ?>')">
                                        <i class="fa-solid fa-rotate"></i> Renew
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Collect Payment Modal -->
                            <div class="modal fade" id="collectModal<?= $u['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                                        <div class="modal-header border-bottom p-4">
                                            <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Payment</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="collect_only">
                                            <input type="hidden" name="subscriber_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="subscriber_username" value="<?= htmlspecialchars($u['username']) ?>">
                                            
                                            <div class="modal-body p-4 text-start">
                                                <div class="alert alert-success bg-opacity-10 text-success border-success border-opacity-25 py-2 mb-3">
                                                    <strong>User:</strong> <?= htmlspecialchars($u['username']) ?><br>
                                                    <strong>Current Balance:</strong> Rs. <?= number_format($u['balance'], 0) ?>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Payment Date & Time</label>
                                                    <input type="datetime-local" name="payment_date" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Collected Amount (Rs) <span class="text-danger">*</span></label>
                                                    <input type="number" step="1" name="amount" class="form-control form-control-lg fw-bold text-success" placeholder="e.g. 1500" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Note (Optional)</label>
                                                    <input type="text" name="note" class="form-control" placeholder="e.g. Previous month pending">
                                                </div>
                                            </div>
                                            <div class="modal-footer border-top p-3">
                                                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">Save Payment</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
<?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning fw-bold shadow-sm"><i class="fa-solid fa-circle-exclamation me-2"></i> No users found matching your search.</div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- Expired Users List -->
            <?php if(!isset($_GET['search_query']) || empty(trim($_GET['search_query']))): ?>
                
<!-- Upcoming Recovery Metrics -->
<div class="row g-2 mb-3 mt-1">
    <div class="col-md-4 col-4">
        <a href="?filter=expired" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter=='expired')?'bg-danger text-white':'bg-white' ?> shadow-sm">
                <div class="card-body text-center p-2 py-2">
                    <div class="fw-bold mb-0 text-uppercase" style="font-size:0.65rem;" <?= ($filter=='expired')?'':'text-danger' ?>">Expired</div>
                    <div class="fw-bold mb-0" style="font-size:1.1rem;""><?= $c_expired ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-4">
        <a href="?filter=expiring_1d" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter=='expiring_1d')?'bg-warning text-dark':'bg-white' ?> shadow-sm">
                <div class="card-body text-center p-2 py-2">
                    <div class="fw-bold mb-0 text-uppercase" style="font-size:0.65rem;" <?= ($filter=='expiring_1d')?'':'text-warning' ?>">1 Day</div>
                    <div class="fw-bold mb-0" style="font-size:1.1rem;" <?= ($filter=='expiring_1d')?'':'text-dark' ?>"><?= $c_1d ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-4">
        <a href="?filter=expiring_3d" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter=='expiring_3d')?'bg-info text-white':'bg-white' ?> shadow-sm">
                <div class="card-body text-center p-2 py-2">
                    <div class="fw-bold mb-0 text-uppercase" style="font-size:0.65rem;" <?= ($filter=='expiring_3d')?'':'text-info' ?>">3 Days</div>
                    <div class="fw-bold mb-0" style="font-size:1.1rem;" <?= ($filter=='expiring_3d')?'':'text-dark' ?>"><?= $c_3d ?></div>
                </div>
            </div>
        </a>
    </div>
    
    
</div>

<h5 class="fw-bold mt-5 border-bottom pb-2 mb-3 <?= ($filter==='expired')?'text-danger':'text-primary' ?>"><i class="fa-solid <?= ($filter==='expired')?'fa-triangle-exclamation':'fa-clock-rotate-left' ?> me-2"></i> <?= htmlspecialchars($filter_title) ?></h5>
                <?php if($expired_users): ?>
                    <div class="list-group border-0 mb-4">
                        <?php foreach($expired_users as $u): ?>
                        <div class="list-group-item p-2 border <?= ($filter==='expired')?'border-danger':'border-primary' ?> border-opacity-25 rounded-3 mb-2 shadow-sm bg-white">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <div class="fw-bold mb-1 text-dark" style="font-size:0.9rem;"><?= htmlspecialchars($u['full_name']) ?> <span class="badge bg-primary ms-1"><?= htmlspecialchars($u['username']) ?></span></div>
                                    <div class="text-secondary small fw-bold"><i class="fa-solid fa-box me-1"></i> <?= htmlspecialchars($u['package_name']) ?> (Rs. <?= number_format($u['package_price'], 0) ?>)</div>
                                </div>
                                <div>
                                    <?php if($u['live_ip']): ?>
                                        <span class="badge bg-success rounded-pill"><i class="fa-solid fa-wifi me-1"></i> Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger rounded-pill">Expired</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="row g-2 text-secondary small mb-3">
                                <div class="col-6">
                                    <i class="fa-solid fa-calendar-xmark text-danger me-1"></i> Expiry: <strong class="text-danger"><?= $u['expiry_date'] ? date('d M Y', strtotime($u['expiry_date'])) : 'N/A' ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-phone text-primary me-1"></i> Phone: <strong class="text-dark"><?= htmlspecialchars($u['phone'] ?: 'N/A') ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-arrow-down text-success me-1"></i> Download: <strong class="text-dark"><?= formatBytes($u['download_bytes']??0) ?></strong>
                                </div>
                                <div class="col-6">
                                    <i class="fa-solid fa-arrow-up text-primary me-1"></i> Upload: <strong class="text-dark"><?= formatBytes($u['upload_bytes']??0) ?></strong>
                                </div>
                                <div class="col-12 mt-1">
                                    <i class="fa-solid fa-location-dot text-warning me-1"></i> Address: <?= htmlspecialchars($u['address'] ?: 'N/A') ?>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded-3 border">
                                <div>
                                    <span class="small fw-bold text-secondary">Current Balance</span><br>
                                    <strong style="font-size:0.9rem;" class="<?= $u['balance'] < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= number_format($u['balance'], 0) ?></strong>
                                </div>
                                <div class="text-end">
                                    <button class="btn btn-success btn-sm px-2 py-1 fw-bold shadow-sm mb-1 d-block w-100" data-bs-toggle="modal" data-bs-target="#collectModal<?= $u['id'] ?>">
                                        <i class="fa-solid fa-hand-holding-dollar"></i> Collect
                                    </button>
                                    <button class="btn btn-danger btn-sm px-2 py-1 fw-bold shadow-sm d-block w-100" onclick="openCollectModal('<?= $u['username'] ?>', <?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= $u['balance'] ?>')">
                                        <i class="fa-solid fa-rotate"></i> Renew
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Collect Payment Modal -->
                            <div class="modal fade" id="collectModal<?= $u['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                                        <div class="modal-header border-bottom p-4">
                                            <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Payment</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="collect_only">
                                            <input type="hidden" name="subscriber_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="subscriber_username" value="<?= htmlspecialchars($u['username']) ?>">
                                            
                                            <div class="modal-body p-4 text-start">
                                                <div class="alert alert-success bg-opacity-10 text-success border-success border-opacity-25 py-2 mb-3">
                                                    <strong>User:</strong> <?= htmlspecialchars($u['username']) ?><br>
                                                    <strong>Current Balance:</strong> Rs. <?= number_format($u['balance'], 0) ?>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Payment Date & Time</label>
                                                    <input type="datetime-local" name="payment_date" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Collected Amount (Rs) <span class="text-danger">*</span></label>
                                                    <input type="number" step="1" name="amount" class="form-control form-control-lg fw-bold text-success" placeholder="e.g. 1500" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Note (Optional)</label>
                                                    <input type="text" name="note" class="form-control" placeholder="e.g. Previous month pending">
                                                </div>
                                            </div>
                                            <div class="modal-footer border-top p-3">
                                                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">Save Payment</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success fw-bold shadow-sm"><i class="fa-solid fa-check-circle me-2"></i> Great! There are no expired users currently.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Collect Payment Modal -->
<div class="modal fade" id="collectModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-bottom px-4 pt-4 bg-danger text-white rounded-top-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-rotate me-2"></i> Renew User Package</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="stopLiveSpeed()"></button>
      </div>
      <div class="modal-body p-0 bg-light">
          
          <!-- Tabs -->
          <ul class="nav nav-tabs nav-fill bg-white pt-2 border-bottom shadow-sm" id="myTab" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active fw-bold text-dark" id="payment-tab" data-bs-toggle="tab" data-bs-target="#payment" type="button" role="tab"><i class="fa-solid fa-rotate me-1"></i> Renew & Collect</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link fw-bold text-dark" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab" onclick="loadHistory()"><i class="fa-solid fa-clock-rotate-left me-1"></i> History & Usage</button>
            </li>
          </ul>

          <div class="tab-content p-4" id="myTabContent">
            
            <!-- Payment Tab -->
            <div class="tab-pane fade show active" id="payment" role="tabpanel">
                <div class="text-center mb-4">
                    <h4 class="fw-bold text-dark mb-1" id="m_fullname"></h4>
                    <span class="badge bg-primary mb-2" id="m_username"></span>
                    <div class="p-3 bg-white border rounded shadow-sm d-inline-block mt-2">
                        <div class="text-secondary small fw-bold">Current Balance</div>
                        <h3 class="mb-0 fw-bold" id="m_balance"></h3>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="receive_payment">
                    <input type="hidden" name="subscriber_id" id="m_subid">
                    <input type="hidden" name="subscriber_username" id="m_subuser">
                    
                    <div class="mb-3 text-start">
                        <label class="form-label fw-bold text-secondary small">Change Package (Optional)</label>
                        <select name="new_package_id" id="m_newpkg" class="form-select fw-bold text-primary" onchange="updatePkgAmount()">
                            <option value="">-- Keep Current Package --</option>
                            <?php foreach($all_packages as $pkg): ?>
                                <option value="<?= $pkg['id'] ?>" data-price="<?= $pkg['price'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Rs <?= number_format($pkg['price']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3 text-start">
                        <label class="form-label fw-bold text-secondary small">Enter Amount Received (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="1" name="amount" id="m_amount" class="form-control form-control-lg fw-bold text-success text-center" placeholder="e.g. 1500" required>
                        <div class="form-text text-muted text-center" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-info"></i> Expiry will be extended automatically based on amount vs package price.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold text-secondary small">Notes / Remarks (Optional)</label>
                        <input type="text" name="note" class="form-control bg-white" placeholder="Month of October, etc.">
                    </div>
                    <button type="submit" class="btn btn-success btn-lg w-100 fw-bold shadow-sm"><i class="fa-solid fa-check-circle me-2"></i> Confirm Payment Receipt</button>
                </form>
            </div>

            <!-- History & Usage Tab -->
            <div class="tab-pane fade" id="history" role="tabpanel">
                
                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <div class="p-3 bg-white border border-success rounded text-center shadow-sm">
                            <div class="text-secondary small fw-bold"><i class="fa-solid fa-arrow-down text-success"></i> Live Download</div>
                            <h4 class="mb-0 fw-bold text-success mt-1"><span id="live_down">0.00</span> <small class="fs-6 text-muted">Mbps</small></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-white border border-primary rounded text-center shadow-sm">
                            <div class="text-secondary small fw-bold"><i class="fa-solid fa-arrow-up text-primary"></i> Live Upload</div>
                            <h4 class="mb-0 fw-bold text-primary mt-1"><span id="live_up">0.00</span> <small class="fs-6 text-muted">Mbps</small></h4>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-receipt me-2"></i> Lifetime Transaction History</h6>
                <div id="ledger_content" class="text-center py-4">
                    <div class="spinner-border text-danger spinner-border-sm" role="status"></div> Loading history...
                </div>

            </div>

          </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let speedInterval = null;
let currentUsername = '';

function openCollectModal(username, id, fullname, balance) {
    document.getElementById('m_username').innerText = username;
    document.getElementById('m_subuser').value = username;
    document.getElementById('m_subid').value = id;
    document.getElementById('m_fullname').innerText = fullname;
    
    let balColor = parseFloat(balance) < 0 ? 'text-danger' : 'text-success';
    document.getElementById('m_balance').innerHTML = `<span class="${balColor}">Rs. ${balance}</span>`;
    
    currentUsername = username;
    
    // Switch to payment tab by default
    var triggerEl = document.querySelector('#payment-tab');
    bootstrap.Tab.getInstance(triggerEl) || new bootstrap.Tab(triggerEl).show();

    var modal = new bootstrap.Modal(document.getElementById('collectModal'));
    modal.show();
    
    // Load history immediately so the bottom section populates
    loadHistory();
}

function loadHistory() {
    if(!currentUsername) return;
    
    // Fetch Ledger History
    fetch(`api_user_details.php?username=${encodeURIComponent(currentUsername)}`)
        .then(res => res.text())
        .then(html => { document.getElementById('ledger_content').innerHTML = html; })
        .catch(err => { document.getElementById('ledger_content').innerHTML = '<span class="text-danger">Failed to load history.</span>'; });

    // Start live speed polling
    startLiveSpeed();
}

let lastBytesIn = null;
let lastBytesOut = null;
let lastTime = null;

function startLiveSpeed() {
    stopLiveSpeed();
    lastBytesIn = null; lastBytesOut = null; lastTime = null;
    
    function fetchSpeed() {
        fetch(`../lineman/api_bandwidth.php?username=${encodeURIComponent(currentUsername)}`)
        .then(response => response.json())
        .then(data => {
            if (data.error || data.msg) {
                document.getElementById('live_down').innerText = "0.00";
                document.getElementById('live_up').innerText = "0.00";
                return;
            }
            let currentBytesIn = data.bytes_in;
            let currentBytesOut = data.bytes_out;
            let currentTime = Date.now();
            if (lastBytesIn !== null && lastBytesOut !== null && lastTime !== null) {
                let timeDiffSecs = (currentTime - lastTime) / 1000;
                if (timeDiffSecs > 0) {
                    let bytesInDiff = currentBytesIn - lastBytesIn;
                    let bytesOutDiff = currentBytesOut - lastBytesOut;
                    if (bytesInDiff < 0) bytesInDiff = 0;
                    if (bytesOutDiff < 0) bytesOutDiff = 0;
                    let rx_mbps = (bytesInDiff * 8 / timeDiffSecs) / 1048576; 
                    let tx_mbps = (bytesOutDiff * 8 / timeDiffSecs) / 1048576; 
                    document.getElementById('live_up').innerText = rx_mbps.toFixed(2);
                    document.getElementById('live_down').innerText = tx_mbps.toFixed(2);
                }
            }
            lastBytesIn = currentBytesIn; lastBytesOut = currentBytesOut; lastTime = currentTime;
        }).catch(err => {});
    }
    
    speedInterval = setInterval(fetchSpeed, 3000);
    fetchSpeed();
}

function stopLiveSpeed() {
    if(speedInterval) clearInterval(speedInterval);
}
</script>
<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-bottom px-4 pt-4 bg-primary text-white rounded-top-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus me-2"></i> Create New User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="create_user">
        <div class="modal-body p-4 bg-light">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Full Name</label>
                    <input type="text" name="full_name" class="form-control" required placeholder="John Doe">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Mobile No.</label>
                    <input type="text" name="mobile" class="form-control" placeholder="03001234567">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Username (PPPoE ID)</label>
                    <input type="text" name="username" class="form-control" required placeholder="john123">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Password</label>
                    <input type="text" name="password" class="form-control" required placeholder="pass123">
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-secondary">Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Street 1, House 2">
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-secondary">Select Package (1 Month Validity)</label>
                    <select name="package_id" class="form-select fw-bold text-primary" required>
                        <option value="">-- Choose Package --</option>
                        <?php foreach($all_packages as $pkg): ?>
                            <option value="<?= $pkg['id'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Price: Rs <?= number_format($pkg['price']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-secondary">Amount Collected Now (Optional)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white">Rs.</span>
                        <input type="number" step="0.01" name="amount_collected" class="form-control" value="0" placeholder="0.00">
                    </div>
                    <small class="text-muted">If you don't collect the full package price, the remaining will be saved as a Minus (-) Balance for this user.</small>
                </div>
            </div>
        </div>
        <div class="modal-footer px-4 py-3 bg-white border-top">
            <button type="button" class="btn btn-light fw-bold px-4" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary fw-bold px-5 shadow-sm"><i class="fa-solid fa-check me-1"></i> Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
    body { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
    input, textarea, select, [contenteditable="true"] { -webkit-user-select: text !important; -moz-user-select: text !important; -ms-user-select: text !important; user-select: text !important; }
</style>
<script src="/security-shield.js?v=2.1"></script>
</body>
</html>