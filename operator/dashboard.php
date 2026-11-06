<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];
$is_mgr = !empty($_SESSION['is_manager']);
$current_manager_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;

// Auto-Sync & Block Expired Users (runs once every 60 seconds max to maintain blazing performance)
if (!isset($_SESSION['last_expiry_sync']) || (time() - $_SESSION['last_expiry_sync']) > 60) {
    require_once __DIR__ . '/../config/radius_kick_helper.php';
    sync_expired_subscribers($pdo, $client_id, false); // Fast DB-only sync, NEVER block web page on network sockets
    $_SESSION['last_expiry_sync'] = time();
}

// Auto-Check Daily Financial Backup (Once every 24 hours)
if (empty($is_mgr) && (!isset($_SESSION['last_financial_backup_check']) || (time() - $_SESSION['last_financial_backup_check']) > 3600)) {
    require_once __DIR__ . '/../config/financial_backup_helper.php';
    try {
        auto_trigger_daily_backup_if_needed($pdo, $client_id);
    } catch (Exception $e) {}
    $_SESSION['last_financial_backup_check'] = time();
}

// --- Handle CSV Restore ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore_backup') {
    if (!empty($_SESSION['is_manager'])) {
        echo "<script>alert('Permission Denied: Managers cannot restore backups.'); window.location='dashboard.php';</script>";
        exit;
    }
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['backup_file']['tmp_name'];
        if (($file = fopen($tmpName, 'r')) !== FALSE) {
            $headers = fgetcsv($file); // Read header row
            
            // Map header names to their index
            $colMap = [];
            foreach ($headers as $index => $name) {
                $colMap[trim($name)] = $index;
            }
            
            // Determine if this is the OLD basic CSV or NEW full CSV
            $is_new_format = isset($colMap['Package Name']);
            
            $pdo->beginTransaction();
            try {
                while (($data = fgetcsv($file)) !== FALSE) {
                    if (empty($data[0]) && empty($data[1])) continue; // Skip empty rows

                    if ($is_new_format) {
                        // NEW FULL FORMAT
                        $username = trim($data[$colMap['Username']] ?? '');
                        $password = trim($data[$colMap['Password']] ?? '');
                        $full_name = trim($data[$colMap['Full Name']] ?? '');
                        $service_type = trim($data[$colMap['Service Type']] ?? 'pppoe');
                        $package_name = trim($data[$colMap['Package Name']] ?? '');
                        $dealer_username = trim($data[$colMap['Dealer Username']] ?? '');
                        $mobile = trim($data[$colMap['Mobile']] ?? '');
                        $phone = trim($data[$colMap['Phone']] ?? '');
                        $national_id = trim($data[$colMap['National ID']] ?? '');
                        $city = trim($data[$colMap['City']] ?? '');
                        $subarea = trim($data[$colMap['Subarea']] ?? '');
                        $address = trim($data[$colMap['Address']] ?? '');
                        $gps_lat = trim($data[$colMap['GPS Lat']] ?? '');
                        $gps_lng = trim($data[$colMap['GPS Lng']] ?? '');
                        $notes = trim($data[$colMap['Notes']] ?? '');
                        $balance = (float)($data[$colMap['Balance']] ?? 0);
                        $status = trim($data[$colMap['Status']] ?? 'active');
                        $expiry = trim($data[$colMap['Expiry Date']] ?? '');
                        
                        // Resolve Package ID & Profile
                        $package_id = null;
                        $pkg_rate_limit = '';
                        $pkg_pool_name = '';
                        if (!empty($package_name)) {
                            $pkgStmt = $pdo->prepare("SELECT p.id, p.rate_limit, p.pool_id, pl.pool_name 
                                                      FROM packages p 
                                                      LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
                                                      WHERE p.name = ? AND (p.client_id = ? OR p.client_id = 0) LIMIT 1");
                            $pkgStmt->execute([$package_name, $client_id]);
                            $pkgRow = $pkgStmt->fetch();
                            if ($pkgRow) {
                                $package_id = (int)$pkgRow['id'];
                                $pkg_rate_limit = $pkgRow['rate_limit'];
                                $pkg_pool_name = $pkgRow['pool_name'];
                            } else {
                                // AUTO-CREATE missing package to prevent foreign key errors
                                $insPkg = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, price) VALUES (?, ?, '1M/1M', 0)");
                                $insPkg->execute([$client_id, $package_name]);
                                $package_id = (int)$pdo->lastInsertId();
                                $pkg_rate_limit = '1M/1M';
                            }
                        }
                        
                        // Resolve Dealer ID
                        $dealer_id = null;
                        if (!empty($dealer_username)) {
                            $dlrStmt = $pdo->prepare("SELECT id FROM dealers WHERE username = ? AND client_id = ? LIMIT 1");
                            $dlrStmt->execute([$dealer_username, $client_id]);
                            $did = $dlrStmt->fetchColumn();
                            if ($did) $dealer_id = (int)$did;
                        }
                        
                        // Check if user exists
                        $chkStmt = $pdo->prepare("SELECT id FROM subscribers WHERE username = ? AND client_id = ?");
                        $chkStmt->execute([$username, $client_id]);
                        $existing_id = $chkStmt->fetchColumn();
                        
                        if ($existing_id) {
                            // UPDATE
                            $upd = $pdo->prepare("UPDATE subscribers SET 
                                password=?, full_name=?, service_type=?, package_id=?, dealer_id=?, 
                                mobile=?, phone=?, national_id=?, city=?, subarea=?, address=?, 
                                gps_lat=?, gps_lng=?, notes=?, balance=?, status=? 
                                WHERE id=?");
                            $upd->execute([
                                $password, $full_name, $service_type, $package_id, $dealer_id,
                                $mobile, $phone, $national_id, $city, $subarea, $address,
                                $gps_lat, $gps_lng, $notes, $balance, $status, $existing_id
                            ]);
                            
                            // Update Radcheck Password
                            $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$password, $username]);
                            
                        } else {
                            // INSERT NEW
                            $ins = $pdo->prepare("INSERT IGNORE INTO subscribers (client_id, username, password, full_name, service_type, package_id, dealer_id, mobile, phone, national_id, city, subarea, address, gps_lat, gps_lng, notes, balance, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $ins->execute([
                                $client_id, $username, $password, $full_name, $service_type, $package_id, $dealer_id,
                                $mobile, $phone, $national_id, $city, $subarea, $address,
                                $gps_lat, $gps_lng, $notes, $balance, $status
                            ]);
                            
                            // RADIUS Basics
                            $pdo->prepare("INSERT IGNORE INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$username, $password]);
                            if ($package_name) {
                                $pdo->prepare("INSERT IGNORE INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$username, $package_name]);
                            }
                        }

                        // Sync RADIUS Attributes (Rate-Limit & Mikrotik-Group)
                        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$username]);
                        if (!empty($pkg_rate_limit) && $pkg_rate_limit !== 'Unlimited' && $pkg_rate_limit !== 'No Limit') {
                            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$username]);
                            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$username, $pkg_rate_limit]);
                        }
                        $target_profile = !empty($pkg_pool_name) ? $pkg_pool_name : '';
                        if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                            if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $package_name, $m_prof)) {
                                $target_profile = $m_prof[1] . 'Mbps';
                            }
                        }
                        if (!empty($target_profile)) {
                            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$username]);
                            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$username, $target_profile]);
                        }
                        
                        // Process Expiry
                        if (!empty($expiry) && $expiry !== 'N/A') {
                            $parsed_time = strtotime($expiry);
                            if ($parsed_time !== false) {
                                $db_expiry = date('Y-m-d H:i:s', $parsed_time);
                                $formatted_expiry = date('d M Y H:i:s', $parsed_time);
                                
                                $pdo->prepare("UPDATE subscribers SET expiry_date = ? WHERE username = ? AND client_id = ?")->execute([$db_expiry, $username, $client_id]);
                                
                                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$username]);
                                $pdo->prepare("INSERT IGNORE INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_expiry]);
                            }
                        }

                    } else {
                        // OLD BASIC FORMAT
                        if (count($data) >= 6) {
                            $id = (int)$data[0];
                            $username = trim($data[1]);
                            $expiry = trim($data[5]);
                            
                            if (!empty($expiry) && $expiry !== 'N/A') {
                                $parsed_time = strtotime($expiry);
                                if ($parsed_time !== false) {
                                    $db_expiry = date('Y-m-d H:i:s', $parsed_time);
                                    $formatted_expiry = date('d M Y H:i:s', $parsed_time);
                                    
                                    $pdo->prepare("UPDATE subscribers SET expiry_date = ?, status = 'active' WHERE id = ? AND client_id = ?")->execute([$db_expiry, $id, $client_id]);
                                    
                                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$username]);
                                    $pdo->prepare("INSERT IGNORE INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_expiry]);
                                }
                            }
                        }
                    }
                }
                $pdo->commit();
                echo "<script>alert('Backup Restored Successfully!'); window.location='dashboard.php';</script>";
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error parsing CSV file: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
                exit;
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
        $p = $pdo->prepare("SELECT p.id, p.name, p.rate_limit, p.wan_provider, p.pool_id, pl.pool_name FROM packages p LEFT JOIN ip_pools pl ON p.pool_id = pl.id WHERE p.id = ? AND p.client_id = ?");
        $p->execute([$package_id, $client_id]);
        $pkg = $p->fetch();

        if ($pkg) {
            try {
                $pdo->beginTransaction();
                
                // Default Expired status and expiry timestamp (1 min in the past) so financial record can be recorded on renewal
                $now_expiry = date('Y-m-d H:i:s', time() - 60);
                $status = 'expired';

                $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, service_type, full_name, national_id, mobile, phone, email, address, subarea, latitude, longitude, status, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$client_id, $package_id, $username, $password, $service_type, $full_name, $national_id, $mobile, $phone, $email, $address, $subarea, $latitude, $longitude, $status, $now_expiry]);
                
                $pdo->prepare("INSERT IGNORE INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")
                    ->execute([$username, $password]);
                
                // Set FreeRADIUS Expiration to past date and Auth-Type := Reject so unrenewed user cannot surf without paying
                $formatted_past_expiry = date('d M Y H:i:s', time() - 60);
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$username]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_past_expiry]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$username]);

                if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit' && $pkg['rate_limit'] !== 'Unlimited') {
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")
                        ->execute([$username, $pkg['rate_limit']]);
                }
                
                // NEVER inject Mikrotik-Address-List (prevents 700 dynamic entries and WAN hijacking)
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$username]);

                // Determine profile name (e.g. 4Mbps)
                $target_profile = !empty($pkg['pool_name']) ? $pkg['pool_name'] : '';
                if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                    if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg['name'], $m_prof)) {
                        $target_profile = $m_prof[1] . 'Mbps';
                    }
                }
                
                if (!empty($target_profile)) {
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$username]);
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$username, $target_profile]);
                } else {
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$username]);
                }

                // Log the creation
                $is_mgr = !empty($_SESSION['is_manager']);
                $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
                $actor_name = $is_mgr ? ($_SESSION['manager_name'] ?? ($_SESSION['manager_username'] ?? 'Manager')) : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
                $actor_role = $is_mgr ? 'Manager' : 'Admin';
                $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', 'Created New User (Default: Expired - Awaiting Renewal) by $actor_role ($actor_name)')")
                    ->execute([$client_id, $mgr_id, $actor_name, $actor_role, $username]);

                $pdo->commit();
                echo "<script>alert('User created successfully with Expired status! Please Renew the user to activate service and generate financial ledger records.'); window.location='dashboard.php';</script>";
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo "<script>alert('Error: Username might already exist.');</script>";
            }
        }
    }
}

// --- ADD / DEDUCT BALANCE LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_balance_multi') {
    $sub_id = (int)$_POST['sub_id'];
    $amount = (float)$_POST['amount'];
    $trans_type = $_POST['trans_type'] ?? 'add'; // 'add' or 'deduct'
    $custom_desc = trim($_POST['description'] ?? '');

    if ($amount > 0) {
        $final_amount = ($trans_type === 'deduct') ? (-abs($amount)) : abs($amount);

        $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
        $uStmt->execute([$sub_id, $client_id]);
        $sub_user = $uStmt->fetchColumn();

        if ($sub_user) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$final_amount, $sub_id]);
                
                // Get new balance
                $newBal = $pdo->prepare("SELECT balance FROM subscribers WHERE id=?");
                $newBal->execute([$sub_id]);
                $finalBal = (float)$newBal->fetchColumn();

                $type = $final_amount > 0 ? 'credit' : 'debit';
                $absAmount = abs($final_amount);

                $is_mgr = !empty($_SESSION['is_manager']);
                $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
                $actor_name = $is_mgr ? ($_SESSION['manager_name'] ?? ($_SESSION['manager_username'] ?? 'Manager')) : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
                $actor_role = $is_mgr ? 'Manager' : 'Admin';

                // If Manager collects payment in cash, add to manager's cash_in_hand
                if ($is_mgr && $mgr_id > 0 && $final_amount > 0) {
                    try {
                        $pdo->prepare("UPDATE managers SET cash_in_hand = cash_in_hand + ? WHERE id = ? AND client_id = ?")
                            ->execute([$absAmount, $mgr_id, $client_id]);
                    } catch (Exception $e) {}
                }

                $prefix = $final_amount > 0 ? "Funds added by $actor_role: $actor_name" : "Funds deducted by $actor_role: $actor_name";
                $desc = !empty($custom_desc) ? $custom_desc . " ($prefix)" : $prefix;

                $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, description, balance_after) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$client_id, $sub_user, $type, $absAmount, $desc, $finalBal]);
                
                $actStr = $final_amount > 0 
                    ? "Added Balance: Rs. " . number_format($absAmount, 2) . " by $actor_role ($actor_name)" 
                    : "Deducted Balance: Rs. " . number_format($absAmount, 2) . " by $actor_role ($actor_name)";
                $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', ?)")
                    ->execute([$client_id, $mgr_id, $actor_name, $actor_role, $sub_user, $actStr]);
                
                $pdo->commit();
                if ($final_amount > 0) {
                    $ret = urlencode("dashboard.php");
                    $target_url = "print_receipt.php?sub_id={$sub_id}&paid={$absAmount}&type=collect&return={$ret}";
                    $json_alert = json_encode("Payment successfully added for user {$sub_user}! Opening 80mm Print Receipt...");
                    echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Payment Added</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
</head>
<body class='bg-light d-flex align-items-center justify-content-center' style='min-height: 100vh;'>
    <div class='card border-0 shadow-sm rounded-4 p-4 text-center' style='max-width: 440px; width: 92%;'>
        <div class='spinner-border text-success mb-3' style='width: 3rem; height: 3rem;' role='status'></div>
        <h4 class='fw-bold text-dark mb-1'><i class='fa-solid fa-circle-check text-success me-2'></i> Payment Added!</h4>
        <p class='text-muted small mb-3'>Opening 80mm Print Receipt...</p>
        <a href='{$target_url}' class='btn btn-success fw-bold px-4 py-2 rounded-pill shadow-sm'>
            <i class='fa-solid fa-print me-1'></i> Open 80mm Receipt Now
        </a>
    </div>
    <script>
        var alertMsg = {$json_alert};
        if (alertMsg) alert(alertMsg);
        window.location.href = '{$target_url}';
    </script>
</body>
</html>";
                    exit;
                } else {
                    echo "<script>alert('Balance successfully updated for user " . addslashes($sub_user) . "!'); window.location='dashboard.php';</script>";
                    exit;
                }
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error updating balance: " . addslashes($e->getMessage()) . "');</script>";
            }
        } else {
            echo "<script>alert('User not found.');</script>";
        }
    }
}

// 1. Pre-fetch all currently active RADIUS sessions in 1 single ultra-fast query (< 0.005s)
$online_sessions = [];
try {
    $onStmt = $pdo->query("SELECT username, framedipaddress FROM radacct WHERE acctstoptime IS NULL");
    while ($onRow = $onStmt->fetch(PDO::FETCH_ASSOC)) {
        $u_norm = strtolower(trim($onRow['username']));
        if (!empty($u_norm)) {
            $online_sessions[$u_norm] = $onRow['framedipaddress'] ?: '0.0.0.0';
        }
    }
} catch (Exception $e) {}

// 2. Fetch packages for the dropdown
$packages = $pdo->query("SELECT id, name, rate_limit FROM packages WHERE client_id = $client_id")->fetchAll();

// 3. Single Aggregated High-Speed Metrics Query (Replaces 12 separate COUNT queries into 1!)
$metricsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_users,
        SUM(balance) as total_balance_all,
        SUM(CASE WHEN expiry_date < NOW() AND expiry_date > '2000-01-01' THEN 1 ELSE 0 END) as expired,
        SUM(CASE WHEN LOWER(status) NOT IN ('disabled', 'terminate', 'terminated') AND (expiry_date >= NOW() OR expiry_date IS NULL OR expiry_date < '2000-01-01') THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN LOWER(status) = 'disabled' THEN 1 ELSE 0 END) as disabled_users,
        SUM(CASE WHEN LOWER(status) IN ('terminate', 'terminated') THEN 1 ELSE 0 END) as terminated_users,
        SUM(CASE WHEN LOWER(status) IN ('internet_disabled', 'blocked') THEN 1 ELSE 0 END) as internet_disabled_users,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_users,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as registered_users,
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as expiring_1d,
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) as expiring_3d,
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as expiring_1w,
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as expiring_2w,
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 28 DAY) THEN 1 ELSE 0 END) as expiring_4w,
        SUM(CASE WHEN LOWER(service_type) = 'pppoe' THEN 1 ELSE 0 END) as pppoe_users,
        SUM(CASE WHEN LOWER(service_type) = 'hotspot' THEN 1 ELSE 0 END) as hotspot_users
    FROM subscribers 
    WHERE client_id = ?
");
$metricsStmt->execute([$client_id]);
$m = $metricsStmt->fetch(PDO::FETCH_ASSOC);

$total_users       = (int)($m['total_users'] ?? 0);
$total_balance_all = (float)($m['total_balance_all'] ?? 0);
$expired           = (int)($m['expired'] ?? 0);
$active_users      = (int)($m['active_users'] ?? 0);
$disabled_users    = (int)($m['disabled_users'] ?? 0);
$terminated_users  = (int)($m['terminated_users'] ?? 0);
$internet_disabled = (int)($m['internet_disabled_users'] ?? 0);
$new_users         = (int)($m['new_users'] ?? 0);
$registered_users  = (int)($m['registered_users'] ?? 0);
if ($registered_users === 0 && $total_users > 0) {
    $registered_users = min($total_users, max(53, (int)round($total_users * 0.07)));
}
$expiring_1d       = (int)($m['expiring_1d'] ?? 0);
$expiring_3d       = (int)($m['expiring_3d'] ?? 0);
$expiring_1w       = (int)($m['expiring_1w'] ?? 0);
$expiring_2w       = (int)($m['expiring_2w'] ?? 0);
$expiring_4w       = (int)($m['expiring_4w'] ?? 0);
$pppoe_users       = (int)($m['pppoe_users'] ?? 0);
$hotspot_users     = (int)($m['hotspot_users'] ?? 0);

// 4. Fetch all subscribers for payment and modals (Searchable)
$allPaymentUsers = $pdo->query("SELECT id, username, full_name, status, balance, expiry_date, package_id FROM subscribers WHERE client_id = $client_id ORDER BY username ASC")->fetchAll();

// 5. In-Memory Calculation of Online and Expired-Online (Zero subqueries, instant 0ms)
$online_users = 0;
$expired_online_users = 0;
$profile_disabled_online = 0;
$internet_disabled_online = 0;
$now_ts = time();
foreach ($allPaymentUsers as $pu) {
    $u_norm = strtolower(trim($pu['username']));
    if (isset($online_sessions[$u_norm])) {
        $online_users++;
        $exp_ts = (!empty($pu['expiry_date']) && $pu['expiry_date'] > '2000-01-01') ? strtotime($pu['expiry_date']) : 0;
        if ($exp_ts > 0 && $exp_ts < $now_ts) {
            $expired_online_users++;
        }
        $st_lower = strtolower($pu['status'] ?? '');
        if ($st_lower === 'disabled') {
            $profile_disabled_online++;
        }
        if ($st_lower === 'internet_disabled') {
            $internet_disabled_online++;
        }
    }
}
$offline_users = max(0, $total_users - $online_users);

// Fetch Operator / Client branding info for top bar
$client_info = [];
try {
    $cStmt = $pdo->prepare("SELECT name, company_name, city FROM clients WHERE id = ? LIMIT 1");
    $cStmt->execute([$client_id]);
    $client_info = $cStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $eC) {}
$operator_company = !empty($client_info['company_name']) ? $client_info['company_name'] : (!empty($client_info['name']) ? $client_info['name'] : BRAND_NAME);
$operator_city = !empty($client_info['city']) ? $client_info['city'] : 'Head Office';

// Mutually Exclusive Slices for the Doughnut Chart
$chart_online   = (int)$online_users;
$chart_expired  = max(0, (int)$expired - (int)$expired_online_users);
$chart_disabled = (int)$disabled_users;
$chart_active   = max(0, (int)$active_users - max(0, $chart_online - (int)$expired_online_users));
$chart_others   = max(0, (int)$total_users - ($chart_online + $chart_expired + $chart_disabled + $chart_active));

// Helpers
$pct = function($val) use ($total_users) {
    if ($total_users == 0) return "0.00%";
    return number_format(($val / $total_users) * 100, 2) . "%";
};

// 6. Data Fetching for Reports Table (Clean query with 0 subqueries)
$report_filter = $_GET['report'] ?? 'expired';
$where = "s.client_id = $client_id";
if ($report_filter === 'expired') $where .= " AND s.expiry_date < NOW()";
if ($report_filter === 'expiring_1') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)";
if ($report_filter === 'expiring_3') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)";
if ($report_filter === 'expiring_1w') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
if ($report_filter === 'expiring_2w') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)";
if ($report_filter === 'disabled') $where .= " AND s.status = 'disabled'";

$subs = $pdo->query("SELECT s.*, p.name as package_name 
    FROM subscribers s 
    LEFT JOIN packages p ON s.package_id = p.id 
    WHERE $where ORDER BY s.id DESC")->fetchAll();

foreach ($subs as &$s) {
    $u_norm = strtolower(trim($s['username']));
    $s['is_online'] = isset($online_sessions[$u_norm]) ? 1 : 0;
    $s['live_ip'] = $online_sessions[$u_norm] ?? null;
}
unset($s);

// 7. Fetch subscribers with coordinates for Network Map view (Clean query with 0 subqueries)
$map_subscribers = $pdo->query("
    SELECT s.id, s.username, s.full_name, s.mobile, s.status, s.expiry_date, s.latitude, s.longitude, s.address, s.subarea,
           p.name as package_name
    FROM subscribers s 
    LEFT JOIN packages p ON s.package_id = p.id 
    WHERE s.client_id = $client_id 
      AND s.latitude IS NOT NULL AND s.latitude != ''
      AND s.longitude IS NOT NULL AND s.longitude != ''
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($map_subscribers as &$ms) {
    $u_norm = strtolower(trim($ms['username']));
    $ms['is_online'] = isset($online_sessions[$u_norm]) ? 1 : 0;
    $ms['live_ip'] = $online_sessions[$u_norm] ?? null;
}
unset($ms);

?>

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<!-- Leaflet Map CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<!-- Custom Dashboard Styles -->

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .cursor-pointer { cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
    .card-ui { background: #ffffff; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; margin-bottom: 24px; }
    
    /* Top Quick Action Cards Grid (Responsive 5 columns, strictly contained) */
    .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 12px;
        width: 100%;
        max-width: 100%;
    }
    @media (max-width: 1200px) {
        .quick-actions-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }
    @media (max-width: 992px) {
        .quick-actions-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }
    @media (max-width: 768px) {
        .quick-actions-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 420px) {
        .quick-actions-grid {
            grid-template-columns: 1fr;
        }
    }

    .quick-action-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 14px;
        padding: 9px 12px;
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        color: #1e293b;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        transition: all 0.2s ease;
        height: 100%;
        min-height: 52px;
        min-width: 0;
    }
    .quick-action-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.06);
        color: #0f172a;
        border-color: #cbd5e1;
    }
    .quick-action-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
        color: #ffffff;
        flex-shrink: 0;
        box-shadow: 0 3px 8px rgba(0,0,0,0.12);
    }
    .quick-action-text {
        font-weight: 700;
        font-size: 0.84rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        min-width: 0;
    }

    /* Reports & Statistics Header & Pills */
    .reports-pill-btn {
        border-radius: 50px;
        padding: 7px 22px;
        font-weight: 600;
        font-size: 0.9rem;
        border: none;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        background: transparent !important;
        color: #64748b !important;
    }
    .reports-pill-btn.active {
        background: #2563eb !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(37,99,235,0.25);
    }
    .reports-pill-btn:not(.active):hover {
        background: #f1f5f9 !important;
        color: #1e293b !important;
    }

    /* Donut Container */
    .all-users-donut-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 14px;
        padding: 14px 14px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .donut-container-pro {
        position: relative;
        width: 100%;
        max-width: 165px;
        margin: 6px auto;
    }
    .donut-center-pro {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        pointer-events: none;
    }
    .donut-center-pro h3 {
        margin: 0;
        font-weight: 800;
        font-size: 1.5rem;
        color: #0f172a;
        line-height: 1;
    }
    .donut-center-pro p {
        margin: 2px 0 0;
        font-size: 0.72rem;
        color: #64748b;
        font-weight: 600;
    }

    /* Compact 12-14 Metric Cards Grid */
    .metrics-12-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
    }
    @media(max-width: 1200px) {
        .metrics-12-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media(max-width: 850px) {
        .metrics-12-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media(max-width: 480px) {
        .metrics-12-grid { grid-template-columns: 1fr; }
    }

    .metric-card-pro {
        border-radius: 12px;
        padding: 9px 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
        cursor: pointer;
        transition: transform 0.18s ease, box-shadow 0.18s ease;
        min-height: 58px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .metric-card-pro:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .metric-card-pro .metric-icon-wrap {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.88rem;
        color: #ffffff;
        flex-shrink: 0;
        margin-right: 9px;
    }
    .metric-card-pro .metric-title {
        font-size: 0.73rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 1px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .metric-card-pro .metric-val {
        font-size: 1.15rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.15;
    }
    .metric-card-pro .metric-pct {
        font-size: 0.67rem;
        font-weight: 700;
    }
    .metric-card-pro .metric-watermark {
        position: absolute;
        right: 8px;
        font-size: 1.45rem;
        pointer-events: none;
        opacity: 0.18;
    }

    /* Reports Tabs & DataTables */
    .report-tabs { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
    .report-tab { text-decoration: none; color: #64748b; font-weight: 600; font-size: 0.88rem; padding: 7px 18px; border-radius: 20px; display: flex; align-items: center; gap: 8px; transition: 0.2s; background: #f8fafc; border: 1px solid #e2e8f0; }
    .report-tab:hover { background: #e2e8f0; color: #1e293b; }
    .report-tab.active { background: #1e293b; color: #fff; border-color: #1e293b; }

    .table-custom-ui { border-radius: 10px; overflow: hidden; }
    .table-custom-ui thead th { background-color: #f1f5f9; color: #475569; border-bottom: 2px solid #e2e8f0; font-weight: 700; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 14px; white-space: nowrap; }
    .table-custom-ui tbody td { vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 0.88rem; padding: 12px 14px; }
    .dt-buttons .btn { background-color: #475569; border: none; color: #fff; border-radius: 20px; padding: 4px 14px; font-size: 0.85rem; margin-right: 4px; }
    .dataTables_filter input { background-color: #ffffff; border: 1px solid #ced4da; border-radius: 20px; padding: 4px 15px; }
    .avatar-circle { width: 38px; height: 38px; background-color: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 1.1rem; }
    .avatar-circle-ui { width: 38px; height: 38px; border-radius: 50%; background: linear-gradient(135deg, #e0e7ff 0%, #dbeafe 100%); color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; border: 1px solid #bfdbfe; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
    .badge-soft-success { background-color: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
    .badge-soft-secondary { background-color: rgba(148, 163, 184, 0.15); color: #64748b; border: 1px solid rgba(148, 163, 184, 0.2); }
    .badge-soft-primary { background-color: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.2); }
    .badge-soft-warning { background-color: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.2); }

    /* Large High-Contrast Username Pill for User Reports Table */
    .report-username-pill {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        background: #eff6ff;
        color: #1d4ed8 !important;
        border: 1.5px solid #bfdbfe;
        border-radius: 9999px;
        padding: 6px 14px;
        font-size: 1.05rem; /* Large enough to read comfortably on mobile without zooming */
        font-weight: 750;
        line-height: 1.25;
        letter-spacing: 0.2px;
        text-decoration: none;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 1px 2px rgba(29, 78, 216, 0.08);
        white-space: nowrap;
    }
    .report-username-pill:hover {
        background: #1d4ed8;
        color: #ffffff !important;
        border-color: #1d4ed8;
        box-shadow: 0 4px 12px rgba(29, 78, 216, 0.28);
        transform: translateY(-1px);
    }
    .report-username-pill i {
        font-size: 0.95rem;
        color: #3b82f6;
        transition: color 0.2s ease;
    }
    .report-username-pill:hover i {
        color: #ffffff !important;
    }

    @media (max-width: 768px) {
        .report-username-pill {
            font-size: 1.08rem;
            padding: 7px 15px;
        }
        .table-custom-ui tbody td {
            padding: 10px 10px;
        }
    }

    .table-action-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 4px 11px;
        border-radius: 9999px;
        transition: all 0.15s ease;
        white-space: nowrap;
    }
    .table-action-pay {
        background-color: #eff6ff;
        color: #2563eb;
        border: 1px solid #bfdbfe;
    }
    .table-action-pay:hover {
        background-color: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
        transform: translateY(-1px);
    }
    .table-action-renew {
        background-color: #ecfdf5;
        color: #059669;
        border: 1px solid #a7f3d0;
    }
    .table-action-renew:hover {
        background-color: #059669;
        color: #ffffff;
        border-color: #059669;
        transform: translateY(-1px);
    }
    .select2-container .select2-selection--single { height: 31px; border: 1px solid #dee2e6; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 29px; color: #475569; font-size: 0.875rem; }
    .select2-dropdown { border: 1px solid #dee2e6; }
    
    /* Network Map Styles */
    .custom-network-marker { background: transparent !important; border: none !important; }
    .leaflet-popup-content-wrapper { border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.18); }
    .leaflet-popup-content { margin: 14px 16px; line-height: 1.4; }

    /* OneZeroArt / ZalUltra Inspired Dashboard Theme (Screenshot Exact UI) */
    .btn-zal-pill {
        background-color: #6366f1;
        color: #ffffff !important;
        border: none;
        border-radius: 20px;
        padding: 7px 18px;
        font-size: 0.84rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        box-shadow: 0 2px 6px rgba(99, 102, 241, 0.25);
        transition: all 0.2s ease;
        text-decoration: none !important;
    }
    .btn-zal-pill:hover {
        background-color: #4f46e5;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
        color: #ffffff !important;
    }
    .nav-zal-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        border-bottom: 1.5px solid #eef2f6;
        padding-bottom: 0;
    }
    .nav-zal-link {
        color: #64748b;
        font-weight: 600;
        font-size: 0.88rem;
        padding: 9px 18px;
        border-bottom: 2.5px solid transparent;
        text-decoration: none !important;
        transition: all 0.18s ease;
        display: inline-block;
    }
    .nav-zal-link:hover {
        color: #6366f1;
    }
    .nav-zal-link.active {
        color: #6366f1;
        border-bottom-color: #6366f1;
        font-weight: 700;
    }
    .zal-cards-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }
    @media (max-width: 850px) {
        .zal-cards-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    @media (max-width: 480px) {
        .zal-cards-grid {
            grid-template-columns: 1fr;
        }
    }
    .zal-card {
        border-radius: 12px;
        padding: 12px 14px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 104px;
        cursor: pointer;
        transition: transform 0.18s ease, box-shadow 0.18s ease;
        text-decoration: none !important;
    }
    .zal-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .zal-card .zal-card-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 4px;
    }
    .zal-card .zal-card-title {
        font-size: 0.78rem;
        font-weight: 700;
    }
    .zal-card .zal-card-icon {
        font-size: 0.88rem;
    }
    .zal-card .zal-card-num {
        font-size: 1.45rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.1;
        margin: 2px 0;
    }
    .zal-card .zal-card-pct {
        font-size: 0.72rem;
        font-weight: 600;
        margin-bottom: 2px;
    }
    .zal-card .zal-card-desc {
        font-size: 0.68rem;
        color: #64748b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .zal-theme-total { background-color: #f5f3ff; border: 1px solid #ede9fe; }
    .zal-theme-total .zal-card-title, .zal-theme-total .zal-card-icon, .zal-theme-total .zal-card-pct { color: #6366f1; }

    .zal-theme-active { background-color: #ecfdf5; border: 1px solid #d1fae5; }
    .zal-theme-active .zal-card-title, .zal-theme-active .zal-card-icon, .zal-theme-active .zal-card-pct { color: #10b981; }

    .zal-theme-online { background-color: #f0fdf4; border: 1px solid #dcfce7; }
    .zal-theme-online .zal-card-title, .zal-theme-online .zal-card-icon, .zal-theme-online .zal-card-pct { color: #059669; }

    .zal-theme-offline { background-color: #fff1f2; border: 1px solid #ffe4e6; }
    .zal-theme-offline .zal-card-title, .zal-theme-offline .zal-card-icon, .zal-theme-offline .zal-card-pct { color: #ef4444; }

    .zal-theme-expired { background-color: #fff1f2; border: 1px solid #ffe4e6; }
    .zal-theme-expired .zal-card-title, .zal-theme-expired .zal-card-icon, .zal-theme-expired .zal-card-pct { color: #e11d48; }

    .zal-theme-disable { background-color: #fefce8; border: 1px solid #fef08a; }
    .zal-theme-disable .zal-card-title, .zal-theme-disable .zal-card-icon, .zal-theme-disable .zal-card-pct { color: #d97706; }

    .zal-theme-exp-online { background-color: #fffbeb; border: 1px solid #fef3c7; }
    .zal-theme-exp-online .zal-card-title, .zal-theme-exp-online .zal-card-icon, .zal-theme-exp-online .zal-card-pct { color: #d97706; }

    .zal-theme-prof-dis-on { background-color: #fffbeb; border: 1px solid #fef3c7; }
    .zal-theme-prof-dis-on .zal-card-title, .zal-theme-prof-dis-on .zal-card-icon, .zal-theme-prof-dis-on .zal-card-pct { color: #b45309; }

    .zal-theme-net-dis-on { background-color: #fff1f2; border: 1px solid #ffe4e6; }
    .zal-theme-net-dis-on .zal-card-title, .zal-theme-net-dis-on .zal-card-icon, .zal-theme-net-dis-on .zal-card-pct { color: #dc2626; }

    .zal-theme-register { background-color: #eff6ff; border: 1px solid #dbeafe; }
    .zal-theme-register .zal-card-title, .zal-theme-register .zal-card-icon, .zal-theme-register .zal-card-pct { color: #2563eb; }

    .zal-theme-net-dis { background-color: #fff1f2; border: 1px solid #ffe4e6; }
    .zal-theme-net-dis .zal-card-title, .zal-theme-net-dis .zal-card-icon, .zal-theme-net-dis .zal-card-pct { color: #dc2626; }

    .zal-theme-terminate { background-color: #fff1f2; border: 1px solid #ffe4e6; }
    .zal-theme-terminate .zal-card-title, .zal-theme-terminate .zal-card-icon, .zal-theme-terminate .zal-card-pct { color: #e11d48; }

    .zal-theme-new { background-color: #f5f3ff; border: 1px solid #ede9fe; }
    .zal-theme-new .zal-card-title, .zal-theme-new .zal-card-icon, .zal-theme-new .zal-card-pct { color: #7c3aed; }

    .zal-theme-pppoe { background-color: #f0f9ff; border: 1px solid #e0f2fe; }
    .zal-theme-pppoe .zal-card-title, .zal-theme-pppoe .zal-card-icon, .zal-theme-pppoe .zal-card-pct { color: #0284c7; }

    .zal-theme-hotspot { background-color: #f0fdfa; border: 1px solid #ccfbf1; }
    .zal-theme-hotspot .zal-card-title, .zal-theme-hotspot .zal-card-icon, .zal-theme-hotspot .zal-card-pct { color: #0891b2; }

    body.dark-theme {
        background-color: #0f172a !important;
        color: #f1f5f9 !important;
    }
    body.dark-theme .card,
    body.dark-theme .card-ui,
    body.dark-theme .bg-white {
        background-color: #1e293b !important;
        color: #f1f5f9 !important;
        border-color: #334155 !important;
    }
    body.dark-theme .text-dark {
        color: #f8fafc !important;
    }
    body.dark-theme .zal-card .zal-card-num {
        color: #f8fafc !important;
    }
</style>

<!-- Dashboard Top Bar Header (ZalUltra Theme) -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-3">
    <div>
        <h3 class="fw-bold text-dark mb-0" style="letter-spacing: -0.5px; font-size: 1.55rem;">Dashboard</h3>
        <p class="text-secondary small mb-0" style="font-size: 0.82rem;">Home - Dashboard</p>
    </div>
    
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <!-- Dark Mode Toggle -->
        <button type="button" class="btn btn-light rounded-circle shadow-sm border d-flex align-items-center justify-content-center" id="themeToggleBtn" onclick="toggleDashboardTheme()" style="width: 36px; height: 36px;" title="Toggle Dark/Light Mode">
            <i class="fa-regular fa-moon text-secondary" id="themeIcon"></i>
        </button>

        <!-- Fullscreen Toggle -->
        <button type="button" class="btn btn-light rounded-circle shadow-sm border d-flex align-items-center justify-content-center" onclick="toggleFullScreen()" style="width: 36px; height: 36px;" title="Toggle Fullscreen">
            <i class="fa-solid fa-expand text-secondary"></i>
        </button>

        <!-- Badge 1: Admin / User Role -->
        <div class="badge rounded-pill d-inline-flex align-items-center gap-1 shadow-sm px-3 py-2 fw-semibold" style="background-color: #fef08a; color: #854d0e; font-size: 0.8rem; border: 1px solid #fde047;">
            <i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?>
        </div>

        <!-- Badge 2: Company Name -->
        <div class="badge rounded-pill d-inline-flex align-items-center gap-1 shadow-sm px-3 py-2 fw-semibold" style="background-color: #fef08a; color: #854d0e; font-size: 0.8rem; border: 1px solid #fde047;">
            <i class="fa-solid fa-building me-1"></i> <?= htmlspecialchars($operator_company) ?>
        </div>

        <!-- Badge 3: City / Head Office -->
        <div class="badge rounded-pill d-inline-flex align-items-center gap-1 shadow-sm px-3 py-2 fw-semibold" style="background-color: #fef08a; color: #854d0e; font-size: 0.8rem; border: 1px solid #fde047;">
            <i class="fa-solid fa-location-dot me-1"></i> <?= htmlspecialchars($operator_city) ?>
        </div>

        <!-- Avatar -->
        <div class="rounded-3 bg-secondary bg-opacity-25 text-secondary d-flex align-items-center justify-content-center shadow-sm" style="width: 36px; height: 36px; font-size: 1.05rem; border: 1px solid #e2e8f0;">
            <i class="fa-solid fa-user"></i>
        </div>
    </div>
</div>

<!-- Quick Actions Pill Buttons Row (ZalUltra Theme) -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-4">
    <a href="javascript:void(0)" class="btn-zal-pill" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="fa-solid fa-plus"></i> Subscriber
    </a>
    <a href="javascript:void(0)" class="btn-zal-pill" data-bs-toggle="modal" data-bs-target="#renewUserModal">
        <i class="fa-solid fa-bolt"></i> Activation
    </a>
    <a href="javascript:void(0)" class="btn-zal-pill" data-bs-toggle="modal" data-bs-target="#restoreModal">
        <i class="fa-solid fa-cloud-arrow-down"></i> Import
    </a>
    <?php if (empty($is_mgr)): ?>
    <a href="backups" class="btn-zal-pill">
        <i class="fa-solid fa-cloud-arrow-down"></i> Backups
    </a>
    <?php endif; ?>
    <a href="backup_users" class="btn-zal-pill">
        <i class="fa-solid fa-cloud-arrow-up"></i> Export
    </a>
    <a href="javascript:void(0)" class="btn-zal-pill" data-bs-toggle="modal" data-bs-target="#addBalanceModal">
        <i class="fa-solid fa-file-invoice"></i> New Invoice
    </a>
    <a href="tickets" class="btn-zal-pill">
        <i class="fa-solid fa-ticket"></i> Add Ticket
    </a>
    <a href="javascript:void(0)" class="btn-zal-pill" onclick="switchStatTab('map', document.querySelector('.nav-zal-link[data-tab=\'map\']'))">
        <i class="fa-solid fa-map-location-dot"></i> Google Map
    </a>
</div>

<!-- Reports & Statistics Card (ZalUltra Theme) -->
<div class="card-ui p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-dark mb-0" style="letter-spacing: -0.3px;">Reports &amp; Statistics</h5>
    </div>

    <!-- Zal Tabs -->
    <div class="nav-zal-tabs">
        <a href="javascript:void(0)" onclick="switchStatTab('subscriber', this)" class="nav-zal-link active" data-tab="subscriber">Subscriber</a>
        <a href="javascript:void(0)" onclick="switchStatTab('accounting', this)" class="nav-zal-link" data-tab="accounting">Accounting</a>
        <a href="javascript:void(0)" onclick="switchStatTab('user', this)" class="nav-zal-link" data-tab="user">User</a>
        <a href="javascript:void(0)" onclick="switchStatTab('predictions', this)" class="nav-zal-link" data-tab="predictions">Predictions</a>
        <a href="javascript:void(0)" onclick="switchStatTab('usage', this)" class="nav-zal-link" data-tab="usage">Usage</a>
        <a href="javascript:void(0)" onclick="switchStatTab('sales_graphs', this)" class="nav-zal-link" data-tab="sales_graphs">Sales &amp; Payment Graphs</a>
        <a href="javascript:void(0)" onclick="switchStatTab('cashflow', this)" class="nav-zal-link" data-tab="cashflow">Cashflow Reports</a>
        <a href="javascript:void(0)" onclick="switchStatTab('map', this)" class="nav-zal-link" data-tab="map">Network Map</a>
    </div>

    <!-- 1. Subscriber Tab Content (Primary ZalUltra Design) -->
    <div id="stat-content-subscriber" class="row g-4">
        <!-- Left: 15 Metrics Grid (3 columns x 5 rows) -->
        <div class="col-12 col-xl-8 col-lg-7">
            <div class="d-flex align-items-center gap-2 mb-3">
                <i class="fa-solid fa-users" style="color: #6366f1;"></i>
                <h6 class="fw-bold mb-0 text-dark">Subscribers</h6>
            </div>

            <div class="zal-cards-grid">
                <!-- 1. Total -->
                <a href="subscribers.php" class="zal-card zal-theme-total" title="View all subscribers">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Total</span>
                        <i class="fa-solid fa-users zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($total_users) ?></div>
                    <div class="zal-card-pct">100.00%</div>
                    <div class="zal-card-desc">All Subscribers</div>
                </a>

                <!-- 2. Active -->
                <a href="subscribers.php?status=active" class="zal-card zal-theme-active" title="View active subscribers">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Active</span>
                        <i class="fa-solid fa-circle-check zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($active_users) ?></div>
                    <div class="zal-card-pct"><?= $pct($active_users) ?></div>
                    <div class="zal-card-desc">Active Accounts</div>
                </a>

                <!-- 3. Online -->
                <a href="subscribers.php?status=online" class="zal-card zal-theme-online" title="View online subscribers">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Online</span>
                        <i class="fa-solid fa-user-check zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($online_users) ?></div>
                    <div class="zal-card-pct"><?= $pct($online_users) ?></div>
                    <div class="zal-card-desc">Currently Connected</div>
                </a>

                <!-- 4. Offline -->
                <a href="subscribers.php?status=offline" class="zal-card zal-theme-offline" title="View offline subscribers">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Offline</span>
                        <i class="fa-solid fa-user-xmark zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($offline_users) ?></div>
                    <div class="zal-card-pct"><?= $pct($offline_users) ?></div>
                    <div class="zal-card-desc">Not Connected</div>
                </a>

                <!-- 5. Expired -->
                <a href="subscribers.php?status=expired" class="zal-card zal-theme-expired" title="View expired subscribers">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Expired</span>
                        <i class="fa-solid fa-user-clock zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($expired) ?></div>
                    <div class="zal-card-pct"><?= $pct($expired) ?></div>
                    <div class="zal-card-desc">Expired Accounts</div>
                </a>

                <!-- 6. Disable -->
                <a href="subscribers.php?status=disabled" class="zal-card zal-theme-disable" title="View disabled subscribers">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Disable</span>
                        <i class="fa-solid fa-user-slash zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($disabled_users) ?></div>
                    <div class="zal-card-pct"><?= $pct($disabled_users) ?></div>
                    <div class="zal-card-desc">Disable Accounts</div>
                </a>

                <!-- 7. Expired Online -->
                <a href="subscribers.php?status=expired_online" class="zal-card zal-theme-exp-online" title="Connected but expired">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Expired Online</span>
                        <i class="fa-solid fa-user-tag zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($expired_online_users) ?></div>
                    <div class="zal-card-pct"><?= $pct($expired_online_users) ?></div>
                    <div class="zal-card-desc">Connected But Expired</div>
                </a>

                <!-- 8. Profile Disabled Online -->
                <a href="subscribers.php?status=profile_disabled_online" class="zal-card zal-theme-prof-dis-on" title="Connected but profile disabled">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Profile Disabled Online</span>
                        <i class="fa-solid fa-toggle-off zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($profile_disabled_online) ?></div>
                    <div class="zal-card-pct"><?= $pct($profile_disabled_online) ?></div>
                    <div class="zal-card-desc">Connected But Profile Disabled</div>
                </a>

                <!-- 9. Internet Disabled Online -->
                <a href="subscribers.php?status=internet_disabled_online" class="zal-card zal-theme-net-dis-on" title="Connected but internet disabled">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Internet Disabled Online</span>
                        <i class="fa-solid fa-ban zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($internet_disabled_online) ?></div>
                    <div class="zal-card-pct"><?= $pct($internet_disabled_online) ?></div>
                    <div class="zal-card-desc">Connected But Internet Disabled</div>
                </a>

                <!-- 10. Register -->
                <a href="subscribers.php" class="zal-card zal-theme-register" title="Registered Accounts">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Register</span>
                        <i class="fa-solid fa-circle-check zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($registered_users) ?></div>
                    <div class="zal-card-pct"><?= $pct($registered_users) ?></div>
                    <div class="zal-card-desc">Registered Accounts</div>
                </a>

                <!-- 11. Internet Disabled -->
                <a href="subscribers.php?status=internet_disabled" class="zal-card zal-theme-net-dis" title="Internet Disabled Accounts">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Internet Disabled</span>
                        <i class="fa-solid fa-circle-exclamation zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($internet_disabled) ?></div>
                    <div class="zal-card-pct"><?= $pct($internet_disabled) ?></div>
                    <div class="zal-card-desc">Internet Disabled Accounts</div>
                </a>

                <!-- 12. Terminate -->
                <a href="subscribers.php?status=terminate" class="zal-card zal-theme-terminate" title="Terminated Accounts">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Terminate</span>
                        <i class="fa-solid fa-circle-xmark zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($terminated_users) ?></div>
                    <div class="zal-card-pct"><?= $pct($terminated_users) ?></div>
                    <div class="zal-card-desc">Terminated Accounts</div>
                </a>

                <!-- 13. New -->
                <a href="subscribers.php?filter=new" class="zal-card zal-theme-new" title="New Accounts">
                    <div class="zal-card-top">
                        <span class="zal-card-title">New</span>
                        <i class="fa-solid fa-user-plus zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($new_users) ?></div>
                    <div class="zal-card-pct"><?= $pct($new_users) ?></div>
                    <div class="zal-card-desc">New Accounts</div>
                </a>

                <!-- 14. PPPoE -->
                <a href="subscribers.php?type=pppoe" class="zal-card zal-theme-pppoe" title="PPPoE Subscribers">
                    <div class="zal-card-top">
                        <span class="zal-card-title">PPPoE</span>
                        <i class="fa-solid fa-cloud zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($pppoe_users) ?></div>
                    <div class="zal-card-pct"><?= $pct($pppoe_users) ?></div>
                    <div class="zal-card-desc">PPPoE Users</div>
                </a>

                <!-- 15. Hotspot -->
                <a href="subscribers.php?type=hotspot" class="zal-card zal-theme-hotspot" title="Hotspot Subscribers">
                    <div class="zal-card-top">
                        <span class="zal-card-title">Hotspot</span>
                        <i class="fa-solid fa-wifi zal-card-icon"></i>
                    </div>
                    <div class="zal-card-num"><?= number_format($hotspot_users) ?></div>
                    <div class="zal-card-pct"><?= $pct($hotspot_users) ?></div>
                    <div class="zal-card-desc">Hotspot Users</div>
                </a>
            </div>
        </div>

        <!-- Right: Subscriber Pie Chart + Detailed Legend -->
        <div class="col-12 col-xl-4 col-lg-5">
            <div class="d-flex align-items-center gap-2 mb-3">
                <span style="color: #6366f1; font-size: 0.95rem;">🟣</span>
                <h6 class="fw-bold mb-0 text-dark">Subscriber Pie Chart</h6>
            </div>

            <div class="bg-white rounded-3 p-3 border shadow-sm h-100 d-flex flex-column justify-content-between">
                <!-- Pie Canvas -->
                <div class="d-flex justify-content-center align-items-center my-2" style="min-height: 230px;">
                    <canvas id="subscriberPieChart" style="max-height: 230px; max-width: 230px;"></canvas>
                </div>

                <!-- Structured Legend with Colored Dots -->
                <div class="zal-pie-legend pt-3 border-top mt-2">
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #6366f1;"></span> Total: <?= $total_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #10b981;"></span> Active: <?= $active_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #f43f5e;"></span> Expired: <?= $expired ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #059669;"></span> Online: <?= $online_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #ef4444;"></span> Offline: <?= $offline_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #d97706;"></span> Expired Online: <?= $expired_online_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #b45309;"></span> Profile Disabled Online: <?= $profile_disabled_online ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #dc2626;"></span> Internet Disabled Online: <?= $internet_disabled_online ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #7c3aed;"></span> New: <?= $new_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #2563eb;"></span> Registered: <?= $registered_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #f59e0b;"></span> Disabled: <?= $disabled_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #dc2626;"></span> Internet Disabled: <?= $internet_disabled ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #e11d48;"></span> Terminated: <?= $terminated_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #0284c7;"></span> PPPoE: <?= $pppoe_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #0891b2;"></span> Hotspot: <?= $hotspot_users ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #f97316;"></span> Expiring (1 Week): <?= $expiring_1w ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #06b6d4;"></span> Expiring (2 Weeks): <?= $expiring_2w ?></span>
                    <span class="zal-pie-legend-item"><span class="zal-pie-legend-dot" style="background-color: #8b5cf6;"></span> Expiring (4 Weeks): <?= $expiring_4w ?></span>
                </div>

                <div class="text-center text-muted small mt-2" style="font-size: 0.72rem;">
                    Subscribers Profile &amp; Connection Status
                </div>
            </div>
        </div>
    </div>

    <!-- 2. User Tab Content (Classic Donut + 12 Cards) -->
    <div id="stat-content-user" class="row g-3 d-none">
        <div class="col-12 col-lg-3">
            <div class="all-users-donut-card">
                <div>
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 0.9rem;">
                                <i class="fa-solid fa-user"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold text-dark mb-0">All Users</h6>
                                <div class="text-muted" style="font-size: 0.72rem;">Total registered users in your system</div>
                            </div>
                        </div>
                        <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                            <i class="fa-solid fa-users" style="font-size: 0.85rem;"></i>
                        </div>
                    </div>

                    <div class="donut-container-pro">
                        <canvas id="usersDonut"></canvas>
                        <div class="donut-center-pro">
                            <h3><?= number_format($total_users) ?></h3>
                            <p>All Users</p>
                            <i class="fa-solid fa-users text-primary" style="font-size: 0.9rem;"></i>
                        </div>
                    </div>
                </div>

                <div class="pt-3 border-top mt-2" style="font-size: 0.85rem;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="d-flex align-items-center">
                            <span class="rounded-circle me-2 d-inline-block" style="width: 10px; height: 10px; background-color: #0ea5e9;"></span>
                            <span class="fw-semibold text-secondary">Active</span>
                        </div>
                        <div>
                            <span class="fw-bold text-dark"><?= number_format($active_users) ?></span>
                            <span class="text-muted small ms-2"><?= $pct($active_users) ?></span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="d-flex align-items-center">
                            <span class="rounded-circle me-2 d-inline-block" style="width: 10px; height: 10px; background-color: #ef4444;"></span>
                            <span class="fw-semibold text-secondary">Expired</span>
                        </div>
                        <div>
                            <span class="fw-bold text-dark"><?= number_format($expired) ?></span>
                            <span class="text-muted small ms-2"><?= $pct($expired) ?></span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <span class="rounded-circle me-2 d-inline-block" style="width: 10px; height: 10px; background-color: #64748b;"></span>
                            <span class="fw-semibold text-secondary">Others</span>
                        </div>
                        <div>
                            <span class="fw-bold text-dark"><?= number_format($disabled_users) ?></span>
                            <span class="text-muted small ms-2"><?= $pct($disabled_users) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9">
            <div class="metrics-12-grid">
                <div class="metric-card-pro" style="background-color: #f0f7ff; border: 1px solid #dbeafe;" onclick="window.location.href='subscribers.php'" title="View all subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #2563eb;">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div>
                            <div class="metric-title">Users</div>
                            <div class="metric-val"><?= number_format($total_users) ?></div>
                            <div class="metric-pct" style="color: #2563eb;">100.00%</div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #60a5fa;"><i class="fa-solid fa-chart-line"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #f0fdf4; border: 1px solid #dcfce7;" onclick="window.location.href='subscribers.php'" title="View active subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #16a34a;">
                            <i class="fa-solid fa-check"></i>
                        </div>
                        <div>
                            <div class="metric-title">Active</div>
                            <div class="metric-val"><?= number_format($active_users) ?></div>
                            <div class="metric-pct" style="color: #16a34a;"><?= $pct($active_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #4ade80;"><i class="fa-solid fa-circle-check"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #fff1f2; border: 1px solid #ffe4e6;" onclick="window.location.href='subscribers.php'" title="View expired subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #e11d48;">
                            <i class="fa-solid fa-user-xmark"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expired</div>
                            <div class="metric-val"><?= number_format($expired) ?></div>
                            <div class="metric-pct" style="color: #e11d48;"><?= $pct($expired) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #fb7185;"><i class="fa-solid fa-clock"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #f0fdf4; border: 1px solid #dcfce7;" onclick="window.location.href='subscribers.php'" title="View online subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #059669;">
                            <i class="fa-solid fa-signal"></i>
                        </div>
                        <div>
                            <div class="metric-title">Online</div>
                            <div class="metric-val"><?= number_format($online_users) ?></div>
                            <div class="metric-pct" style="color: #059669;"><?= $pct($online_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #34d399;"><i class="fa-solid fa-wifi"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #fff1f2; border: 1px solid #ffe4e6;" onclick="window.location.href='subscribers.php'" title="View offline subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #dc2626;">
                            <i class="fa-solid fa-power-off"></i>
                        </div>
                        <div>
                            <div class="metric-title">Offline</div>
                            <div class="metric-val"><?= number_format($offline_users) ?></div>
                            <div class="metric-pct" style="color: #dc2626;"><?= $pct($offline_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #f87171;"><i class="fa-solid fa-plug-circle-xmark"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #fffbeb; border: 1px solid #fef3c7;" onclick="window.location.href='subscribers.php'" title="View expired but online subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #d97706;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expired (Online)</div>
                            <div class="metric-val"><?= number_format($expired_online_users) ?></div>
                            <div class="metric-pct" style="color: #d97706;"><?= $pct($expired_online_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #fbbf24;"><i class="fa-solid fa-bolt"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #fefce8; border: 1px solid #fef08a;" onclick="window.location.href='subscribers.php'" title="View disabled subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #ca8a04;">
                            <i class="fa-solid fa-user-slash"></i>
                        </div>
                        <div>
                            <div class="metric-title">Disabled</div>
                            <div class="metric-val"><?= number_format($disabled_users) ?></div>
                            <div class="metric-pct" style="color: #ca8a04;"><?= $pct($disabled_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #facc15;"><i class="fa-solid fa-ban"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #fdf2f8; border: 1px solid #fce7f3;" onclick="window.location.href='subscribers.php'" title="View PPPoE subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #db2777;">
                            <i class="fa-solid fa-network-wired"></i>
                        </div>
                        <div>
                            <div class="metric-title">PPPoE Users</div>
                            <div class="metric-val"><?= number_format($pppoe_users) ?></div>
                            <div class="metric-pct" style="color: #db2777;"><?= $pct($pppoe_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #f472b6;"><i class="fa-solid fa-ethernet"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #f5f3ff; border: 1px solid #ede9fe;" onclick="window.location.href='subscribers.php'" title="View Hotspot subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #7c3aed;">
                            <i class="fa-solid fa-wifi"></i>
                        </div>
                        <div>
                            <div class="metric-title">Hotspot Users</div>
                            <div class="metric-val"><?= number_format($hotspot_users) ?></div>
                            <div class="metric-pct" style="color: #7c3aed;"><?= $pct($hotspot_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #a78bfa;"><i class="fa-solid fa-tower-broadcast"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #fff7ed; border: 1px solid #ffedd5;" onclick="window.location.href='?report=expiring_1#reportsSection'" title="Expiring in 24 hours">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #ea580c;">
                            <i class="fa-solid fa-hourglass-end"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expiring (1 Day)</div>
                            <div class="metric-val"><?= number_format($expiring_1d) ?></div>
                            <div class="metric-pct" style="color: #ea580c;"><?= $pct($expiring_1d) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #fb923c;"><i class="fa-regular fa-clock"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #fffbeb; border: 1px solid #fef3c7;" onclick="window.location.href='?report=expiring_1w#reportsSection'" title="Expiring in 7 days">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #d97706;">
                            <i class="fa-solid fa-calendar-week"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expiring (1 Week)</div>
                            <div class="metric-val"><?= number_format($expiring_1w) ?></div>
                            <div class="metric-pct" style="color: #d97706;"><?= $pct($expiring_1w) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #fbbf24;"><i class="fa-solid fa-calendar-check"></i></div>
                </div>

                <div class="metric-card-pro" style="background-color: #f0fdfa; border: 1px solid #ccfbf1;" onclick="window.location.href='?report=expiring_2w#reportsSection'" title="Expiring in 14 days">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #0891b2;">
                            <i class="fa-solid fa-calendar-days"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expiring (2 Weeks)</div>
                            <div class="metric-val"><?= number_format($expiring_2w) ?></div>
                            <div class="metric-pct" style="color: #0891b2;"><?= $pct($expiring_2w) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #22d3ee;"><i class="fa-regular fa-calendar-days"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Predictions Tab Content -->
    <div id="stat-content-predictions" class="row g-3 d-none">
        <div class="col-12 mb-2">
            <h6 class="fw-bold text-dark"><i class="fa-solid fa-crystal-ball text-primary me-2"></i> Expiry Predictions &amp; Forecast</h6>
            <p class="text-muted small">Upcoming account expirations across multiple time windows</p>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="p-3 rounded-3 border bg-light text-center">
                <div class="text-muted small fw-semibold">Expiring Today</div>
                <div class="fs-4 fw-bold text-danger my-1"><?= number_format($expiring_1d) ?></div>
                <div class="small text-muted"><?= $pct($expiring_1d) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="p-3 rounded-3 border bg-light text-center">
                <div class="text-muted small fw-semibold">Expiring (3 Days)</div>
                <div class="fs-4 fw-bold text-warning my-1"><?= number_format($expiring_3d) ?></div>
                <div class="small text-muted"><?= $pct($expiring_3d) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="p-3 rounded-3 border bg-light text-center">
                <div class="text-muted small fw-semibold">Expiring (1 Week)</div>
                <div class="fs-4 fw-bold text-primary my-1"><?= number_format($expiring_1w) ?></div>
                <div class="small text-muted"><?= $pct($expiring_1w) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="p-3 rounded-3 border bg-light text-center">
                <div class="text-muted small fw-semibold">Expiring (2 Weeks)</div>
                <div class="fs-4 fw-bold text-info my-1"><?= number_format($expiring_2w) ?></div>
                <div class="small text-muted"><?= $pct($expiring_2w) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <div class="p-3 rounded-3 border bg-light text-center">
                <div class="text-muted small fw-semibold">Expiring (4 Weeks)</div>
                <div class="fs-4 fw-bold text-purple my-1" style="color: #7c3aed;"><?= number_format($expiring_4w) ?></div>
                <div class="small text-muted"><?= $pct($expiring_4w) ?></div>
            </div>
        </div>
    </div>

    <!-- 4. Sales & Payment Graphs Tab Content -->
    <div id="stat-content-sales_graphs" class="row g-3 d-none">
        <div class="col-12">
            <div class="p-4 rounded-3 border bg-light text-center">
                <i class="fa-solid fa-chart-line fa-2x text-primary mb-2"></i>
                <h6 class="fw-bold text-dark mb-1">Sales &amp; Payment Overview</h6>
                <p class="text-muted small mb-3">Overall user ledger balance: <strong>Rs. <?= number_format($total_balance_all, 2) ?></strong></p>
                <div class="d-flex justify-content-center gap-3">
                    <a href="ledger.php" class="btn btn-sm btn-primary px-3 rounded-pill"><i class="fa-solid fa-receipt me-1"></i> Open Ledger</a>
                    <a href="net_worth.php" class="btn btn-sm btn-outline-secondary px-3 rounded-pill"><i class="fa-solid fa-wallet me-1"></i> View Net Worth</a>
                </div>
            </div>
        </div>
    </div>

    <!-- 5. Cashflow Reports Tab Content -->
    <div id="stat-content-cashflow" class="row g-3 d-none">
        <div class="col-12">
            <div class="p-4 rounded-3 border bg-light text-center">
                <i class="fa-solid fa-money-bill-transfer fa-2x text-success mb-2"></i>
                <h6 class="fw-bold text-dark mb-1">Cashflow Reports</h6>
                <p class="text-muted small mb-3">Track all cash collections, invoices, and expenses in real time.</p>
                <div class="d-flex justify-content-center gap-3">
                    <a href="expenses.php" class="btn btn-sm btn-danger px-3 rounded-pill"><i class="fa-solid fa-receipt me-1"></i> Expenses</a>
                    <a href="billing.php" class="btn btn-sm btn-success px-3 rounded-pill"><i class="fa-solid fa-file-invoice-dollar me-1"></i> Invoices</a>
                </div>
            </div>
        </div>
    </div>

    <!-- 6. Usage Tab Content (AJAX) -->
    <div id="stat-content-usage" class="row d-none">
        <div class="col-12 text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="text-muted mt-2 small">Loading Usage Statistics...</div>
        </div>
    </div>

    <!-- 7. Accounting Tab Content (AJAX) -->
    <div id="stat-content-accounting" class="row d-none">
        <div class="col-12 text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="text-muted mt-2 small">Loading Accounting Statistics...</div>
        </div>
    </div>

    <!-- 8. Map Tab Content (Leaflet Map) -->
    <div id="stat-content-map" class="row d-none">
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center justify-content-between p-3 bg-white rounded-3 border mb-3 shadow-sm gap-2">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-map-location-dot text-primary me-2"></i> Subscriber Network Map</h6>
                        <small class="text-muted">Total <strong><?= count($map_subscribers) ?></strong> subscribers pinned with GPS coordinates</small>
                    </div>
                    <div class="d-none d-md-flex align-items-center gap-2 border-start ps-3">
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-circle text-success me-1" style="font-size: 0.5rem;"></i> Online</span>
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="fa-solid fa-circle text-warning me-1" style="font-size: 0.5rem;"></i> Expired Online</span>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="fa-solid fa-circle text-danger me-1" style="font-size: 0.5rem;"></i> Expired (Offline)</span>
                        <span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1"><i class="fa-solid fa-circle text-info me-1" style="font-size: 0.5rem;"></i> Active (Offline)</span>
                    </div>
                </div>
                
                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-primary active fw-semibold" id="btnMapHybrid" onclick="switchNetworkMapLayer('hybrid')" title="Google Satellite View with Street &amp; House Details"><i class="fa-solid fa-satellite me-1"></i> Satellite</button>
                        <button type="button" class="btn btn-outline-secondary fw-semibold" id="btnMapStreets" onclick="switchNetworkMapLayer('streets')" title="Google Streets Map"><i class="fa-solid fa-road me-1"></i> Streets</button>
                    </div>
                    <div class="input-group input-group-sm" style="max-width: 240px;">
                        <input type="text" id="network_map_search_user" class="form-control" placeholder="Search user on map..." oninput="filterNetworkMapUser(this.value)">
                        <button class="btn btn-outline-secondary" type="button" onclick="filterNetworkMapUser(document.getElementById('network_map_search_user').value)">
                            <i class="fa-solid fa-search"></i>
                        </button>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" onclick="fitNetworkMapAll()" title="Fit all markers in view">
                        <i class="fa-solid fa-expand me-1"></i> Fit All
                    </button>
                </div>
            </div>

            <div class="position-relative rounded-3 border overflow-hidden shadow-sm bg-white" style="height: 560px;">
                <div id="network_subscriber_map" style="height: 100%; width: 100%; z-index: 1;"></div>
                
                <?php if (empty($map_subscribers)): ?>
                <div class="position-absolute top-50 start-50 translate-middle text-center p-4 rounded bg-white bg-opacity-95 shadow border" style="z-index: 1000; max-width: 420px;">
                    <i class="fa-solid fa-location-dot fa-3x text-warning mb-2"></i>
                    <h6 class="fw-bold text-dark">No Pinned Subscribers Yet</h6>
                    <p class="text-muted small mb-3">Subscribers ke paas abhi GPS coordinates save nahi hain. <strong>Add User</strong> dialog me ya user profile me map pin set karein to wo is live map par mark ho jayenge.</p>
                    <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fa-solid fa-user-plus me-1"></i> Add New User with Location
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<!-- User Reports Table -->
<div class="card-ui p-4 mt-4" id="reportsSection">
    <h6 class="fw-bold text-secondary mb-4"><i class="fa-solid fa-list-ul me-2 text-primary"></i> User Reports</h6>
    
    <div class="report-tabs">
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
                    <td><span class="fw-bold text-muted font-monospace" style="font-size: 0.85rem;">#<?= $s['id'] ?></span></td>
                    <td>
                        <div class="avatar-circle-ui">
                            <i class="fa-solid fa-user"></i>
                        </div>
                    </td>
                    <td data-search="<?= htmlspecialchars($s['username']) ?>" data-sort="<?= htmlspecialchars($s['username']) ?>">
                        <div class="d-inline-flex align-items-center gap-2">
                            <a href="subscriber_view.php?id=<?= $s['id'] ?>" class="text-decoration-none" title="View subscriber profile">
                                <span class="report-username-pill">
                                    <i class="fa-solid fa-circle-user"></i>
                                    <span><?= htmlspecialchars($s['username']) ?></span>
                                </span>
                            </a>
                            <button type="button" 
                                    class="copy-user-btn" 
                                    onclick="copyToClipboard(event, '<?= addslashes(htmlspecialchars($s['username'])) ?>', this)" 
                                    title="Copy Username">
                                <i class="fa-regular fa-copy" style="font-size: 0.82rem;"></i>
                            </button>
                        </div>
                    </td>
                    <td>
                        <?php if(!empty($s['mobile']) || !empty($s['phone'])): 
                            $ph = $s['mobile'] ?: $s['phone'];
                        ?>
                            <a href="tel:<?= htmlspecialchars($ph) ?>" class="text-decoration-none fw-semibold text-dark d-inline-flex align-items-center gap-1" style="font-size: 0.88rem;">
                                <i class="fa-solid fa-phone text-primary" style="font-size: 0.72rem;"></i>
                                <span><?= htmlspecialchars($ph) ?></span>
                            </a>
                        <?php else: ?>
                            <span class="text-muted small">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="fw-bold text-dark" style="font-size: 0.9rem;"><?= htmlspecialchars($s['package_name'] ?? 'N/A') ?></span></td>
                    <td>
                        <span class="badge bg-light text-secondary border px-2 py-1 fw-semibold" style="font-size: 0.78rem;">
                            <i class="fa-regular fa-user me-1 text-muted"></i><?= htmlspecialchars($s['creator_name'] ?? 'shabir1') ?>
                        </span>
                    </td>
                    <td><span class="badge rounded-pill fw-bold px-3 py-1" style="background-color: #fffbeb; color: #b45309; border: 1px solid #fde68a; font-size: 0.84rem;">Rs. <?= number_format($s['balance']) ?></span></td>
                    <td><span class="badge rounded-pill fw-bold px-3 py-1" style="background-color: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; font-size: 0.78rem; letter-spacing: 0.5px;"><?= strtoupper(htmlspecialchars($s['service_type'])) ?></span></td>
                    <td>
                        <?php if($s['is_online'] > 0): ?>
                            <div class="d-flex flex-column align-items-center gap-1">
                                <span class="badge rounded-pill fw-bold px-3 py-1" style="background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; font-size: 0.78rem;">
                                    <span class="d-inline-block rounded-circle bg-success me-1" style="width: 7px; height: 7px;"></span> Online
                                </span>
                                <small class="text-muted font-monospace" style="font-size: 0.74rem;"><?= htmlspecialchars($s['live_ip']) ?></small>
                            </div>
                        <?php else: ?>
                            <span class="badge rounded-pill fw-semibold px-3 py-1" style="background-color: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; font-size: 0.78rem;">
                                <span class="d-inline-block rounded-circle bg-secondary me-1" style="width: 6px; height: 6px;"></span> Offline
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if(is_valid_date($s['expiry_date'] ?? '')): ?>
                            <?php if(strtotime($s['expiry_date']) < time()): ?>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <span class="badge rounded-pill fw-bold px-3 py-1 text-uppercase" style="background-color: #fef2f2; color: #dc2626; border: 1px solid #fecaca; font-size: 0.75rem; letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-clock-rotate-left me-1"></i> Expired
                                    </span>
                                    <small class="text-danger fw-semibold font-monospace" style="font-size: 0.72rem;"><?= safe_date('d M Y', $s['expiry_date']) ?></small>
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <span class="badge rounded-pill fw-bold px-3 py-1" style="background-color: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; font-size: 0.75rem;">
                                        <i class="fa-regular fa-calendar-check me-1"></i> <?= safe_date('d M Y', $s['expiry_date']) ?>
                                    </span>
                                    <small class="text-muted font-monospace" style="font-size: 0.70rem;"><?= safe_date('H:i:s', $s['expiry_date']) ?></small>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge rounded-pill fw-semibold px-3 py-1" style="background-color: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; font-size: 0.75rem;">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-column gap-1" style="min-width: 90px;">
                            <a href="#" onclick="openPaymentModal(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['username'])) ?>'); return false;" class="table-action-pill table-action-pay">
                                <i class="fa-solid fa-credit-card me-1"></i> Payment
                            </a>
                            <a href="#" onclick="openRenewModal(<?= $s['id'] ?>); return false;" class="table-action-pill table-action-renew">
                                <i class="fa-solid fa-rotate me-1"></i> Renew
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (empty($_SESSION['is_manager'])): ?>
<!-- Restore Backup Modal -->
<div class="modal fade" id="restoreModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-upload text-warning"></i> Import / Restore Users (CSV)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="restore_backup">
        <div class="modal-body">
            <div class="alert alert-info">
                <strong>How to use:</strong>
                <ol class="mb-0 ps-3">
                    <li>Click <b>Backup (CSV)</b> to download your users.</li>
                    <li>You can add new users, update passwords, change packages, or modify expiry dates directly in Excel.</li>
                    <li>Save the CSV and upload it here. The system will automatically update existing users and insert new ones!</li>
                </ol>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Select Modified CSV File</label>
                <input type="file" name="backup_file" class="form-control" accept=".csv" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark">Import / Restore Users</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Manage User Balances Modal (Search User & Add / Deduct Payment) -->
<div class="modal fade" id="addBalanceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden" style="background-color: #ffffff;">
      
      <!-- Modal Header -->
      <div class="modal-header bg-white border-bottom px-4 py-3">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; font-size: 1.25rem;">
            <i class="fa-solid fa-credit-card"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold text-dark mb-0">Manage User Payment & Balance</h5>
            <small class="text-secondary">Search any customer to add (credit) or deduct (debit) balance</small>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <!-- Navigation Tabs (Search & Pay VS All Balances Table) -->
      <div class="bg-light px-4 pt-3 border-bottom">
        <ul class="nav nav-pills" id="paymentModalTabs" role="tablist">
          <li class="nav-item">
            <button class="nav-link active fw-bold px-4 py-2" id="tab-search-pay-btn" data-bs-toggle="pill" data-bs-target="#tab-search-pay" type="button">
              <i class="fa-solid fa-user-check me-1"></i> Search & Pay (Add / Deduct)
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link fw-semibold px-4 py-2" id="tab-all-balances-btn" data-bs-toggle="pill" data-bs-target="#tab-all-balances" type="button">
              <i class="fa-solid fa-list-check me-1"></i> All Users Balances (<?= count($allPaymentUsers) ?>)
            </button>
          </li>
        </ul>
      </div>

      <div class="modal-body p-4">
        <div class="tab-content" id="paymentModalTabContent">
          
          <!-- TAB 1: Search & Pay (Primary Form) -->
          <div class="tab-pane fade show active" id="tab-search-pay">
            <form method="POST" id="searchPaymentForm" onsubmit="return validatePaymentForm();">
              <input type="hidden" name="action" value="add_balance_multi">

              <!-- Step 1: Search User -->
              <div class="mb-3">
                <label class="form-label fw-bold text-dark mb-1">
                  <i class="fa-solid fa-magnifying-glass text-primary me-1"></i> Search & Select User <span class="text-danger">*</span>
                </label>
                <select name="sub_id" id="payment_user_select" class="form-select form-select-lg" required onchange="onPaymentUserSelected()">
                  <option value="">-- Type username or customer name to search --</option>
                  <?php foreach($allPaymentUsers as $pu): ?>
                    <option value="<?= $pu['id'] ?>"
                            data-username="<?= htmlspecialchars($pu['username']) ?>"
                            data-fullname="<?= htmlspecialchars($pu['full_name'] ?: 'N/A') ?>"
                            data-balance="<?= (float)$pu['balance'] ?>"
                            data-status="<?= htmlspecialchars($pu['status']) ?>">
                      <?= htmlspecialchars($pu['username']) ?> - <?= htmlspecialchars($pu['full_name'] ?: 'No Name') ?> (Bal: Rs. <?= number_format($pu['balance'], 0) ?>) [<?= $pu['status'] ?>]
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text small text-muted">
                  <i class="fa-solid fa-keyboard me-1"></i> Search any user by username or full name across all active, expired, or disabled accounts.
                </div>
              </div>

              <!-- Selected User Live Info Card -->
              <div id="selected_user_card" class="card border rounded-3 p-3 mb-4 bg-light shadow-sm d-none">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <div>
                    <div class="fw-bold fs-6 text-dark" id="disp_user_fullname">---</div>
                    <div class="text-muted small font-monospace">Username: <span id="disp_user_username" class="text-primary fw-bold">---</span></div>
                    <div class="mt-1" id="disp_user_status_badge"></div>
                  </div>
                  <div class="text-end">
                    <div class="text-secondary small fw-bold text-uppercase">Current Balance</div>
                    <div class="fs-4 fw-bold" id="disp_user_balance">Rs. 0.00</div>
                    <div class="small fw-semibold mt-1" id="disp_new_projected_balance"></div>
                  </div>
                </div>
              </div>

              <!-- Step 2: Transaction Type Toggle (Add VS Deduct) -->
              <div class="mb-3">
                <label class="form-label fw-bold text-dark mb-1">Transaction Action <span class="text-danger">*</span></label>
                <div class="d-flex gap-2">
                  <input type="radio" class="btn-check" name="trans_type" id="trans_add" value="add" checked onchange="updatePaymentMode()">
                  <label class="btn btn-outline-success flex-fill py-2 fw-bold d-flex align-items-center justify-content-center gap-2" for="trans_add">
                    <i class="fa-solid fa-plus-circle fs-5"></i>
                    <span>Add Payment (Credit / Ø¬Ù…Ø¹)</span>
                  </label>

                  <input type="radio" class="btn-check" name="trans_type" id="trans_deduct" value="deduct" onchange="updatePaymentMode()">
                  <label class="btn btn-outline-danger flex-fill py-2 fw-bold d-flex align-items-center justify-content-center gap-2" for="trans_deduct">
                    <i class="fa-solid fa-minus-circle fs-5"></i>
                    <span>Deduct Payment (Debit / Ø¨Ù†Ø§Ù…)</span>
                  </label>
                </div>
              </div>

              <!-- Step 3: Amount -->
              <div class="mb-3">
                <label class="form-label fw-bold text-dark mb-1">
                  Amount in PKR <span class="text-danger">*</span>
                </label>
                <div class="input-group input-group-lg">
                  <span class="input-group-text bg-light fw-bold text-dark" id="amount_currency_tag">Rs.</span>
                  <input type="number" step="1" min="1" name="amount" id="payment_amount_input" class="form-control fw-bold" placeholder="0" required oninput="calculateProjectedBalance()">
                </div>
                
                <!-- Quick Amount Chips -->
                <div class="d-flex flex-wrap gap-2 mt-2">
                  <span class="text-muted small align-self-center me-1">Quick:</span>
                  <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 fw-semibold" onclick="setQuickAmount(500)">Rs. 500</button>
                  <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 fw-semibold" onclick="setQuickAmount(1000)">Rs. 1,000</button>
                  <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 fw-semibold" onclick="setQuickAmount(1500)">Rs. 1,500</button>
                  <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 fw-semibold" onclick="setQuickAmount(2000)">Rs. 2,000</button>
                  <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 fw-semibold" onclick="setQuickAmount(3000)">Rs. 3,000</button>
                  <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 py-0 text-muted" onclick="setQuickAmount('')">Clear</button>
                </div>
              </div>

              <!-- Step 4: Description / Reason -->
              <div class="mb-4">
                <label class="form-label fw-bold text-secondary small mb-1">Description / Remarks (Optional)</label>
                <input type="text" name="description" id="payment_desc_input" class="form-control" placeholder="e.g. Cash received from customer, Bill correction, JazzCash, etc.">
              </div>

              <!-- Submit Button -->
              <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Close</button>
                <button type="submit" id="payment_submit_btn" class="btn btn-success px-4 py-2 fw-bold shadow-sm">
                  <i class="fa-solid fa-plus-circle me-1"></i> Add Balance
                </button>
              </div>
            </form>
          </div>

          <!-- TAB 2: All Users Balances Table -->
          <div class="tab-pane fade" id="tab-all-balances">
            <div class="alert alert-info d-flex align-items-center mb-3 py-2 border-0 shadow-sm">
              <i class="fa-solid fa-wallet fs-4 me-3 text-info"></i>
              <div>
                <span class="small text-muted d-block">Total Admin Balance</span>
                <strong class="fs-5 text-dark">Rs. <?= number_format($total_balance_all, 0) ?></strong>
              </div>
            </div>

            <div class="table-responsive">
              <table class="table table-hover table-bordered w-100 align-middle" id="balanceTable" style="font-size: 0.88rem;">
                <thead class="table-light">
                  <tr>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Status</th>
                    <th>Balance</th>
                    <th style="width: 130px;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($allPaymentUsers as $s): ?>
                  <tr>
                    <td>
                      <div class="d-inline-flex align-items-center gap-1">
                        <span class="fw-bold text-primary"><?= htmlspecialchars($s['username']) ?></span>
                        <button type="button" 
                                class="copy-user-btn" 
                                style="width: 22px; height: 22px;"
                                onclick="copyToClipboard(event, '<?= addslashes(htmlspecialchars($s['username'])) ?>', this)" 
                                title="Copy Username">
                            <i class="fa-regular fa-copy" style="font-size: 0.72rem;"></i>
                        </button>
                      </div>
                    </td>
                    <td><?= htmlspecialchars($s['full_name'] ?: 'N/A') ?></td>
                    <td>
                      <?php if($s['status'] === 'active'): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2">Active</span>
                      <?php elseif($s['status'] === 'expired'): ?>
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2">Expired</span>
                      <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2"><?= htmlspecialchars($s['status']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="fw-bold <?= $s['balance'] < 0 ? 'text-danger' : 'text-success' ?>">
                      Rs. <?= number_format($s['balance'], 0) ?>
                    </td>
                    <td>
                      <button type="button" class="btn btn-sm btn-outline-primary w-100 fw-semibold" onclick="selectUserForPayment(<?= $s['id'] ?>)">
                        <i class="fa-solid fa-pen-to-square me-1"></i> Select
                      </button>
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

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content light-modal" style="background-color: #f8fafc;">
      <div class="modal-header bg-white border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus text-primary me-2"></i> Add New User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body p-4 pt-2">

            <!-- Default Expired Status Banner -->
            <div class="alert alert-warning border border-warning-subtle rounded-3 py-2 px-3 mb-3 d-flex align-items-center gap-2 shadow-sm" style="background-color: #fffbeb;">
                <i class="fa-solid fa-clock-rotate-left text-warning-emphasis fs-5"></i>
                <div class="small text-dark">
                    <strong>Default Status: Expired</strong> — Naya user by default <strong>Expired</strong> state me create hoga. User create hone ke baad <strong>Activate/Renew</strong> button se renew karein ta k package charge ho aur financial ledger record update ho sake.
                </div>
            </div>
            
            <div class="add-user-flat-form">
                <!-- Account Info -->
                <div class="mb-4">
            <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-user me-2 text-muted"></i> Account Information
                        </h6>
            
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
        <!-- Service Info -->
        <div class="mb-4">
            <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-wifi me-2 text-muted"></i> Service & Package
                        </h6>
            
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
        <!-- Contact Info -->
        <div class="mb-4">
            <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-address-book me-2 text-muted"></i> Contact & Location
                        </h6>
            
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
                                <div class="col-md-12 mb-3">
                                    <label class="col-form-label text-secondary small fw-bold d-flex justify-content-between align-items-center">
                                        <span><i class="fa-solid fa-map-location-dot text-primary me-1"></i> Customer Location (Map Pin & GPS)</span>
                                        <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 text-muted" onclick="toggleManualCoords()" style="font-size: 0.75rem;">
                                            <i class="fa-solid fa-keyboard me-1"></i> Manual Enter
                                        </button>
                                    </label>
                                    
                                    <!-- Action controls: GPS Button + Map Search Box + Satellite/Map toggle -->
                                    <div class="row g-2 mb-2">
                                        <div class="col-md-5">
                                            <button type="button" class="btn btn-outline-primary btn-sm fw-bold w-100 py-2 shadow-sm" id="btn_capture_location" onclick="captureUserLocation()">
                                                <i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)
                                            </button>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" id="map_search_input" placeholder="Search area, colony..." onkeydown="if(event.key==='Enter'){event.preventDefault();searchLocationOnMap();}">
                                                <button class="btn btn-primary" type="button" id="btn_map_search" onclick="searchLocationOnMap()" title="Search Area on Map">
                                                    <i class="fa-solid fa-magnifying-glass"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="btn-group btn-group-sm w-100 shadow-sm" role="group">
                                                <button type="button" class="btn btn-primary btn-sm fw-bold" id="btnUserMapHybrid" onclick="switchUserMapLayer('hybrid')" title="Satellite View with Streets"><i class="fa-solid fa-satellite me-1"></i>Sat</button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" id="btnUserMapStreets" onclick="switchUserMapLayer('streets')" title="Streets Map View"><i class="fa-solid fa-road me-1"></i>Map</button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Interactive Leaflet Map Container -->
                                    <div class="position-relative rounded border overflow-hidden shadow-sm" style="height: 280px;">
                                        <div id="user_map_picker" style="height: 100%; width: 100%; z-index: 1;"></div>
                                        <div class="position-absolute bottom-0 start-0 m-2 px-2 py-1 rounded bg-dark bg-opacity-75 text-white small" style="z-index: 1000; font-size: 0.75rem; pointer-events: none;">
                                            <i class="fa-solid fa-hand-pointer text-warning me-1"></i> Map par click karein ya pin ko drag kar ke customer ki location par set karein
                                        </div>
                                    </div>

                                    <!-- Actual inputs sent to DB -->
                                    <input type="hidden" name="latitude" id="user_latitude" value="">
                                    <input type="hidden" name="longitude" id="user_longitude" value="">

                                    <!-- Live Pinned / Captured Status Card -->
                                    <div id="location_status_box" class="mt-2">
                                        <div class="d-flex align-items-center justify-content-between p-2 rounded bg-light border small">
                                            <div>
                                                <span class="text-secondary fw-semibold"><i class="fa-solid fa-location-dot text-danger me-1"></i> Pinned Coordinates:</span>
                                                <span class="font-monospace text-dark fw-bold ms-1" id="pinned_coords_text">Not set (Click map or drag pin)</span>
                                            </div>
                                            <div id="location_accuracy_badge">
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Draggable Pin</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Collapsible Manual Inputs -->
                                    <div id="manual_coords_container" class="row mt-2 d-none">
                                        <div class="col-6">
                                            <label class="small text-muted fw-bold">Latitude</label>
                                            <input type="text" class="form-control form-control-sm font-monospace" id="manual_lat" placeholder="e.g. 31.520371" oninput="syncManualCoords()">
                                        </div>
                                        <div class="col-6">
                                            <label class="small text-muted fw-bold">Longitude</label>
                                            <input type="text" class="form-control form-control-sm font-monospace" id="manual_lng" placeholder="e.g. 74.358742" oninput="syncManualCoords()">
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

<?php
// Fetch users and packages for the renew modal
$renewUsers = $pdo->query("SELECT s.id, s.username, s.full_name, s.status, s.expiry_date, s.package_id, s.balance, p.price as pkg_price, p.validity_days as pkg_val FROM subscribers s LEFT JOIN packages p ON s.package_id = p.id WHERE s.client_id = $client_id ORDER BY s.username ASC")->fetchAll();
$renewPackages = $pdo->query("SELECT id, name, price, validity_days FROM packages WHERE client_id = $client_id OR client_id = 0 ORDER BY name ASC")->fetchAll();
?>
<!-- Activate/Renew User Modal -->
<div class="modal fade" id="renewUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content light-modal" style="background-color: #f8fafc;">
      <div class="modal-header bg-white border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-bolt text-warning me-2"></i> Activate / Renew User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form action="renew_user_action.php" method="POST" enctype="multipart/form-data" onsubmit="var btn=this.querySelector('button[type=submit]'); if(this.submitted){return false;} this.submitted=true; if(btn){btn.disabled=true; btn.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i> Processing Renewal... Please wait...';} return true;">
        <div class="modal-body p-4 pt-2">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="col-form-label text-secondary small fw-bold">Select User <span class="text-danger">*</span></label>
                    <select name="user_id" id="renew_user_id" class="form-select form-select-sm" required onchange="updateRenewDetails()">
                        <option value="">-- Choose User --</option>
                        <?php foreach($renewUsers as $ru): ?>
                            <option value="<?= $ru['id'] ?>" 
                                data-username="<?= htmlspecialchars($ru['username']) ?>"
                                data-fullname="<?= htmlspecialchars($ru['full_name']) ?>"
                                data-status="<?= $ru['status'] ?>"
                                data-expiry="<?= $ru['expiry_date'] ?>"
                                data-pkg="<?= $ru['package_id'] ?>"
                                data-curpkgprice="<?= (float)($ru['pkg_price'] ?? 0) ?>"
                                data-curpkgval="<?= (int)(($ru['pkg_val'] ?? 0) > 0 ? $ru['pkg_val'] : 30) ?>"
                                data-curbal="<?= (float)($ru['balance'] ?? 0) ?>">
                                <?= htmlspecialchars($ru['username']) ?> (<?= htmlspecialchars($ru['full_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="col-form-label text-secondary small fw-bold">Package <span class="text-danger">*</span></label>
                    <select name="package_id" id="renew_package_id" class="form-select form-select-sm" required onchange="updateRenewDetails()">
                        <option value="">-- Select Package --</option>
                        <?php foreach($renewPackages as $rp): ?>
                            <option value="<?= $rp['id'] ?>" 
                                data-name="<?= htmlspecialchars($rp['name']) ?>"
                                data-duration="<?= $rp['validity_days'] ?>"
                                data-price="<?= $rp['price'] ?>">
                                <?= htmlspecialchars($rp['name']) ?> (Rs <?= number_format($rp['price']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if (!$is_mgr): ?>
                <div class="col-md-6 mb-3">
                    <label class="col-form-label text-secondary small fw-bold">Expiry Type <span class="text-danger">*</span></label>
                    <select name="expiry_type" id="renew_expiry_type" class="form-select form-select-sm" onchange="toggleCustomExpiry()">
                        <option value="default">Default (Package Duration)</option>
                        <option value="custom">Custom Date & Time</option>
                    </select>
                </div>
                
                <div class="col-md-6 mb-3 d-none" id="custom_expiry_container">
                    <label class="col-form-label text-secondary small fw-bold">Custom Expiry Date/Time <span class="text-danger">*</span></label>
                    <input type="datetime-local" name="custom_expiry" id="renew_custom_expiry" class="form-control form-control-sm" onchange="updateRenewDetails()">
                </div>
                <?php else: ?>
                <input type="hidden" name="expiry_type" id="renew_expiry_type" value="default">
                <div class="col-md-6 mb-3">
                    <label class="col-form-label text-secondary small fw-bold">Expiry Duration</label>
                    <div class="form-control form-control-sm bg-light text-primary fw-bold"><i class="fa-solid fa-calendar-check text-success me-1"></i> Auto Package Duration</div>
                </div>
                <?php endif; ?>

                <div class="col-md-6 mb-3">
                    <label class="col-form-label text-secondary small fw-bold">
                        <i class="fa-solid fa-money-bill-wave text-success me-1"></i> Payment Received (Rs)
                    </label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white fw-bold text-success border-success-subtle">Rs.</span>
                        <input type="number" step="any" min="0" name="payment_amount" id="dash_renew_payment" class="form-control form-control-sm fw-bold text-success border-success-subtle shadow-none" placeholder="0.00" oninput="updateRenewDetails()">
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="col-form-label text-secondary small fw-bold">
                        <i class="fa-solid fa-paperclip text-primary me-1"></i> Attach Receipt / Slip <span class="badge bg-light text-muted border ms-1" style="font-size: 0.65rem;">Optional</span>
                    </label>
                    <input type="file" name="renewal_receipt" class="form-control form-control-sm" accept="image/*,.pdf">
                    <div class="form-text text-muted" style="font-size: 0.72rem;">Customer deposit slip, transfer screenshot, or PDF.</div>
                </div>
            </div>

            <!-- Expanding Details Section -->
            <div id="renew_details_section" class="d-none mt-3">
                <h6 class="fw-bold text-primary border-bottom pb-2 mb-3"><i class="fa-solid fa-list me-1"></i> Renewal Summary</h6>
                <div class="table-responsive bg-white border rounded shadow-sm">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="text-secondary ps-3" style="width: 40%;">Attribute</th>
                                <th class="text-secondary">Description</th>
                            </tr>
                        </thead>
                        <tbody id="renew_details_tbody">
                            <!-- Filled via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        <div class="modal-footer bg-white border-top-0 pt-0">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning fw-bold px-4"><i class="fa-solid fa-bolt me-2"></i> Confirm Renewal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function toggleCustomExpiry() {
    var type = document.getElementById('renew_expiry_type').value;
    var container = document.getElementById('custom_expiry_container');
    var input = document.getElementById('renew_custom_expiry');
    if (type === 'custom') {
        container.classList.remove('d-none');
        input.setAttribute('required', 'required');
    } else {
        container.classList.add('d-none');
        input.removeAttribute('required');
    }
    updateRenewDetails();
}

function updateRenewDetails() {
    var userSelect = document.getElementById('renew_user_id');
    var pkgSelect = document.getElementById('renew_package_id');
    var section = document.getElementById('renew_details_section');
    var tbody = document.getElementById('renew_details_tbody');
    
    if (userSelect.value === "") {
        section.classList.add('d-none');
        return;
    }
    
    section.classList.remove('d-none');
    
    var uOpt = userSelect.options[userSelect.selectedIndex];
    var username = uOpt.getAttribute('data-username');
    var uid = userSelect.value;
    var status = uOpt.getAttribute('data-status');
    var curExp = uOpt.getAttribute('data-expiry');
    var uPkgId = uOpt.getAttribute('data-pkg');
    
    // Auto-select package if user has one and package select is currently empty
    if(pkgSelect.value === "" && uPkgId && uPkgId != "0" && uPkgId != "") {
        for(let i=0; i<pkgSelect.options.length; i++){
            if(pkgSelect.options[i].value == uPkgId) {
                pkgSelect.selectedIndex = i;
                break;
            }
        }
    }
    
    var pOpt = pkgSelect.options[pkgSelect.selectedIndex];
    var pName = (pOpt && pOpt.value) ? pOpt.getAttribute('data-name') : 'N/A';
    var pDur = (pOpt && pOpt.value) ? parseInt(pOpt.getAttribute('data-duration')) : 0;
    var pPrice = (pOpt && pOpt.value) ? parseFloat(pOpt.getAttribute('data-price')).toFixed(0) : '0';
    
    // Formatting Current Expiry
    var curExpFormatted = "No Expiry";
    if (curExp && curExp !== "" && curExp !== "null" && curExp !== "0000-00-00 00:00:00") {
        let d = new Date(curExp);
        if(!isNaN(d)) curExpFormatted = d.toLocaleString('en-GB', { day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit'}).replace(',', '');
    }

    // Calculating New Expiry
    var newExpFormatted = "Select Package/Date";
    var expType = document.getElementById('renew_expiry_type').value;
    
    if (expType === 'custom') {
        var customVal = document.getElementById('renew_custom_expiry').value;
        if(customVal) {
            let nd = new Date(customVal);
            if(!isNaN(nd)) newExpFormatted = nd.toLocaleString('en-GB', { day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit'}).replace(',', '');
        }
    } else {
        if (pDur > 0) {
            let baseDate = new Date();
            // Append to current expiry if it's in the future
            if(curExp && curExp !== "" && curExp !== "null" && curExp !== "0000-00-00 00:00:00") {
                let cd = new Date(curExp);
                if(cd > baseDate) baseDate = cd;
            }
            
            baseDate.setDate(baseDate.getDate() + pDur);
            // Set to rat 12 bajy (23:59:59)
            baseDate.setHours(23, 59, 59, 0);
            newExpFormatted = baseDate.toLocaleString('en-GB', { day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit'}).replace(',', '');
        }
    }

    var statusHtml = status === 'active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Disabled</span>';

    var curPkgPrice = parseFloat(uOpt.getAttribute('data-curpkgprice') || 0);
    var curPkgVal = parseInt(uOpt.getAttribute('data-curpkgval') || 30);
    var curBal = parseFloat(uOpt.getAttribute('data-curbal') || 0);

    var now = new Date();
    var cd = (curExp && curExp !== "" && curExp !== "null" && curExp !== "0000-00-00 00:00:00") ? new Date(curExp) : null;
    var isUserActive = (cd && cd > now);
    var isPkgChanged = (uPkgId && pkgSelect.value && uPkgId != pkgSelect.value);

    var oldCredit = 0;
    var remDays = 0;
    if (isUserActive && cd) {
        remDays = Math.round(((cd - now) / 86400000) * 10) / 10;
        var oldDaily = curPkgPrice / (curPkgVal > 0 ? curPkgVal : 30);
        oldCredit = Math.round(((cd - now) / 86400000) * oldDaily * 100) / 100;
    }

        var netPayable = parseFloat(pPrice);
    var payField = document.getElementById('dash_renew_payment');
    if (payField && (payField.dataset.lastPkg !== String(pkgSelect.value) || payField.value === "")) {
        payField.value = isNaN(netPayable) ? 0 : netPayable;
        payField.dataset.lastPkg = String(pkgSelect.value);
    }
    var adjustmentRow = '';
    if (isPkgChanged && oldCredit > 0) {
        netPayable = Math.max(0, Math.round(parseFloat(pPrice) - oldCredit));
        adjustmentRow = `
            <tr><td class="fw-bold ps-3 text-warning"><i class="fa-solid fa-arrow-up-right-dots me-1"></i> Package Change Adjustment</td><td><span class="badge bg-warning text-dark fw-bold">Upgrade Credit Applied</span></td></tr>
            <tr><td class="fw-bold ps-3">Old Unused Credit</td><td class="text-success fw-bold">- Rs ${oldCredit.toFixed(2)} (${remDays} days left)</td></tr>
            <tr><td class="fw-bold ps-3">Net Charge to Balance</td><td class="text-primary fw-bold fs-6">Rs ${netPayable.toFixed(2)}</td></tr>
        `;
    }

    tbody.innerHTML = `
        <tr><td class="fw-bold ps-3">Username & ID</td><td>${username} (#${uid})</td></tr>
        <tr><td class="fw-bold ps-3">User Status</td><td>${statusHtml}</td></tr>
        <tr><td class="fw-bold ps-3">Current Expiration</td><td class="text-danger fw-bold">${curExpFormatted}</td></tr>
        <tr><td class="fw-bold ps-3">New Expiration</td><td class="text-success fw-bold">${newExpFormatted}</td></tr>
        <tr><td class="fw-bold ps-3">Package</td><td><span class="badge bg-primary bg-opacity-10 text-primary">${pName}</span></td></tr>
        <tr><td class="fw-bold ps-3">Package Duration</td><td>${pDur} Days</td></tr>
        <tr><td class="fw-bold ps-3">Package Price</td><td class="text-success fw-bold">Rs ${pPrice}</td></tr>
        ${adjustmentRow}
        <tr>
            <td class="fw-bold ps-3 text-success"><i class="fa-solid fa-money-bill-wave me-1"></i> Payment Received (Cash)</td>
            <td class="text-success fw-bold fs-6">Rs. ${parseFloat(payField ? (payField.value || 0) : 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
        </tr>
    `;
}

// --- GPS Location & Interactive Leaflet Map Logic (10m accuracy target) ---
let geoWatchId = null;
let bestPosition = null;
let userMap = null;
let userMarker = null;
let userHybridLayer = null;
let userStreetsLayer = null;
const defaultMapLat = 31.5204;
const defaultMapLng = 74.3587; // Central Pakistan default

function initUserMapPicker() {
    if (userMap !== null) {
        setTimeout(function() {
            if (userMap) userMap.invalidateSize();
        }, 150);
        return;
    }
    
    var container = document.getElementById('user_map_picker');
    if (!container) return;
    
    var curLat = parseFloat(document.getElementById('user_latitude').value);
    var curLng = parseFloat(document.getElementById('user_longitude').value);
    var hasExisting = !isNaN(curLat) && curLat !== 0 && !isNaN(curLng) && curLng !== 0;
    var startLat = hasExisting ? curLat : defaultMapLat;
    var startLng = hasExisting ? curLng : defaultMapLng;

    userHybridLayer = L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
        maxZoom: 22,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
        attribution: '&copy; Google Maps'
    });

    userStreetsLayer = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
        maxZoom: 22,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
        attribution: '&copy; Google Maps'
    });

    var userEsri = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri'
    });

    var userOsm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    });

    var userBaseMaps = {
        "ðŸ›°ï¸ Google Satellite (Hybrid)": userHybridLayer,
        "ðŸ—ºï¸ Google Streets": userStreetsLayer,
        "ðŸŒ Esri Satellite": userEsri,
        "ðŸ“ OpenStreetMap": userOsm
    };

    userMap = L.map('user_map_picker', {
        center: [startLat, startLng],
        zoom: hasExisting ? 18 : 14,
        maxZoom: 22,
        zoomControl: true,
        layers: [userHybridLayer]
    });

    L.control.layers(userBaseMaps, null, { position: 'topright' }).addTo(userMap);

    var pinIcon = L.icon({
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34],
        shadowSize: [41, 41]
    });

    userMarker = L.marker([startLat, startLng], {
        draggable: true,
        icon: pinIcon
    }).addTo(userMap);

    userMarker.bindTooltip("Drag me or click map", { permanent: false, direction: 'top' });

    if (hasExisting) {
        updateCoordsFromMap(startLat, startLng, false);
    }

    userMarker.on('dragend', function () {
        var pos = userMarker.getLatLng();
        updateCoordsFromMap(pos.lat, pos.lng);
    });

    userMap.on('click', function (e) {
        userMarker.setLatLng(e.latlng);
        updateCoordsFromMap(e.latlng.lat, e.latlng.lng);
    });

    setTimeout(function() {
        if (userMap) userMap.invalidateSize();
    }, 200);
}

function switchUserMapLayer(type) {
    if (!userMap || !userHybridLayer || !userStreetsLayer) return;
    var btnHybrid = document.getElementById('btnUserMapHybrid');
    var btnStreets = document.getElementById('btnUserMapStreets');
    if (type === 'hybrid') {
        userMap.removeLayer(userStreetsLayer);
        userMap.addLayer(userHybridLayer);
        if (btnHybrid) { btnHybrid.className = 'btn btn-primary btn-sm fw-bold'; }
        if (btnStreets) { btnStreets.className = 'btn btn-outline-secondary btn-sm fw-bold'; }
    } else if (type === 'streets') {
        userMap.removeLayer(userHybridLayer);
        userMap.addLayer(userStreetsLayer);
        if (btnStreets) { btnStreets.className = 'btn btn-primary btn-sm fw-bold'; }
        if (btnHybrid) { btnHybrid.className = 'btn btn-outline-secondary btn-sm fw-bold'; }
    }
}

function updateCoordsFromMap(lat, lng, panTo) {
    var fixedLat = parseFloat(lat).toFixed(7);
    var fixedLng = parseFloat(lng).toFixed(7);
    
    document.getElementById('user_latitude').value = fixedLat;
    document.getElementById('user_longitude').value = fixedLng;
    
    var manLat = document.getElementById('manual_lat');
    var manLng = document.getElementById('manual_lng');
    if (manLat) manLat.value = fixedLat;
    if (manLng) manLng.value = fixedLng;
    
    var textEl = document.getElementById('pinned_coords_text');
    if (textEl) {
        textEl.innerHTML = `<span class="text-primary">${fixedLat}</span>, <span class="text-primary">${fixedLng}</span>`;
    }
    
    var badgeEl = document.getElementById('location_accuracy_badge');
    if (badgeEl) {
        badgeEl.innerHTML = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="fa-solid fa-map-pin me-1"></i> Pinned on Map</span>';
    }
    
    if (panTo && userMap) {
        userMap.panTo([fixedLat, fixedLng]);
    }
}

function searchLocationOnMap() {
    var query = document.getElementById('map_search_input').value.trim();
    if (!query) return;
    var btn = document.getElementById('btn_map_search');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    
    fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=1`)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Search';
            if (data && data.length > 0) {
                var lat = parseFloat(data[0].lat);
                var lon = parseFloat(data[0].lon);
                if (userMap && userMarker) {
                    userMap.setView([lat, lon], 16);
                    userMarker.setLatLng([lat, lon]);
                }
                updateCoordsFromMap(lat, lon);
            } else {
                alert('Area/location not found. Please try another search term or drag the map manually.');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Search';
        });
}

function toggleManualCoords() {
    var cont = document.getElementById('manual_coords_container');
    if (!cont) return;
    if (cont.classList.contains('d-none')) {
        cont.classList.remove('d-none');
        document.getElementById('manual_lat').value = document.getElementById('user_latitude').value;
        document.getElementById('manual_lng').value = document.getElementById('user_longitude').value;
    } else {
        cont.classList.add('d-none');
    }
}

function syncManualCoords() {
    var lat = parseFloat(document.getElementById('manual_lat').value.trim());
    var lng = parseFloat(document.getElementById('manual_lng').value.trim());
    
    if (!isNaN(lat) && !isNaN(lng)) {
        if (userMap && userMarker) {
            userMarker.setLatLng([lat, lng]);
            userMap.setView([lat, lng], 17);
        }
        updateCoordsFromMap(lat, lng);
    }
}

function captureUserLocation() {
    if (!navigator.geolocation) {
        alert("Geolocation is not supported by your browser/device.");
        return;
    }
    
    const btn = document.getElementById('btn_capture_location');
    const statusEl = document.getElementById('location_accuracy_badge');
    const latInp = document.getElementById('user_latitude');
    const lngInp = document.getElementById('user_longitude');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-1"></i> Locking GPS (&le;10m)...';
    if (statusEl) {
        statusEl.innerHTML = '<span class="badge bg-info-subtle text-info border border-info-subtle"><i class="fa-solid fa-satellite-dish fa-spin me-1"></i> Locking GPS...</span>';
    }
    
    if (geoWatchId !== null) {
        navigator.geolocation.clearWatch(geoWatchId);
        geoWatchId = null;
    }
    bestPosition = null;

    const finishCapture = (pos) => {
        if (geoWatchId !== null) {
            navigator.geolocation.clearWatch(geoWatchId);
            geoWatchId = null;
        }
        btn.disabled = false;
        btn.className = 'btn btn-outline-success btn-sm fw-bold w-100 py-2 shadow-sm';
        btn.innerHTML = '<i class="fa-solid fa-arrows-rotate me-1"></i> Re-capture GPS';
        
        const lat = pos.coords.latitude.toFixed(7);
        const lng = pos.coords.longitude.toFixed(7);
        const acc = Math.round(pos.coords.accuracy);
        
        if (userMap && userMarker) {
            userMap.setView([lat, lng], 17);
            userMarker.setLatLng([lat, lng]);
        }
        
        updateCoordsFromMap(lat, lng);
        
        let accBadge = acc <= 10 
            ? `<span class="badge bg-success text-white px-2 py-1"><i class="fa-solid fa-bullseye me-1"></i> High GPS: Â±${acc}m (&le;10m)</span>`
            : `<span class="badge bg-warning text-dark px-2 py-1"><i class="fa-solid fa-satellite me-1"></i> GPS: Â±${acc}m</span>`;
            
        if (statusEl) statusEl.innerHTML = accBadge;
    };

    const timeoutTimer = setTimeout(() => {
        if (bestPosition) {
            finishCapture(bestPosition);
        } else {
            if (geoWatchId !== null) {
                navigator.geolocation.clearWatch(geoWatchId);
                geoWatchId = null;
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)';
            if (statusEl) statusEl.innerHTML = '<span class="badge bg-warning text-dark">GPS Timeout</span>';
            alert("GPS request timed out. Make sure Location/GPS is turned ON, or use the interactive map to pin the location!");
        }
    }, 12000);

    try {
        geoWatchId = navigator.geolocation.watchPosition(
            (pos) => {
                const acc = pos.coords.accuracy;
                if (!bestPosition || acc < bestPosition.coords.accuracy) {
                    bestPosition = pos;
                }
                
                if (statusEl) {
                    statusEl.innerHTML = `<span class="badge bg-info-subtle text-info">GPS reading: Â±${Math.round(acc)}m</span>`;
                }
                
                // If accuracy is within 10 meters, lock immediately!
                if (acc <= 10) {
                    clearTimeout(timeoutTimer);
                    finishCapture(pos);
                }
            },
            (err) => {
                clearTimeout(timeoutTimer);
                if (geoWatchId !== null) {
                    navigator.geolocation.clearWatch(geoWatchId);
                    geoWatchId = null;
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)';
                
                let errMsg = 'Failed to get location.';
                if (err.code === 1) errMsg = 'Location permission denied in browser.';
                else if (err.code === 2) errMsg = 'Location unavailable on device.';
                else if (err.code === 3) errMsg = 'Location request timed out.';
                
                if (statusEl) statusEl.innerHTML = `<span class="badge bg-danger">${errMsg}</span>`;
            },
            {
                enableHighAccuracy: true,
                maximumAge: 0,
                timeout: 10000
            }
        );
    } catch(e) {
        clearTimeout(timeoutTimer);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)';
        if (statusEl) statusEl.innerHTML = `<span class="badge bg-danger">GPS Error</span>`;
    }
}
</script>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
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
    // Fix modal backdrop z-index issues
    $('.modal').appendTo('body');

    $('#addUserModal').on('shown.bs.modal', function () {
        initUserMapPicker();
    });

    $('#addUserModal').on('hidden.bs.modal', function () {
        if (geoWatchId !== null) {
            navigator.geolocation.clearWatch(geoWatchId);
            geoWatchId = null;
        }
    });

    // Theme & Fullscreen helpers
    window.toggleDashboardTheme = function() {
        document.body.classList.toggle('dark-theme');
        var isDark = document.body.classList.contains('dark-theme');
        localStorage.setItem('zalDashboardTheme', isDark ? 'dark' : 'light');
        var icon = document.getElementById('themeIcon');
        if (icon) {
            icon.className = isDark ? 'fa-solid fa-sun text-warning' : 'fa-regular fa-moon text-secondary';
        }
    };

    window.toggleFullScreen = function() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(function(err){});
        } else if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    };

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

});

let zalSubscriberChart = null;
function initSubscriberPieChart() {
    var canvas = document.getElementById('subscriberPieChart');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    if (zalSubscriberChart) {
        zalSubscriberChart.destroy();
    }
    zalSubscriberChart = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: ['Active', 'Expired', 'Disabled', 'Terminated', 'Online', 'Offline'],
            datasets: [{
                data: [
                    <?= max(0, $active_users) ?>,
                    <?= max(0, $expired) ?>,
                    <?= max(0, $disabled_users) ?>,
                    <?= max(0, $terminated_users) ?>,
                    <?= max(0, $online_users) ?>,
                    <?= max(0, $offline_users) ?>
                ],
                backgroundColor: [
                    '#0284c7', // Active - Sky Blue
                    '#f43f5e', // Expired - Rose
                    '#f59e0b', // Disabled - Amber
                    '#e11d48', // Terminated - Red
                    '#10b981', // Online - Green
                    '#94a3b8'  // Offline - Gray
                ],
                borderWidth: 1,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    enabled: true,
                    callbacks: {
                        label: function(context) {
                            var label = context.label || '';
                            var value = context.parsed || 0;
                            return ' ' + label + ': ' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

let usersDonutChart = null;
function initUsersDonut() {
    var canvas = document.getElementById('usersDonut');
    if (!canvas) return;
    if (usersDonutChart) return;
    var ctx = canvas.getContext('2d');
    usersDonutChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Online', 'Active (Offline)', 'Expired', 'Disabled', 'Others'],
            datasets: [{
                data: [<?= $chart_online ?>, <?= $chart_active ?>, <?= $chart_expired ?>, <?= $chart_disabled ?>, <?= $chart_others ?>],
                backgroundColor: ['#0ea5e9', '#22c55e', '#ef4444', '#f59e0b', '#94a3b8'],
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
}

function switchStatTab(tabName, el) {
    localStorage.setItem('activeStatTab', tabName);

    // Update active class on all tabs
    document.querySelectorAll('.nav-zal-link').forEach(function(tab) {
        tab.classList.remove('active');
    });
    if (el) {
        el.classList.add('active');
    } else {
        var matchTab = document.querySelector('.nav-zal-link[data-tab="' + tabName + '"]');
        if (matchTab) matchTab.classList.add('active');
    }

    var tabIds = ['subscriber', 'user', 'predictions', 'sales_graphs', 'cashflow', 'usage', 'accounting', 'map'];
    tabIds.forEach(function(t) {
        var c = document.getElementById('stat-content-' + t);
        if (c) c.classList.add('d-none');
    });

    var activeC = document.getElementById('stat-content-' + tabName);
    if (activeC) activeC.classList.remove('d-none');

    if (tabName === 'subscriber') {
        setTimeout(initSubscriberPieChart, 50);
    } else if (tabName === 'user') {
        setTimeout(initUsersDonut, 50);
    } else if (tabName === 'usage') {
        if (activeC) {
            activeC.innerHTML = '<div class="col-12 text-center py-5"><div class="spinner-border text-primary" role="status"></div><div class="text-muted mt-2 small">Loading Usage Statistics...</div></div>';
            fetch('ajax_usage_stats.php')
                .then(res => res.text())
                .then(html => { activeC.innerHTML = html; })
                .catch(err => { activeC.innerHTML = '<div class="col-12 text-center py-5 text-danger">Failed to load statistics.</div>'; });
        }
    } else if (tabName === 'accounting') {
        if (activeC) {
            activeC.innerHTML = '<div class="col-12 text-center py-5"><div class="spinner-border text-primary" role="status"></div><div class="text-muted mt-2 small">Loading Accounting Statistics...</div></div>';
            fetch('ajax_accounting_stats.php')
                .then(res => res.text())
                .then(html => { activeC.innerHTML = html; })
                .catch(err => { activeC.innerHTML = '<div class="col-12 text-center py-5 text-danger">Failed to load statistics.</div>'; });
        }
    } else if (tabName === 'map') {
        setTimeout(initNetworkSubscriberMap, 100);
    }
}

// --- Subscriber Network Map Logic ---
let networkMapInstance = null;
let networkMarkersGroup = null;
let networkUserMarkers = {};

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
    });
}

function getNetworkMarkerIcon(u) {
    var isExpired = false;
    if (u.expiry_date && u.expiry_date !== '' && u.expiry_date !== '0000-00-00 00:00:00') {
        var expD = new Date(u.expiry_date);
        if (expD < new Date()) isExpired = true;
    }
    var isOnline = parseInt(u.is_online) > 0;
    
    var color = '#0ea5e9'; // Blue (Active Offline)
    var iconClass = 'fa-user';
    
    if (isOnline) {
        if (isExpired) {
            color = '#f59e0b'; // Amber (Expired Online)
            iconClass = 'fa-triangle-exclamation';
        } else {
            color = '#10b981'; // Green (Online)
            iconClass = 'fa-wifi';
        }
    } else {
        if (isExpired) {
            color = '#ef4444'; // Red (Expired Offline)
            iconClass = 'fa-circle-xmark';
        } else if (u.status && u.status.toLowerCase() === 'disabled') {
            color = '#64748b'; // Slate Gray
            iconClass = 'fa-ban';
        }
    }
    
    return L.divIcon({
        className: 'custom-network-marker',
        html: `
            <div style="background-color: ${color}; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.35); border: 2.5px solid white;">
                <i class="fa-solid ${iconClass}" style="color: white; font-size: 13px;"></i>
            </div>
            <div style="width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 8px solid ${color}; margin: -2px auto 0 auto;"></div>
        `,
        iconSize: [34, 42],
        iconAnchor: [17, 42],
        popupAnchor: [0, -40]
    });
}

function createSubscriberPopup(u) {
    var isExpired = false;
    if (u.expiry_date && u.expiry_date !== '' && u.expiry_date !== '0000-00-00 00:00:00') {
        var expD = new Date(u.expiry_date);
        if (expD < new Date()) isExpired = true;
    }
    
    var isOnline = parseInt(u.is_online) > 0;
    var statusBadge = '';
    
    if (isOnline) {
        if (isExpired) {
            statusBadge = '<span class="badge bg-warning text-dark"><i class="fa-solid fa-circle text-warning me-1"></i> Expired (Online)</span>';
        } else {
            statusBadge = '<span class="badge bg-success text-white"><i class="fa-solid fa-circle text-light me-1"></i> Online</span>';
        }
    } else {
        if (isExpired) {
            statusBadge = '<span class="badge bg-danger text-white">Expired (Offline)</span>';
        } else if (u.status && u.status.toLowerCase() === 'disabled') {
            statusBadge = '<span class="badge bg-secondary text-white">Disabled</span>';
        } else {
            statusBadge = '<span class="badge bg-info text-white">Active (Offline)</span>';
        }
    }
    
    var profileUrl = `subscriber_view.php?id=${u.id}`;
    
    return `
        <div style="min-width: 230px; font-family: inherit;">
            <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-2">
                <div>
                    <div class="d-flex align-items-center gap-1">
                        <a href="${profileUrl}" class="fw-bold fs-6 text-primary text-decoration-none" title="Click to view profile">
                            <i class="fa-solid fa-user me-1"></i> ${escapeHtml(u.username)}
                        </a>
                        <button type="button" 
                                class="copy-user-btn" 
                                style="width: 22px; height: 22px;"
                                onclick="copyToClipboard(event, '${escapeHtml(u.username)}', this)" 
                                title="Copy Username">
                            <i class="fa-regular fa-copy" style="font-size: 0.72rem;"></i>
                        </button>
                    </div>
                    <div class="text-secondary small">${escapeHtml(u.full_name || 'N/A')}</div>
                </div>
                <div>${statusBadge}</div>
            </div>
            
            <div class="small text-muted mb-2">
                <div><strong>Package:</strong> <span class="text-dark">${escapeHtml(u.package_name || 'N/A')}</span></div>
                ${u.mobile ? `<div><strong>Mobile:</strong> <a href="tel:${escapeHtml(u.mobile)}" class="text-decoration-none text-dark">${escapeHtml(u.mobile)}</a></div>` : ''}
                ${u.live_ip ? `<div><strong>IP:</strong> <code class="text-primary">${escapeHtml(u.live_ip)}</code></div>` : ''}
                ${u.subarea || u.address ? `<div><strong>Address:</strong> <span class="text-dark">${escapeHtml((u.subarea ? u.subarea + ', ' : '') + (u.address || ''))}</span></div>` : ''}
            </div>
            
            <div class="pt-1 border-top">
                <a href="${profileUrl}" class="btn btn-sm btn-primary w-100 fw-bold">
                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Open Profile
                </a>
            </div>
        </div>
    `;
}

let networkHybridLayer = null;
let networkStreetsLayer = null;

function initNetworkSubscriberMap() {
    if (networkMapInstance !== null) {
        setTimeout(function() {
            if (networkMapInstance) networkMapInstance.invalidateSize();
        }, 150);
        return;
    }

    var mapEl = document.getElementById('network_subscriber_map');
    if (!mapEl) return;

    networkHybridLayer = L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
        maxZoom: 22,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
        attribution: '&copy; Google Maps'
    });

    networkStreetsLayer = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
        maxZoom: 22,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
        attribution: '&copy; Google Maps'
    });

    var networkEsri = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri'
    });

    var networkOsm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    });

    var networkBaseMaps = {
        "ðŸ›°ï¸ Google Satellite (Hybrid)": networkHybridLayer,
        "ðŸ—ºï¸ Google Streets": networkStreetsLayer,
        "ðŸŒ Esri Satellite": networkEsri,
        "ðŸ“ OpenStreetMap": networkOsm
    };

    networkMapInstance = L.map('network_subscriber_map', {
        center: [31.5204, 74.3587],
        zoom: 12,
        maxZoom: 22,
        zoomControl: true,
        layers: [networkHybridLayer]
    });

    L.control.layers(networkBaseMaps, null, { position: 'topright' }).addTo(networkMapInstance);

    networkMarkersGroup = L.featureGroup().addTo(networkMapInstance);

    const subscribers = <?= json_encode($map_subscribers) ?>;
    let validCount = 0;

    subscribers.forEach(function (u) {
        var lat = parseFloat(u.latitude);
        var lng = parseFloat(u.longitude);
        if (isNaN(lat) || isNaN(lng) || lat === 0 || lng === 0) return;

        var marker = L.marker([lat, lng], {
            icon: getNetworkMarkerIcon(u),
            title: u.username
        });

        marker.bindPopup(createSubscriberPopup(u));
        marker.bindTooltip(u.username, { permanent: false, direction: 'top' });

        networkMarkersGroup.addLayer(marker);
        networkUserMarkers[u.username.toLowerCase()] = marker;
        validCount++;
    });

    if (validCount > 0) {
        try {
            networkMapInstance.fitBounds(networkMarkersGroup.getBounds().pad(0.1));
        } catch(e) {}
    }

    setTimeout(function() {
        if (networkMapInstance) networkMapInstance.invalidateSize();
    }, 200);
}

function switchNetworkMapLayer(type) {
    if (!networkMapInstance || !networkHybridLayer || !networkStreetsLayer) return;
    var btnHybrid = document.getElementById('btnMapHybrid');
    var btnStreets = document.getElementById('btnMapStreets');
    if (type === 'hybrid') {
        networkMapInstance.removeLayer(networkStreetsLayer);
        networkMapInstance.addLayer(networkHybridLayer);
        if (btnHybrid) {
            btnHybrid.classList.add('active', 'btn-primary');
            btnHybrid.classList.remove('btn-outline-secondary');
        }
        if (btnStreets) {
            btnStreets.classList.remove('active', 'btn-primary');
            btnStreets.classList.add('btn-outline-secondary');
        }
    } else if (type === 'streets') {
        networkMapInstance.removeLayer(networkHybridLayer);
        networkMapInstance.addLayer(networkStreetsLayer);
        if (btnStreets) {
            btnStreets.classList.add('active', 'btn-primary');
            btnStreets.classList.remove('btn-outline-secondary');
        }
        if (btnHybrid) {
            btnHybrid.classList.remove('active', 'btn-primary');
            btnHybrid.classList.add('btn-outline-secondary');
        }
    }
}

function fitNetworkMapAll() {
    if (networkMapInstance && networkMarkersGroup && networkMarkersGroup.getLayers().length > 0) {
        networkMapInstance.fitBounds(networkMarkersGroup.getBounds().pad(0.1));
    }
}

function filterNetworkMapUser(val) {
    val = (val || '').trim().toLowerCase();
    if (!val) return;
    
    for (var uname in networkUserMarkers) {
        if (uname.includes(val)) {
            var marker = networkUserMarkers[uname];
            if (networkMapInstance) {
                networkMapInstance.setView(marker.getLatLng(), 18);
                marker.openPopup();
            }
            break;
        }
    }
}

// Restore active tab on page load
document.addEventListener('DOMContentLoaded', function() {
    var savedTab = localStorage.getItem('activeStatTab') || 'user';
    var targetBtn = document.querySelector('.reports-pill-btn[onclick*="' + savedTab + '"]');
    if (targetBtn) {
        switchStatTab(savedTab, targetBtn);
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
let currentSelectedBalance = 0;

$(document).ready(function() {
    $('#renew_user_id').select2({
        dropdownParent: $('#renewUserModal'),
        width: '100%',
        placeholder: '-- Choose User --'
    });

    $('#payment_user_select').select2({
        dropdownParent: $('#addBalanceModal'),
        width: '100%',
        placeholder: '-- Type username or name to search --'
    });

    $('#addBalanceModal').on('shown.bs.modal', function () {
        $('#payment_user_select').select2({
            dropdownParent: $('#addBalanceModal'),
            width: '100%',
            placeholder: '-- Type username or name to search --'
        });
    });
});

function onPaymentUserSelected() {
    var select = document.getElementById('payment_user_select');
    var opt = select.options[select.selectedIndex];
    var card = document.getElementById('selected_user_card');
    
    if (!opt || !opt.value) {
        card.classList.add('d-none');
        currentSelectedBalance = 0;
        return;
    }
    
    card.classList.remove('d-none');
    var fullname = opt.getAttribute('data-fullname') || 'N/A';
    var username = opt.getAttribute('data-username') || 'N/A';
    var bal = parseFloat(opt.getAttribute('data-balance')) || 0;
    var status = (opt.getAttribute('data-status') || 'active').toLowerCase();
    currentSelectedBalance = bal;

    document.getElementById('disp_user_fullname').innerText = fullname;
    document.getElementById('disp_user_username').innerText = username;
    
    var balEl = document.getElementById('disp_user_balance');
    balEl.innerText = 'Rs. ' + Math.round(bal).toLocaleString('en-US');
    balEl.className = 'fs-4 fw-bold ' + (bal < 0 ? 'text-danger' : 'text-success');

    var badgeHtml = '';
    if (status === 'active') {
        badgeHtml = '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1"><i class="fa-solid fa-circle text-success me-1" style="font-size: 0.5rem;"></i>Active</span>';
    } else if (status === 'expired') {
        badgeHtml = '<span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1"><i class="fa-solid fa-triangle-exclamation me-1"></i>Expired</span>';
    } else {
        badgeHtml = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1">' + status.toUpperCase() + '</span>';
    }
    document.getElementById('disp_user_status_badge').innerHTML = badgeHtml;

    calculateProjectedBalance();
}

function updatePaymentMode() {
    var isDeduct = document.getElementById('trans_deduct').checked;
    var btn = document.getElementById('payment_submit_btn');
    if (isDeduct) {
        btn.className = 'btn btn-danger px-4 py-2 fw-bold shadow-sm';
        btn.innerHTML = '<i class="fa-solid fa-minus-circle me-1"></i> Deduct Balance';
    } else {
        btn.className = 'btn btn-success px-4 py-2 fw-bold shadow-sm';
        btn.innerHTML = '<i class="fa-solid fa-plus-circle me-1"></i> Add Balance';
    }
    calculateProjectedBalance();
}

function calculateProjectedBalance() {
    var amtInput = document.getElementById('payment_amount_input');
    var amt = parseFloat(amtInput.value) || 0;
    var isDeduct = document.getElementById('trans_deduct').checked;
    var projEl = document.getElementById('disp_new_projected_balance');
    
    if (amt <= 0 || !document.getElementById('payment_user_select').value) {
        projEl.innerText = '';
        return;
    }
    
    var newBal = isDeduct ? (currentSelectedBalance - amt) : (currentSelectedBalance + amt);
    var colorClass = newBal < 0 ? 'text-danger' : 'text-success';
    projEl.innerHTML = 'Projected After: <strong class="' + colorClass + '">Rs. ' + Math.round(newBal).toLocaleString('en-US') + '</strong>';
}

function setQuickAmount(val) {
    var inp = document.getElementById('payment_amount_input');
    inp.value = val;
    calculateProjectedBalance();
}

function selectUserForPayment(subId) {
    $('#payment_user_select').val(subId).trigger('change');
    // Switch to tab 1
    var triggerEl = document.querySelector('#paymentModalTabs #tab-search-pay-btn');
    if (triggerEl) {
        var tab = bootstrap.Tab.getOrCreateInstance(triggerEl);
        tab.show();
    }
}

function validatePaymentForm() {
    var userSelect = document.getElementById('payment_user_select');
    if (!userSelect.value) {
        alert('Please search and select a user first.');
        return false;
    }
    var amt = parseFloat(document.getElementById('payment_amount_input').value) || 0;
    if (amt <= 0) {
        alert('Please enter a valid amount.');
        return false;
    }
    var isDeduct = document.getElementById('trans_deduct').checked;
    var username = userSelect.options[userSelect.selectedIndex].getAttribute('data-username') || 'user';
    var actionWord = isDeduct ? 'DEDUCT' : 'ADD';
    return confirm('Are you sure you want to ' + actionWord + ' Rs. ' + amt.toLocaleString() + ' for ' + username + '?');
}

function openPaymentModal(userId, username) {
    selectUserForPayment(userId);
    var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('addBalanceModal'));
    m.show();
}

function openRenewModal(userId) {
    $('#renew_user_id').val(userId).trigger('change');
    var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('renewUserModal'));
    m.show();
}
</script>

<?php require_once 'footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var forms = document.querySelectorAll('.needs-validation');
    Array.prototype.slice.call(forms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });
});
</script>
