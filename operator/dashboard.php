<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Auto-Sync & Block Expired Users (runs once every 60 seconds max to maintain blazing performance)
if (!isset($_SESSION['last_expiry_sync']) || (time() - $_SESSION['last_expiry_sync']) > 60) {
    require_once __DIR__ . '/../config/radius_kick_helper.php';
    sync_expired_subscribers($pdo, $client_id, true);
    $_SESSION['last_expiry_sync'] = time();
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
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Created New User (Default: Expired - Awaiting Renewal)')")
                    ->execute([$client_id, $username]);

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
                $desc = !empty($custom_desc) ? $custom_desc : ($final_amount > 0 ? 'Manual Balance Added' : 'Manual Balance Deducted');

                $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, description, balance_after) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$client_id, $sub_user, $type, $absAmount, $desc, $finalBal]);
                
                $actStr = $final_amount > 0 ? "Added Balance: Rs. " . number_format($absAmount, 0) . " ($desc)" : "Deducted Balance: Rs. " . number_format($absAmount, 0) . " ($desc)";
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', ?)")
                    ->execute([$client_id, $sub_user, $actStr]);
                
                $pdo->commit();
                echo "<script>alert('Balance successfully updated for user " . addslashes($sub_user) . "!'); window.location='dashboard.php';</script>";
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

// Fetch packages for the dropdown
$packages = $pdo->query("SELECT id, name, rate_limit FROM packages WHERE client_id = $client_id")->fetchAll();

// Fetch total balance of all users
$total_balance_all = $pdo->query("SELECT SUM(balance) FROM subscribers WHERE client_id = $client_id")->fetchColumn() ?: 0;

// Fetch all subscribers for payment and modals (Searchable)
$allPaymentUsers = $pdo->query("SELECT id, username, full_name, status, balance, expiry_date, package_id FROM subscribers WHERE client_id = $client_id ORDER BY username ASC")->fetchAll();

// --- Data Fetching for Metrics ---
$total_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id")->fetchColumn();

// Base metrics for cards
// Expired: Date is strictly in the past, excluding zero-dates
$expired = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date < NOW() AND expiry_date > '2000-01-01'")->fetchColumn();
// Active: Status is not disabled, and date is in future OR is null/zero
$active_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(status) != 'disabled' AND (expiry_date >= NOW() OR expiry_date IS NULL OR expiry_date < '2000-01-01')")->fetchColumn();
$disabled_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(status) = 'disabled'")->fetchColumn();

// Online Users
$online_users = $pdo->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL AND username IN (SELECT username FROM subscribers WHERE client_id = $client_id)")->fetchColumn();
$offline_users = max(0, $total_users - $online_users);
$expired_online_users = $pdo->query("SELECT COUNT(DISTINCT s.username) FROM subscribers s JOIN radacct r ON s.username = r.username COLLATE utf8mb4_general_ci WHERE s.client_id = $client_id AND r.acctstoptime IS NULL AND s.expiry_date < NOW() AND s.expiry_date > '2000-01-01'")->fetchColumn() ?: 0;

// Mutually Exclusive Slices for the Doughnut Chart
$chart_online = $online_users;
$chart_expired = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date < NOW() AND expiry_date > '2000-01-01' AND username NOT IN (SELECT username FROM radacct WHERE acctstoptime IS NULL)")->fetchColumn();
$chart_disabled = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(status) = 'disabled' AND username NOT IN (SELECT username FROM radacct WHERE acctstoptime IS NULL)")->fetchColumn();
$chart_active = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(status) != 'disabled' AND (expiry_date >= NOW() OR expiry_date IS NULL OR expiry_date < '2000-01-01') AND username NOT IN (SELECT username FROM radacct WHERE acctstoptime IS NULL)")->fetchColumn();
$chart_others = max(0, $total_users - ($chart_online + $chart_expired + $chart_disabled + $chart_active));

// Expirations (Detailed)
$expiring_1d = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)")->fetchColumn();
$expiring_3d = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetchColumn();
$expiring_1w = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$expiring_2w = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)")->fetchColumn();

// Services
$pppoe_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(service_type) = 'pppoe'")->fetchColumn();
$hotspot_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND LOWER(service_type) = 'hotspot'")->fetchColumn();

// Helpers
$pct = function($val) use ($total_users) {
    if ($total_users == 0) return "0.00%";
    return number_format(($val / $total_users) * 100, 2) . "%";
};

// --- Data Fetching for Reports Table ---
$report_filter = $_GET['report'] ?? 'expired';
$where = "s.client_id = $client_id";
if ($report_filter === 'expired') $where .= " AND s.expiry_date < NOW()";
if ($report_filter === 'expiring_1') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)";
if ($report_filter === 'expiring_3') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)";
if ($report_filter === 'expiring_1w') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
if ($report_filter === 'expiring_2w') $where .= " AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)";
if ($report_filter === 'disabled') $where .= " AND s.status = 'disabled'";

$subs = $pdo->query("SELECT s.*, p.name as package_name, 
    (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
    (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s 
    LEFT JOIN packages p ON s.package_id = p.id 
    WHERE $where ORDER BY s.id DESC")->fetchAll();

// Fetch subscribers with coordinates for Network Map view
$map_subscribers = $pdo->query("
    SELECT s.id, s.username, s.full_name, s.mobile, s.status, s.expiry_date, s.latitude, s.longitude, s.address, s.subarea,
           p.name as package_name,
           (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
           (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s 
    LEFT JOIN packages p ON s.package_id = p.id 
    WHERE s.client_id = $client_id 
      AND s.latitude IS NOT NULL AND s.latitude != ''
      AND s.longitude IS NOT NULL AND s.longitude != ''
")->fetchAll(PDO::FETCH_ASSOC);

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
        border-radius: 18px;
        padding: 22px 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .donut-container-pro {
        position: relative;
        width: 100%;
        max-width: 230px;
        margin: 10px auto;
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
        font-size: 2.1rem;
        color: #0f172a;
        line-height: 1;
    }
    .donut-center-pro p {
        margin: 3px 0 0;
        font-size: 0.8rem;
        color: #64748b;
        font-weight: 600;
    }

    /* 12 Metric Cards Grid */
    .metrics-12-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
    }
    @media(max-width: 991px) {
        .metrics-12-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media(max-width: 575px) {
        .metrics-12-grid { grid-template-columns: 1fr; }
    }

    .metric-card-pro {
        border-radius: 16px;
        padding: 16px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
        cursor: pointer;
        transition: transform 0.18s ease, box-shadow 0.18s ease;
        min-height: 86px;
    }
    .metric-card-pro:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .metric-card-pro .metric-icon-wrap {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        color: #ffffff;
        flex-shrink: 0;
        margin-right: 14px;
    }
    .metric-card-pro .metric-title {
        font-size: 0.82rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 2px;
    }
    .metric-card-pro .metric-val {
        font-size: 1.45rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.2;
    }
    .metric-card-pro .metric-pct {
        font-size: 0.72rem;
        font-weight: 700;
    }
    .metric-card-pro .metric-watermark {
        position: absolute;
        right: 14px;
        font-size: 2.3rem;
        pointer-events: none;
        opacity: 0.35;
    }

    /* Reports Tabs & DataTables */
    .report-tabs { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px; }
    .report-tab { text-decoration: none; color: #64748b; font-weight: 600; font-size: 0.88rem; padding: 7px 18px; border-radius: 20px; display: flex; align-items: center; gap: 8px; transition: 0.2s; background: #f8fafc; border: 1px solid #e2e8f0; }
    .report-tab:hover { background: #e2e8f0; color: #1e293b; }
    .report-tab.active { background: #1e293b; color: #fff; border-color: #1e293b; }

    .table-custom-ui { border-radius: 8px; overflow: hidden; }
    .table-custom-ui thead th { background-color: #f8f9fa; color: #64748b; border-bottom: 2px solid #e5e7eb; font-weight: 600; font-size: 0.88rem; white-space: nowrap; }
    .table-custom-ui tbody td { vertical-align: middle; border-bottom: 1px solid #e5e7eb; color: #334155; font-size: 0.88rem; }
    .dt-buttons .btn { background-color: #475569; border: none; color: #fff; border-radius: 20px; padding: 4px 14px; font-size: 0.85rem; margin-right: 4px; }
    .dataTables_filter input { background-color: #ffffff; border: 1px solid #ced4da; border-radius: 20px; padding: 4px 15px; }
    .avatar-circle { width: 40px; height: 40px; background-color: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 1.2rem; }
    .badge-soft-success { background-color: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
    .badge-soft-secondary { background-color: rgba(148, 163, 184, 0.15); color: #64748b; border: 1px solid rgba(148, 163, 184, 0.2); }
    .badge-soft-primary { background-color: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.2); }
    .badge-soft-warning { background-color: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.2); }
    .select2-container .select2-selection--single { height: 31px; border: 1px solid #dee2e6; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 29px; color: #475569; font-size: 0.875rem; }
    .select2-dropdown { border: 1px solid #dee2e6; }
    
    /* Network Map Styles */
    .custom-network-marker { background: transparent !important; border: none !important; }
    .leaflet-popup-content-wrapper { border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.18); }
    .leaflet-popup-content { margin: 14px 16px; line-height: 1.4; }
</style>

<!-- Dashboard Header -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-dark mb-1">Admin Dashboard</h3>
        <p class="text-secondary small mb-0">Manage your network, users and services</p>
    </div>
    
    <div class="d-flex align-items-center gap-3">
        <!-- Live System Time Capsule -->
        <div class="d-flex align-items-center bg-white px-3 py-2 rounded-pill shadow-sm border">
            <i class="fa-regular fa-clock fs-5 text-primary me-2"></i>
            <div>
                <div class="text-muted" style="font-size: 0.68rem; font-weight: 600; text-transform: uppercase;">Live System Time (<?= date_default_timezone_get() ?>)</div>
                <div id="live-server-clock" class="fw-bold text-primary" style="font-size: 0.95rem; font-family: monospace;">Loading...</div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var tz = "<?= date_default_timezone_get() ?>";
    function updateClock() {
        var now = new Date();
        var timeOptions = { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
        var timeString = now.toLocaleTimeString('en-US', timeOptions);
        var dateOptions = { timeZone: tz, day: 'numeric', month: 'short', year: 'numeric' };
        var dateString = now.toLocaleDateString('en-GB', dateOptions);
        var clockEl = document.getElementById('live-server-clock');
        if(clockEl) {
            clockEl.innerHTML = '<span class="text-dark">' + dateString + '</span> &nbsp;|&nbsp; ' + timeString;
        }
    }
    updateClock();
    setInterval(updateClock, 1000);
})();
</script>

<!-- Top 7 Quick Actions -->
<div class="quick-actions-grid mb-4">
    <!-- 1. My Profile -->
    <a href="profile" class="quick-action-card">
        <div class="quick-action-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
            <i class="fa-solid fa-user"></i>
        </div>
        <div class="quick-action-text">My Profile</div>
    </a>

    <!-- 2. All Packages -->
    <a href="packages" class="quick-action-card">
        <div class="quick-action-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
            <i class="fa-solid fa-box-open"></i>
        </div>
        <div class="quick-action-text">All Packages</div>
    </a>

    <!-- 3. Add Payment -->
    <a href="#" class="quick-action-card" data-bs-toggle="modal" data-bs-target="#addBalanceModal">
        <div class="quick-action-icon" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9);">
            <i class="fa-solid fa-credit-card"></i>
        </div>
        <div class="quick-action-text">Add Payment</div>
    </a>

    <!-- 4. Add New User -->
    <a href="#" class="quick-action-card" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <div class="quick-action-icon" style="background: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <i class="fa-solid fa-user-plus"></i>
        </div>
        <div class="quick-action-text">Add New User</div>
    </a>

    <!-- 5. Activate/Renew -->
    <a href="#" class="quick-action-card" data-bs-toggle="modal" data-bs-target="#renewUserModal">
        <div class="quick-action-icon" style="background: linear-gradient(135deg, #f97316, #c2410c);">
            <i class="fa-solid fa-bolt"></i>
        </div>
        <div class="quick-action-text">Activate/Renew</div>
    </a>

    <?php if (empty($_SESSION['is_manager'])): ?>
    <!-- 6. Backup (CSV) -->
    <a href="backup_users" class="quick-action-card">
        <div class="quick-action-icon" style="background: linear-gradient(135deg, #14b8a6, #0f766e);">
            <i class="fa-solid fa-download"></i>
        </div>
        <div class="quick-action-text">Backup (CSV)</div>
    </a>

    <!-- 7. Restore CSV -->
    <a href="#" class="quick-action-card" data-bs-toggle="modal" data-bs-target="#restoreModal">
        <div class="quick-action-icon" style="background: linear-gradient(135deg, #6366f1, #4338ca);">
            <i class="fa-solid fa-cloud-arrow-up"></i>
        </div>
        <div class="quick-action-text">Restore CSV</div>
    </a>

    <!-- 8. IP Pool -->
    <a href="ip_pools" class="quick-action-card">
        <div class="quick-action-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
            <i class="fa-solid fa-network-wired"></i>
        </div>
        <div class="quick-action-text">IP Pool</div>
    </a>
    <?php endif; ?>

    <!-- Net Worth -->
    <a href="net_worth" class="quick-action-card">
        <div class="quick-action-icon" style="background: linear-gradient(135deg, #10b981, #047857);">
            <i class="fa-solid fa-chart-line"></i>
        </div>
        <div class="quick-action-text">Net Worth</div>
    </a>

    <!-- Expenses -->
    <a href="expenses" class="quick-action-card">
        <div class="quick-action-icon" style="background: linear-gradient(135deg, #ef4444, #b91c1c);">
            <i class="fa-solid fa-receipt"></i>
        </div>
        <div class="quick-action-text">Expenses</div>
    </a>
</div>

<!-- Reports & Statistics Card -->
<div class="card-ui p-4">
    <!-- Section Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center">
            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-3" style="width: 42px; height: 42px; font-size: 1.15rem;">
                <i class="fa-solid fa-chart-pie"></i>
            </div>
            <div>
                <h5 class="fw-bold text-dark mb-0">Reports & Statistics</h5>
                <div class="text-secondary small">Quick overview of your network performance</div>
            </div>
        </div>

        <div class="dropdown mt-2 mt-md-0">
            <button class="btn btn-sm btn-white bg-white border rounded-pill px-3 py-2 fw-semibold text-secondary d-flex align-items-center gap-2 shadow-sm" type="button">
                <i class="fa-regular fa-calendar"></i> Last 30 Days <i class="fa-solid fa-chevron-down small"></i>
            </button>
        </div>
    </div>
    
    <!-- Sub-navigation Pills -->
    <div class="d-flex flex-wrap gap-2 mb-4 pb-2 border-bottom">
        <a href="javascript:void(0)" onclick="switchStatTab('user', this)" class="reports-pill-btn active"><i class="fa-solid fa-user me-2"></i> User</a>
        <a href="javascript:void(0)" onclick="switchStatTab('accounting', this)" class="reports-pill-btn"><i class="fa-solid fa-dollar-sign me-1"></i> Accounting</a>
        <a href="javascript:void(0)" onclick="switchStatTab('usage', this)" class="reports-pill-btn"><i class="fa-solid fa-chart-line me-1"></i> Usage</a>
        <a href="javascript:void(0)" onclick="switchStatTab('map', this)" class="reports-pill-btn"><i class="fa-solid fa-map-location-dot me-1"></i> Map</a>
    </div>

    <!-- User Tab Content -->
    <div id="stat-content-user" class="row g-4">
        <!-- Left: Donut Chart Card -->
        <div class="col-12 col-lg-3">
            <div class="all-users-donut-card">
                <div>
                    <!-- Header -->
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

                    <!-- Donut Chart -->
                    <div class="donut-container-pro">
                        <canvas id="usersDonut"></canvas>
                        <div class="donut-center-pro">
                            <h3><?= number_format($total_users) ?></h3>
                            <p>All Users</p>
                            <i class="fa-solid fa-users text-primary" style="font-size: 0.9rem;"></i>
                        </div>
                    </div>
                </div>

                <!-- Legend -->
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

        <!-- Right: 12 Metrics Grid (3 columns x 4 rows) -->
        <div class="col-12 col-lg-9">
            <div class="metrics-12-grid">
                <!-- 1. Users -->
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
                    <div class="metric-watermark" style="color: #60a5fa;">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                </div>

                <!-- 2. Active -->
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
                    <div class="metric-watermark" style="color: #4ade80;">
                        <i class="fa-solid fa-arrow-trend-up"></i>
                    </div>
                </div>

                <!-- 3. Online -->
                <div class="metric-card-pro" style="background-color: #ecfdf5; border: 1px solid #d1fae5;" onclick="window.location.href='live_sessions.php'" title="View live sessions">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #059669;">
                            <i class="fa-solid fa-wifi"></i>
                        </div>
                        <div>
                            <div class="metric-title">Online</div>
                            <div class="metric-val"><?= number_format($online_users) ?></div>
                            <div class="metric-pct" style="color: #059669;"><?= $pct($online_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #34d399;">
                        <i class="fa-solid fa-wifi"></i>
                    </div>
                </div>

                <!-- 4. Expired Online -->
                <div class="metric-card-pro" style="background-color: #fffbeb; border: 1px solid #fef3c7;" onclick="window.location.href='live_sessions.php?filter=expired_online'" title="View users online with expired package">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #d97706;">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expired Online</div>
                            <div class="metric-val"><?= number_format($expired_online_users) ?></div>
                            <div class="metric-pct" style="color: #d97706;"><?= $pct($expired_online_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #fbbf24;">
                        <i class="fa-regular fa-clock"></i>
                    </div>
                </div>

                <!-- 5. Offline -->
                <div class="metric-card-pro" style="background-color: #f8fafc; border: 1px solid #e2e8f0;" onclick="window.location.href='subscribers.php?filter=offline'" title="View offline subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #475569;">
                            <i class="fa-solid fa-user-xmark"></i>
                        </div>
                        <div>
                            <div class="metric-title">Offline</div>
                            <div class="metric-val"><?= number_format($offline_users) ?></div>
                            <div class="metric-pct" style="color: #64748b;"><?= $pct($offline_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #94a3b8;">
                        <i class="fa-solid fa-user-slash"></i>
                    </div>
                </div>

                <!-- 6. Registered -->
                <div class="metric-card-pro" style="background-color: #faf5ff; border: 1px solid #f3e8ff;" onclick="window.location.href='subscribers.php'" title="View registered subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #7c3aed;">
                            <i class="fa-solid fa-user-plus"></i>
                        </div>
                        <div>
                            <div class="metric-title">Registered</div>
                            <div class="metric-val"><?= number_format($total_users) ?></div>
                            <div class="metric-pct" style="color: #7c3aed;">100.00%</div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #a855f7;">
                        <i class="fa-solid fa-users-gear"></i>
                    </div>
                </div>

                <!-- 7. Disable -->
                <div class="metric-card-pro" style="background-color: #f1f5f9; border: 1px solid #e2e8f0;" onclick="window.location.href='?report=disabled#reportsSection'" title="View disabled users">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #334155;">
                            <i class="fa-solid fa-user-lock"></i>
                        </div>
                        <div>
                            <div class="metric-title">Disable</div>
                            <div class="metric-val"><?= number_format($disabled_users) ?></div>
                            <div class="metric-pct" style="color: #64748b;"><?= $pct($disabled_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #94a3b8;">
                        <i class="fa-solid fa-ban"></i>
                    </div>
                </div>

                <!-- 8. Expired -->
                <div class="metric-card-pro" style="background-color: #fff1f2; border: 1px solid #ffe4e6;" onclick="window.location.href='?report=expired#reportsSection'" title="View expired users">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #e11d48;">
                            <i class="fa-solid fa-circle-exclamation"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expired</div>
                            <div class="metric-val"><?= number_format($expired) ?></div>
                            <div class="metric-pct" style="color: #e11d48;"><?= $pct($expired) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #fb7185;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                </div>

                <!-- 9. PPPoE -->
                <div class="metric-card-pro" style="background-color: #eef2ff; border: 1px solid #e0e7ff;" onclick="window.location.href='subscribers.php'" title="View PPPoE subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #4f46e5;">
                            <i class="fa-solid fa-diagram-project"></i>
                        </div>
                        <div>
                            <div class="metric-title">PPPoE</div>
                            <div class="metric-val"><?= number_format($pppoe_users) ?></div>
                            <div class="metric-pct" style="color: #4f46e5;"><?= $pct($pppoe_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #818cf8;">
                        <i class="fa-solid fa-sitemap"></i>
                    </div>
                </div>

                <!-- 10. Hotspot -->
                <div class="metric-card-pro" style="background-color: #fdf4ff; border: 1px solid #fae8ff;" onclick="window.location.href='subscribers.php'" title="View Hotspot subscribers">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #c026d3;">
                            <i class="fa-solid fa-wifi"></i>
                        </div>
                        <div>
                            <div class="metric-title">Hotspot</div>
                            <div class="metric-val"><?= number_format($hotspot_users) ?></div>
                            <div class="metric-pct" style="color: #c026d3;"><?= $pct($hotspot_users) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #e879f9;">
                        <i class="fa-solid fa-tower-broadcast"></i>
                    </div>
                </div>

                <!-- 11. Expiring (1 Day) -->
                <div class="metric-card-pro" style="background-color: #fff1f2; border: 1px solid #ffe4e6;" onclick="window.location.href='?report=expiring_1#reportsSection'" title="Expiring within 1 day">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #f43f5e;">
                            <i class="fa-solid fa-hourglass-end"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expiring (1 Day)</div>
                            <div class="metric-val"><?= number_format($expiring_1d) ?></div>
                            <div class="metric-pct" style="color: #f43f5e;"><?= $pct($expiring_1d) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #fb7185;">
                        <i class="fa-regular fa-clock"></i>
                    </div>
                </div>

                <!-- 12. Expiring (3 Days) -->
                <div class="metric-card-pro" style="background-color: #faf5ff; border: 1px solid #f3e8ff;" onclick="window.location.href='?report=expiring_3#reportsSection'" title="Expiring within 3 days">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #9333ea;">
                            <i class="fa-solid fa-hourglass-half"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expiring (3 Days)</div>
                            <div class="metric-val"><?= number_format($expiring_3d) ?></div>
                            <div class="metric-pct" style="color: #9333ea;"><?= $pct($expiring_3d) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #c084fc;">
                        <i class="fa-regular fa-hourglass"></i>
                    </div>
                </div>

                <!-- 13. Expiring (1 Week) -->
                <div class="metric-card-pro" style="background-color: #fff7ed; border: 1px solid #ffedd5;" onclick="window.location.href='?report=expiring_1w#reportsSection'" title="Expiring within 1 week">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #ea580c;">
                            <i class="fa-solid fa-calendar-days"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expiring (1 Week)</div>
                            <div class="metric-val"><?= number_format($expiring_1w) ?></div>
                            <div class="metric-pct" style="color: #ea580c;"><?= $pct($expiring_1w) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #fb923c;">
                        <i class="fa-regular fa-calendar-check"></i>
                    </div>
                </div>

                <!-- 14. Expiring (2 Weeks) -->
                <div class="metric-card-pro" style="background-color: #ecfeff; border: 1px solid #cffafe;" onclick="window.location.href='?report=expiring_2w#reportsSection'" title="Expiring within 2 weeks">
                    <div class="d-flex align-items-center">
                        <div class="metric-icon-wrap" style="background-color: #0891b2;">
                            <i class="fa-solid fa-calendar-week"></i>
                        </div>
                        <div>
                            <div class="metric-title">Expiring (2 Weeks)</div>
                            <div class="metric-val"><?= number_format($expiring_2w) ?></div>
                            <div class="metric-pct" style="color: #0891b2;"><?= $pct($expiring_2w) ?></div>
                        </div>
                    </div>
                    <div class="metric-watermark" style="color: #22d3ee;">
                        <i class="fa-regular fa-calendar-days"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Usage Tab Content -->
    <div id="stat-content-usage" class="row d-none">
        <div class="col-12 text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="text-muted mt-2 small">Loading Usage Statistics...</div>
        </div>
    </div>

    <!-- Accounting Tab Content -->
    <div id="stat-content-accounting" class="row d-none">
        <div class="col-12 text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="text-muted mt-2 small">Loading Accounting Statistics...</div>
        </div>
    </div>

    <!-- Map Tab Content -->
    <div id="stat-content-map" class="row d-none">
        <div class="col-12">
            <!-- Map Header & Controls Card -->
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
                        <button type="button" class="btn btn-primary active fw-semibold" id="btnMapHybrid" onclick="switchNetworkMapLayer('hybrid')" title="Google Satellite View with Street & House Details"><i class="fa-solid fa-satellite me-1"></i> Satellite</button>
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

            <!-- Full Leaflet Map View -->
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
                    <td><?= $s['id'] ?></td>
                    <td><div class="avatar-circle"><i class="fa-solid fa-user"></i></div></td>
                    <td>
                        <a href="subscriber_view.php?id=<?= $s['id'] ?>">
                            <span class="badge rounded-pill badge-soft-success px-3 py-2"><?= htmlspecialchars($s['username']) ?></span>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($s['mobile'] ?: ($s['phone'] ?: 'N/A')) ?></td>
                    <td><?= htmlspecialchars($s['package_name'] ?? 'N/A') ?></td>
                    <td>shabir1</td> <!-- Placeholder seller -->
                    <td><span class="badge rounded-pill badge-soft-warning px-3 py-2"><?= number_format($s['balance']) ?></span></td>
                    <td><span class="badge rounded-pill badge-soft-primary px-3 py-2 fw-bold"><?= strtoupper(htmlspecialchars($s['service_type'])) ?></span></td>
                    <td>
                        <?php if($s['is_online'] > 0): ?>
                            <div class="d-flex flex-column align-items-center gap-1">
                                <span class="badge rounded-pill badge-soft-success px-3 py-1">Online</span>
                                <small class="text-muted font-monospace" style="font-size: 0.75rem;"><?= htmlspecialchars($s['live_ip']) ?></small>
                            </div>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-2">Offline</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($s['expiry_date']): ?>
                            <?php if(strtotime($s['expiry_date']) < time()): ?>
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <span class="badge rounded-pill bg-danger text-white fw-bold px-3 py-1 text-uppercase" style="letter-spacing: 0.5px;">Expired</span>
                                    <small class="text-muted font-monospace" style="font-size: 0.70rem;"><?= date('d M Y', strtotime($s['expiry_date'])) ?></small>
                                </div>
                            <?php else: ?>
                                <span class="badge rounded-pill badge-soft-success px-3 py-2"><?= date('d M Y H:i:s', strtotime($s['expiry_date'])) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-2">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-column gap-1">
                            <a href="#" onclick="openPaymentModal(<?= $s['id'] ?>, '<?= addslashes(htmlspecialchars($s['username'])) ?>'); return false;" class="badge rounded-pill badge-soft-primary text-decoration-none px-3 py-2"><i class="fa-brands fa-paypal"></i> Payment</a>
                            <a href="#" onclick="openRenewModal(<?= $s['id'] ?>); return false;" class="badge rounded-pill badge-soft-success text-decoration-none px-3 py-2"><i class="fa-solid fa-rotate"></i> Renew</a>
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
                    <td class="fw-bold text-primary"><?= htmlspecialchars($s['username']) ?></td>
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
      <form action="renew_user_action.php" method="POST">
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

    // Donut Chart initialization
    const ctx = document.getElementById('usersDonut').getContext('2d');
    new Chart(ctx, {
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

function switchStatTab(tabName, el) {
    // Save to localStorage
    localStorage.setItem('activeStatTab', tabName);

    // Update active class on all pills
    document.querySelectorAll('.reports-pill-btn').forEach(function(tab) {
        tab.classList.remove('active');
    });
    if (el) {
        el.classList.add('active');
    }

    var userContent = document.getElementById('stat-content-user');
    var usageContainer = document.getElementById('stat-content-usage');
    var accContainer = document.getElementById('stat-content-accounting');
    var mapContainer = document.getElementById('stat-content-map');

    if (userContent) userContent.classList.add('d-none');
    if (usageContainer) usageContainer.classList.add('d-none');
    if (accContainer) accContainer.classList.add('d-none');
    if (mapContainer) mapContainer.classList.add('d-none');

    // Toggle content visibility
    if(tabName === 'user') {
        if (userContent) userContent.classList.remove('d-none');
    } else if(tabName === 'usage') {
        if (usageContainer) {
            usageContainer.classList.remove('d-none');
            // Fetch via AJAX
            usageContainer.innerHTML = '<div class="col-12 text-center py-5"><div class="spinner-border text-primary" role="status"></div><div class="text-muted mt-2 small">Loading Usage Statistics...</div></div>';
            fetch('ajax_usage_stats.php')
                .then(res => res.text())
                .then(html => {
                    usageContainer.innerHTML = html;
                })
                .catch(err => {
                    usageContainer.innerHTML = '<div class="col-12 text-center py-5 text-danger">Failed to load statistics.</div>';
                });
        }
    } else if(tabName === 'accounting') {
        if (accContainer) {
            accContainer.classList.remove('d-none');
            // Fetch via AJAX
            accContainer.innerHTML = '<div class="col-12 text-center py-5"><div class="spinner-border text-primary" role="status"></div><div class="text-muted mt-2 small">Loading Accounting Statistics...</div></div>';
            fetch('ajax_accounting_stats.php')
                .then(res => res.text())
                .then(html => {
                    accContainer.innerHTML = html;
                })
                .catch(err => {
                    accContainer.innerHTML = '<div class="col-12 text-center py-5 text-danger">Failed to load statistics.</div>';
                });
        }
    } else if(tabName === 'map') {
        if (mapContainer) {
            mapContainer.classList.remove('d-none');
            initNetworkSubscriberMap();
        }
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
                    <a href="${profileUrl}" class="fw-bold fs-6 text-primary text-decoration-none" title="Click to view profile">
                        <i class="fa-solid fa-user me-1"></i> ${escapeHtml(u.username)}
                    </a>
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
