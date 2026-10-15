<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Handle Actions (Add, Renew, Delete, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // MASS ACTIONS
    if ($action === 'mass_delete' && !empty($_POST['selected_ids'])) {
        $ids = explode(',', $_POST['selected_ids']);
        foreach($ids as $id) {
            $del = (int)$id;
            $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
            $uStmt->execute([$del, $client_id]);
            $u = $uStmt->fetchColumn();
            if ($u) {
                $pdo->prepare("DELETE FROM subscribers WHERE id = ?")->execute([$del]);
                $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
                $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
            }
        }
        echo "<script>alert('Selected subscribers deleted successfully!'); window.location='subscribers.php';</script>";
        exit;
    }

    if ($action === 'mass_payment' && !empty($_POST['selected_ids'])) {
        $ids = explode(',', $_POST['selected_ids']);
        $amount = (float)$_POST['amount'];
        if ($amount != 0) {
            foreach($ids as $id) {
                $sub_id = (int)$id;
                $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
                $uStmt->execute([$sub_id, $client_id]);
                if ($uStmt->fetchColumn()) {
                    $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);
                }
            }
            echo "<script>alert('Mass Payment applied successfully!'); window.location='subscribers.php';</script>";
            exit;
        }
    }

    if ($action === 'mass_renew' && !empty($_POST['selected_ids'])) {
        $ids = explode(',', $_POST['selected_ids']);
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        if ($expiry_date) {
            foreach($ids as $id) {
                $sub_id = (int)$id;
                $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
                $uStmt->execute([$sub_id, $client_id]);
                if ($uStmt->fetchColumn()) {
                    $pdo->prepare("UPDATE subscribers SET expiry_date = ? WHERE id = ?")->execute([$expiry_date, $sub_id]);
                }
            }
            echo "<script>alert('Mass Expiry updated successfully!'); window.location='subscribers.php';</script>";
            exit;
        }
    }

    
    // SINGLE PAYMENT
    if ($action === 'single_payment') {
        $id = (int)$_POST['id'];
        $amount = (float)$_POST['amount'];
        if ($amount != 0) {
            $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
            $uStmt->execute([$id, $client_id]);
            if ($uStmt->fetchColumn()) {
                $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $id]);
                echo "<script>alert('Payment applied successfully!'); window.location='subscribers.php';</script>";
                exit;
            }
        }
    }

    // DELETE
    if ($action === 'delete_id' && isset($_POST['id'])) {
        $del = (int)$_POST['id'];
        $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
        $uStmt->execute([$del, $client_id]);
        $u = $uStmt->fetchColumn();
        if ($u) {
            $pdo->prepare("DELETE FROM subscribers WHERE id = ?")->execute([$del]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
            echo "<script>alert('Subscriber deleted successfully!'); window.location='subscribers.php';</script>";
            exit;
        }
    }

    // RENEW USER / SET EXPIRY
    if ($action === 'renew_user') {
        $id = (int)$_POST['id'];
        $package_id = (int)$_POST['package_id'];
        $expiry_date = $_POST['expiry_date']; // format: YYYY-MM-DDTHH:MM

        $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
        $uStmt->execute([$id, $client_id]);
        $u = $uStmt->fetchColumn();

        if ($u) {
            $p = $pdo->prepare("SELECT p.name, p.rate_limit, p.price, p.pool_id, pl.pool_name FROM packages p LEFT JOIN ip_pools pl ON p.pool_id = pl.id WHERE p.id = ? AND p.client_id = ?");
            $p->execute([$package_id, $client_id]);
            $pkg = $p->fetch();

            if ($pkg) {
                try {
                    $pdo->beginTransaction();
                    
                    // Deduct balance if user belongs to a dealer (Handling Upgrades & Renewals)
                    $dStmt = $pdo->prepare("SELECT dealer_id, package_id as old_package_id, expiry_date as old_expiry FROM subscribers WHERE id = ?");
                    $dStmt->execute([$id]);
                    $subData = $dStmt->fetch(PDO::FETCH_ASSOC);
                    $sub_dealer_id = $subData['dealer_id'] ?? 0;
                    
                    if ($sub_dealer_id > 0) {
                        // 1. Calculate Old Package Remaining Value
                        $old_remaining_value = 0;
                        if (!empty($subData['old_expiry']) && strtotime($subData['old_expiry']) > time()) {
                            $old_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                            $old_dpStmt->execute([$sub_dealer_id, $subData['old_package_id']]);
                            $old_dp_price = $old_dpStmt->fetchColumn();
                            if ($old_dp_price === false) {
                                $opq = $pdo->prepare("SELECT price FROM packages WHERE id = ?");
                                $opq->execute([$subData['old_package_id']]);
                                $old_dp_price = $opq->fetchColumn() ?: 0;
                            }
                            $old_seconds = strtotime($subData['old_expiry']) - time();
                            $old_days = $old_seconds / 86400; // Exact fractional days
                            $old_price_per_day = $old_dp_price / 30;
                            $old_remaining_value = $old_days * $old_price_per_day;
                        }

                        // 2. Calculate New Package Total Value
                        $new_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                        $new_dpStmt->execute([$sub_dealer_id, $package_id]);
                        $new_dp_price = $new_dpStmt->fetchColumn();
                        if ($new_dp_price === false) {
                            $new_dp_price = $pkg['price'] ?? 0;
                        }
                        
                        $new_total_value = 0;
                        $new_seconds = strtotime($expiry_date) - time();
                        if ($new_seconds > 0) {
                            $new_days = $new_seconds / 86400; // Exact fractional days
                            $new_price_per_day = $new_dp_price / 30;
                            $new_total_value = $new_days * $new_price_per_day;
                        }

                        // 3. Net Deduction
                        $net_deduction = round($new_total_value - $old_remaining_value, 2);

                        if ($net_deduction > 0) {
                            // Deduct from dealer
                            $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $sub_dealer_id]);
                            
                            $note = "Package Upgrade/Renew for user $u. Total Cost: Rs." . round($new_total_value, 2) . ", Old Credit: Rs." . round($old_remaining_value, 2) . ". Net Deducted: Rs. $net_deduction";
                            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
                        } else {
                            // Downgrade or unused credit covers it. No deduction.
                            $note = "Package Downgrade/Change for user $u. Total Cost: Rs." . round($new_total_value, 2) . " covered by Old Credit Rs." . round($old_remaining_value, 2) . ". No balance deducted.";
                            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
                        }
                    }

                    $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active' WHERE id = ?")->execute([$package_id, $expiry_date, $id]);
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
                    }
                    
                    // NEVER inject Mikrotik-Address-List (prevents 700 dynamic entries and WAN hijacking)
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$u]);
                    $target_profile = !empty($pkg['pool_name']) ? $pkg['pool_name'] : '';
                    if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                        if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg['name'], $m_prof)) {
                            $target_profile = $m_prof[1] . 'Mbps';
                        }
                    }
                    if (!empty($target_profile)) {
                        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$u]);
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$u, $target_profile]);
                    }
                    $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
                    
                    $act_dealer_id = ($sub_dealer_id > 0) ? $sub_dealer_id : null;
                    $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Admin', ?, 'User', 'Package Renewed & Expiry Updated')")->execute([$client_id, $act_dealer_id, $u]);
                    $pdo->commit();
                    echo "<script>alert('Subscriber renewed and expiry updated successfully!'); window.location='subscribers.php';</script>";
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo "<script>alert('Error renewing user.');</script>";
                }
            }
        }
    }

    // ADD USER (From Modal)
    if ($action === 'add_user') {
        $dealer_id = isset($_POST['dealer_id']) ? (int)$_POST['dealer_id'] : 0;
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $full_name = trim($_POST['full_name']);
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $package_id = (int)$_POST['package_id'];
        $service_type = $_POST['service_type'];
        $national_id = trim($_POST['national_id']);
        $mobile = trim($_POST['mobile']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        $subarea = trim($_POST['subarea']);
        $latitude = trim($_POST['latitude']);
        $longitude = trim($_POST['longitude']);

        $clientStmt = $pdo->prepare("SELECT max_subscribers FROM clients WHERE id = ?");
        $clientStmt->execute([$client_id]);
        $max_sub = $clientStmt->fetchColumn();

        $curStmt = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ?");
        $curStmt->execute([$client_id]);
        if ($curStmt->fetchColumn() >= $max_sub) {
            echo "<script>alert('Subscriber Quota Exceeded!');</script>";
        } else {
            $p = $pdo->prepare("SELECT p.name, p.rate_limit, p.price, p.pool_id, pl.pool_name FROM packages p LEFT JOIN ip_pools pl ON p.pool_id = pl.id WHERE p.id = ? AND p.client_id = ?");
            $p->execute([$package_id, $client_id]);
            $pkg = $p->fetch();

            if ($pkg) {
                try {
                    $pdo->beginTransaction();
                    
                    if ($dealer_id > 0) {
                        // Find price
                        $dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                        $dpStmt->execute([$dealer_id, $package_id]);
                        $dp_price = $dpStmt->fetchColumn();
                        if ($dp_price === false) {
                            $dp_price = $pkg['price'] ?? 0;
                        }
                        
                        $deduction = 0;
                        if ($expiry_date) {
                            $seconds = strtotime($expiry_date) - time();
                            if ($seconds > 0) {
                                $days = ceil($seconds / 86400);
                                $price_per_day = $dp_price / 30;
                                $deduction = round($days * $price_per_day, 2);
                            }
                        } else {
                            $deduction = $dp_price; // Standard 1 month deduction
                        }
                        
                        if ($deduction > 0) {
                            $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$deduction, $dealer_id]);
                            $note = "Created user $username. Deducted Rs. $deduction";
                            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                        }
                    }

                    $pdo->prepare("INSERT INTO subscribers (client_id, dealer_id, package_id, username, password, service_type, full_name, national_id, mobile, phone, email, address, subarea, latitude, longitude, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$client_id, $dealer_id > 0 ? $dealer_id : null, $package_id, $username, $password, $service_type, $full_name, $national_id, $mobile, $phone, $email, $address, $subarea, $latitude, $longitude, $expiry_date]);
                    
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")
                        ->execute([$username, $password]);
                    
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")
                            ->execute([$username, $pkg['rate_limit']]);
                    }

                    // NEVER inject Mikrotik-Address-List (prevents 700 dynamic entries and WAN hijacking)
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$username]);
                    $target_profile = !empty($pkg['pool_name']) ? $pkg['pool_name'] : '';
                    if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                        if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg['name'], $m_prof)) {
                            $target_profile = $m_prof[1] . 'Mbps';
                        }
                    }
                    if (!empty($target_profile)) {
                        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$username]);
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$username, $target_profile]);
                    }
                    
                    $act_dealer_id = ($dealer_id > 0) ? $dealer_id : null;
                    $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Admin', ?, 'User', 'Created New User')")->execute([$client_id, $act_dealer_id, $username]);
                    
                    $pdo->commit();
                    echo "<script>alert('User created successfully!'); window.location='subscribers.php';</script>";
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    echo "<script>alert('Error: Username might already exist.');</script>";
                }
            }
        }
    }
}

// Fetch Dealers for Dropdown
$dealerStmt = $pdo->prepare("SELECT id, username, full_name, balance FROM dealers WHERE client_id = ? AND status = 'active'");
$dealerStmt->execute([$client_id]);
$dealers = $dealerStmt->fetchAll();

// Fetch Packages for Dropdown
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ?");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll();

// Advanced Fetch for Export Data
// Handle Filter Logic
$filter = $_GET['filter'] ?? '';
$filter_sql = "";
$params = [$client_id];

if ($filter === 'active') {
    $filter_sql = " AND s.status = 'active'";
} elseif ($filter === 'expired') {
    $filter_sql = " AND (s.status = 'expired' OR (s.expiry_date IS NOT NULL AND s.expiry_date < NOW()))";
} elseif ($filter === 'expiring_1w') {
    $filter_sql = " AND (s.expiry_date IS NOT NULL AND s.expiry_date >= NOW() AND s.expiry_date <= DATE_ADD(NOW(), INTERVAL 7 DAY))";
} elseif ($filter === 'expiring_2w') {
    $filter_sql = " AND (s.expiry_date IS NOT NULL AND s.expiry_date >= NOW() AND s.expiry_date <= DATE_ADD(NOW(), INTERVAL 14 DAY))";
} elseif ($filter === 'suspended') {
    $filter_sql = " AND s.status = 'suspended'";
} elseif ($filter === 'online') {
    $filter_sql = " AND EXISTS (SELECT 1 FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL)";
} elseif ($filter === 'offline') {
    $filter_sql = " AND NOT EXISTS (SELECT 1 FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL)";
}

$sql = "SELECT s.*, p.name as package_name, 
        (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
        (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip,
        (SELECT MAX(acctstarttime) FROM radacct r WHERE r.username = s.username) as last_on_time,
        (SELECT MAX(acctstoptime) FROM radacct r WHERE r.username = s.username) as last_off_time,
        (SELECT SUM(acctinputoctets + acctoutputoctets) FROM radacct r WHERE r.username = s.username) as total_usage_bytes,
        (SELECT SUM(acctsessiontime) FROM radacct r WHERE r.username = s.username) as total_time_sec,
        (SELECT nasipaddress FROM radacct r WHERE r.username = s.username ORDER BY radacctid DESC LIMIT 1) as nas_ip
        FROM subscribers s 
        LEFT JOIN packages p ON s.package_id = p.id 
        WHERE s.client_id = ? $filter_sql
        ORDER BY s.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subs = $stmt->fetchAll();

function formatUptime($seconds) {
    if (!$seconds) return '0d 00h 00m 00s';
    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf("%dd %02dh %02dm %02ds", $d, $h, $m, $s);
}

// Stats for quick filter capsules
$count_all = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id")->fetchColumn() ?: 0;
$count_online = $pdo->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL AND username IN (SELECT username FROM subscribers WHERE client_id = $client_id)")->fetchColumn() ?: 0;
$count_expired = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id AND expiry_date < NOW() AND expiry_date > '2000-01-01'")->fetchColumn() ?: 0;
$count_offline = max(0, $count_all - $count_online);
?>
<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    body { background-color: #f8fafc; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    
    .card-ui { 
        background: #ffffff; 
        border-radius: 20px; 
        box-shadow: 0 4px 20px rgba(0,0,0,0.03); 
        border: 1px solid #eef2f6; 
    }

    /* Filter Pills */
    .filter-pill-btn {
        border-radius: 50px;
        padding: 7px 18px;
        font-weight: 600;
        font-size: 0.86rem;
        border: 1px solid #e2e8f0;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #ffffff;
        color: #64748b;
    }
    .filter-pill-btn:hover {
        background: #f1f5f9;
        color: #1e293b;
        border-color: #cbd5e1;
    }
    .filter-pill-btn.active {
        background: #2563eb !important;
        color: #ffffff !important;
        border-color: #2563eb !important;
        box-shadow: 0 4px 12px rgba(37,99,235,0.22);
    }
    .filter-pill-btn .pill-count {
        background: rgba(0,0,0,0.06);
        padding: 2px 7px;
        border-radius: 20px;
        font-size: 0.75rem;
    }
    .filter-pill-btn.active .pill-count {
        background: rgba(255,255,255,0.25);
        color: #ffffff;
    }

    /* Action Buttons */
    .btn-action-pro {
        border-radius: 12px;
        padding: 9px 16px;
        font-weight: 600;
        font-size: 0.88rem;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
        box-shadow: 0 2px 6px rgba(0,0,0,0.03);
    }
    .btn-action-pro:hover {
        transform: translateY(-1px);
        box-shadow: 0 5px 14px rgba(0,0,0,0.08);
    }
    .btn-action-primary {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #ffffff;
    }
    .btn-action-primary:hover { color: #ffffff; background: linear-gradient(135deg, #1d4ed8, #1e40af); }
    .btn-action-success {
        background: linear-gradient(135deg, #10b981, #059669);
        color: #ffffff;
    }
    .btn-action-success:hover { color: #ffffff; }
    .btn-action-purple {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        color: #ffffff;
    }
    .btn-action-purple:hover { color: #ffffff; }
    .btn-action-secondary {
        background: #ffffff;
        color: #334155;
        border-color: #e2e8f0;
    }
    .btn-action-secondary:hover { background: #f8fafc; color: #0f172a; border-color: #cbd5e1; }
    .btn-action-danger {
        background: #fee2e2;
        color: #dc2626;
        border-color: #fecaca;
    }
    .btn-action-danger:hover { background: #fca5a5; color: #b91c1c; }

    /* Premium DataTables Styling */
    #usersTable {
        border-collapse: separate !important;
        border-spacing: 0 8px !important;
    }
    #usersTable thead th {
        background: #f8fafc !important;
        color: #64748b !important;
        font-size: 0.75rem !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.8px !important;
        padding: 12px 14px !important;
        border: none !important;
        white-space: nowrap !important;
    }
    #usersTable thead th:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
    #usersTable thead th:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
    
    #usersTable tbody tr {
        background: #ffffff !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02) !important;
        border-radius: 14px !important;
        transition: transform 0.18s ease, box-shadow 0.18s ease !important;
    }
    #usersTable tbody tr:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 6px 18px rgba(0,0,0,0.06) !important;
    }
    #usersTable tbody td {
        border-top: 1px solid #f1f5f9 !important;
        border-bottom: 1px solid #f1f5f9 !important;
        border-left: none !important;
        border-right: none !important;
        vertical-align: middle !important;
        padding: 12px 14px !important;
        background: transparent !important;
        font-size: 0.9rem !important;
    }
    #usersTable tbody td:first-child {
        border-left: 1px solid #f1f5f9 !important;
        border-top-left-radius: 14px !important;
        border-bottom-left-radius: 14px !important;
    }
    #usersTable tbody td:last-child {
        border-right: 1px solid #f1f5f9 !important;
        border-top-right-radius: 14px !important;
        border-bottom-right-radius: 14px !important;
    }

    /* Avatars */
    .avatar-pro {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
        background: linear-gradient(135deg, #eff6ff, #dbeafe);
        color: #2563eb;
        box-shadow: 0 2px 6px rgba(37,99,235,0.08);
    }

    /* Badges */
    .badge-soft-success { background-color: #ecfdf5 !important; color: #059669 !important; border: 1px solid #a7f3d0 !important; font-weight: 600; }
    .badge-soft-secondary { background-color: #f1f5f9 !important; color: #475569 !important; border: 1px solid #e2e8f0 !important; font-weight: 600; }
    .badge-soft-primary { background-color: #eff6ff !important; color: #2563eb !important; border: 1px solid #bfdbfe !important; font-weight: 600; }
    .badge-soft-warning { background-color: #fffbeb !important; color: #d97706 !important; border: 1px solid #fde68a !important; font-weight: 600; }
    .badge-soft-danger { background-color: #fef2f2 !important; color: #dc2626 !important; border: 1px solid #fecaca !important; font-weight: 600; }

    /* DataTables Buttons & Filter */
    .dt-buttons .btn {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        color: #475569 !important;
        border-radius: 50px !important;
        padding: 5px 14px !important;
        font-size: 0.82rem !important;
        font-weight: 600 !important;
        margin-right: 5px !important;
        transition: all 0.2s ease !important;
    }
    .dt-buttons .btn:hover {
        background: #f1f5f9 !important;
        color: #0f172a !important;
        border-color: #cbd5e1 !important;
    }
    .dataTables_filter input {
        border-radius: 50px !important;
        border: 1px solid #e2e8f0 !important;
        padding: 6px 16px !important;
        font-size: 0.88rem !important;
        background: #ffffff !important;
        box-shadow: 0 1px 4px rgba(0,0,0,0.02) !important;
        outline: none !important;
    }
    .dataTables_filter input:focus {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12) !important;
    }
    .dataTables_length select {
        border-radius: 10px !important;
        border: 1px solid #e2e8f0 !important;
        padding: 5px 10px !important;
        font-size: 0.86rem !important;
    }

    /* Modal Styling */
    .light-modal {
        border-radius: 20px !important;
        border: 1px solid #eef2f6 !important;
        box-shadow: 0 10px 40px rgba(0,0,0,0.1) !important;
        overflow: hidden;
    }
    .light-modal .modal-header {
        background: #f8fafc !important;
        border-bottom: 1px solid #eef2f6 !important;
        padding: 18px 24px !important;
    }
    .light-modal .modal-title { color: #0f172a; font-size: 1.15rem; font-weight: 700; }
    .light-modal .modal-body { padding: 24px !important; }
    .light-modal .modal-footer {
        background: #f8fafc !important;
        border-top: 1px solid #eef2f6 !important;
        padding: 16px 24px !important;
    }
    .light-modal .col-form-label {
        color: #475569;
        font-weight: 600;
        font-size: 0.86rem;
    }
    .light-modal .form-control, .light-modal .form-select {
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        color: #1e293b;
        padding: 0.5rem 0.85rem;
    }
    .light-modal .form-control:focus, .light-modal .form-select:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
    .light-modal .btn-submit {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
        border: none;
        padding: 8px 24px;
        border-radius: 50px;
        font-weight: 600;
    }
    .light-modal .btn-submit:hover {
        background: linear-gradient(135deg, #1d4ed8, #1e40af);
        color: #fff;
    }
</style>

<!-- Top Header Section -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div class="d-flex align-items-center">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 48px; height: 48px; font-size: 1.25rem; background: linear-gradient(135deg, #2563eb, #1d4ed8);">
            <i class="fa-solid fa-users"></i>
        </div>
        <div>
            <h4 class="fw-bold text-dark mb-0">Subscribers & Users</h4>
            <div class="text-secondary small">Manage subscribers, packages, renewals and payments</div>
        </div>
    </div>
    
    <!-- Top Action Buttons -->
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-action-pro btn-action-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fa-solid fa-user-plus"></i> Add New User
        </button>
        <button type="button" class="btn btn-action-pro btn-action-success shadow-sm" onclick="openMassRenewModal()">
            <i class="fa-solid fa-user-check"></i> Mass Renew
        </button>
        <button type="button" class="btn btn-action-pro btn-action-purple shadow-sm" onclick="openMassPaymentModal()">
            <i class="fa-brands fa-paypal"></i> Mass Payment
        </button>
        <button type="button" class="btn btn-action-pro btn-action-secondary shadow-sm" data-bs-toggle="modal" data-bs-target="#restoreModal">
            <i class="fa-solid fa-file-import"></i> Import CSV
        </button>
        <button type="button" class="btn btn-action-pro btn-action-danger shadow-sm" onclick="submitMassAction('delete')">
            <i class="fa-solid fa-trash"></i> Mass Delete
        </button>
    </div>
</div>

<!-- Filter Pills Row -->
<div class="d-flex flex-wrap gap-2 mb-4">
    <a href="subscribers.php" class="filter-pill-btn <?= empty($filter) ? 'active' : '' ?>">
        <i class="fa-solid fa-users"></i> All Users <span class="pill-count"><?= number_format($count_all) ?></span>
    </a>
    <a href="subscribers.php?filter=online" class="filter-pill-btn <?= $filter==='online' ? 'active' : '' ?>">
        <i class="fa-solid fa-wifi"></i> Online <span class="pill-count"><?= number_format($count_online) ?></span>
    </a>
    <a href="subscribers.php?filter=offline" class="filter-pill-btn <?= $filter==='offline' ? 'active' : '' ?>">
        <i class="fa-solid fa-user-slash"></i> Offline <span class="pill-count"><?= number_format($count_offline) ?></span>
    </a>
    <a href="subscribers.php?filter=expired" class="filter-pill-btn <?= $filter==='expired' ? 'active' : '' ?>">
        <i class="fa-solid fa-circle-exclamation"></i> Expired <span class="pill-count"><?= number_format($count_expired) ?></span>
    </a>
    <a href="subscribers.php?filter=expiring_1w" class="filter-pill-btn <?= $filter==='expiring_1w' ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-week"></i> Expiring (1 Week)
    </a>
    <a href="subscribers.php?filter=expiring_2w" class="filter-pill-btn <?= $filter==='expiring_2w' ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-days"></i> Expiring (2 Weeks)
    </a>
</div>

<!-- Main Table Card Container -->
<div class="card-ui p-4 mb-4 position-relative">
    <!-- Loading State Dialog -->
    <div id="usersLoadingBox" class="text-center py-5">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <h5 class="fw-bold text-dark mt-3 mb-1">Loading Subscribers...</h5>
        <div class="text-secondary small">Please wait while user records are being initialized.</div>
    </div>

    <!-- Table Container (Initially Hidden) -->
    <div id="usersTableContainer" class="table-responsive" style="display: none;">
        <table id="usersTable" class="table table-hover w-100">
            <thead>
                <tr>
                    <th style="width: 40px;"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                    <th>#ID</th>
                    <th>Photo</th>
                    <th>Username</th>
                    <th>Password</th>
                    <th>Full Name</th>
                    <th>NID</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>Package</th>
                    <th>Balance</th>
                    <th>Service</th>
                    <th>Status</th>
                    <th>On/Off Time</th>
                    <th>Usage / Time</th>
                    <th>Expiry Date</th>
                    <th>NAS IP</th>
                    <th>Created</th>
                    <th style="min-width: 140px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($subs as $s): 
                    $usage_gb = $s['total_usage_bytes'] > 0 ? round($s['total_usage_bytes'] / 1073741824, 2) : 0.00;
                    $uptime_fmt = formatUptime($s['total_time_sec']);
                    $on_time = $s['last_on_time'] ? date('Y-m-d H:i:s', strtotime($s['last_on_time'])) : 'N/A';
                    $off_time = $s['last_off_time'] ? date('Y-m-d H:i:s', strtotime($s['last_off_time'])) : 'N/A';
                ?>
                <tr>
                    <td><input type="checkbox" class="row-checkbox form-check-input" value="<?= $s['id'] ?>"></td>
                    <td class="fw-semibold text-secondary">#<?= $s['id'] ?></td>
                    <td>
                        <div class="avatar-pro">
                            <i class="fa-solid fa-user"></i>
                        </div>
                    </td>
                    <td>
                        <a href="subscriber_view.php?id=<?= $s['id'] ?>" class="text-decoration-none">
                            <span class="badge rounded-pill badge-soft-primary px-3 py-2 fw-bold" style="font-size: 0.88rem;">
                                <i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($s['username']) ?>
                            </span>
                        </a>
                    </td>
                    <td class="font-monospace small"><?= htmlspecialchars($s['password']) ?></td>
                    <td class="fw-medium text-dark"><?= htmlspecialchars($s['full_name']) ?></td>
                    <td><?= htmlspecialchars($s['national_id']) ?></td>
                    <td><?= htmlspecialchars($s['mobile'] ?: ($s['phone'] ?: 'N/A')) ?></td>
                    <td><?= htmlspecialchars($s['address']) ?></td>
                    <td>
                        <span class="fw-bold text-dark"><?= htmlspecialchars($s['package_name'] ?? 'N/A') ?></span>
                        <br><span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.7rem;">Local</span>
                    </td>
                    <td>
                        <span class="badge rounded-pill badge-soft-warning px-3 py-2 fw-bold">Rs. <?= number_format($s['balance'], 2) ?></span>
                    </td>
                    <td>
                        <span class="badge rounded-pill badge-soft-secondary px-3 py-2 text-uppercase fw-bold" style="font-size: 0.78rem;">
                            <?= htmlspecialchars($s['service_type']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if($s['is_online'] > 0): ?>
                            <div class="d-flex flex-column gap-1">
                                <span class="badge rounded-pill badge-soft-success px-3 py-1">
                                    <i class="fa-solid fa-circle text-success me-1" style="font-size: 0.55rem;"></i> Online
                                </span>
                                <small class="text-muted font-monospace" style="font-size: 0.75rem;"><?= htmlspecialchars($s['live_ip']) ?></small>
                            </div>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-1">
                                <i class="fa-regular fa-circle text-muted me-1" style="font-size: 0.55rem;"></i> Offline
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small class="text-muted d-block font-monospace" style="font-size: 0.75rem;"><?= $on_time ?></small>
                        <small class="text-muted d-block font-monospace" style="font-size: 0.75rem;"><?= $off_time ?></small>
                    </td>
                    <td>
                        <div class="fw-bold text-dark"><?= $usage_gb ?> GB</div>
                        <small class="text-muted font-monospace" style="font-size: 0.75rem;"><?= $uptime_fmt ?></small>
                    </td>
                    <td>
                        <?php if($s['expiry_date']): ?>
                            <?php if(strtotime($s['expiry_date']) < time()): ?>
                                <div class="d-flex flex-column gap-1">
                                    <span class="badge rounded-pill badge-soft-danger px-3 py-1 fw-bold text-uppercase" style="letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-triangle-exclamation me-1"></i> Expired
                                    </span>
                                    <small class="text-muted font-monospace" style="font-size: 0.70rem;"><?= date('d M Y h:i A', strtotime($s['expiry_date'])) ?></small>
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-1">
                                    <span class="badge rounded-pill badge-soft-success px-3 py-1">
                                        <i class="fa-regular fa-clock me-1"></i> <?= date('d M Y', strtotime($s['expiry_date'])) ?>
                                    </span>
                                    <small class="text-muted font-monospace" style="font-size: 0.70rem;"><?= date('h:i A', strtotime($s['expiry_date'])) ?></small>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-1">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td><small class="font-monospace text-muted"><?= htmlspecialchars($s['nas_ip'] ?: 'N/A') ?></small></td>
                    <td><small class="font-monospace text-muted"><?= $s['created_at'] ? date('Y-m-d', strtotime($s['created_at'])) : 'N/A' ?></small></td>
                    <td>
                        <div class="d-flex flex-column gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1 w-100 btn-payment fw-bold" style="font-size: 0.78rem;" data-id="<?= $s['id'] ?>" data-username="<?= htmlspecialchars($s['username']) ?>" data-balance="<?= number_format($s['balance'], 2) ?>">
                                <i class="fa-brands fa-paypal me-1"></i> Payment
                            </button>
                            <div class="d-flex gap-1">
                                <!-- Trigger Renew Modal -->
                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-2 py-1 flex-grow-1 btn-renew fw-bold" style="font-size: 0.78rem;"
                                    data-id="<?= $s['id'] ?>" 
                                    data-username="<?= htmlspecialchars($s['username']) ?>" 
                                    data-pkg="<?= $s['package_id'] ?>" 
                                    data-expiry="<?= $s['expiry_date'] ? date('Y-m-d\TH:i', strtotime($s['expiry_date'])) : '' ?>">
                                    <i class="fa-solid fa-rotate me-1"></i> Renew
                                </button>
                                
                                <form action="subscriber_action.php" method="POST" class="d-inline" onsubmit="return confirm('Disconnect this user now?');">
                                    <input type="hidden" name="action" value="kick">
                                    <input type="hidden" name="username" value="<?= htmlspecialchars($s['username']) ?>">
                                    <button type="submit" class="btn btn-sm btn-light border text-success rounded-circle p-0" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;" title="Live Kick"><i class="fa-solid fa-bolt" style="font-size: 0.75rem;"></i></button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user permanently?');">
                                    <input type="hidden" name="action" value="delete_id">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-light border text-danger rounded-circle p-0" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;" title="Delete"><i class="fa-solid fa-trash" style="font-size: 0.75rem;"></i></button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->

<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content light-modal">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-user-plus text-primary"></i> Add New User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body p-4">
            
            <div class="add-user-flat-form">
                
                <!-- Account Info -->
                <div class="mb-4">
            <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-user"></i> Account Information
                        </h6>
            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Full Name</label>
                                    <input type="text" name="full_name" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">National ID (CNIC)</label>
                                    <input type="text" name="national_id" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" name="username" class="form-control" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" name="password" id="genPassword" class="form-control" required>
                                        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('genPassword').value = Math.random().toString(36).slice(-8);"><i class="fa-solid fa-shuffle"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
        <!-- Service Info -->
        <div class="mb-4">
            <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-wifi"></i> Service & Package
                        </h6>
            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Service Type</label>
                                    <select name="service_type" class="form-select">
                                        <option value="pppoe">PPPoE</option>
                                        <option value="hotspot">Hotspot</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Select Package <span class="text-danger">*</span></label>
                                    <select name="package_id" class="form-select" required>
                                        <option value="">Choose...</option>
                                        <?php foreach($packages as $p): ?>
                                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['rate_limit']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Assign Dealer (Optional)</label>
                                    <select name="dealer_id" class="form-select">
                                        <option value="">None</option>
                                        <?php foreach($dealers as $d): ?>
                                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (Bal: <?= $d['balance'] ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Custom Expiry (Optional)</label>
                                    <input type="datetime-local" name="expiry_date" class="form-control">
                                    <small class="text-muted" style="font-size: 0.75rem;">If dealer selected, balance deducts by days.</small>
                                </div>
                            </div>
                        </div>
        <!-- Contact Info -->
        <div class="mb-4">
            <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-address-book"></i> Contact & Location
                        </h6>
            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Mobile</label>
                                    <input type="text" name="mobile" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Email</label>
                                    <input type="email" name="email" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Area / City</label>
                                    <input type="text" name="subarea" class="form-control">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="col-form-label">Full Address</label>
                                    <input type="text" name="address" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Latitude</label>
                                    <input type="text" name="latitude" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="col-form-label">Longitude</label>
                                    <input type="text" name="longitude" class="form-control">
                                </div>
                            </div>
                        </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-submit">Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- Renew Modal (Simplified for display) -->
<div class="modal fade" id="renewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content light-modal">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-rotate text-success"></i> Renew User / Set Expiry</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="renew_user">
        <input type="hidden" name="id" id="renew_user_id">
        <div class="modal-body p-4">
            <p><strong>Username:</strong> <span id="renew_username_display" class="badge bg-success text-white px-2 py-1 ms-2"></span></p>
            
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary">Change Package</label>
                <select name="package_id" id="renew_package_id" class="form-select" required>
                    <option value="">Select Package</option>
                    <?php foreach($packages as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['rate_limit']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold text-secondary">Set Expiry Date & Time <span class="text-danger">*</span></label>
                <input type="datetime-local" name="expiry_date" id="renew_expiry_date" class="form-control" required>
                <small class="text-muted">This will automatically enforce disconnection via FreeRADIUS when time reaches.</small>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-submit">Update & Renew</button>
        </div>
      </form>
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
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    var exportColumns = [1, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17]; // Everything except Checkbox, Photo, Action

    $('#usersTable').DataTable({
        dom: '<"row align-items-center mb-3"<"col-md-2"l><"col-md-6 dt-buttons"B><"col-md-4"f>>rtip',
        buttons: [
            { extend: 'print', exportOptions: { columns: exportColumns }, text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', exportOptions: { columns: exportColumns }, text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'pdf', exportOptions: { columns: exportColumns }, orientation: 'landscape', pageSize: 'LEGAL', text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'excel', exportOptions: { columns: exportColumns }, text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'csv', exportOptions: { columns: exportColumns }, text: '<i class="fa-solid fa-file-csv"></i> CSV' }
        ],
        order: [[1, 'desc']],
        columnDefs: [
            { orderable: false, targets: [0, 2, 18] }, // Disable sorting on checkbox, photo, actions
            { visible: false, targets: [4, 5, 6, 8, 13, 14, 16, 17] } // Hide extra info from UI to save space, but they will still be exported!
        ],
        pageLength: 50,
        scrollX: true,
        language: {
            search: "Search:",
            searchPlaceholder: "Type & Submit",
            emptyTable: '<div class="text-center py-4"><i class="fa-solid fa-users-slash fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No subscribers found.</div></div>',
            zeroRecords: '<div class="text-center py-4"><i class="fa-solid fa-magnifying-glass fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No matching subscribers found.</div></div>'
        },
        initComplete: function() {
            $('#usersLoadingBox').fadeOut(120, function() {
                $('#usersTableContainer').fadeIn(180, function() {
                    $('#usersTable').DataTable().columns.adjust().draw();
                });
            });
        }
    });

    $('#selectAll').on('click', function(){
        var rows = $('#usersTable').DataTable().rows({ 'search': 'applied' }).nodes();
        $('input[type="checkbox"]', rows).prop('checked', this.checked);
    });

    // Robust DataTables Modal Triggers
    $('#usersTable tbody').on('click', '.btn-renew', function(e) {
        e.preventDefault();
        $('#renew_user_id').val($(this).attr('data-id'));
        $('#renew_username_display').text($(this).attr('data-username'));
        $('#renew_package_id').val($(this).attr('data-pkg'));
        var exp = $(this).attr('data-expiry');
        $('#renew_expiry_date').val(exp ? exp : '');
        var m = new bootstrap.Modal(document.getElementById('renewModal'));
        m.show();
    });

    $('#usersTable tbody').on('click', '.btn-payment', function(e) {
        e.preventDefault();
        $('#pay_user_id').val($(this).attr('data-id'));
        $('#pay_username_display').text($(this).attr('data-username'));
        $('#pay_current_balance').text($(this).attr('data-balance'));
        var m = new bootstrap.Modal(document.getElementById('singlePaymentModal'));
        m.show();
    });
    
    // Append modals to body to prevent z-index backdrop bugs inside card containers
    $('.modal').appendTo('body');

});
</script>


<!-- Single Payment Modal -->
<div class="modal fade" id="singlePaymentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-white border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
      <div class="modal-header border-bottom p-4" style="background-color: #f8fafc; border-color: #e2e8f0 !important;">
        <h5 class="modal-title text-dark fw-bold"><i class="fa-brands fa-paypal text-primary me-2"></i> Add Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="single_payment">
        <input type="hidden" name="id" id="pay_user_id" value="">
        <div class="modal-body p-4">
            <div class="mb-4 text-center p-3 rounded" style="background-color: #f1f5f9; border: 1px dashed #cbd5e1;">
                <h5 class="text-primary fw-bold mb-1" id="pay_username_display">Username</h5>
                <div class="text-muted small">Current Balance: <span class="text-success fw-bold fs-6">Rs <span id="pay_current_balance">0.00</span></span></div>
            </div>
            <div class="mb-2">
                <label class="form-label text-dark fw-bold small text-uppercase" style="letter-spacing: 0.5px;">Amount to Add (Rs) <span class="text-danger">*</span></label>
                <div class="input-group shadow-sm" style="border-radius: 10px; overflow: hidden;">
                    <span class="input-group-text bg-light border-0 text-muted px-3 fw-bold">Rs</span>
                    <input type="number" step="1" name="amount" class="form-control bg-light border-0 px-3 py-2 text-dark fs-5 fw-bold" placeholder="e.g. 500" required>
                </div>
                <small class="text-muted mt-2 d-block"><i class="fa-solid fa-circle-info me-1"></i>Use negative amount to deduct balance.</small>
            </div>
        </div>
        <div class="modal-footer border-top p-3" style="background-color: #f8fafc; border-color: #e2e8f0 !important;">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"><i class="fa-solid fa-check me-1"></i> Apply Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- Mass Payment Modal -->
<div class="modal fade" id="massPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-brands fa-paypal text-primary"></i> Mass Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="mass_payment">
                <input type="hidden" name="selected_ids" id="massPaymentIds">
                <div class="modal-body">
                    <p>Apply balance change to <strong id="massPaymentCount">0</strong> selected users.</p>
                    <div class="mb-3">
                        <label class="form-label">Amount (Rs.) <small class="text-muted">Use negative for deduction</small></label>
                        <input type="number" step="0.01" name="amount" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply Mass Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mass Renew Modal -->
<div class="modal fade" id="massRenewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-user-check text-success"></i> Mass Activation / Renew</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="mass_renew">
                <input type="hidden" name="selected_ids" id="massRenewIds">
                <div class="modal-body">
                    <p>Set a new expiry date for <strong id="massRenewCount">0</strong> selected users.</p>
                    <div class="mb-3">
                        <label class="form-label">New Expiry Date & Time</label>
                        <input type="datetime-local" name="expiry_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Expiry</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Restore Backup / Import Modal -->
<div class="modal fade" id="restoreModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-upload text-warning"></i> Import / Restore Users (CSV)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="dashboard.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="restore_backup">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>How to use:</strong>
                        <ol class="mb-0 ps-3">
                            <li>Go to Dashboard and click <b>Backup (CSV)</b>.</li>
                            <li>Add/edit users in Excel.</li>
                            <li>Upload here to auto-update the system.</li>
                        </ol>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select CSV File</label>
                        <input type="file" name="backup_file" class="form-control" accept=".csv" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark">Import Users</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="massDeleteForm" method="POST" style="display:none;">
    <input type="hidden" name="action" value="mass_delete">
    <input type="hidden" name="selected_ids" id="massDeleteIds">
</form>

<script>
function getSelectedUserIds() {
    var ids = [];
    document.querySelectorAll('.row-checkbox:checked').forEach(function(cb) {
        ids.push(cb.value);
    });
    return ids;
}

function submitMassAction(actionType) {
    var ids = getSelectedUserIds();
    if (ids.length === 0) {
        alert("Please select at least one user!");
        return;
    }
    if (actionType === 'delete') {
        if (confirm("Are you sure you want to completely delete " + ids.length + " selected users?")) {
            document.getElementById('massDeleteIds').value = ids.join(',');
            document.getElementById('massDeleteForm').submit();
        }
    }
}

function openMassPaymentModal() {
    var ids = getSelectedUserIds();
    if (ids.length === 0) { alert("Please select at least one user!"); return; }
    $('#massPaymentIds').val(ids.join(','));
    $('#massPaymentCount').text(ids.length);
    $('#massPaymentModal').appendTo("body").modal('show');
}

function openMassRenewModal() {
    var ids = getSelectedUserIds();
    if (ids.length === 0) { alert("Please select at least one user!"); return; }
    $('#massRenewIds').val(ids.join(','));
    $('#massRenewCount').text(ids.length);
    $('#massRenewModal').appendTo("body").modal('show');
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
