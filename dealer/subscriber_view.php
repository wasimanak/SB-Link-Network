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
$can_delete_user   = (bool)($current_dealer['perm_delete_user'] ?? 0);

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
if (!function_exists('formatSubscriberBytes')) {
    function formatSubscriberBytes($bytes) {
        $bytes = (float)$bytes;
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . " GB";
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . " MB";
        if ($bytes > 0) return round($bytes / 1024, 2) . " KB";
        return "0 MB";
    }
}

// Fetch Dealer Balance
$dBalStmt = $pdo->prepare("SELECT balance, username, name FROM dealers WHERE id = ?");
$dBalStmt->execute([$dealer_id]);
$dealer_row = $dBalStmt->fetch(PDO::FETCH_ASSOC);
$current_dealer_balance = (float)($dealer_row['balance'] ?? 0);

// Fetch Subscriber - STRICTLY SCOPED to this dealer
$stmt = $pdo->prepare("
    SELECT s.*, 
           p.name as pkg_name, p.price as pkg_price, p.validity_days, p.rate_limit,
           c.company_name,
           d.username as dealer_username, d.name as dealer_full_name
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN dealers d ON s.dealer_id = d.id
    WHERE s.id = ? AND s.dealer_id = ?
");
$stmt->execute([$id, $dealer_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: dashboard.php");
    exit;
}

$u = $user['username'];

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD PAYMENT (Credit subscriber, deduct from dealer wallet)
    if ($action === 'add_balance') {
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $desc = trim($_POST['description'] ?? '');

        if ($amount <= 0) {
            echo "<script>alert('Invalid payment amount!'); window.location='subscriber_view.php?id=$id';</script>";
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

    // 2. RENEW USER
    if ($action === 'renew_user') {
        $package_id = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
        $expiry_date = $_POST['expiry_date'] ?? null;
        $payment_amount = isset($_POST['payment_amount']) ? (float)$_POST['payment_amount'] : 0;
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

            // Deduct package price from dealer wallet
            if ($deduction > 0) {
                $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$deduction, $dealer_id]);
                $note = "Package Renewal: {$pkg['name']} for $u. Deducted: Rs. $deduction";
                $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
            }

            // If cash was received from customer, record credit in subscriber ledger & update subscriber balance
            $current_sub_bal = (float)$user['balance'];
            if ($payment_amount > 0) {
                $current_sub_bal += $payment_amount;
                $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ? AND dealer_id = ?")->execute([$payment_amount, $id, $dealer_id]);
                $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                    ->execute([$client_id, $u, $payment_amount, $current_sub_bal, "Cash Payment Received at Renewal"]);
            }

            $new_expiry_str = date('Y-m-d H:i:s', $new_expiry_time);
            $status = ($new_expiry_time > $now) ? 'active' : 'expired';
            $now_dt = date('Y-m-d H:i:s');

            $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = ?, last_renewed_at = ? WHERE id = ? AND dealer_id = ?")
                ->execute([$target_pkg_id, $new_expiry_str, $status, $now_dt, $id, $dealer_id]);

            // Debit package charge from subscriber ledger
            $bal_after_pkg = $current_sub_bal - $deduction;
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, ?, ?)")
                ->execute([$client_id, $u, $deduction, $bal_after_pkg, "Package Renewed: {$pkg['name']} by Dealer {$current_dealer['username']}"]);

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

    // 3. CHANGE PASSWORD
    if ($action === 'change_password') {
        $new_pass = trim($_POST['new_password'] ?? '');
        if (!empty($new_pass)) {
            $pdo->prepare("UPDATE subscribers SET password = ? WHERE id = ? AND dealer_id = ?")->execute([$new_pass, $id, $dealer_id]);
            $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$new_pass, $u]);
            $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'User-Password'")->execute([$new_pass, $u]);
            
            $actStr = "Changed password for $u by Dealer " . $current_dealer['username'];
            $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'User', ?)")
                ->execute([$client_id, $dealer_id, $current_dealer['username'], $u, $actStr]);
            
            kick_subscriber_session($pdo, $u, $client_id);
            echo "<script>alert('Password updated successfully!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
    }

    // 4. ADD / UPDATE NOTE
    if ($action === 'add_note') {
        $notes = trim($_POST['notes'] ?? '');
        $pdo->prepare("UPDATE subscribers SET notes = ? WHERE id = ? AND dealer_id = ?")->execute([$notes, $id, $dealer_id]);
        echo "<script>alert('Notes updated successfully!'); window.location='subscriber_view.php?id=$id';</script>";
        exit;
    }

    // 5. UPLOAD PHOTO
    if ($action === 'upload_photo') {
        if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $allowed)) {
                $dir = __DIR__ . '/../uploads/profiles/';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $fname = 'sub_' . $id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dir . $fname)) {
                    $pdo->prepare("UPDATE subscribers SET photo = ? WHERE id = ? AND dealer_id = ?")->execute([$fname, $id, $dealer_id]);
                    echo "<script>alert('Profile photo updated successfully!'); window.location='subscriber_view.php?id=$id';</script>";
                    exit;
                }
            }
        }
    }

    // 6. ENABLE NET / PROFILE ENABLE
    if ($action === 'enable_net') {
        $pdo->prepare("UPDATE subscribers SET status = 'active' WHERE id = ? AND dealer_id = ?")->execute([$id, $dealer_id]);
        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'")->execute([$u]);
        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Mikrotik-Group') AND value IN ('EXPIRED_USERS', 'expired')")->execute([$u]);

        $actStr = "Enabled Network for $u by Dealer " . $current_dealer['username'];
        $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'User', ?)")
            ->execute([$client_id, $dealer_id, $current_dealer['username'], $u, $actStr]);

        echo "<script>alert('Internet enabled for $u!'); window.location='subscriber_view.php?id=$id';</script>";
        exit;
    }

    // 7. DISABLE NET / PROFILE DISABLE
    if ($action === 'disable_net' || $action === 'profile_disable') {
        $pdo->prepare("UPDATE subscribers SET status = 'disabled' WHERE id = ? AND dealer_id = ?")->execute([$id, $dealer_id]);
        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$u]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
        kick_subscriber_session($pdo, $u, $client_id);

        $actStr = "Disabled Network for $u by Dealer " . $current_dealer['username'];
        $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'User', ?)")
            ->execute([$client_id, $dealer_id, $current_dealer['username'], $u, $actStr]);

        echo "<script>alert('Internet disabled for $u!'); window.location='subscriber_view.php?id=$id';</script>";
        exit;
    }

    // 8. DISCONNECT USER
    if ($action === 'disconnect_user') {
        kick_subscriber_session($pdo, $u, $client_id);
        echo "<script>alert('User disconnect command executed successfully!'); window.location='subscriber_view.php?id=$id';</script>";
        exit;
    }

    // 9. DELETE USER (Only if dealer has permission)
    if ($action === 'delete_user' && $can_delete_user) {
        $pdo->prepare("DELETE FROM subscribers WHERE id = ? AND dealer_id = ?")->execute([$id, $dealer_id]);
        $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
        $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
        kick_subscriber_session($pdo, $u, $client_id);

        $actStr = "Deleted customer profile $u by Dealer " . $current_dealer['username'];
        $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, 'Dealer', ?, 'User', ?)")
            ->execute([$client_id, $dealer_id, $current_dealer['username'], $u, $actStr]);

        echo "<script>alert('Customer profile deleted successfully.'); window.location='dashboard.php#reportsSection';</script>";
        exit;
    }
}

// Fetch Dealer Packages for Renew Modal
$dpStmt = $pdo->prepare("
    SELECT p.id, p.name, p.rate_limit, p.validity_days, dp.dealer_price 
    FROM dealer_packages dp 
    JOIN packages p ON dp.package_id = p.id 
    WHERE dp.dealer_id = ? 
    ORDER BY p.name ASC
");
$dpStmt->execute([$dealer_id]);
$packages = $dpStmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($packages)) {
    $dpStmt = $pdo->prepare("SELECT id, name, rate_limit, validity_days, price as dealer_price FROM packages WHERE client_id = ? ORDER BY name ASC");
    $dpStmt->execute([$client_id]);
    $packages = $dpStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ================= COMPUTE CYCLE USAGE & ACCOUNTING (IDENTICAL TO OPERATOR) =================
$last_ren_stmt = $pdo->prepare("SELECT created_at FROM activity_logs WHERE client_id = ? AND against_to = ? AND (activity LIKE '%Renew%' OR activity LIKE '%Extended Expiry%') ORDER BY id DESC LIMIT 1");
$last_ren_stmt->execute([$client_id, $user['username']]);
$last_renewal_log = $last_ren_stmt->fetchColumn();

$validity_days = (int)(($user['validity_days'] ?? 0) > 0 ? $user['validity_days'] : 30);
$created_at = !empty($user['created_at']) ? $user['created_at'] : '2000-01-01 00:00:00';

if (!empty($user['last_renewed_at']) && $user['last_renewed_at'] !== '0000-00-00 00:00:00') {
    $cycle_start = $user['last_renewed_at'];
} elseif (!empty($last_renewal_log)) {
    $cycle_start = $last_renewal_log;
} elseif (!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00') {
    $computed_start = date('Y-m-d H:i:s', strtotime($user['expiry_date'] . " -{$validity_days} days"));
    $cycle_start = ($computed_start > $created_at) ? $computed_start : $created_at;
} else {
    $cycle_start = $created_at;
}
$last_act = $cycle_start;

// 1. Fetch data usage from CLOSED sessions in this current cycle
$volStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down 
    FROM radacct 
    WHERE username = ? 
      AND acctstoptime IS NOT NULL 
      AND acctstarttime >= ?");
$volStmt->execute([$user['username'], $cycle_start]);
$vol = $volStmt->fetch();

$closed_up = (float)($vol['up'] ?? 0);
$closed_down = (float)($vol['down'] ?? 0);
$past_bytes = $closed_up + $closed_down;

// 2. Fetch active session in radacct (if online and started within current cycle)
$liveStmt = $pdo->prepare("SELECT acctinputoctets, acctoutputoctets 
    FROM radacct 
    WHERE username = ? 
      AND acctstoptime IS NULL 
      AND acctstarttime >= ? 
    ORDER BY radacctid DESC LIMIT 1");
$liveStmt->execute([$user['username'], $cycle_start]);
$live_rad = $liveStmt->fetch();

$live_rad_up = (float)($live_rad['acctinputoctets'] ?? 0);
$live_rad_down = (float)($live_rad['acctoutputoctets'] ?? 0);
$live_rad_bytes = $live_rad_up + $live_rad_down;

$total_bytes = $past_bytes + $live_rad_bytes;

$used_volume_str = formatSubscriberBytes($total_bytes);
$cycle_up_str = formatSubscriberBytes($closed_up + $live_rad_up);
$cycle_down_str = formatSubscriberBytes($closed_down + $live_rad_down);

// 3. Lifetime all-time volume across entire history
$lifeStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down FROM radacct WHERE username = ?");
$lifeStmt->execute([$user['username']]);
$lifeVol = $lifeStmt->fetch();
$lifetime_bytes = (float)($lifeVol['up'] ?? 0) + (float)($lifeVol['down'] ?? 0);
$lifetime_vol_str = formatSubscriberBytes($lifetime_bytes);

// 4. Check Live Online Session
$onStmt = $pdo->prepare("SELECT * FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1");
$onStmt->execute([$user['username']]);
$online = $onStmt->fetch();

$uptime_str = "0.00";
if ($online) {
    $raw_start = $online['acctstarttime'] ?? '';
    $start_time = null;
    if (!empty($raw_start) && $raw_start !== '0000-00-00 00:00:00') {
        $start_time = is_numeric($raw_start) ? (int)$raw_start : strtotime($raw_start);
    }
    if ($start_time && $start_time > 86400) {
        $diff = max(0, time() - $start_time);
    } else {
        $diff = (int)($online['acctsessiontime'] ?? 0);
    }
    $hours = floor($diff / 3600);
    $mins = floor(($diff % 3600) / 60);
    $uptime_str = "{$hours}h {$mins}m";
}

// 5. Session hours in current cycle
$sessStmt = $pdo->prepare("SELECT SUM(acctsessiontime) as total_time FROM radacct WHERE username = ? AND acctstarttime >= ?");
$sessStmt->execute([$user['username'], $cycle_start]);
$total_time_sec = (int)$sessStmt->fetchColumn();
$used_sess_hours = floor($total_time_sec / 3600);
$used_sess_mins = floor(($total_time_sec % 3600) / 60);
$used_sess_str = sprintf("%02d Hours %02d Minutes", $used_sess_hours, $used_sess_mins);

// Latest connection record
$latestStmt = $pdo->prepare("SELECT * FROM radacct WHERE username = ? ORDER BY radacctid DESC LIMIT 1");
$latestStmt->execute([$user['username']]);
$latest_conn = $latestStmt->fetch();

// Calculate Remaining Volume
$data_limit_gb = (float)($user['data_limit_gb'] ?? 0);
if ($data_limit_gb > 0) {
    $total_vol_str = $data_limit_gb . " GB";
    $rem_gb = $data_limit_gb - ($total_bytes / 1073741824);
    $rem_vol_str = round($rem_gb, 2) . " GB";
} else {
    $total_vol_str = "Unlimited";
    $rem_vol_str = "Unlimited";
}

// Annual Financial Ledger Data (Funds vs Deductions for Chart)
$year = date('Y');
$payStmt = $pdo->prepare("SELECT MONTH(created_at) as m, type, SUM(amount) as total 
                          FROM user_ledger 
                          WHERE client_id = ? AND username = ? AND YEAR(created_at) = ?
                          GROUP BY m, type");
$payStmt->execute([$client_id, $user['username'], $year]);
$monthly_added = array_fill(1, 12, 0);
$monthly_deducted = array_fill(1, 12, 0);

while ($row = $payStmt->fetch()) {
    if ($row['type'] == 'credit') {
        $monthly_added[(int)$row['m']] += (float)$row['total'];
    } else {
        $monthly_deducted[(int)$row['m']] += (float)$row['total'];
    }
}
$payment_data_json = json_encode(array_values($monthly_added));
$deducted_data_json = json_encode(array_values($monthly_deducted));

// Ledger History for Accordion
$ledgerStmt = $pdo->prepare("SELECT * FROM user_ledger WHERE client_id = ? AND username = ? ORDER BY id DESC LIMIT 50");
$ledgerStmt->execute([$client_id, $user['username']]);
$ledger_history = $ledgerStmt->fetchAll();

// Profile Status & Badges
$has_expiry = (!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00');
$expiry_ts  = $has_expiry ? strtotime($user['expiry_date']) : 0;
$now_ts     = time();
$is_time_expired = ($has_expiry && $expiry_ts <= $now_ts);
$is_disabled = (strtolower($user['status'] ?? '') === 'disabled');

// Auto-heal status if expiry_date and status are out of sync:
if (!$is_disabled) {
    if ($has_expiry && $expiry_ts > $now_ts && $user['status'] !== 'active') {
        $user['status'] = 'active';
        try {
            $pdo->prepare("UPDATE subscribers SET status = 'active' WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'")->execute([$user['username']]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Mikrotik-Group') AND value IN ('EXPIRED_USERS', 'expired')")->execute([$user['username']]);
        } catch (Exception $e) {}
    } elseif ($is_time_expired && $user['status'] !== 'expired') {
        $user['status'] = 'expired';
        try {
            $pdo->prepare("UPDATE subscribers SET status = 'expired' WHERE id = ?")->execute([$id]);
        } catch (Exception $e) {}
    }
}

$is_expired = (!$is_disabled && ($is_time_expired || (!$has_expiry && strtolower($user['status'] ?? '') === 'expired')));

$profile_status = 'Offline';
$status_color = 'secondary';
$header_badge_class = 'offline';

if ($is_disabled) {
    $profile_status = 'Disabled';
    $status_color = 'danger';
    $header_badge_class = 'disabled';
} elseif ($is_expired) {
    $profile_status = 'Expired';
    $status_color = 'warning';
    $header_badge_class = 'expired';
} else {
    $profile_status = 'Active';
    $status_color = 'success';
    $header_badge_class = 'active';
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    .dt-buttons .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
        margin-right: 2px;
        margin-bottom: 10px;
    }
    .dataTables_filter {
        margin-bottom: 10px;
    }
    .dataTables_filter input { background-color: #ffffff; border: 1px solid #ced4da; color: #333; border-radius: 20px; padding: 4px 15px; }
    .dataTables_length select { background-color: #ffffff; border: 1px solid #ced4da; color: #333; border-radius: 6px; }

    .view-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; margin-bottom: 20px; }
    
    .profile-header { display: flex; align-items: center; gap: 15px; padding: 20px; border-bottom: 1px solid #f1f5f9; }
    .avatar-large { width: 60px; height: 60px; background-color: #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #94a3b8; }
    .profile-info h5 { margin: 0; font-weight: 700; color: #1e293b; font-size: 1.1rem; }
    .profile-info p { margin: 0; color: #64748b; font-size: 0.9rem; }
    .status-badge { display: inline-block; background: rgba(16, 185, 129, 0.15); color: #10b981; padding: 2px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; margin-top: 5px; border: 1px solid rgba(16, 185, 129, 0.2); }
    .status-badge.offline { background: rgba(148, 163, 184, 0.15); color: #64748b; border-color: rgba(148, 163, 184, 0.2); }
    .status-badge.disabled { background: rgba(239, 68, 68, 0.15); color: #ef4444; border-color: rgba(239, 68, 68, 0.2); }
    .status-badge.expired { background: rgba(245, 158, 11, 0.15); color: #d97706; border-color: rgba(245, 158, 11, 0.2); }
    .status-badge.active { background: rgba(16, 185, 129, 0.15); color: #10b981; border-color: rgba(16, 185, 129, 0.2); }
    
    .profile-list { list-style: none; padding: 0; margin: 0; }
    .profile-list li { padding: 12px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #f8fafc; color: #475569; font-size: 0.9rem; }
    .profile-list li i { color: #64748b; width: 16px; text-align: center; }
    
    .action-grid { padding: 20px; display: flex; flex-wrap: wrap; gap: 10px; }
    .btn-pill { background: #fff; border: 1px solid #e2e8f0; color: #334155; border-radius: 20px; padding: 6px 14px; font-size: 0.85rem; font-weight: 500; transition: 0.2s; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
    .btn-pill:hover { background: #f8fafc; border-color: #cbd5e1; }
    .btn-pill.dark { background: #1e293b; color: #fff; border-color: #1e293b; }
    .btn-pill.dark:hover { background: #0f172a; }
    .btn-pill.danger { color: #ef4444; border-color: rgba(239, 68, 68, 0.3); }
    .btn-pill.danger:hover { background: rgba(239, 68, 68, 0.05); }

    .metric-card { display: flex; align-items: center; gap: 15px; padding: 15px 20px; }
    .metric-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
    .bg-light-info { background: rgba(14, 165, 233, 0.1); color: #0ea5e9; }
    .bg-light-primary { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
    .bg-light-warning { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
    .bg-light-success { background: rgba(16, 185, 129, 0.1); color: #10b981; }
    .bg-light-secondary { background: rgba(148, 163, 184, 0.1); color: #64748b; }
    .metric-data h6 { margin: 0; font-size: 0.8rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; }
    .metric-data h4 { margin: 0; font-size: 1.25rem; font-weight: 700; color: #1e293b; }

    .custom-accordion .accordion-item { border: none; background: #fff; border-radius: 12px !important; margin-bottom: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); overflow: hidden; }
    .custom-accordion .accordion-button { background: #fff; color: #1e293b; font-weight: 600; padding: 15px 20px; box-shadow: none; border-bottom: 1px solid #f1f5f9; }
    .custom-accordion .accordion-button:not(.collapsed) { background: #f8fafc; color: #3b82f6; }
    .custom-accordion .accordion-button i { color: #64748b; margin-right: 10px; width: 16px; text-align: center; }

    .copy-user-btn {
        background: none;
        border: none;
        color: #94a3b8;
        padding: 2px 5px;
        cursor: pointer;
        transition: color 0.15s ease-in-out;
    }
    .copy-user-btn:hover {
        color: #3b82f6;
    }
</style>

<!-- Top Breadcrumb & Title -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none">Dealer Dashboard</a></li>
                <li class="breadcrumb-item"><a href="users.php" class="text-decoration-none">Subscribers</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($u) ?></li>
            </ol>
        </nav>
        <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
            <span>Customer Profile:</span>
            <span class="text-primary font-monospace"><?= htmlspecialchars($u) ?></span>
        </h4>
    </div>
    <div class="d-flex align-items-center gap-2 mt-2 mt-md-0">
        <a href="users.php" class="btn btn-sm btn-light border rounded-pill px-3 fw-semibold">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Users
        </a>
    </div>
</div>

<div class="row">
    <!-- Left Column: Profile Card -->
    <div class="col-lg-4 col-md-5">
        <div class="view-card">
            <div class="profile-header">
                <?php if (!empty($user['photo']) && file_exists("../uploads/profiles/" . $user['photo'])): ?>
                    <div style="width: 60px; height: 60px; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; background-color: #e2e8f0; flex-shrink: 0; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#photoViewModal" title="Click to view">
                        <img src="../uploads/profiles/<?= htmlspecialchars($user['photo']) ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                <?php else: ?>
                    <div class="avatar-large"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div class="profile-info">
                    <h5><?= htmlspecialchars($user['full_name'] ?: 'Unknown Name') ?></h5>
                    <p class="mb-1 d-inline-flex align-items-center gap-2">
                        <span class="fw-bold text-primary font-monospace"><?= htmlspecialchars($user['username']) ?></span>
                        <button type="button" 
                                class="copy-user-btn" 
                                onclick="copyToClipboard(event, '<?= addslashes(htmlspecialchars($user['username'])) ?>', this)" 
                                title="Copy Username">
                            <i class="fa-regular fa-copy" style="font-size: 0.8rem;"></i>
                        </button>
                    </p>
                    <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
                        <span class="status-badge <?= $header_badge_class ?>"><?= $profile_status ?></span>
                        <?php if ($online): ?>
                            <span class="badge rounded-pill bg-success text-white px-2 py-1 shadow-sm d-inline-flex align-items-center gap-1" style="font-size: 0.72rem; letter-spacing: 0.3px;">
                                <span class="spinner-grow spinner-grow-sm text-white" style="width: 6px; height: 6px;" role="status"></span>
                                <span>ONLINE</span>
                            </span>
                        <?php else: ?>
                            <span class="badge rounded-pill bg-secondary text-white px-2 py-1 d-inline-flex align-items-center gap-1" style="font-size: 0.72rem; letter-spacing: 0.3px;">
                                <i class="fa-solid fa-circle text-white-50" style="font-size: 0.45rem;"></i>
                                <span>OFFLINE</span>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <ul class="profile-list">
                <li><i class="fa-solid fa-building"></i> <?= htmlspecialchars($user['company_name'] ?: BRAND_NAME) ?></li>
                <li><i class="fa-solid fa-user-tie"></i> Dealer: <span class="fw-semibold text-dark"><?= htmlspecialchars($current_dealer['username']) ?></span></li>
                <li><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($user['national_id'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($user['mobile'] ?: ($user['phone'] ?: 'N/A')) ?></li>
                <li><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($user['address'] ?: 'N/A') ?>
                    <?php if (!empty($user['latitude']) && !empty($user['longitude']) && (float)$user['latitude'] != 0): ?>
                        <a href="https://www.google.com/maps?q=<?= $user['latitude'] ?>,<?= $user['longitude'] ?>" target="_blank" class="badge bg-primary text-white text-decoration-none ms-1 px-2 py-1" style="font-size: 0.7rem;" title="View exact house location on Google Maps">
                            <i class="fa-solid fa-map-location-dot me-1"></i> Maps
                        </a>
                    <?php endif; ?>
                </li>
                <li>
                    <i class="fa-solid fa-calendar-plus text-primary"></i> 
                    <span class="text-muted small me-1">Created:</span> 
                    <span class="fw-semibold text-dark"><?= !empty($user['created_at']) ? date('d M Y, h:i A', strtotime($user['created_at'])) : 'N/A' ?></span>
                </li>
                <li>
                    <i class="fa-solid fa-calendar-xmark text-danger"></i> 
                    <span class="text-muted small me-1">Expiry:</span> 
                    <span class="fw-semibold text-dark"><?= !empty($user['expiry_date']) ? date('d M Y, h:i A', strtotime($user['expiry_date'])) : 'N/A' ?></span>
                </li>
            </ul>
            <div class="action-grid">
                <!-- Modals Triggers Allowed for Dealer -->
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="fa-brands fa-paypal"></i> Payment</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#renewModal"><i class="fa-solid fa-rotate"></i> Renew</button>
                <a href="print_receipt.php?sub_id=<?= $id ?>&return=<?= urlencode('subscriber_view.php?id=' . $id) ?>" target="_blank" class="btn-pill text-primary fw-bold"><i class="fa-solid fa-receipt text-primary"></i> Print Receipt</a>
                <button type="button" class="btn-pill" onclick="alert('User Password: <?= htmlspecialchars($user['password']) ?>')"><i class="fa-solid fa-lock"></i> Toggle Password</button>
                
                <form method="POST" enctype="multipart/form-data" id="photoForm" style="display:none;">
                    <input type="hidden" name="action" value="upload_photo">
                    <input type="file" name="profile_photo" id="photoInput" accept="image/*" onchange="document.getElementById('photoForm').submit();">
                </form>
                <button type="button" class="btn-pill" onclick="document.getElementById('photoInput').click();"><i class="fa-regular fa-image"></i> Change Photo</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#noteModal"><i class="fa-regular fa-note-sticky"></i> Add Note</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#passwordModal"><i class="fa-solid fa-key"></i> Change Password</button>

                <?php if ($can_delete_user): ?>
                <!-- Delete Profile Form (Only if dealer permitted) -->
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this profile permanently?');">
                    <input type="hidden" name="action" value="delete_user">
                    <button type="submit" class="btn-pill danger"><i class="fa-solid fa-trash"></i> Delete Profile</button>
                </form>
                <?php endif; ?>

                <?php if($user['status'] === 'disabled'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="enable_net">
                        <button type="submit" class="btn-pill" style="color: #10b981; border-color: rgba(16, 185, 129, 0.3);"><i class="fa-solid fa-wifi"></i> Enable Net</button>
                    </form>
                    
                    <form method="POST" class="d-inline" onsubmit="return confirm('Disconnect user from router?');">
                        <input type="hidden" name="action" value="disconnect_user">
                        <button type="submit" class="btn-pill danger"><i class="fa-solid fa-plug-circle-xmark"></i> Disconnect</button>
                    </form>
                    
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="enable_net">
                        <button type="submit" class="btn-pill" style="color: #10b981; border-color: rgba(16, 185, 129, 0.3);"><i class="fa-solid fa-user-check"></i> Profile Enable</button>
                    </form>
                <?php else: ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Disable internet for this user?');">
                        <input type="hidden" name="action" value="disable_net">
                        <button type="submit" class="btn-pill danger"><i class="fa-solid fa-wifi"></i> Disable Net</button>
                    </form>
                    
                    <form method="POST" class="d-inline" onsubmit="return confirm('Disconnect user from router?');">
                        <input type="hidden" name="action" value="disconnect_user">
                        <button type="submit" class="btn-pill danger"><i class="fa-solid fa-plug-circle-xmark"></i> Disconnect</button>
                    </form>

                    <form method="POST" class="d-inline" onsubmit="return confirm('Disable this profile?');">
                        <input type="hidden" name="action" value="profile_disable">
                        <button type="submit" class="btn-pill danger"><i class="fa-solid fa-user-slash"></i> Profile Disable</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Metrics & Accordions -->
    <div class="col-lg-8 col-md-7">
        
        <!-- Metrics Row -->
        <div class="row mb-4">
            <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
                <div class="view-card metric-card mb-0 h-100">
                    <div class="metric-icon <?= $online ? 'bg-light-success text-success' : 'bg-light-secondary text-secondary' ?>"><i class="fa-solid fa-wifi"></i></div>
                    <div class="metric-data">
                        <h6>Connection Status</h6>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <?php if ($online): ?>
                                <span class="badge bg-success rounded-pill px-2 py-1" style="font-size: 0.75rem;">Online</span>
                                <h5 class="mb-0 fw-bold text-dark font-monospace" id="live_uptime" style="font-size: 0.95rem;"><?= $uptime_str ?></h5>
                            <?php else: ?>
                                <span class="badge bg-secondary rounded-pill px-2 py-1" style="font-size: 0.75rem;">Offline</span>
                                <span class="text-muted small" id="live_uptime">Disconnected</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
                <div class="view-card metric-card mb-0 h-100">
                    <div class="metric-icon bg-light-primary"><i class="fa-solid fa-hourglass-start"></i></div>
                    <div class="metric-data">
                        <h6>Total Volume</h6>
                        <h4>Unlimited</h4>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-3 mb-sm-0">
                <div class="view-card metric-card mb-0 h-100">
                    <div class="metric-icon bg-light-warning"><i class="fa-solid fa-hourglass-half"></i></div>
                    <div class="metric-data">
                        <h6>Used Volume</h6>
                        <h4 id="live_used_volume"><?= $used_volume_str ?></h4>
                        <small class="text-muted" style="font-size: 0.72rem;">Since <?= $cycle_start ? date('d M Y', strtotime($cycle_start)) : 'Activation' ?></small>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="view-card metric-card mb-0 h-100">
                    <div class="metric-icon bg-light-success"><i class="fa-regular fa-credit-card"></i></div>
                    <div class="metric-data">
                        <h6>Current Balance</h6>
                        <h4>Rs. <?= number_format($user['balance'], 0) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Graph Card -->
        <div class="view-card p-4 mb-4 border-0 shadow-sm" style="border-radius: 14px; background: #ffffff;">
            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; border-radius: 10px;">
                        <i class="fa-solid fa-chart-line fs-5"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">Live Bandwidth Monitor &amp; Ledger</h6>
                        <small class="text-muted" style="font-size: 0.78rem;">Real-time MikroTik traffic throughput &amp; billing history</small>
                    </div>
                </div>
                <div>
                    <span id="live_status_badge" class="badge bg-secondary bg-opacity-10 text-secondary border px-3 py-1.5 rounded-pill fs-7 d-inline-flex align-items-center gap-1.5">
                        <i class="fa-solid fa-circle text-muted me-1" style="font-size:0.45rem;"></i> Connecting...
                    </span>
                </div>
            </div>
            
            <div class="row g-4">
                <!-- Left: Annual Financial Ledger -->
                <div class="col-lg-6 border-end">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="fw-bold text-secondary small text-uppercase" style="letter-spacing: 0.5px;">
                            <i class="fa-solid fa-scale-balanced me-1 text-primary"></i> Annual Ledger (Funds vs Usage)
                        </span>
                        <span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.72rem;">Last 12 Months</span>
                    </div>
                    <div style="position: relative; height: 215px;">
                        <canvas id="ledgerChart"></canvas>
                    </div>
                </div>
                
                <!-- Right: Live MikroTik Bandwidth -->
                <div class="col-lg-6">
                    <div id="bw_router_alert" class="d-none"></div>

                    <!-- Live Speed Stat Cards -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="p-2.5 rounded-3 border" style="background: linear-gradient(135deg, rgba(6, 182, 212, 0.08) 0%, rgba(14, 165, 233, 0.03) 100%); border-color: rgba(6, 182, 212, 0.25) !important;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-muted text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-circle-down text-info me-1"></i> Live Download
                                    </span>
                                    <span class="badge bg-info bg-opacity-10 text-info font-monospace px-1.5 py-0.5" id="peak_down_badge" style="font-size: 0.68rem;">Peak: 0.00</span>
                                </div>
                                <div class="d-flex align-items-baseline gap-1">
                                    <span id="live_down_val" class="font-monospace fw-bolder fs-3 text-info" style="line-height: 1.1;">0.00</span>
                                    <span id="live_down_unit" class="text-info fw-bold small">Mbps</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2.5 rounded-3 border" style="background: linear-gradient(135deg, rgba(168, 85, 247, 0.08) 0%, rgba(217, 70, 239, 0.03) 100%); border-color: rgba(168, 85, 247, 0.25) !important;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-muted text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-circle-up me-1" style="color: #a855f7;"></i> Live Upload
                                    </span>
                                    <span class="badge font-monospace px-1.5 py-0.5" id="peak_up_badge" style="font-size: 0.68rem; background: rgba(168, 85, 247, 0.12); color: #a855f7;">Peak: 0.00</span>
                                </div>
                                <div class="d-flex align-items-baseline gap-1">
                                    <span id="live_up_val" class="font-monospace fw-bolder fs-3" style="color: #a855f7; line-height: 1.1;">0.00</span>
                                    <span id="live_up_unit" class="fw-bold small" style="color: #a855f7;">Mbps</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Waveform Canvas -->
                    <div style="position: relative; height: 160px;">
                        <canvas id="bwChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accordions -->
        <div class="accordion custom-accordion" id="userDetailsAccordion">
            
            <!-- Accordion 1: Service Details -->
            <div class="accordion-item">
                <h2 class="accordion-header d-flex align-items-center">
                    <button class="accordion-button collapsed flex-grow-1" type="button" data-bs-toggle="collapse" data-bs-target="#accService">
                        <i class="fa-solid fa-wrench"></i> Service Details
                    </button>
                </h2>
                <div id="accService" class="accordion-collapse collapse show" data-bs-parent="#userDetailsAccordion">
                    <div class="accordion-body text-secondary p-3">
                        <table class="table table-sm table-borderless text-secondary mb-0" style="font-size: 0.9rem;">
                            <tbody>
                                <tr>
                                    <td class="fw-bold w-50">Profile Status</td>
                                    <td>
                                        <?php if ($is_disabled): ?>
                                            <span class="badge bg-danger">Disabled</span>
                                        <?php elseif ($is_expired): ?>
                                            <span class="badge bg-warning text-dark">Expired</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Connection State</td>
                                    <td>
                                        <?php if ($online): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-wifi me-1"></i>Online</span>
                                            <span class="text-success small fw-semibold ms-2">(Connected - Uptime: <span id="svc_uptime_display"><?= $uptime_str ?></span>)</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fa-solid fa-power-off me-1"></i>Offline</span>
                                            <span class="text-muted small ms-2">(Disconnected)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr><td class="fw-bold">Connection Type</td><td>Radius <?= strtoupper($user['service_type'] ?: 'PPPoE') ?></td></tr>
                                <tr><td class="fw-bold">Package</td><td><?= htmlspecialchars($user['pkg_name'] ?? 'N/A') ?></td></tr>
                                <tr><td class="fw-bold">Package Duration</td><td><?= $user['validity_days'] ? $user['validity_days'] . ' Days' : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Created / Registered Date</td><td><?= !empty($user['created_at']) ? date('d M Y H:i:s', strtotime($user['created_at'])) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Last Expiration Date</td><td><?= $user['expiry_date'] ? date('d M Y H:i:s', strtotime($user['expiry_date'])) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Total Volume</td><td><?= $total_vol_str ?></td></tr>
                                <tr>
                                    <td class="fw-bold">Used Volume (Current Cycle)</td>
                                    <td id="svc_used_volume">
                                        <strong><?= $used_volume_str ?></strong>
                                        <span class="text-muted small ms-1">(Up: <?= $cycle_up_str ?> | Down: <?= $cycle_down_str ?>)</span>
                                    </td>
                                </tr>
                                <tr><td class="fw-bold">Remaining Volume</td><td id="svc_rem_volume"><?= $rem_vol_str ?></td></tr>
                                <tr><td class="fw-bold">All-Time Lifetime Usage</td><td><span class="text-muted"><?= $lifetime_vol_str ?></span></td></tr>
                                <tr><td class="fw-bold">Current Cycle Started</td><td><?= $cycle_start ? date('d M Y, h:i A', strtotime($cycle_start)) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Total Session Time</td><td>Unlimited</td></tr>
                                <tr><td class="fw-bold">Used Session Time</td><td><?= $used_sess_str ?></td></tr>
                                <tr><td class="fw-bold">Remaining Session Time</td><td>Unlimited</td></tr>
                                <tr><td class="fw-bold">Last Activation Date</td><td><?= $last_act ? date('d M Y H:i:s', strtotime($last_act)) : 'N/A' ?></td></tr>
                                <?php
                                $latest_login_ts = null;
                                if (!empty($latest_conn['acctstarttime']) && $latest_conn['acctstarttime'] !== '0000-00-00 00:00:00') {
                                    $latest_login_ts = is_numeric($latest_conn['acctstarttime']) ? (int)$latest_conn['acctstarttime'] : strtotime($latest_conn['acctstarttime']);
                                }
                                if (!$latest_login_ts && !empty($latest_conn['acctstoptime']) && $latest_conn['acctstoptime'] !== '0000-00-00 00:00:00') {
                                    $stop_ts = is_numeric($latest_conn['acctstoptime']) ? (int)$latest_conn['acctstoptime'] : strtotime($latest_conn['acctstoptime']);
                                    if ($stop_ts > 86400) {
                                        $latest_login_ts = $stop_ts - (int)($latest_conn['acctsessiontime'] ?? 0);
                                    }
                                }
                                $last_login_display = ($latest_login_ts && $latest_login_ts > 86400) ? date('d M Y H:i:s', $latest_login_ts) : 'N/A';
                                ?>
                                <tr><td class="fw-bold">Last Login</td><td><?= $last_login_display ?></td></tr>
                                <tr><td class="fw-bold">IPv6 IP Address</td><td><?= !empty($latest_conn['framedipv6address']) ? htmlspecialchars($latest_conn['framedipv6address']) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Connected IP</td><td><?= !empty($latest_conn['framedipaddress']) ? htmlspecialchars($latest_conn['framedipaddress']) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Connected MAC</td><td><?= !empty($latest_conn['callingstationid']) ? htmlspecialchars($latest_conn['callingstationid']) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Connected NAS/Router</td><td><?= !empty($latest_conn['nasipaddress']) ? htmlspecialchars($latest_conn['nasipaddress']) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Connected Port</td><td><?= !empty($latest_conn['nasportid']) ? htmlspecialchars($latest_conn['nasportid']) : 'N/A' ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Accordion 2: Profile Notes -->
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accNote">
                        <i class="fa-regular fa-note-sticky"></i> Profile Notes
                    </button>
                </h2>
                <div id="accNote" class="accordion-collapse collapse" data-bs-parent="#userDetailsAccordion">
                    <div class="accordion-body text-secondary">
                        <p><?= nl2br(htmlspecialchars($user['notes'] ?: 'No notes found for this user.')) ?></p>
                    </div>
                </div>
            </div>

            <!-- Accordion 3: Ledger -->
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accLedger">
                        <i class="fa-solid fa-chart-line"></i> Ledger
                    </button>
                </h2>
                <div id="accLedger" class="accordion-collapse collapse" data-bs-parent="#userDetailsAccordion">
                    <div class="accordion-body text-secondary p-0">
                        <?php if (empty($ledger_history)): ?>
                            <p class="p-3 mb-0">No ledger transactions found yet.</p>
                        <?php else: ?>
                            <div class="row px-3 pt-3 pb-0">
                                <div class="col-md-4 mb-2">
                                    <label class="form-label small text-secondary mb-1">From Date</label>
                                    <input type="date" id="minLedgerDate" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label small text-secondary mb-1">To Date</label>
                                    <input type="date" id="maxLedgerDate" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="table-responsive p-3 pt-0">
                                <table id="ledgerTable" class="table table-hover table-bordered mb-0 text-secondary w-100" style="font-size: 0.85rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th>Amount</th>
                                            <th>Balance After</th>
                                            <th class="text-end">Receipt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ledger_history as $l): ?>
                                            <tr>
                                                <td data-order="<?= date('Y-m-d H:i:s', strtotime($l['created_at'])) ?>"><?= date('d M Y, h:i A', strtotime($l['created_at'])) ?></td>
                                                <td><?= htmlspecialchars($l['description']) ?></td>
                                                <td class="<?= $l['type'] == 'credit' ? 'text-success' : 'text-danger' ?>">
                                                    <?= $l['type'] == 'credit' ? '+' : '-' ?> Rs <?= number_format($l['amount'], 0) ?>
                                                </td>
                                                <td class="fw-bold">Rs <?= number_format($l['balance_after'], 0) ?></td>
                                                <td class="text-end">
                                                    <a href="print_receipt.php?ledger_id=<?= $l['id'] ?>&sub_id=<?= $id ?>&return=<?= urlencode('subscriber_view.php?id=' . $id) ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-bold" style="font-size: 0.78rem;">
                                                        <i class="fa-solid fa-print me-1"></i> Receipt
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODALS ================= -->

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-brands fa-paypal"></i> Add Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_balance">
        <div class="modal-body">
            <div class="alert alert-info py-2 px-3 small mb-3">
                <i class="fa-solid fa-wallet me-1"></i> Your Available Wallet: <strong>Rs. <?= number_format($current_dealer_balance, 2) ?></strong>
                <div class="text-muted" style="font-size: 0.78rem;">Payment amount will be credited to customer balance and deducted from your dealer wallet.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold small text-secondary">Amount (Rs) <span class="text-danger">*</span></label>
                <input type="number" step="1" min="1" max="<?= $current_dealer_balance ?>" name="amount" class="form-control" placeholder="Enter amount" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold small text-secondary">Description / Note</label>
                <input type="text" name="description" class="form-control" placeholder="e.g. Cash collected by dealer">
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold"><i class="fa-solid fa-check me-1"></i> Add Balance</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Renew Package Modal -->
<div class="modal fade" id="renewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content shadow-lg border-0 rounded-4">
      <div class="modal-header bg-light border-bottom px-4 py-3">
        <h5 class="modal-title fw-bold text-dark">
          <i class="fa-solid fa-rotate text-primary me-2"></i> Renew Subscriber Package
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="renew_user">
        <div class="modal-body p-4">

            <!-- Subscriber Quick Status Strip -->
            <div class="bg-light rounded-3 p-3 mb-3 border">
                <div class="row g-2 align-items-center">
                    <div class="col-6 col-md-3">
                        <div class="text-muted small fw-semibold">Status</div>
                        <div class="mt-1">
                            <?php if (!$is_disabled && !$is_expired): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold rounded-pill px-2 py-1">
                                    <i class="fa-solid fa-circle-check me-1"></i> Active
                                </span>
                            <?php elseif ($is_disabled): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold rounded-pill px-2 py-1">
                                    <i class="fa-solid fa-ban me-1"></i> Disabled
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold rounded-pill px-2 py-1">
                                    <i class="fa-solid fa-circle-xmark me-1"></i> Expired
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small fw-semibold">Current Expiry</div>
                        <div class="fw-bold text-dark mt-1 font-monospace small">
                            <?= (!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00') ? date('d M Y, h:i A', strtotime($user['expiry_date'])) : '<span class="text-muted">None / Expired</span>' ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small fw-semibold">Customer Balance</div>
                        <div class="fw-bold mt-1 <?= (float)$user['balance'] < 0 ? 'text-danger' : 'text-success' ?>">
                            Rs. <?= number_format((float)$user['balance'], 2) ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small fw-semibold">Dealer Wallet</div>
                        <div class="fw-bold mt-1 text-primary">
                            Rs. <?= number_format($current_dealer_balance, 2) ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Inputs -->
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-secondary small">Package <span class="text-danger">*</span></label>
                    <select name="package_id" id="renew_package_select" class="form-select form-select-sm" required onchange="recalculateRenewAdjustment()">
                        <?php foreach($packages as $p): 
                            $dp_cost = (float)($p['dealer_price'] ?? $p['price']);
                        ?>
                            <option value="<?= $p['id'] ?>" 
                                data-price="<?= $dp_cost ?>" 
                                data-days="<?= (int)(($p['validity_days'] ?? 0) > 0 ? $p['validity_days'] : 30) ?>" 
                                data-name="<?= htmlspecialchars($p['name']) ?>"
                                <?= $p['id'] == $user['package_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?> (Dealer: Rs. <?= number_format($dp_cost) ?> / <?= $p['validity_days'] ?: 30 ?>d) - <?= htmlspecialchars($p['rate_limit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($can_custom_expiry): ?>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-secondary small">Set Expiry Date &amp; Time <span class="text-danger">*</span></label>
                    <input type="datetime-local" name="expiry_date" id="renew_expiry_input" class="form-control form-control-sm" 
                           value="<?= (!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00') ? date('Y-m-d\TH:i', strtotime($user['expiry_date'])) : date('Y-m-d\TH:i', strtotime('+30 days')) ?>" 
                           required oninput="recalculateRenewAdjustment()">
                </div>
                <?php else: ?>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-secondary small">Package Validity / Expiry</label>
                    <div class="form-control form-control-sm bg-light text-primary fw-bold d-flex align-items-center justify-content-between">
                        <span><i class="fa-solid fa-calendar-check me-2 text-success"></i> Auto (+<span id="mgr_pkg_days_display">30</span> Days)</span>
                        <span class="badge bg-primary text-white" id="mgr_target_expiry_display">Calculating...</span>
                    </div>
                    <input type="hidden" name="expiry_date" id="renew_expiry_input" value="">
                </div>
                <?php endif; ?>

                <!-- Payment Received Field -->
                <div class="col-12 mt-2">
                    <div class="p-3 rounded-3 border border-success-subtle bg-success bg-opacity-10 shadow-sm">
                        <div class="row align-items-center g-2">
                            <div class="col-md-7">
                                <label class="form-label fw-bold text-success mb-0 d-flex align-items-center fs-6">
                                    <i class="fa-solid fa-money-bill-wave me-2 text-success fs-5"></i> Payment Received (Rs)
                                </label>
                                <div class="text-muted small mt-1" style="font-size: 0.78rem;">
                                    Cash collected from subscriber at renewal. Recorded in Ledger and added to subscriber balance.
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="input-group">
                                    <span class="input-group-text bg-white fw-bold text-success border-success-subtle">Rs.</span>
                                    <input type="number" step="any" min="0" name="payment_amount" id="renew_payment_amount" class="form-control form-control-lg fw-bold text-success border-success-subtle shadow-none" placeholder="0.00" value="<?= (float)($user['pkg_price'] ?? 0) ?>" oninput="recalculateRenewAdjustment()">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($can_custom_expiry): ?>
            <!-- Quick Preset Helper Buttons -->
            <div class="mt-3">
                <label class="form-label fw-semibold text-secondary small mb-1">
                    <i class="fa-solid fa-wand-magic-sparkles text-primary me-1"></i> Quick Expiry Presets:
                </label>
                <div class="d-flex flex-wrap gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setRenewPreset(7)">+7 Days</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setRenewPreset(15)">+15 Days</button>
                    <button type="button" class="btn btn-sm btn-outline-primary fw-semibold rounded-pill px-3" onclick="setRenewPreset(30)">+30 Days (1 Month)</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setRenewPreset(60)">+60 Days (2 Months)</button>
                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2" onclick="setRenewPreset(0)" title="Set Expiry to Right Now (Expire User)">Expire Now</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Live Financial Calculation Container -->
            <div class="mt-4">
                <label class="form-label fw-semibold text-secondary small mb-1">
                    <i class="fa-solid fa-calculator text-primary me-1"></i> Live Financial Impact &amp; Ledger Calculation:
                </label>
                <div id="renew_calculation_result">
                    <!-- Populated dynamically by JavaScript -->
                </div>
            </div>

        </div>
        <div class="modal-footer bg-light border-top px-4 py-3">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold rounded-pill px-4 shadow-sm">
              <i class="fa-solid fa-check me-1"></i> Confirm Renewal
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Note Modal -->
<div class="modal fade" id="noteModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-regular fa-note-sticky"></i> Profile Note</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_note">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label fw-semibold small text-secondary">Note</label>
                <textarea name="notes" class="form-control" rows="4"><?= htmlspecialchars($user['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Save Note</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-key"></i> Change Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label fw-semibold small text-secondary">New Password</label>
                <input type="text" name="new_password" class="form-control font-monospace" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Update Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View Photo Modal -->
<div class="modal fade" id="photoViewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-transparent border-0">
            <div class="modal-header border-0 pb-0 justify-content-end">
                <button type="button" class="btn-close bg-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center pt-0">
                <?php if (!empty($user['photo'])): ?>
                    <img src="../uploads/profiles/<?= htmlspecialchars($user['photo']) ?>" class="img-fluid rounded shadow-lg" oncontextmenu="return false;" style="max-height: 80vh; pointer-events: none; border: 4px solid white;" alt="Profile View">
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- jQuery & DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<!-- DataTables Buttons JS -->
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<script>
// Clipboard Copy Helper
function copyToClipboard(e, text, btn) {
    if (e) e.stopPropagation();
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(showCopied).catch(fallback);
    } else {
        fallback();
    }
    function showCopied() {
        if (!btn) return;
        const origHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check text-success" style="font-size: 0.8rem;"></i>';
        btn.title = 'Copied!';
        setTimeout(() => {
            btn.innerHTML = origHtml;
            btn.title = 'Copy Username';
        }, 1500);
    }
    function fallback() {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            showCopied();
        } catch (err) {}
        document.body.removeChild(ta);
    }
}

// DataTables Initialization for Ledger
$(document).ready(function() {
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex, rowData, counter) {
        let min = $('#minLedgerDate').val();
        let max = $('#maxLedgerDate').val();
        let dateStr = settings.aoData[dataIndex].anCells[0].getAttribute('data-order');
        if (!dateStr) return true;
        
        let rowDate = new Date(dateStr.split(' ')[0]);
        let minDate = min ? new Date(min) : null;
        let maxDate = max ? new Date(max) : null;

        if (
            (minDate === null && maxDate === null) ||
            (minDate === null && rowDate <= maxDate) ||
            (minDate <= rowDate && maxDate === null) ||
            (minDate <= rowDate && rowDate <= maxDate)
        ) {
            return true;
        }
        return false;
    });

    var table = $('#ledgerTable').DataTable({
        dom: '<"row"<"col-sm-12 col-md-6"B><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            { extend: 'copy', className: 'btn btn-light border btn-sm text-secondary', title: 'Ledger Details' },
            { extend: 'csv', className: 'btn btn-light border btn-sm text-secondary', title: 'Ledger Details' },
            { extend: 'excel', className: 'btn btn-light border btn-sm text-secondary', title: 'Ledger Details' },
            { extend: 'pdf', className: 'btn btn-light border btn-sm text-secondary', title: 'Ledger Details' },
            { extend: 'print', className: 'btn btn-light border btn-sm text-secondary', title: 'Ledger Details' }
        ],
        pageLength: 10,
        order: [[0, 'desc']]
    });

    $('#minLedgerDate, #maxLedgerDate').on('change', function() {
        table.draw();
    });
});

// Annual Financial Ledger Chart
const ctxLedger = document.getElementById('ledgerChart').getContext('2d');
new Chart(ctxLedger, {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        datasets: [
            { label: 'Funds Added', data: <?= $payment_data_json ?>, borderColor: '#10b981', tension: 0.1, fill: false },
            { label: 'Usage / Deductions', data: <?= $deducted_data_json ?>, borderColor: '#ef4444', tension: 0.1, fill: false }
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'top', labels: { boxWidth: 12 } } }, scales: { y: { beginAtZero: true } } }
});

// Live MikroTik Bandwidth Chart
const ctxBw = document.getElementById('bwChart').getContext('2d');
const downGrad = ctxBw.createLinearGradient(0, 0, 0, 160);
downGrad.addColorStop(0, 'rgba(6, 182, 212, 0.35)');
downGrad.addColorStop(1, 'rgba(6, 182, 212, 0.00)');

const upGrad = ctxBw.createLinearGradient(0, 0, 0, 160);
upGrad.addColorStop(0, 'rgba(168, 85, 247, 0.30)');
upGrad.addColorStop(1, 'rgba(168, 85, 247, 0.00)');

const bwChart = new Chart(ctxBw, {
    type: 'line',
    data: {
        labels: ['--', '--', '--', '--', '--', '--', '--', '--', '--', '--', '--', '--'],
        datasets: [
            { 
                label: 'Download', 
                data: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 
                borderColor: '#06b6d4', 
                backgroundColor: downGrad,
                borderWidth: 2.5,
                tension: 0.4, 
                fill: true,
                pointRadius: 0,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#ffffff',
                pointHoverBorderColor: '#06b6d4',
                pointHoverBorderWidth: 2.5
            },
            { 
                label: 'Upload', 
                data: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 
                borderColor: '#a855f7', 
                backgroundColor: upGrad,
                borderWidth: 2.5,
                tension: 0.4, 
                fill: true,
                pointRadius: 0,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#ffffff',
                pointHoverBorderColor: '#a855f7',
                pointHoverBorderWidth: 2.5
            }
        ]
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false,
        plugins: { 
            legend: { 
                position: 'top', 
                align: 'end',
                labels: { 
                    boxWidth: 8, 
                    boxHeight: 8,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    font: { size: 11, weight: 'bold' },
                    padding: 8
                } 
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                backgroundColor: 'rgba(15, 23, 42, 0.92)',
                titleFont: { size: 11 },
                bodyFont: { size: 12, weight: 'bold' },
                padding: 10,
                cornerRadius: 8,
                callbacks: {
                    label: function(ctx) {
                        return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' Mbps';
                    }
                }
            }
        }, 
        scales: { 
            x: {
                grid: { display: false },
                ticks: { font: { size: 9 }, maxRotation: 0 }
            },
            y: { 
                beginAtZero: true,
                suggestedMax: 2,
                grid: {
                    color: 'rgba(0, 0, 0, 0.04)',
                    borderDash: [4, 4]
                },
                ticks: { 
                    font: { size: 10 },
                    callback: function(v) { return v + ' M'; } 
                }
            } 
        }, 
        animation: false 
    }
});

let prevBytesIn = null;
let prevBytesOut = null;
let lastTime = null;
const pastBytes = <?= (int)$past_bytes ?>;
let peakDown = 0;
let peakUp = 0;
let isFetchingBw = false;
let bwPollTimer = null;

function splitSpeed(mbps) {
    if (isNaN(mbps) || mbps <= 0) return { val: '0.00', unit: 'Mbps' };
    if (mbps < 0.1) {
        return { val: (mbps * 1024).toFixed(1), unit: 'Kbps' };
    }
    return { val: mbps.toFixed(2), unit: 'Mbps' };
}

function fetchLiveBandwidth() {
    if (isFetchingBw) return;
    isFetchingBw = true;

    fetch(`api_bandwidth.php?username=<?= urlencode($user['username']) ?>&sub_id=<?= (int)$user['id'] ?>&_=${Date.now()}`, {
        cache: 'no-store'
    })
        .then(res => res.json())
        .then(data => {
            if (data.bytes_in !== undefined && data.bytes_out !== undefined) {
                if (data.uptime !== undefined && data.uptime !== null && data.uptime !== '') {
                    let uEl = document.getElementById('live_uptime');
                    if (uEl) uEl.innerText = data.uptime;
                    let svcU = document.getElementById('svc_uptime_display');
                    if (svcU) svcU.innerText = data.uptime;
                }
                
                let sBadge = document.getElementById('live_status_badge');
                let alertBox = document.getElementById('bw_router_alert');
                
                if (sBadge) {
                    if (data.status === 'online') {
                        if (data.source === 'mikrotik') {
                            sBadge.className = 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-1.5 rounded-pill fs-7 d-inline-flex align-items-center gap-1.5';
                            sBadge.innerHTML = '<span class="spinner-grow spinner-grow-sm text-success" style="width:7px; height:7px;" role="status"></span> Active (Live MikroTik)';
                            if (alertBox) alertBox.className = 'd-none';
                        } else {
                            sBadge.className = 'badge bg-warning bg-opacity-15 text-dark border border-warning border-opacity-50 px-3 py-1.5 rounded-pill fs-7 d-inline-flex align-items-center gap-1.5';
                            sBadge.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> RADIUS Accounting';
                            if (alertBox) alertBox.className = 'd-none';
                        }
                    } else {
                        sBadge.className = 'badge bg-secondary bg-opacity-10 text-secondary border px-3 py-1.5 rounded-pill fs-7 d-inline-flex align-items-center gap-1.5';
                        sBadge.innerHTML = '<i class="fa-solid fa-circle text-muted me-1" style="font-size:0.45rem;"></i> Offline';
                        if (alertBox) alertBox.className = 'd-none';
                    }
                }

                // Update Used Volume LIVE
                let currentLiveBytes = parseFloat(data.bytes_in) + parseFloat(data.bytes_out);
                let totalCurrentBytes = pastBytes + currentLiveBytes;
                
                let volStr = "0 MB";
                if (totalCurrentBytes >= 1073741824) {
                    volStr = (totalCurrentBytes / 1073741824).toFixed(2) + " GB";
                } else if (totalCurrentBytes >= 1048576) {
                    volStr = (totalCurrentBytes / 1048576).toFixed(2) + " MB";
                } else if (totalCurrentBytes > 0) {
                    volStr = (totalCurrentBytes / 1024).toFixed(2) + " KB";
                }
                
                let usedElem = document.getElementById('live_used_volume');
                if (usedElem) usedElem.innerText = volStr;
                let svcU = document.getElementById('svc_used_volume');
                if (svcU) svcU.innerText = volStr;
                
                let svcR = document.getElementById('svc_rem_volume');
                const dataLimitGb = <?= (float)($user['data_limit_gb'] ?? 0) ?>;
                if (svcR && dataLimitGb > 0) {
                    let remGb = dataLimitGb - (totalCurrentBytes / 1073741824);
                    svcR.innerText = remGb.toFixed(2) + " GB";
                }

                let nowTime = Date.now();
                let currentBytesIn = parseFloat(data.bytes_in) || 0;
                let currentBytesOut = parseFloat(data.bytes_out) || 0;
                
                let tx_mbps = 0; // Download
                let rx_mbps = 0; // Upload

                if (data.rx_bps !== undefined && data.tx_bps !== undefined && (parseFloat(data.rx_bps) > 0 || parseFloat(data.tx_bps) > 0)) {
                    rx_mbps = parseFloat(data.rx_bps) / 1048576;
                    tx_mbps = parseFloat(data.tx_bps) / 1048576;
                } else if (data.source === 'mikrotik' && prevBytesIn !== null && prevBytesOut !== null && lastTime !== null) {
                    let timeDiffSecs = (nowTime - lastTime) / 1000;
                    if (timeDiffSecs > 0.5) {
                        let bytesInDiff = currentBytesIn - prevBytesIn;
                        let bytesOutDiff = currentBytesOut - prevBytesOut;
                        if (bytesInDiff < 0) bytesInDiff = 0;
                        if (bytesOutDiff < 0) bytesOutDiff = 0;
                        rx_mbps = (bytesInDiff * 8 / timeDiffSecs) / 1048576;
                        tx_mbps = (bytesOutDiff * 8 / timeDiffSecs) / 1048576;
                    }
                } else {
                    rx_mbps = 0;
                    tx_mbps = 0;
                }

                if (isNaN(tx_mbps) || tx_mbps < 0) tx_mbps = 0;
                if (isNaN(rx_mbps) || rx_mbps < 0) rx_mbps = 0;

                prevBytesIn = currentBytesIn;
                prevBytesOut = currentBytesOut;
                lastTime = nowTime;

                // Track Peak Speed
                if (tx_mbps > peakDown) {
                    peakDown = tx_mbps;
                    let sp = splitSpeed(peakDown);
                    let pEl = document.getElementById('peak_down_badge');
                    if (pEl) pEl.innerText = 'Peak: ' + sp.val + ' ' + sp.unit;
                }
                if (rx_mbps > peakUp) {
                    peakUp = rx_mbps;
                    let sp = splitSpeed(peakUp);
                    let pEl = document.getElementById('peak_up_badge');
                    if (pEl) pEl.innerText = 'Peak: ' + sp.val + ' ' + sp.unit;
                }

                let spDown = splitSpeed(tx_mbps);
                let spUp = splitSpeed(rx_mbps);
                let dVal = document.getElementById('live_down_val');
                let dUnit = document.getElementById('live_down_unit');
                if (dVal) dVal.innerText = spDown.val;
                if (dUnit) dUnit.innerText = spDown.unit;

                let uVal = document.getElementById('live_up_val');
                let uUnit = document.getElementById('live_up_unit');
                if (uVal) uVal.innerText = spUp.val;
                if (uUnit) uUnit.innerText = spUp.unit;

                // Shift old data and push new sample
                bwChart.data.labels.shift();
                bwChart.data.datasets[0].data.shift();
                bwChart.data.datasets[1].data.shift();
                
                let now = new Date();
                let timeStr = String(now.getHours()).padStart(2, '0') + ':' + 
                              String(now.getMinutes()).padStart(2, '0') + ':' + 
                              String(now.getSeconds()).padStart(2, '0');
                bwChart.data.labels.push(timeStr);
                
                bwChart.data.datasets[0].data.push(parseFloat(tx_mbps.toFixed(2)));
                bwChart.data.datasets[1].data.push(parseFloat(rx_mbps.toFixed(2)));
                
                bwChart.update();
            }
        })
        .catch(err => console.error("Error fetching bandwidth:", err))
        .finally(() => {
            isFetchingBw = false;
            bwPollTimer = setTimeout(fetchLiveBandwidth, 3000);
        });
}

// Initial poll start
fetchLiveBandwidth();

// Renew Modal Logic
const canCustomExpiry = <?= $can_custom_expiry ? 'true' : 'false' ?>;
const userCurBal = <?= (float)($user['balance'] ?? 0) ?>;
const userCurExpStr = <?= json_encode((!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00') ? $user['expiry_date'] : '') ?>;
const dealerWalletBal = <?= $current_dealer_balance ?>;

function recalculateRenewAdjustment() {
    const pkgSelect = document.getElementById('renew_package_select');
    const expiryInput = document.getElementById('renew_expiry_input');
    const resultBox = document.getElementById('renew_calculation_result');
    const payInput = document.getElementById('renew_payment_amount');
    if (!pkgSelect || !expiryInput || !resultBox) return;

    const opt = pkgSelect.options[pkgSelect.selectedIndex];
    const newPrice = opt ? parseFloat(opt.dataset.price || 0) : 0;
    const newValidity = opt ? parseInt(opt.dataset.days || 30) : 30;

    const now = Math.floor(Date.now() / 1000);
    let curExpTs = userCurExpStr ? Math.floor(new Date(userCurExpStr).getTime() / 1000) : 0;
    if (isNaN(curExpTs)) curExpTs = 0;

    if (!canCustomExpiry) {
        const mgrDaysDisp = document.getElementById('mgr_pkg_days_display');
        const mgrExpDisp = document.getElementById('mgr_target_expiry_display');
        if (mgrDaysDisp) mgrDaysDisp.innerText = newValidity;

        const baseTs = (curExpTs > now) ? curExpTs : now;
        const targetTs = baseTs + (newValidity * 86400);
        const targetDate = new Date(targetTs * 1000);
        
        const y = targetDate.getFullYear();
        const m = String(targetDate.getMonth() + 1).padStart(2, '0');
        const d = String(targetDate.getDate()).padStart(2, '0');
        const h = String(targetDate.getHours()).padStart(2, '0');
        const min = String(targetDate.getMinutes()).padStart(2, '0');
        expiryInput.value = `${y}-${m}-${d}T${h}:${min}`;

        if (mgrExpDisp) {
            mgrExpDisp.innerText = targetDate.toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) + ', ' + targetDate.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit', hour12: true});
        }
    }

    let paymentVal = payInput ? parseFloat(payInput.value || 0) : 0;
    if (isNaN(paymentVal) || paymentVal < 0) paymentVal = 0;

    const deduction = newPrice;
    const dealerBalAfter = dealerWalletBal - deduction;
    const subBalAfter = (userCurBal - deduction) + paymentVal;

    let html = `
        <div class="alert ${dealerBalAfter < 0 ? 'alert-danger' : 'alert-warning'} border rounded-3 p-3 mb-0">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-bold text-dark"><i class="fa-solid fa-box text-primary me-1"></i> Package: ${opt ? opt.dataset.name : ''}</span>
                <span class="badge ${dealerBalAfter < 0 ? 'bg-danger' : 'bg-danger-subtle text-danger'} border px-2 py-1 fw-bold fs-6">Dealer Cost: - Rs. ${deduction.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2 border-top small">
                <span class="text-secondary">Your Dealer Wallet Impact:</span>
                <span class="fw-bold text-dark">Rs. ${dealerWalletBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} &rarr; <span class="${dealerBalAfter < 0 ? 'text-danger' : 'text-primary'} fw-bold">Rs. ${dealerBalAfter.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></span>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-1 small">
                <span class="text-secondary">Customer Cash Payment Received:</span>
                <span class="fw-bold text-success">+ Rs. ${paymentVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-1 small">
                <span class="text-secondary">Customer Net Balance:</span>
                <span class="fw-bold text-dark">Rs. ${userCurBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} &rarr; <span class="${subBalAfter < 0 ? 'text-danger' : 'text-success'} fw-bold">Rs. ${subBalAfter.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></span>
            </div>
            ${dealerBalAfter < 0 ? '<div class="text-danger small mt-2 fw-bold"><i class="fa-solid fa-triangle-exclamation me-1"></i> Warning: Insufficient dealer wallet balance for this renewal!</div>' : ''}
        </div>
    `;

    resultBox.innerHTML = html;
}

function setRenewPreset(days) {
    const input = document.getElementById('renew_expiry_input');
    if (!input) return;

    let base = new Date();
    if (userCurExpStr) {
        const cd = new Date(userCurExpStr);
        if (cd > base) {
            base = cd;
        }
    }

    if (days === 0) {
        base = new Date();
    } else {
        base.setDate(base.getDate() + days);
    }

    const y = base.getFullYear();
    const m = String(base.getMonth() + 1).padStart(2, '0');
    const d = String(base.getDate()).padStart(2, '0');
    const h = String(base.getHours()).padStart(2, '0');
    const min = String(base.getMinutes()).padStart(2, '0');
    input.value = `${y}-${m}-${d}T${h}:${min}`;
    recalculateRenewAdjustment();
}

// Calculate on load
document.addEventListener('DOMContentLoaded', function() {
    recalculateRenewAdjustment();
});
</script>

<?php require_once 'footer.php'; ?>
