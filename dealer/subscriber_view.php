<?php
require_once 'header.php';
require_once __DIR__ . '/../config/radius_kick_helper.php';

if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit;
}
$id = (int)$_GET['id'];

// Permissions for Dealer
$can_custom_expiry = (bool)($current_dealer['perm_custom_expiry'] ?? 0);

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

// Format bytes helper
function formatBytes($bytes, $precision = 2) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0.00 MB';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    if ($pow < 2) $pow = 2; // Default at least MB
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Format session duration helper
function formatDuration($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '0s';
    $days = floor($seconds / 86400);
    $seconds %= 86400;
    $hours = floor($seconds / 3600);
    $seconds %= 3600;
    $minutes = floor($seconds / 60);
    $secs = $seconds % 60;
    
    $parts = [];
    if ($days > 0) $parts[] = "{$days}d";
    if ($hours > 0) $parts[] = "{$hours}h";
    if ($minutes > 0) $parts[] = "{$minutes}m";
    if (empty($parts) || $secs > 0) $parts[] = "{$secs}s";
    return implode(' ', $parts);
}

// Fetch Subscriber (Strictly scoped to current dealer!)
$stmt = $pdo->prepare("
    SELECT s.*, 
           p.name as package_name, p.rate_limit, p.validity_days, p.price as default_pkg_price,
           dp.dealer_price,
           n.nasname as router_nasname, n.shortname as router_shortname
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    LEFT JOIN dealer_packages dp ON (dp.dealer_id = s.dealer_id AND dp.package_id = s.package_id)
    LEFT JOIN nas n ON (s.client_id = n.client_id)
    WHERE s.id = ? AND s.dealer_id = ?
");
$stmt->execute([$id, $dealer_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "<script>alert('User not found or access denied.'); window.location='dashboard.php';</script>";
    exit;
}

$u = $user['username'];
$cur_dealer_pkg_price = ($user['dealer_price'] !== null && $user['dealer_price'] !== '') ? (float)$user['dealer_price'] : (float)($user['default_pkg_price'] ?? 0);

// Refresh current dealer balance from DB
$dBalStmt = $pdo->prepare("SELECT balance, username FROM dealers WHERE id = ?");
$dBalStmt->execute([$dealer_id]);
$dData = $dBalStmt->fetch(PDO::FETCH_ASSOC);
$current_dealer_balance = (float)($dData['balance'] ?? 0);

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. DISCONNECT USER
    if ($action === 'disconnect_user') {
        kick_subscriber_session($pdo, $u, $client_id);
        $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'User', 'User Disconnected by Dealer')")
            ->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);
        echo "<script>alert('User kicked and disconnected successfully!'); window.location='subscriber_view.php?id=$id';</script>";
        exit;
    }

    // 2. TOGGLE USER STATUS (Enable / Disable)
    if ($action === 'toggle_user') {
        $newStatus = ($user['status'] === 'active') ? 'disabled' : 'active';
        $pdo->prepare("UPDATE subscribers SET status = ? WHERE id = ? AND dealer_id = ?")->execute([$newStatus, $id, $dealer_id]);

        if ($newStatus === 'disabled') {
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$u]);
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
            kick_subscriber_session($pdo, $u, $client_id);
            $act = 'Profile Disabled & Disconnected by Dealer';
        } else {
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'")->execute([$u]);
            $act = 'Profile Enabled by Dealer';
        }

        $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'User', ?)")
            ->execute([$client_id, $dealer_id, $current_dealer['username'], $u, $act]);
        echo "<script>alert('User status changed to " . ucfirst($newStatus) . "!'); window.location='subscriber_view.php?id=$id';</script>";
        exit;
    }

    // 3. CHANGE PASSWORD
    if ($action === 'change_password') {
        $new_pass = trim($_POST['new_password'] ?? '');
        if (empty($new_pass)) {
            echo "<script>alert('Password cannot be empty!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }

        $pdo->prepare("UPDATE subscribers SET password = ? WHERE id = ? AND dealer_id = ?")->execute([$new_pass, $id, $dealer_id]);
        $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$new_pass, $u]);
        
        $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'User', 'Password changed by Dealer')")
            ->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);
        echo "<script>alert('Password updated successfully!'); window.location='subscriber_view.php?id=$id';</script>";
        exit;
    }

    // 4. ADD PAYMENT (Dealer balance -> Customer balance)
    if ($action === 'add_payment') {
        $amount = (float)($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');

        if ($amount <= 0) {
            echo "<script>alert('Please enter a valid amount.'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }

        if ($current_dealer_balance < $amount) {
            echo "<script>alert('Insufficient balance in your wallet! Available: Rs. " . number_format($current_dealer_balance, 2) . ", Required: Rs. " . number_format($amount, 2) . "'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }

        try {
            $pdo->beginTransaction();

            // 1. Deduct from Dealer
            $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$amount, $dealer_id]);
            $new_dealer_bal = $current_dealer_balance - $amount;

            // 2. Add to Subscriber
            $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ? AND dealer_id = ?")->execute([$amount, $id, $dealer_id]);
            $new_sub_bal = (float)$user['balance'] + $amount;

            // 3. Dealer Note
            $note_text = "Added Payment Rs. " . number_format($amount, 2) . " to customer " . $u . (!empty($desc) ? " ($desc)" : "") . ". Remaining wallet: Rs. " . number_format($new_dealer_bal, 2);
            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note_text]);

            // 4. User Ledger
            $ledger_desc = "Payment added by Dealer " . $current_dealer['username'] . (!empty($desc) ? " - $desc" : "");
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $u, $amount, $new_sub_bal, $ledger_desc]);

            // 5. Activity Log
            $actStr = "Added Payment: Rs. " . number_format($amount, 2) . " by Dealer " . $current_dealer['username'];
            $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'User', ?)")
                ->execute([$client_id, $dealer_id, $current_dealer['username'], $u, $actStr]);

            $pdo->commit();
            echo "<script>alert('Payment of Rs. " . number_format($amount, 2) . " successfully added to user " . addslashes($u) . "!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error processing payment: " . addslashes($e->getMessage()) . "'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
    }

    // 5. RENEW USER
    if ($action === 'renew_user') {
        $package_id = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
        $expiry_date = $_POST['expiry_date'] ?? null;
        $target_pkg_id = ($package_id > 0) ? $package_id : (int)$user['package_id'];

        $p = $pdo->prepare("SELECT name, rate_limit, price, validity_days FROM packages WHERE id = ?");
        $p->execute([$target_pkg_id]);
        $pkg = $p->fetch(PDO::FETCH_ASSOC);

        if (!$pkg) {
            echo "<script>alert('Invalid package selected.'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }

        try {
            $pdo->beginTransaction();
            $now = time();
            $old_expiry = (!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00') ? strtotime($user['expiry_date']) : 0;
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
                echo "<script>alert('Insufficient dealer wallet balance! Required: Rs. $deduction, Available: Rs. $current_dealer_balance'); window.location='subscriber_view.php?id=$id';</script>";
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
            echo "<script>alert('User renewed successfully!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error renewing user: " . addslashes($e->getMessage()) . "'); window.location='subscriber_view.php?id=$id';</script>";
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

// Check Live Session
$liveStmt = $pdo->prepare("SELECT * FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1");
$liveStmt->execute([$u]);
$live_session = $liveStmt->fetch(PDO::FETCH_ASSOC);
$is_online = !empty($live_session);

// Compute Cycle Usage (from last_renewed_at or lifetime)
$cycle_sql = "";
$cycle_params = [$u];
if (!empty($user['last_renewed_at']) && $user['last_renewed_at'] !== '0000-00-00 00:00:00') {
    $cycle_sql = " AND (acctstarttime >= ? OR (acctstoptime IS NOT NULL AND acctstoptime >= ?))";
    $cycle_params[] = $user['last_renewed_at'];
    $cycle_params[] = $user['last_renewed_at'];
}
$usageStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(acctinputoctets), 0) as total_upload,
        COALESCE(SUM(acctoutputoctets), 0) as total_download
    FROM radacct 
    WHERE username = ? $cycle_sql
");
$usageStmt->execute($cycle_params);
$usage = $usageStmt->fetch(PDO::FETCH_ASSOC);
$total_upload = (float)($usage['total_upload'] ?? 0);
$total_download = (float)($usage['total_download'] ?? 0);
$total_usage = $total_upload + $total_download;

// Fetch Recent Sessions (Last 15 Sessions)
$sessionsStmt = $pdo->prepare("SELECT * FROM radacct WHERE username = ? ORDER BY radacctid DESC LIMIT 15");
$sessionsStmt->execute([$u]);
$recent_sessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Billing / Ledger History (Last 20 records)
$ledgerStmt = $pdo->prepare("SELECT * FROM user_ledger WHERE client_id = ? AND username = ? ORDER BY id DESC LIMIT 20");
$ledgerStmt->execute([$client_id, $u]);
$ledger_entries = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

// Status Badge Logic
$is_expired = ($user['status'] === 'expired') || (!empty($user['expiry_date']) && strtotime($user['expiry_date']) < time());
$is_disabled = ($user['status'] === 'disabled');
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<style>
    .view-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; margin-bottom: 24px; }
    .profile-header { display: flex; align-items: center; gap: 16px; padding: 22px; border-bottom: 1px solid #f1f5f9; }
    .avatar-large { width: 64px; height: 64px; background: linear-gradient(135deg, #e0e7ff, #ede9fe); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: #4f46e5; flex-shrink: 0; }
    .profile-info h5 { margin: 0; font-weight: 700; color: #0f172a; font-size: 1.15rem; }
    .profile-info p { margin: 0; color: #64748b; font-size: 0.88rem; }
    
    .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
    .status-badge.online { background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); }
    .status-badge.offline { background: rgba(148, 163, 184, 0.15); color: #64748b; border: 1px solid rgba(148, 163, 184, 0.25); }
    .status-badge.active-status { background: rgba(59, 130, 246, 0.12); color: #2563eb; border: 1px solid rgba(59, 130, 246, 0.2); }
    .status-badge.expired-status { background: rgba(239, 68, 68, 0.12); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.2); }
    .status-badge.disabled-status { background: rgba(100, 116, 139, 0.15); color: #475569; border: 1px solid rgba(100, 116, 139, 0.25); }

    .profile-list { list-style: none; padding: 0; margin: 0; }
    .profile-list li { padding: 12px 22px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f8fafc; color: #475569; font-size: 0.88rem; }
    .profile-list li .label { color: #64748b; display: flex; align-items: center; gap: 10px; font-weight: 500; }
    .profile-list li .label i { width: 16px; text-align: center; color: #94a3b8; }
    .profile-list li .val { font-weight: 600; color: #1e293b; text-align: right; }

    .action-grid { padding: 18px 22px; display: flex; flex-wrap: wrap; gap: 8px; border-top: 1px solid #f1f5f9; background: #fafafa; border-radius: 0 0 14px 14px; }
    .btn-pill { background: #fff; border: 1px solid #e2e8f0; color: #334155; border-radius: 20px; padding: 6px 14px; font-size: 0.82rem; font-weight: 600; transition: all 0.2s; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
    .btn-pill:hover { background: #f8fafc; border-color: #cbd5e1; color: #0f172a; transform: translateY(-1px); }
    .btn-pill.primary { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
    .btn-pill.primary:hover { background: #dbeafe; color: #1d4ed8; }
    .btn-pill.success { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }
    .btn-pill.success:hover { background: #d1fae5; color: #047857; }
    .btn-pill.danger { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
    .btn-pill.danger:hover { background: #fee2e2; color: #b91c1c; }

    .metric-card { background: #fff; border-radius: 12px; padding: 18px; border: 1px solid #f1f5f9; box-shadow: 0 2px 8px rgba(0,0,0,0.02); display: flex; align-items: center; gap: 14px; height: 100%; }
    .metric-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; }
    .metric-data h6 { margin: 0; font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .metric-data h4 { margin: 2px 0 0 0; font-size: 1.2rem; font-weight: 700; color: #0f172a; }

    .table-custom th { background: #f8fafc; font-size: 0.82rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding: 12px 14px; }
    .table-custom td { padding: 12px 14px; font-size: 0.86rem; color: #334155; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
</style>

<!-- Top Breadcrumb & Title -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none">Dealer Dashboard</a></li>
                <li class="breadcrumb-item"><a href="dashboard.php#reportsSection" class="text-decoration-none">Subscribers</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($u) ?></li>
            </ol>
        </nav>
        <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
            <span>Customer Profile:</span>
            <span class="text-primary font-monospace"><?= htmlspecialchars($u) ?></span>
        </h4>
    </div>
    <div class="d-flex align-items-center gap-2 mt-2 mt-md-0">
        <a href="dashboard.php#reportsSection" class="btn btn-sm btn-light border rounded-pill px-3 fw-semibold">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Users
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- LEFT COLUMN: Profile Card & Actions -->
    <div class="col-lg-4 col-md-5">
        <div class="view-card">
            <div class="profile-header">
                <div class="avatar-large">
                    <i class="fa-solid fa-user-astronaut"></i>
                </div>
                <div class="profile-info flex-grow-1">
                    <h5><?= htmlspecialchars($user['full_name'] ?: $u) ?></h5>
                    <div class="d-flex align-items-center gap-1 my-1">
                        <span class="font-monospace text-primary fw-bold small"><?= htmlspecialchars($u) ?></span>
                        <button type="button" class="copy-user-btn" onclick="copyToClipboard(event, '<?= addslashes(htmlspecialchars($u)) ?>', this)" title="Copy Username">
                            <i class="fa-regular fa-copy" style="font-size: 0.82rem;"></i>
                        </button>
                    </div>
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        <?php if ($is_online): ?>
                            <span class="status-badge online"><i class="fa-solid fa-circle" style="font-size: 6px;"></i> Online</span>
                        <?php else: ?>
                            <span class="status-badge offline"><i class="fa-solid fa-circle" style="font-size: 6px;"></i> Offline</span>
                        <?php endif; ?>

                        <?php if ($is_disabled): ?>
                            <span class="status-badge disabled-status"><i class="fa-solid fa-ban"></i> Disabled</span>
                        <?php elseif ($is_expired): ?>
                            <span class="status-badge expired-status"><i class="fa-solid fa-circle-xmark"></i> Expired</span>
                        <?php else: ?>
                            <span class="status-badge active-status"><i class="fa-solid fa-check"></i> Active</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Details List -->
            <ul class="profile-list">
                <li>
                    <span class="label"><i class="fa-solid fa-wallet text-success"></i> Customer Balance</span>
                    <span class="val text-success">Rs. <?= number_format($user['balance'], 2) ?></span>
                </li>
                <li>
                    <span class="label"><i class="fa-solid fa-phone"></i> Mobile / Phone</span>
                    <span class="val"><?= htmlspecialchars($user['mobile'] ?: ($user['phone'] ?: 'N/A')) ?></span>
                </li>
                <li>
                    <span class="label"><i class="fa-solid fa-id-card"></i> National ID</span>
                    <span class="val"><?= htmlspecialchars($user['national_id'] ?: 'N/A') ?></span>
                </li>
                <li>
                    <span class="label"><i class="fa-solid fa-network-wired"></i> Service Type</span>
                    <span class="val text-uppercase badge bg-light text-dark border"><?= htmlspecialchars($user['service_type'] ?: 'PPPoE') ?></span>
                </li>
                <li>
                    <span class="label"><i class="fa-solid fa-location-dot"></i> Subarea</span>
                    <span class="val"><?= htmlspecialchars($user['subarea'] ?: 'N/A') ?></span>
                </li>
                <li>
                    <span class="label"><i class="fa-solid fa-map-pin"></i> Address</span>
                    <span class="val text-truncate" style="max-width: 170px;" title="<?= htmlspecialchars($user['address'] ?: '') ?>"><?= htmlspecialchars($user['address'] ?: 'N/A') ?></span>
                </li>
                <li>
                    <span class="label"><i class="fa-solid fa-user-tie"></i> Dealer / Seller</span>
                    <span class="val"><?= htmlspecialchars($current_dealer['username']) ?></span>
                </li>
                <li>
                    <span class="label"><i class="fa-regular fa-calendar-plus"></i> Created On</span>
                    <span class="val"><?= $user['created_at'] ? date('d M Y', strtotime($user['created_at'])) : 'N/A' ?></span>
                </li>
            </ul>

            <!-- Quick Action Buttons -->
            <div class="action-grid">
                <!-- Add Payment Button -->
                <button type="button" class="btn-pill primary" data-bs-toggle="modal" data-bs-target="#paymentModal">
                    <i class="fa-solid fa-money-bill-wave"></i> Add Payment
                </button>

                <!-- Renew Button -->
                <button type="button" class="btn-pill success" data-bs-toggle="modal" data-bs-target="#renewModal">
                    <i class="fa-solid fa-rotate"></i> Renew
                </button>

                <!-- Change Password -->
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#passwordModal">
                    <i class="fa-solid fa-key"></i> Password
                </button>

                <!-- Disconnect Session (If Online) -->
                <?php if ($is_online): ?>
                <form method="POST" onsubmit="return confirm('Disconnect active session for user <?= addslashes($u) ?>?');" class="d-inline m-0">
                    <input type="hidden" name="action" value="disconnect_user">
                    <button type="submit" class="btn-pill danger" title="Disconnect Session">
                        <i class="fa-solid fa-plug-circle-xmark"></i> Kick
                    </button>
                </form>
                <?php endif; ?>

                <!-- Toggle User Status -->
                <form method="POST" onsubmit="return confirm('Are you sure you want to <?= $is_disabled ? 'Enable' : 'Disable' ?> this user?');" class="d-inline m-0">
                    <input type="hidden" name="action" value="toggle_user">
                    <?php if ($is_disabled): ?>
                        <button type="submit" class="btn-pill success" title="Enable Internet">
                            <i class="fa-solid fa-check"></i> Enable
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn-pill danger" title="Disable Internet">
                            <i class="fa-solid fa-ban"></i> Disable
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Service Details, Usage & Sessions -->
    <div class="col-lg-8 col-md-7">
        <!-- 4 Stat Metrics -->
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3">
                <div class="metric-card">
                    <div class="metric-icon" style="background: #e0f2fe; color: #0284c7;">
                        <i class="fa-solid fa-cloud-arrow-down"></i>
                    </div>
                    <div class="metric-data">
                        <h6>Download</h6>
                        <h4><?= formatBytes($total_download) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="metric-card">
                    <div class="metric-icon" style="background: #ede9fe; color: #7c3aed;">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                    </div>
                    <div class="metric-data">
                        <h6>Upload</h6>
                        <h4><?= formatBytes($total_upload) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="metric-card">
                    <div class="metric-icon" style="background: #fef3c7; color: #d97706;">
                        <i class="fa-solid fa-chart-pie"></i>
                    </div>
                    <div class="metric-data">
                        <h6>Total Usage</h6>
                        <h4><?= formatBytes($total_usage) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="metric-card">
                    <div class="metric-icon" style="background: #dcfce7; color: #16a34a;">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                    <div class="metric-data">
                        <h6>User Balance</h6>
                        <h4>Rs. <?= number_format($user['balance']) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Service & Expiry Summary Card -->
        <div class="view-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-box-open text-primary me-2"></i> Package & Subscription</h6>
                <?php if ($is_expired): ?>
                    <span class="badge bg-danger px-3 py-1 rounded-pill fw-bold text-uppercase">Expired</span>
                <?php else: ?>
                    <span class="badge bg-success px-3 py-1 rounded-pill fw-bold text-uppercase">Active Subscription</span>
                <?php endif; ?>
            </div>
            <div class="row g-3">
                <div class="col-sm-6 col-md-3">
                    <div class="text-secondary small fw-semibold">Package Name</div>
                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($user['package_name'] ?: 'None') ?></div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="text-secondary small fw-semibold">Bandwidth Speed</div>
                    <div class="fw-bold text-primary"><?= htmlspecialchars($user['rate_limit'] ?: 'No Limit') ?></div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="text-secondary small fw-semibold">Dealer Rate</div>
                    <div class="fw-bold text-dark">Rs. <?= number_format($cur_dealer_pkg_price) ?></div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="text-secondary small fw-semibold">Expiry Date</div>
                    <div class="fw-bold <?= $is_expired ? 'text-danger' : 'text-success' ?>">
                        <?= $user['expiry_date'] ? date('d M Y, h:i A', strtotime($user['expiry_date'])) : 'Never / Expired' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Session Card (If Connected) -->
        <?php if ($is_online && $live_session): ?>
        <div class="view-card p-4 border border-success border-opacity-25" style="background: #f0fdf4;">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-success mb-0">
                    <i class="fa-solid fa-signal me-2"></i> Active Real-time Connection
                </h6>
                <span class="badge bg-success rounded-pill px-3 py-1">Connected Now</span>
            </div>
            <div class="row g-3 text-secondary small">
                <div class="col-sm-6 col-md-3">
                    <div>Framed IP Address:</div>
                    <strong class="text-dark font-monospace"><?= htmlspecialchars($live_session['framedipaddress'] ?: 'N/A') ?></strong>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div>MAC / Caller ID:</div>
                    <strong class="text-dark font-monospace"><?= htmlspecialchars($live_session['callingstationid'] ?: 'N/A') ?></strong>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div>Session Uptime:</div>
                    <strong class="text-dark"><?= formatDuration($live_session['acctsessiontime'] ?? 0) ?></strong>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div>Session Download:</div>
                    <strong class="text-dark"><?= formatBytes($live_session['acctoutputoctets'] ?? 0) ?></strong>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tabs for Recent Sessions & Ledger -->
        <div class="view-card p-4">
            <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active rounded-pill fw-bold px-3 py-2" id="pills-sessions-tab" data-bs-toggle="pill" data-bs-target="#pills-sessions" type="button" role="tab">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i> Recent Data Sessions
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link rounded-pill fw-bold px-3 py-2 ms-2" id="pills-ledger-tab" data-bs-toggle="pill" data-bs-target="#pills-ledger" type="button" role="tab">
                        <i class="fa-solid fa-receipt me-1"></i> Billing & Ledger History
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="pills-tabContent">
                <!-- Recent Sessions Pane -->
                <div class="tab-pane fade show active" id="pills-sessions" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover table-custom w-100 align-middle">
                            <thead>
                                <tr>
                                    <th>Start Time</th>
                                    <th>Duration</th>
                                    <th>Download</th>
                                    <th>Upload</th>
                                    <th>Total Data</th>
                                    <th>Framed IP</th>
                                    <th>Disconnect Cause</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recent_sessions as $sess): ?>
                                <?php
                                    // Timestamp calculation (1970 fix)
                                    $start_ts = !empty($sess['acctstarttime']) ? strtotime($sess['acctstarttime']) : 0;
                                    $stop_ts = !empty($sess['acctstoptime']) ? strtotime($sess['acctstoptime']) : 0;
                                    $duration = (int)($sess['acctsessiontime'] ?? 0);
                                    
                                    if ($start_ts < 946684800 && $stop_ts > 946684800 && $duration > 0) {
                                        $start_ts = $stop_ts - $duration;
                                    }
                                    $start_display = ($start_ts > 946684800) ? date('d M Y, h:i A', $start_ts) : 'In Progress';
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= $start_display ?></div>
                                        <?php if($sess['acctstoptime']): ?>
                                            <small class="text-muted">Stop: <?= date('d M Y, h:i A', strtotime($sess['acctstoptime'])) ?></small>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill" style="font-size: 0.7rem;">Live</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-light text-dark border px-2 py-1"><i class="fa-regular fa-clock me-1"></i><?= formatDuration($duration) ?></span></td>
                                    <td class="text-success fw-bold"><?= formatBytes($sess['acctoutputoctets'] ?? 0) ?></td>
                                    <td class="text-primary fw-semibold"><?= formatBytes($sess['acctinputoctets'] ?? 0) ?></td>
                                    <td class="fw-bold text-dark"><?= formatBytes(($sess['acctoutputoctets'] ?? 0) + ($sess['acctinputoctets'] ?? 0)) ?></td>
                                    <td><code class="text-dark small"><?= htmlspecialchars($sess['framedipaddress'] ?: 'N/A') ?></code></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($sess['acctterminatecause'] ?: ($sess['acctstoptime'] ? 'Normal' : 'Online')) ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($recent_sessions)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">No session records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Ledger History Pane -->
                <div class="tab-pane fade" id="pills-ledger" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover table-custom w-100 align-middle">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Balance After</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($ledger_entries as $lg): ?>
                                <tr>
                                    <td class="text-secondary small"><?= date('d M Y, h:i A', strtotime($lg['created_at'])) ?></td>
                                    <td>
                                        <?php if($lg['type'] === 'credit'): ?>
                                            <span class="badge bg-success rounded-pill px-3 py-1"><i class="fa-solid fa-plus me-1"></i> Payment</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger rounded-pill px-3 py-1"><i class="fa-solid fa-minus me-1"></i> Debit</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold <?= $lg['type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                                        <?= $lg['type'] === 'credit' ? '+' : '-' ?> Rs. <?= number_format($lg['amount'], 2) ?>
                                    </td>
                                    <td class="fw-semibold text-dark">Rs. <?= number_format($lg['balance_after'], 2) ?></td>
                                    <td><small class="text-secondary"><?= htmlspecialchars($lg['description']) ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($ledger_entries)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No billing records found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: ADD PAYMENT -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
      <div class="modal-header bg-primary text-white p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-money-bill-wave me-2"></i> Add Payment to Customer</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_payment">
        <div class="modal-body p-4 bg-light">
            <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Customer Username:</span>
                    <span class="badge bg-primary font-monospace"><?= htmlspecialchars($u) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Current Customer Balance:</span>
                    <span class="text-success fw-bold">Rs. <?= number_format($user['balance'], 2) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                    <span class="text-secondary small fw-bold">Your Wallet Balance:</span>
                    <span class="text-primary fw-bold">Rs. <?= number_format($current_dealer_balance, 2) ?></span>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label text-dark fw-bold small text-uppercase">Payment Amount (Rs.) <span class="text-danger">*</span></label>
                <div class="input-group input-group-lg shadow-sm">
                    <span class="input-group-text bg-white fw-bold text-muted">Rs.</span>
                    <input type="number" step="0.01" min="1" max="<?= $current_dealer_balance ?>" name="amount" class="form-control fw-bold text-primary" placeholder="e.g. 1000" required>
                </div>
                <small class="text-muted">This amount will be added to the customer and deducted from your dealer wallet.</small>
            </div>

            <div class="mb-3">
                <label class="form-label text-dark fw-bold small text-uppercase">Remarks / Note (Optional)</label>
                <input type="text" name="description" class="form-control" placeholder="e.g. Monthly internet fee cash received">
            </div>
        </div>
        <div class="modal-footer bg-white border-top px-4 py-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" onclick="return confirm('Deduct this amount from your wallet and add to customer balance?');">
              <i class="fa-solid fa-check me-1"></i> Add Payment
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- MODAL: RENEW PACKAGE -->
<div class="modal fade" id="renewModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
      <div class="modal-header bg-success text-white p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-rotate me-2"></i> Renew Subscriber Package</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="renew_user">
        <div class="modal-body p-4 bg-light">
            <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Customer:</span>
                    <span class="badge bg-success font-monospace"><?= htmlspecialchars($u) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Current Package:</span>
                    <span class="fw-bold text-dark"><?= htmlspecialchars($user['package_name'] ?: 'None') ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-secondary small fw-bold">Current Expiry:</span>
                    <span class="<?= $is_expired ? 'text-danger' : 'text-success' ?> fw-semibold">
                        <?= $user['expiry_date'] ? date('d M Y', strtotime($user['expiry_date'])) : 'Expired' ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                    <span class="text-secondary small fw-bold">Your Wallet Balance:</span>
                    <span class="text-primary fw-bold">Rs. <?= number_format($current_dealer_balance, 2) ?></span>
                </div>
            </div>

            <div class="mb-3 text-start">
                <label class="form-label text-dark fw-bold small text-uppercase">Select Package <span class="text-danger">*</span></label>
                <select name="package_id" id="renew_package_select" class="form-select form-select-lg fw-bold text-primary border-success shadow-sm" required onchange="updateRenewLivePrice()">
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p['id'] ?>" data-price="<?= $p['dealer_price'] ?>" <?= ($p['id'] == $user['package_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?> &nbsp;|&nbsp; Dealer Rs. <?= number_format($p['dealer_price']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($can_custom_expiry): ?>
            <div class="mb-3 text-start">
                <label class="form-label text-secondary small fw-bold">Custom Expiry (Optional)</label>
                <input type="datetime-local" name="expiry_date" id="renew_custom_expiry" class="form-control" onchange="updateRenewLivePrice()">
                <small class="text-muted">Leave blank for standard 30-day package renewal.</small>
            </div>
            <?php endif; ?>

            <div class="p-3 bg-white border rounded shadow-sm d-flex justify-content-between align-items-center">
                <div class="text-secondary fw-bold small text-uppercase">Wallet Deduction</div>
                <h4 class="mb-0 fw-bold text-success" id="renew_live_deduction">Rs. <?= number_format($cur_dealer_pkg_price) ?></h4>
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

<!-- MODAL: CHANGE PASSWORD -->
<div class="modal fade" id="passwordModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
      <div class="modal-header bg-dark text-white p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-key me-2"></i> Change Customer Password</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="modal-body p-4 bg-light">
            <div class="mb-3">
                <label class="form-label text-dark fw-bold small text-uppercase">New Password <span class="text-danger">*</span></label>
                <input type="text" name="new_password" class="form-control form-control-lg font-monospace fw-bold" placeholder="Enter new password" value="<?= htmlspecialchars($user['password'] ?? '') ?>" required>
            </div>
        </div>
        <div class="modal-footer bg-white border-top px-4 py-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark rounded-pill px-5 fw-bold shadow-sm">
              <i class="fa-solid fa-save me-1"></i> Update Password
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const pkgPriceMap = {
    <?php foreach($dealer_packages as $p) echo $p['id'] . ': ' . (float)$p['dealer_price'] . ','; ?>
};

function updateRenewLivePrice() {
    const sel = document.getElementById('renew_package_select');
    if (!sel) return;
    const pkgId = sel.value;
    const price = parseFloat(pkgPriceMap[pkgId] || 0);

    const customExpEl = document.getElementById('renew_custom_expiry');
    const customExpVal = customExpEl ? customExpEl.value : '';
    const nowSec = Math.floor(Date.now() / 1000);

    if (customExpVal) {
        const targetTs = Math.floor(new Date(customExpVal).getTime() / 1000);
        if (targetTs > nowSec) {
            const days = Math.ceil((targetTs - nowSec) / 86400);
            const deduction = days * (price / 30);
            document.getElementById('renew_live_deduction').innerText = 'Rs. ' + deduction.toFixed(2);
            return;
        }
    }
    document.getElementById('renew_live_deduction').innerText = 'Rs. ' + price.toLocaleString();
}
</script>

<?php require_once 'footer.php'; ?>
