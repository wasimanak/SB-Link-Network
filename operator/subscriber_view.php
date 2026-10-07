<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

if (!isset($_GET['id'])) {
    header("Location: subscribers.php");
    exit;
}
$id = (int)$_GET['id'];

require_once '../config/routeros_api.class.php';

function kick_user_mikrotik($pdo, $client_id, $username) {
    $stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
    $stmt->execute([$client_id]);
    $nas = $stmt->fetch();
    if ($nas) {
        $api = new RouterosAPI();
        $api->timeout = 2;
        if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
            // Kick from Hotspot
            $api->write('/ip/hotspot/active/print', false);
            $api->write('?user=' . $username, true);
            $hotspot = $api->read();
            if (!empty($hotspot) && isset($hotspot[0]['.id'])) {
                $api->write('/ip/hotspot/active/remove', false);
                $api->write('=.id=' . $hotspot[0]['.id'], true);
                $api->read();
            }
            // Kick from PPPoE
            $api->write('/ppp/active/print', false);
            $api->write('?name=' . $username, true);
            $ppp = $api->read();
            if (!empty($ppp) && isset($ppp[0]['.id'])) {
                $api->write('/ppp/active/remove', false);
                $api->write('=.id=' . $ppp[0]['.id'], true);
                $api->read();
            }
            $api->disconnect();
        }
    }
}

// --- HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
    $uStmt->execute([$id, $client_id]);
    $u = $uStmt->fetchColumn();

    if ($u) {
        if ($action === 'delete_user') {
            kick_user_mikrotik($pdo, $client_id, $u);
            $pdo->prepare("DELETE FROM subscribers WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
            echo "<script>alert('Profile deleted successfully!'); window.location='subscribers.php';</script>";
            exit;
        }
        elseif ($action === 'disconnect_user') {
            kick_user_mikrotik($pdo, $client_id, $u);
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'User Disconnected')")->execute([$client_id, $u]);
            echo "<script>alert('User Kicked Successfully!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'disable_net' || $action === 'profile_disable') {
            kick_user_mikrotik($pdo, $client_id, $u);
            $pdo->prepare("UPDATE subscribers SET status = 'disabled' WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$u]);
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Profile Disabled')")->execute([$client_id, $u]);
            echo "<script>alert('Internet Disabled & User Kicked!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'enable_net') {
            $pdo->prepare("UPDATE subscribers SET status = 'active' WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'")->execute([$u]);
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Profile Enabled')")->execute([$client_id, $u]);
            echo "<script>alert('Internet Enabled!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'change_password') {
            $new_pass = trim($_POST['new_password']);
            $pdo->prepare("UPDATE subscribers SET password = ? WHERE id = ?")->execute([$new_pass, $id]);
            $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$new_pass, $u]);
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Changed Password')")->execute([$client_id, $u]);
            echo "<script>alert('Password updated successfully!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
                elseif ($action === 'edit_profile') {
            $full_name = trim($_POST['full_name']);
            $national_id = trim($_POST['national_id']);
            $new_username = trim($_POST['username']);
            $password = trim($_POST['password']);
            $service_type = $_POST['service_type'];
            $package_id = (int)$_POST['package_id'];
            $dealer_id = !empty($_POST['dealer_id']) ? (int)$_POST['dealer_id'] : null;
            $expiry = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $mobile = trim($_POST['mobile']);
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            $subarea = trim($_POST['subarea']);
            $city = trim($_POST['city']);
            $gps_lat = trim($_POST['gps_lat']);
            $gps_lng = trim($_POST['gps_lng']);
            
            // Check if username is being changed and if it already exists
            if ($new_username !== $u) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE username = ?");
                $chk->execute([$new_username]);
                if ($chk->fetchColumn() > 0) {
                    echo "<script>alert('Error: Username already exists!'); window.location='subscriber_view.php?id=$id';</script>";
                    exit;
                }
            }

            $pdo->beginTransaction();
            
            // Update subscriber
            $pdo->prepare("UPDATE subscribers SET 
                full_name=?, national_id=?, username=?, password=?, service_type=?, 
                package_id=?, dealer_id=?, expiry_date=?, mobile=?, phone=?, email=?, 
                subarea=?, city=?, gps_lat=?, gps_lng=? WHERE id=?")
                ->execute([$full_name, $national_id, $new_username, $password, $service_type, 
                           $package_id, $dealer_id, $expiry, $mobile, $phone, $email, 
                           $subarea, $city, $gps_lat, $gps_lng, $id]);
            
            // Update RADIUS Cleartext-Password & Username
            if ($new_username !== $u) {
                // Changing username in radius is complex; typically we update the radcheck/radreply usernames
                $pdo->prepare("UPDATE radcheck SET username=? WHERE username=?")->execute([$new_username, $u]);
                $pdo->prepare("UPDATE radreply SET username=? WHERE username=?")->execute([$new_username, $u]);
                $pdo->prepare("UPDATE radusergroup SET username=? WHERE username=?")->execute([$new_username, $u]);
            }
            $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Cleartext-Password'")->execute([$password, $new_username]);
            
            // Update Package Group in FreeRADIUS
            $pkgStmt = $pdo->prepare("SELECT name FROM packages WHERE id = ?");
            $pkgStmt->execute([$package_id]);
            $pkg_name = $pkgStmt->fetchColumn();
            
            if ($pkg_name) {
                $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$new_username]);
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$new_username, $pkg_name]);
            }

            $pdo->commit();
            
            // If username changed, also kick the old user
            if ($new_username !== $u) {
                kick_user_mikrotik($pdo, $client_id, $u);
            }
            
            echo "<script>alert('Profile updated successfully!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'add_note') {
            $pdo->prepare("UPDATE subscribers SET notes=? WHERE id=?")->execute([$_POST['notes'], $id]);
            echo "<script>alert('Note saved!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        
        elseif ($action === 'upload_photo') {
            if (!isset($_FILES['profile_photo'])) {
                echo "<script>alert('No file uploaded.'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }
            if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
                $errCode = $_FILES['profile_photo']['error'];
                echo "<script>alert('Upload error code: $errCode. (1=Too large for PHP, 2=Too large for form, 3=Partial, 4=No file)'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }
            
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed)) {
                echo "<script>alert('Invalid file format: $ext. Allowed: JPG, PNG, GIF, WEBP'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }

            // Ensure directory exists
            $uploadDir = '../uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $filename = 'user_' . $id . '_' . time() . '.' . $ext;
            $dest = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest)) {
                
                // Safely add column if it doesn't exist
                try {
                    $pdo->exec("ALTER TABLE subscribers ADD COLUMN photo VARCHAR(255) DEFAULT NULL");
                } catch (PDOException $e) {
                    // Ignore, column likely exists
                }

                try {
                    // Delete old photo if exists
                    $oldStmt = $pdo->prepare('SELECT photo FROM subscribers WHERE id=?');
                    $oldStmt->execute([$id]);
                    $oldPhoto = $oldStmt->fetchColumn();
                    if ($oldPhoto && file_exists('../uploads/profiles/' . $oldPhoto)) {
                        unlink('../uploads/profiles/' . $oldPhoto);
                    }
                    
                    $pdo->prepare('UPDATE subscribers SET photo = ? WHERE id=?')->execute([$filename, $id]);
                    $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Updated Profile Photo')")->execute([$client_id, $u]);
                    
                    echo "<script>window.location='subscriber_view.php?id=$id';</script>";
                    exit;
                } catch (PDOException $e) {
                    $dbErr = addslashes($e->getMessage());
                    echo "<script>alert('Database error: $dbErr'); window.location='subscriber_view.php?id=$id';</script>";
                    exit;
                }
            } else {
                echo "<script>alert('Server error: Failed to save file. Check directory permissions for uploads/profiles/'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }
        }
        elseif ($action === 'add_balance') {
            $amount = (float)$_POST['amount'];
            if ($amount != 0) {
                // Get current balance before adding
                $bStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id=?");
                $bStmt->execute([$id]);
                $current_bal = (float)$bStmt->fetchColumn();
                $new_bal = $current_bal + $amount;
                
                $pdo->prepare("UPDATE subscribers SET balance = ? WHERE id=?")->execute([$new_bal, $id]);
                
                $type = $amount > 0 ? 'credit' : 'debit';
                $absAmount = abs($amount);
                $desc = $amount > 0 ? 'Funds added by Operator' : 'Funds deducted by Operator';
                $actStr = $amount > 0 ? "Added Balance: Rs. $absAmount" : "Deducted Balance: Rs. $absAmount";

                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', ?)")->execute([$client_id, $u, $actStr]);
                
                // Insert into ledger
                $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$client_id, $u, $type, $absAmount, $new_bal, $desc]);

                echo "<script>alert('Balance updated!'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }
        }
        elseif ($action === 'renew_user') {
            $package_id = (int)$_POST['package_id'];
            $expiry_date = $_POST['expiry_date'];
            
            $p = $pdo->prepare("SELECT name, rate_limit, price FROM packages WHERE id = ? AND client_id = ?");
            $p->execute([$package_id, $client_id]);
            $pkg = $p->fetch();

            if ($pkg) {
                // Get current balance
                $bStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id=?");
                $bStmt->execute([$id]);
                $current_bal = (float)$bStmt->fetchColumn();
                $pkg_price = (float)$pkg['price'];
                $new_bal = $current_bal - $pkg_price; // Deduct price
                
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active', balance = ? WHERE id = ?")->execute([$package_id, $expiry_date, $new_bal, $id]);
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
                }
                $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
                
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Package Renewed & Expiry Updated')")->execute([$client_id, $u]);
                
                // Insert deduction into ledger
                if ($pkg_price > 0) {
                    $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, ?, ?)")
                        ->execute([$client_id, $u, $pkg_price, $new_bal, "Package Renewed: " . $pkg['name']]);
                }

                $pdo->commit();
                echo "<script>alert('Renewed successfully!'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }
        }
    }
}
// --- END ACTIONS ---

// Fetch user data
$sql = "SELECT s.*, p.name as pkg_name, p.data_limit_gb, p.validity_days, c.company_name 
        FROM subscribers s 
        JOIN clients c ON s.client_id = c.id 
        LEFT JOIN packages p ON s.package_id = p.id 
        WHERE s.id = ? AND s.client_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id, $client_id]);
$user = $stmt->fetch();

if (!$user) {
    echo "<script>alert('User not found!'); window.location='subscribers.php';</script>";
    exit;
}

// Fetch packages for renew modal
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ?");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll();

// Fetch metrics from past sessions (closed sessions)
$volStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down FROM radacct WHERE username = ?");
$volStmt->execute([$user['username']]);
$vol = $volStmt->fetch();

$past_bytes = ($vol['up'] ?? 0) + ($vol['down'] ?? 0);
$total_bytes = $past_bytes; // Initial total (will be updated by JS if online)
if ($total_bytes >= 1073741824) {
    $used_volume_str = round($total_bytes / 1073741824, 2) . " GB";
} elseif ($total_bytes >= 1048576) {
    $used_volume_str = round($total_bytes / 1048576, 2) . " MB";
} elseif ($total_bytes > 0) {
    $used_volume_str = round($total_bytes / 1024, 2) . " KB";
} else {
    $used_volume_str = "0 MB";
}

// Check if currently online
$onStmt = $pdo->prepare("SELECT acctstarttime FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1");
$onStmt->execute([$user['username']]);
$online = $onStmt->fetch();

$uptime_str = "0.00";
if ($online) {
    $start_time = is_numeric($online['acctstarttime']) ? $online['acctstarttime'] : strtotime($online['acctstarttime']);
    $diff = time() - $start_time;
    $hours = floor($diff / 3600);
    $mins = floor(($diff % 3600) / 60);
    $uptime_str = "{$hours}h {$mins}m";
}

// Additional Service Details
$sessStmt = $pdo->prepare("SELECT SUM(acctsessiontime) as total_time FROM radacct WHERE username = ?");
$sessStmt->execute([$user['username']]);
$total_time_sec = (int)$sessStmt->fetchColumn();
$used_sess_hours = floor($total_time_sec / 3600);
$used_sess_mins = floor(($total_time_sec % 3600) / 60);
$used_sess_str = sprintf("%02d Hours %02d Minutes", $used_sess_hours, $used_sess_mins);

$latestStmt = $pdo->prepare("SELECT * FROM radacct WHERE username = ? ORDER BY radacctid DESC LIMIT 1");
$latestStmt->execute([$user['username']]);
$latest_conn = $latestStmt->fetch();

$actStmt = $pdo->prepare("SELECT created_at FROM activity_logs WHERE against_to = ? AND activity LIKE '%Package Renewed%' ORDER BY id DESC LIMIT 1");
$actStmt->execute([$user['username']]);
$last_act = $actStmt->fetchColumn();
if (!$last_act) $last_act = $user['created_at'];

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

// Fetch monthly payments and deductions from user_ledger for the current year
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

// Fetch full ledger history for the accordion
$ledgerStmt = $pdo->prepare("SELECT * FROM user_ledger WHERE client_id = ? AND username = ? ORDER BY id DESC LIMIT 50");
$ledgerStmt->execute([$client_id, $user['username']]);
$ledger_history = $ledgerStmt->fetchAll();

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
    
    .profile-list { list-style: none; padding: 0; margin: 0; }
    .profile-list li { padding: 12px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #f8fafc; color: #475569; font-size: 0.9rem; }
    .profile-list li i { color: #64748b; width: 16px; text-align: center; }
    
    .action-grid { padding: 20px; display: flex; flex-wrap: wrap; gap: 10px; }
    .btn-pill { background: #fff; border: 1px solid #e2e8f0; color: #334155; border-radius: 20px; padding: 6px 14px; font-size: 0.85rem; font-weight: 500; transition: 0.2s; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
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
    .metric-data h6 { margin: 0; font-size: 0.8rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; }
    .metric-data h4 { margin: 0; font-size: 1.25rem; font-weight: 700; color: #1e293b; }

    .custom-accordion .accordion-item { border: none; background: #fff; border-radius: 12px !important; margin-bottom: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); overflow: hidden; }
    .custom-accordion .accordion-button { background: #fff; color: #1e293b; font-weight: 600; padding: 15px 20px; box-shadow: none; border-bottom: 1px solid #f1f5f9; }
    .custom-accordion .accordion-button:not(.collapsed) { background: #f8fafc; color: #3b82f6; }
    .custom-accordion .accordion-button i { color: #64748b; margin-right: 10px; width: 16px; text-align: center; }
    .btn-acc-action { background: #f59e0b; color: #fff; border: none; font-size: 0.8rem; padding: 4px 12px; border-radius: 12px; font-weight: 600; text-decoration: none; }
    .btn-acc-action:hover { background: #d97706; color: #fff; }
</style>

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
                    <p><?= htmlspecialchars($user['username']) ?></p>
                    <?php if($user['status'] === 'disabled'): ?>
                        <span class="status-badge disabled">Disabled</span>
                    <?php else: ?>
                        <span class="status-badge <?= $online ? '' : 'offline' ?>">
                            <?= $online ? 'Active' : 'Offline' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <ul class="profile-list">
                <li><i class="fa-solid fa-building"></i> <?= htmlspecialchars($user['company_name']) ?></li>
                <li><i class="fa-solid fa-user-shield"></i> Admin</li>
                <li><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($user['national_id'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($user['mobile'] ?: ($user['phone'] ?: 'N/A')) ?></li>
                <li><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($user['address'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-calendar-alt"></i> <?= $user['expiry_date'] ? date('Y-m-d H:i:s', strtotime($user['expiry_date'])) : 'N/A' ?></li>
            </ul>
            <div class="action-grid">
                <!-- Modals Triggers -->
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="fa-brands fa-paypal"></i> Payment</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#renewModal"><i class="fa-solid fa-rotate"></i> Renew</button>
                <button type="button" class="btn-pill" onclick="alert('User Password: <?= htmlspecialchars($user['password']) ?>')"><i class="fa-solid fa-lock"></i> Toggle Password</button>
                <button type="button" class="btn-pill dark" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fa-solid fa-user-pen"></i> Edit Profile</button>
                <form method="POST" enctype="multipart/form-data" id="photoForm" style="display:none;">
                    <input type="hidden" name="action" value="upload_photo">
                    <input type="file" name="profile_photo" id="photoInput" accept="image/*" onchange="document.getElementById('photoForm').submit();">
                </form>
                <button type="button" class="btn-pill" onclick="document.getElementById('photoInput').click();"><i class="fa-regular fa-image"></i> Change Photo</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#noteModal"><i class="fa-regular fa-note-sticky"></i> Add Note</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#passwordModal"><i class="fa-solid fa-key"></i> Change Password</button>
                <button type="button" class="btn-pill"><i class="fa-solid fa-bars-progress"></i> Service Settings</button>
                <button type="button" class="btn-pill"><i class="fa-solid fa-file-circle-plus"></i> Add User Document</button>
                
                <!-- Action Forms -->
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this profile permanently?');">
                    <input type="hidden" name="action" value="delete_user">
                    <button type="submit" class="btn-pill danger"><i class="fa-solid fa-trash"></i> Delete Profile</button>
                </form>

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
                    <div class="metric-icon bg-light-info"><i class="fa-regular fa-clock"></i></div>
                    <div class="metric-data">
                        <h6>Online Uptime</h6>
                        <h4 id="live_uptime"><?= $uptime_str ?></h4>
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
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="view-card metric-card mb-0 h-100">
                    <div class="metric-icon bg-light-success"><i class="fa-regular fa-credit-card"></i></div>
                    <div class="metric-data">
                        <h6>Current Balance</h6>
                        <h4><?= number_format($user['balance'], 2) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Graph Card -->
        <div class="view-card p-4">
            <h6 class="mb-4 text-secondary fw-bold"><i class="fa-solid fa-chart-bar me-2"></i> Ledger & Live Bandwidth Graph</h6>
            <div class="row">
                <div class="col-md-6 border-end">
                    <canvas id="ledgerChart" height="200"></canvas>
                </div>
                <div class="col-md-6">
                    <canvas id="bwChart" height="200"></canvas>
                </div>
            </div>
        </div>

        <!-- Accordions -->
        <div class="accordion custom-accordion" id="userDetailsAccordion">
            
            <div class="accordion-item">
                <h2 class="accordion-header d-flex align-items-center">
                    <button class="accordion-button collapsed flex-grow-1" type="button" data-bs-toggle="collapse" data-bs-target="#accService">
                        <i class="fa-solid fa-wrench"></i> Service Details
                    </button>
                    <a href="#" class="btn-acc-action position-absolute" style="right: 50px; z-index: 5;">
                        <i class="fa-solid fa-bars-progress me-1"></i> Service Settings
                    </a>
                </h2>
                <div id="accService" class="accordion-collapse collapse show" data-bs-parent="#userDetailsAccordion">
                    <div class="accordion-body text-secondary p-3">
                        <table class="table table-sm table-borderless text-secondary mb-0" style="font-size: 0.9rem;">
                            <tbody>
                                <tr><td class="fw-bold w-50">Profile Status</td><td><span class="badge bg-<?= $user['status'] == 'active' ? 'success' : 'danger' ?>"><?= ucfirst($user['status']) ?></span></td></tr>
                                <tr><td class="fw-bold">Connection Type</td><td>Radius <?= strtoupper($user['service_type']) ?></td></tr>
                                <tr><td class="fw-bold">Package</td><td><?= htmlspecialchars($user['pkg_name'] ?? 'N/A') ?></td></tr>
                                <tr><td class="fw-bold">Package Duration</td><td><?= $user['validity_days'] ? $user['validity_days'] . ' Days' : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Last Expiration Date</td><td><?= $user['expiry_date'] ? date('d M Y H:i:s', strtotime($user['expiry_date'])) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Total Volume</td><td><?= $total_vol_str ?></td></tr>
                                <tr><td class="fw-bold">Used Volume</td><td id="svc_used_volume"><?= $used_volume_str ?></td></tr>
                                <tr><td class="fw-bold">Remaining Volume</td><td id="svc_rem_volume"><?= $rem_vol_str ?></td></tr>
                                <tr><td class="fw-bold">Total Session Time</td><td>Unlimited</td></tr>
                                <tr><td class="fw-bold">Used Session Time</td><td><?= $used_sess_str ?></td></tr>
                                <tr><td class="fw-bold">Remaining Session Time</td><td>Unlimited</td></tr>
                                <tr><td class="fw-bold">Last Activation Date</td><td><?= $last_act ? date('d M Y H:i:s', strtotime($last_act)) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Last Login</td><td><?= $latest_conn ? date('d M Y H:i:s', (is_numeric($latest_conn['acctstarttime']) ? $latest_conn['acctstarttime'] : strtotime($latest_conn['acctstarttime']))) : 'N/A' ?></td></tr>
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
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ledger_history as $l): ?>
                                            <tr>
                                                <td data-order="<?= date('Y-m-d H:i:s', strtotime($l['created_at'])) ?>"><?= date('d M Y, h:i A', strtotime($l['created_at'])) ?></td>
                                                <td><?= htmlspecialchars($l['description']) ?></td>
                                                <td class="<?= $l['type'] == 'credit' ? 'text-success' : 'text-danger' ?>">
                                                    <?= $l['type'] == 'credit' ? '+' : '-' ?> Rs <?= number_format($l['amount'], 2) ?>
                                                </td>
                                                <td class="fw-bold">Rs <?= number_format($l['balance_after'], 2) ?></td>
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
            <div class="mb-3">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" name="amount" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Add Balance</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Renew Modal -->
<div class="modal fade" id="renewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-rotate"></i> Renew User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="renew_user">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Change Package</label>
                <select name="package_id" class="form-select" required>
                    <?php foreach($packages as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $p['id'] == $user['package_id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['rate_limit']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Set Expiry Date & Time</label>
                <input type="datetime-local" name="expiry_date" class="form-control" value="<?= $user['expiry_date'] ? date('Y-m-d\TH:i', strtotime($user['expiry_date'])) : '' ?>" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Update & Renew</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content light-modal">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-user-pen text-primary"></i> Edit Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="edit_profile">
        <div class="modal-body p-4">
            <div class="add-user-flat-form">
                <!-- Account Info -->
                <div class="mb-4">
                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-user"></i> Account Information</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">National ID (CNIC)</label>
                            <input type="text" name="national_id" class="form-control" value="<?= htmlspecialchars($user['national_id']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="password" id="editPassword" class="form-control" value="<?= htmlspecialchars($user['password']) ?>" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('editPassword').value = Math.random().toString(36).slice(-8);"><i class="fa-solid fa-shuffle"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Service Info -->
                <div class="mb-4">
                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-wifi"></i> Service & Package</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Service Type</label>
                            <select name="service_type" class="form-select">
                                <option value="pppoe" <?= $user['service_type']=='pppoe'?'selected':'' ?>>PPPoE</option>
                                <option value="hotspot" <?= $user['service_type']=='hotspot'?'selected':'' ?>>Hotspot</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Select Package <span class="text-danger">*</span></label>
                            <select name="package_id" class="form-select" required>
                                <option value="">Choose...</option>
                                <?php 
                                $pkgs = $pdo->query("SELECT * FROM packages WHERE client_id=$client_id")->fetchAll();
                                foreach($pkgs as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $p['id']==$user['package_id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['rate_limit']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Assign Dealer (Optional)</label>
                            <select name="dealer_id" class="form-select">
                                <option value="">None</option>
                                <?php 
                                $dlrs = $pdo->query("SELECT * FROM dealers WHERE client_id=$client_id")->fetchAll();
                                foreach($dlrs as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $d['id']==$user['dealer_id']?'selected':'' ?>><?= htmlspecialchars($d['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Custom Expiry</label>
                            <input type="datetime-local" name="expiry_date" class="form-control" value="<?= $user['expiry_date'] ? date('Y-m-d\TH:i', strtotime($user['expiry_date'])) : '' ?>">
                        </div>
                    </div>
                </div>

                <!-- Contact Info -->
                <div class="mb-4">
                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-address-book"></i> Contact & Location</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Mobile</label>
                            <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($user['mobile']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Area / City</label>
                            <input type="text" name="subarea" class="form-control" value="<?= htmlspecialchars($user['subarea']) ?>">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="col-form-label">Street Address</label>
                            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($user['address']) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
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
        <h5 class="modal-title"><i class="fa-regular fa-note-sticky"></i> Add Note</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_note">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Note</label>
                <textarea name="notes" class="form-control" rows="4"><?= htmlspecialchars($user['notes']) ?></textarea>
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
                <label class="form-label">New Password</label>
                <input type="text" name="new_password" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Update Password</button>
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
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<script>
$(document).ready(function() {
    // Custom filtering function for date range
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex, rowData, counter) {
        let min = $('#minLedgerDate').val();
        let max = $('#maxLedgerDate').val();
        // Get the date string from the data-order attribute
        let dateStr = settings.aoData[dataIndex].anCells[0].getAttribute('data-order');
        if (!dateStr) return true;
        
        let rowDate = new Date(dateStr.split(' ')[0]); // Get Y-m-d part
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

    // Refilter the table on input change
    $('#minLedgerDate, #maxLedgerDate').on('change', function() {
        table.draw();
    });
});
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

const ctxBw = document.getElementById('bwChart').getContext('2d');
const bwChart = new Chart(ctxBw, {
    type: 'line',
    data: {
        labels: ['0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0'],
        datasets: [
            { label: 'Tx/Down (Mbps)', data: [0,0,0,0,0,0,0,0,0,0,0], borderColor: '#475569', tension: 0.1, fill: false },
            { label: 'Rx/Up (Mbps)', data: [0,0,0,0,0,0,0,0,0,0,0], borderColor: '#ef4444', tension: 0.1, fill: false }
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'top', labels: { boxWidth: 12 } } }, scales: { y: { min: 0 } }, animation: false }
});

let prevBytesIn = null;
let prevBytesOut = null;
let lastTime = null;
const pastBytes = <?= (int)$past_bytes ?>;

function fetchLiveBandwidth() {
    fetch(`api_bandwidth.php?username=<?= urlencode($user['username']) ?>`)
        .then(res => res.json())
        .then(data => {
            if(data.bytes_in !== undefined && data.bytes_out !== undefined) {
                if(data.uptime !== undefined) {
                    document.getElementById('live_uptime').innerText = data.uptime;
                }
                
                // Update Used Volume LIVE
                let currentLiveBytes = data.bytes_in + data.bytes_out;
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
                let currentBytesIn = data.bytes_in;
                let currentBytesOut = data.bytes_out;
                
                
                  let tx_mbps = 0;
                  let rx_mbps = 0;

                  if (data.rx_bps !== undefined && data.tx_bps !== undefined) {
                      // We got direct bps from monitor-traffic API
                      rx_mbps = data.rx_bps / 1048576;
                      tx_mbps = data.tx_bps / 1048576;
                  } else if (prevBytesIn !== null && prevBytesOut !== null && lastTime !== null) {
                      let timeDiffSecs = (nowTime - lastTime) / 1000;
                      if (timeDiffSecs > 0) {
                          let bytesInDiff = currentBytesIn - prevBytesIn;
                          let bytesOutDiff = currentBytesOut - prevBytesOut;
                          if(bytesInDiff < 0) bytesInDiff = 0;
                          if(bytesOutDiff < 0) bytesOutDiff = 0;
                          rx_mbps = (bytesInDiff * 8 / timeDiffSecs) / 1048576;
                          tx_mbps = (bytesOutDiff * 8 / timeDiffSecs) / 1048576;
                      }
                  }

                
                prevBytesIn = currentBytesIn;
                prevBytesOut = currentBytesOut;
                lastTime = nowTime;

                // Shift old data
                bwChart.data.labels.shift();
                bwChart.data.datasets[0].data.shift();
                bwChart.data.datasets[1].data.shift();
                
                // Add new data
                let now = new Date();
                let timeStr = now.getHours() + ':' + now.getMinutes() + ':' + now.getSeconds();
                bwChart.data.labels.push(timeStr);
                
                bwChart.data.datasets[0].data.push(tx_mbps.toFixed(2));
                bwChart.data.datasets[1].data.push(rx_mbps.toFixed(2));
                
                bwChart.update();
            }
        })
        .catch(err => console.error("Error fetching bandwidth:", err));
}

// Fetch every 3 seconds
setInterval(fetchLiveBandwidth, 3000);
fetchLiveBandwidth(); // initial call

</script>


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

<?php require_once 'footer.php'; ?>
