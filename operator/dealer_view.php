<?php
require_once 'header.php';

try {
    $pdo->exec("DELETE t1 FROM dealer_packages t1 INNER JOIN dealer_packages t2 WHERE t1.id > t2.id AND t1.dealer_id = t2.dealer_id AND t1.package_id = t2.package_id");
    $pdo->exec("ALTER TABLE dealer_packages ADD UNIQUE KEY unique_dealer_pkg (dealer_id, package_id)");
} catch(Exception $e) {}


?><style>
.badge-soft-success { background-color: rgba(34,197,94,0.1); color: #22c55e; }
.badge-soft-danger { background-color: rgba(239,68,68,0.1); color: #ef4444; }
.badge-soft-warning { background-color: rgba(245,158,11,0.1); color: #f59e0b; }
.badge-soft-primary { background-color: rgba(59,130,246,0.1); color: #3b82f6; }
.badge-soft-secondary { background-color: rgba(100,116,139,0.1); color: #64748b; }
.avatar-circle { width: 35px; height: 35px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 14px; }
.table-custom-ui th { text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
.table-custom-ui td { vertical-align: middle; }
</style><?php


try {
    $pdo->exec("ALTER TABLE dealers ADD COLUMN status ENUM('active', 'disabled') DEFAULT 'active'");
} catch (Exception $e) {}


$client_id = $_SESSION['operator_id'] ?? 0;
$dealer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$dealer_id) {
    echo "<script>window.location.href='dealers.php';</script>";
    exit;
}


// Handle Delete Package
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_package') {
    $pkg_id = (int)$_POST['package_id'];
    try {
        $pdo->prepare("DELETE FROM dealer_packages WHERE dealer_id = ? AND package_id = ?")->execute([$dealer_id, $pkg_id]);
        echo "<script>alert('Package removed from dealer successfully!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
    } catch(PDOException $e) {
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Handle Set New Package
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_package') {
    $pkg_id = (int)$_POST['package_id'];
    $price = (float)$_POST['dealer_price'];
    $profit = (float)$_POST['dealer_profit'];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO dealer_packages (dealer_id, package_id, dealer_price, dealer_profit) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE dealer_price = ?, dealer_profit = ?");
        $stmt->execute([$dealer_id, $pkg_id, $price, $profit, $price, $profit]);
        echo "<script>alert('Package assigned to dealer successfully!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
    } catch(PDOException $e) {
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Handle Edit Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_profile') {
    $arr = isset($_POST['assigned_routers']) ? $_POST['assigned_routers'] : [];
      $arr = array_filter($arr, function($v) { return trim($v) !== ''; });
      $assigned_routers = implode(',', $arr);
    
    if (!empty($_POST['password'])) {
        $stmt = $pdo->prepare("UPDATE dealers SET full_name=?, username=?, password=?, national_id=?, email=?, phone=?, franchise=?, address=?, city=?, assigned_routers=? WHERE id=?");
        $stmt->execute([
            $_POST['full_name'], $_POST['username'], $_POST['password'], $_POST['national_id'], $_POST['email'], 
            $_POST['phone'], $_POST['franchise'], $_POST['address'], $_POST['city'], $assigned_routers, $dealer_id
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE dealers SET full_name=?, username=?, national_id=?, email=?, phone=?, franchise=?, address=?, city=?, assigned_routers=? WHERE id=?");
        $stmt->execute([
            $_POST['full_name'], $_POST['username'], $_POST['national_id'], $_POST['email'], 
            $_POST['phone'], $_POST['franchise'], $_POST['address'], $_POST['city'], $assigned_routers, $dealer_id
        ]);
    }
    echo "<script>alert('Profile updated!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}
// Handle Change Photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_photo') {
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $target = '../uploads/dealers/' . time() . '_' . basename($_FILES['photo']['name']);
        if (!is_dir('../uploads/dealers/')) mkdir('../uploads/dealers/', 0777, true);
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
            $pdo->prepare("UPDATE dealers SET photo=? WHERE id=?")->execute([$target, $dealer_id]);
            echo "<script>alert('Photo updated!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
        }
    }
}
// Handle Add Note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_note') {
    $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $_POST['note']]);
    echo "<script>alert('Note added!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}
// Handle Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dealer_payment') {
    $amount = (float)$_POST['amount'];
    if ($_POST['payment_type'] === 'deduct') $amount = -$amount;
    $pdo->prepare("UPDATE dealers SET balance = balance + ? WHERE id=?")->execute([$amount, $dealer_id]);
    echo "<script>alert('Payment processed!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}
// Handle Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $pdo->prepare("UPDATE dealers SET password=? WHERE id=?")->execute([$_POST['new_password'], $dealer_id]);
    echo "<script>alert('Password updated!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}
// Handle Add Document
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_document') {
    if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
        $target = '../uploads/dealer_docs/' . time() . '_' . basename($_FILES['document']['name']);
        if (!is_dir('../uploads/dealer_docs/')) mkdir('../uploads/dealer_docs/', 0777, true);
        if (move_uploaded_file($_FILES['document']['tmp_name'], $target)) {
            $pdo->prepare("INSERT INTO dealer_documents (dealer_id, title, file_path) VALUES (?, ?, ?)")->execute([$dealer_id, $_POST['title'], $target]);
            echo "<script>alert('Document added!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
        }
    }
}

// Handle Toggle Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    $currStmt = $pdo->prepare("SELECT status FROM dealers WHERE id = ?");
    $currStmt->execute([$dealer_id]);
    $current_status = $currStmt->fetchColumn() ?: 'active';
    $newStatus = $current_status === 'active' ? 'disabled' : 'active';
    
    $pdo->prepare("UPDATE dealers SET status = ? WHERE id = ?")->execute([$newStatus, $dealer_id]);
    
    if ($newStatus === 'disabled') {
        $pdo->prepare("UPDATE subscribers SET status = 'disabled' WHERE dealer_id = ? AND status = 'active'")->execute([$dealer_id]);
        
        $stmt = $pdo->prepare("SELECT username FROM subscribers WHERE dealer_id = ?");
        $stmt->execute([$dealer_id]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($users)) {
            $in = str_repeat('?,', count($users) - 1) . '?';
            $pdo->prepare("DELETE FROM radcheck WHERE attribute = 'Auth-Type' AND value = 'Reject' AND username IN ($in)")->execute($users);
            
            $insertQ = "INSERT INTO radcheck (username, attribute, op, value) VALUES ";
            $insertData = [];
            foreach($users as $u) {
                $insertQ .= "(?, 'Auth-Type', ':=', 'Reject'),";
                array_push($insertData, $u);
            }
            $insertQ = rtrim($insertQ, ',');
            $pdo->prepare($insertQ)->execute($insertData);
        }
        $msg = "Dealer DISABLED. All associated users have been blocked from internet access.";
    } else {
        $pdo->prepare("UPDATE subscribers SET status = 'active' WHERE dealer_id = ? AND (expiry_date IS NULL OR expiry_date > NOW())")->execute([$dealer_id]);
        
        $stmt = $pdo->prepare("SELECT username FROM subscribers WHERE dealer_id = ? AND (expiry_date IS NULL OR expiry_date > NOW())");
        $stmt->execute([$dealer_id]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($users)) {
            $in = str_repeat('?,', count($users) - 1) . '?';
            $pdo->prepare("DELETE FROM radcheck WHERE attribute = 'Auth-Type' AND value = 'Reject' AND username IN ($in)")->execute($users);
        }
        $msg = "Dealer ENABLED. Valid users have been restored.";
    }
    echo "<script>alert('$msg'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
}

// Handle Delete Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_profile') {
    // 1. Fetch all users belonging to this dealer
    $stmt = $pdo->prepare("SELECT username FROM subscribers WHERE dealer_id = ?");
    $stmt->execute([$dealer_id]);
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 2. Delete all users from RADIUS tables
    if (!empty($users)) {
        $in = str_repeat('?,', count($users) - 1) . '?';
        $pdo->prepare("DELETE FROM radcheck WHERE username IN ($in)")->execute($users);
        $pdo->prepare("DELETE FROM radreply WHERE username IN ($in)")->execute($users);
        $pdo->prepare("DELETE FROM radusergroup WHERE username IN ($in)")->execute($users);
        
        // 3. Delete from subscribers table
        $pdo->prepare("DELETE FROM subscribers WHERE dealer_id = ?")->execute([$dealer_id]);
    }
    
    // 4. Finally delete the dealer
    $pdo->prepare("DELETE FROM dealers WHERE id=?")->execute([$dealer_id]);
    
    echo "<script>alert('Dealer and all associated users deleted successfully!'); window.location.href='dealers.php';</script>";
}

// Handle Permissions Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_permissions') {
    $perm_create = isset($_POST['perm_create_user']) ? 1 : 0;
    $perm_delete = isset($_POST['perm_delete_user']) ? 1 : 0;
    $perm_custom = isset($_POST['perm_custom_expiry']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare("UPDATE dealers SET perm_create_user = ?, perm_delete_user = ?, perm_custom_expiry = ? WHERE id = ?");
        $stmt->execute([$perm_create, $perm_delete, $perm_custom, $dealer_id]);
        echo "<script>alert('Permissions updated successfully!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
    } catch(PDOException $e) {
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}

$stmt = $pdo->prepare("
    SELECT d.*, 
    (SELECT COUNT(*) FROM subscribers s WHERE s.dealer_id = d.id) as user_count 
    FROM dealers d 
    WHERE d.id = ? AND d.client_id = ?
");
$stmt->execute([$dealer_id, $client_id]);
$dealer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dealer) {
    echo "<div class='alert alert-danger m-4'>Dealer not found.</div>";
    require_once 'footer.php';
    exit;
}

// Fetch all operator packages
$pkgStmt = $pdo->prepare("SELECT id, name FROM packages WHERE client_id = ? OR client_id = 0 ORDER BY name ASC");
$pkgStmt->execute([$client_id]);
$operator_packages = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch assigned dealer packages
$assignedPkgStmt = $pdo->prepare("
    SELECT dp.package_id, dp.dealer_price, dp.dealer_profit, p.name 
    FROM dealer_packages dp
    JOIN packages p ON dp.package_id = p.id
    WHERE dp.dealer_id = ?
    ORDER BY p.name ASC
");
$assignedPkgStmt->execute([$dealer_id]);
$dealer_assigned_packages = $assignedPkgStmt->fetchAll(PDO::FETCH_ASSOC);


$nasStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE client_id = ?");
$nasStmt->execute([$client_id]);
$all_nas = $nasStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dealer Ledger / Notes
$ledgerStmt = $pdo->prepare("SELECT note, created_at FROM dealer_notes WHERE dealer_id = ? ORDER BY id DESC");
$ledgerStmt->execute([$dealer_id]);
$dealer_ledger = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);


$nasStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE client_id = ?");
$nasStmt->execute([$client_id]);
$all_nas = $nasStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dealer Users
$dUsersStmt = $pdo->prepare("
    SELECT s.id, s.full_name, s.username, s.expiry_date, s.status, s.mobile, s.phone, s.balance, s.service_type, p.name as package_name,
           (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
           (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE s.dealer_id = ?
    ORDER BY s.id DESC
");
$dUsersStmt->execute([$dealer_id]);
$dealer_users = $dUsersStmt->fetchAll(PDO::FETCH_ASSOC);


$nasStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE client_id = ?");
$nasStmt->execute([$client_id]);
$all_nas = $nasStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dealer Activity Logs
$alogsStmt = $pdo->prepare("SELECT activity, against_to, created_at FROM activity_logs WHERE dealer_id = ? ORDER BY id DESC LIMIT 500");
$alogsStmt->execute([$dealer_id]);
$dealer_activities = $alogsStmt->fetchAll(PDO::FETCH_ASSOC);

function formatBytes($bytes) {
    if ($bytes <= 0) return "0 B";
    $s = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    $e = floor(log($bytes, 1024));
    return round($bytes/pow(1024, $e), 2) . ' ' . $s[$e];
}

?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
/* Dealer Profile Styles */
body { background-color: #f1f5f9; }

.card-sidebar {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.profile-header {
    display: flex;
    align-items: center;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 15px;
    margin-bottom: 15px;
}

.sidebar-avatar {
    width: 65px; height: 65px;
    background: #2563eb;
    border-radius: 12px;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px;
    margin-right: 15px;
    flex-shrink: 0;
}
.sidebar-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 12px; }

.profile-name { font-weight: 700; color: #1e293b; font-size: 1.1rem; margin: 0 0 2px 0; }
.profile-role { color: #64748b; font-size: 0.8rem; margin: 0 0 5px 0; }
.status-badge {
    background-color: #f1f5f9;
    color: #0f172a;
    font-weight: 700;
    font-size: 0.7rem;
    padding: 3px 12px;
    border-radius: 20px;
    display: inline-block;
}

.info-list { list-style: none; padding: 0; margin: 0; }
.info-list li {
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
    color: #64748b;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
}
.info-list li:last-child { border-bottom: none; }
.info-list li i { width: 25px; color: #94a3b8; text-align: center; margin-right: 10px; font-size: 1rem; }

.action-btns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: 20px;
}
.action-btns .btn {
    font-size: 0.75rem;
    font-weight: 600;
    text-align: left;
    padding: 8px 10px;
    border-radius: 6px;
    display: flex;
    align-items: center;
}
.action-btns .btn i { width: 20px; text-align: center; margin-right: 5px; }

.btn-outline-custom { border: 1px solid #e2e8f0; color: #475569; background: #fff; }
.btn-outline-custom:hover { background: #f8fafc; color: #0f172a; }
.btn-dark-custom { background: #0f172a; color: #fff; border: 1px solid #0f172a; }
.btn-dark-custom:hover { background: #1e293b; color: #fff; }
.btn-delete { grid-column: span 2; } /* Make delete button span full width if needed, or keep it half */

.stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    height: 100%;
}
.stat-icon {
    width: 48px; height: 48px;
    border-radius: 10px;
    background: #f0fdf4; /* Light green tint */
    color: #10b981;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.35rem;
    margin-right: 15px;
    flex-shrink: 0;
}
.stat-icon.dealer-icon { background: #eff6ff; color: #3b82f6; }
.stat-icon.balance-icon { background: #fffbeb; color: #f59e0b; }
.stat-title { font-size: 0.75rem; color: #94a3b8; font-weight: 600; margin-bottom: 2px; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin: 0; }

.accordion-custom { margin-top: 20px; }
.accordion-custom .card {
    border: none;
    border-radius: 8px;
    margin-bottom: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.accordion-custom .card-header {
    background: #fff;
    border-radius: 8px !important;
    border: none;
    padding: 12px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
}
.accordion-custom .card-header h6 { margin: 0; font-weight: 700; color: #1e293b; font-size: 0.95rem; display: flex; align-items: center; }
.accordion-custom .card-header h6 i { color: #3b82f6; width: 25px; font-size: 1.1rem; }

.btn-warning-custom { background: #f59e0b; color: #fff; border: none; font-weight: 700; font-size: 0.75rem; border-radius: 20px; padding: 6px 14px; box-shadow: 0 2px 4px rgba(245, 158, 11, 0.2); }
.btn-warning-custom:hover { background: #d97706; color: #fff; }

.header-controls { display: flex; align-items: center; gap: 10px; }
.chevron-icon { color: #cbd5e1; font-size: 0.8rem; }
</style>

<div class="row g-4 mt-2">
    <!-- Left Sidebar -->
    <div class="col-lg-4 col-xl-3">
        <div class="card-sidebar">
            <div class="profile-header">
                <div class="sidebar-avatar">
                    <?php if($dealer['photo']): ?>
                        <img src="<?= htmlspecialchars($dealer['photo']) ?>" alt="Avatar" style="cursor:pointer;" data-bs-toggle="modal" data-bs-target="#photoViewModal" title="Click to view">
                    <?php else: ?>
                        <i class="fa-solid fa-user"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <h5 class="profile-name"><?= htmlspecialchars($dealer['full_name']) ?></h5>
                    <p class="profile-role"><?= htmlspecialchars($dealer['username']) ?> - Dealer</p>
                    <span class="status-badge"><?= ucfirst($dealer['status'] ?? 'Active') ?></span>
                </div>
            </div>

            <ul class="info-list">
                <li><i class="fa-solid fa-building"></i> <?= htmlspecialchars($dealer['franchise'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-user-tie"></i> <?= htmlspecialchars($dealer['admin_name']) ?></li>
                <li><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($dealer['national_id'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($dealer['phone'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($dealer['email'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-map-location-dot"></i> <?= htmlspecialchars($dealer['address'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($dealer['city'] ?: ($dealer['area'] ?: 'N/A')) ?></li>
                <li><i class="fa-solid fa-calendar-days"></i> <?= $dealer['created_at'] ? date('Y-m-d H:i:s', strtotime($dealer['created_at'])) : 'N/A' ?></li>
            </ul>

                        <style>
            .action-btns {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                margin-top: 20px;
            }
            .action-btns .btn {
                font-size: 0.85rem;
                font-weight: 600;
                padding: 12px 10px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                background: #fff;
                color: #334155;
                border: 1px solid #e2e8f0;
                box-shadow: 0 1px 2px rgba(0,0,0,0.02);
                transition: 0.2s;
                text-align: left;
                white-space: normal;
                line-height: 1.2;
            }
            .action-btns .btn:hover { border-color: #cbd5e1; background: #f8fafc; }
            .action-btns .btn-dark-custom {
                background: #0f172a;
                color: #fff;
                border-color: #0f172a;
            }
            .action-btns .btn-dark-custom:hover { background: #1e293b; color: #fff; border-color: #1e293b; }
            .action-btns .btn i {
                font-size: 1.1rem;
                width: 28px;
                text-align: center;
                margin-right: 8px;
                color: #64748b;
            }
            .action-btns .btn-dark-custom i { color: #fff; }
            </style>
            
            <div class="action-btns">
                <button class="btn btn-dark-custom" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fa-solid fa-pen-to-square"></i> Edit Profile</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#changePhotoModal"><i class="fa-regular fa-image"></i> Change<br>Photo</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#addNoteModal"><i class="fa-solid fa-file-lines"></i> Add Note</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="fa-brands fa-paypal"></i> Payment</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="fa-solid fa-lock"></i> Change<br>Password</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#setNewPackageModal"><i class="fa-solid fa-plus-square"></i> Set New<br>Package</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#addDocumentModal"><i class="fa-solid fa-file-arrow-up"></i> Add<br>Document</button>
                <button class="btn" data-bs-toggle="modal" data-bs-target="#settingsModal"><i class="fa-solid fa-gear"></i> Settings</button>
                
                
<form method="POST" class="m-0 p-0" onsubmit="return confirm('Are you sure you want to <?= ($dealer['status'] ?? 'active') === 'active' ? 'DISABLE' : 'ENABLE' ?> this dealer? <?= ($dealer['status'] ?? 'active') === 'active' ? 'This will block ALL their users.' : '' ?>');">
    <input type="hidden" name="action" value="toggle_status">
    <button type="submit" class="btn w-100 h-100 <?= ($dealer['status'] ?? 'active') === 'active' ? 'text-warning' : 'text-success' ?>"><i class="fa-solid <?= ($dealer['status'] ?? 'active') === 'active' ? 'fa-user-lock' : 'fa-user-check' ?>"></i> <?= ($dealer['status'] ?? 'active') === 'active' ? 'Disable<br>Dealer' : 'Enable<br>Dealer' ?></button>
</form>

                  <form method="POST" class="m-0 p-0" onsubmit="return confirm('Are you sure you want to completely delete this dealer profile?');">
                    <input type="hidden" name="action" value="delete_profile">
                    <button type="submit" class="btn w-100 h-100"><i class="fa-solid fa-ban"></i> Delete<br>Profile</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Content Area -->
    <div class="col-lg-8 col-xl-9">
        
        <!-- Stat Cards -->
        <div class="row g-3 mb-2">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="stat-title">Total Users</div>
                        <div class="stat-value"><?= number_format($dealer['user_count'], 2) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon dealer-icon"><i class="fa-solid fa-user-group"></i></div>
                    <div>
                        <div class="stat-title">Dealer Package</div>
                        <div class="stat-value">0.00</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon balance-icon"><i class="fa-regular fa-credit-card"></i></div>
                    <div>
                        <div class="stat-title">Current Balance</div>
                        <div class="stat-value"><?= number_format($dealer['balance'], 2) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accordions / Tabs -->
        <div class="accordion-custom">
            
            <!-- Packages -->
            <div class="card overflow-hidden">
                <div class="card-header border-bottom-0 collapsed" data-bs-toggle="collapse" data-bs-target="#collapsePackages" style="background-color: #1e293b;">
                    <h6 style="color: #fff;"><i class="fa-solid fa-box-open me-2" style="color: #fff;"></i> Packages</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-down text-white chevron-icon"></i>
                        <button class="btn btn-warning-custom" onclick="event.stopPropagation();" data-bs-toggle="modal" data-bs-target="#setNewPackageModal"><i class="fa-solid fa-plus me-1"></i> Set New Package</button>
                        
                    </div>
                </div>
                <div id="collapsePackages" class="collapse">
                    <div class="card-body p-4 bg-white border-top">
                        <table class="table table-hover table-borderless w-100" id="dealerPackagesTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="fw-bold text-secondary ps-3">Package</th>
                                    <th class="fw-bold text-secondary">Dealer Price</th>
                                    <th class="fw-bold text-secondary">Dealer Profit</th>
                                    <th class="fw-bold text-secondary text-end pe-3">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($dealer_assigned_packages as $ap): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td class="py-3 ps-3">
                                        <i class="fa-solid fa-box text-success me-2" style="font-size: 1.1rem; vertical-align: middle;"></i> 
                                        <span class="text-secondary fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($ap['name']) ?></span>
                                    </td>
                                    <td class="py-3 fw-bold text-dark">Rs. <?= number_format($ap['dealer_price'], 2) ?></td>
                                    <td class="py-3 fw-bold text-success">Rs. <?= number_format($ap['dealer_profit'], 2) ?></td>
                                    <td class="py-3 text-end pe-3">
                                        <button class="btn btn-sm btn-light text-primary me-1" onclick="editPackage(<?= $ap['package_id'] ?>, <?= $ap['dealer_price'] ?>, <?= $ap['dealer_profit'] ?>)" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to remove this package from the dealer?');">
                                            <input type="hidden" name="action" value="delete_package">
                                            <input type="hidden" name="package_id" value="<?= $ap['package_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-light text-danger" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Ledger -->
            <div class="card overflow-hidden">
                <div class="card-header border-bottom-0 collapsed" data-bs-toggle="collapse" data-bs-target="#collapseLedger" style="background-color: #1e293b;">
                    <h6 style="color: #fff;"><i class="fa-solid fa-chart-simple me-2" style="color: #fff;"></i> Ledger / Notes</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-down text-white chevron-icon"></i>
                    </div>
                </div>
                <div id="collapseLedger" class="collapse">
                    <div class="card-body p-4 bg-white border-top">
                        <table class="table table-hover table-bordered w-100" id="dealerLedgerTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Transaction / Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($dealer_ledger as $note): ?>
                                <tr>
                                    <td class="text-nowrap text-secondary"><?= date('d M Y, h:i A', strtotime($note['created_at'])) ?></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($note['note']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- All Users -->
            <div class="card overflow-hidden">
                <div class="card-header border-bottom-0 collapsed" data-bs-toggle="collapse" data-bs-target="#collapseAllUsers" style="background-color: #1e293b;">
                    <h6 style="color: #fff;"><i class="fa-solid fa-users me-2" style="color: #fff;"></i> All Users</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-down text-white chevron-icon"></i>
                    </div>
                </div>
                <div id="collapseAllUsers" class="collapse">
                    <div class="card-body p-4 bg-white border-top">
                        <div class="table-responsive">
                            
<table class="table table-hover table-bordered table-custom-ui w-100" id="dealerUsersTable">
    <thead class="table-light">
        <tr>
            <th>#ID</th>
            <th>Photo</th>
            <th>Username</th>
            <th>Phone</th>
            <th>Package</th>
            <th>Balance</th>
            <th>Service</th>
            <th>On/Off</th>
            <th>Expiry</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach($dealer_users as $u): ?>
        <tr>
            <td><?= $u['id'] ?></td>
            <td><div class="avatar-circle"><i class="fa-solid fa-user"></i></div></td>
            <td>
                <span class="badge rounded-pill badge-soft-success px-3 py-2"><?= htmlspecialchars($u['username']) ?></span>
            </td>
            <td><?= htmlspecialchars($u['mobile'] ?: ($u['phone'] ?: 'N/A')) ?></td>
            <td><?= htmlspecialchars($u['package_name'] ?? 'N/A') ?></td>
            <td><span class="badge rounded-pill badge-soft-warning px-3 py-2"><?= number_format($u['balance']) ?></span></td>
            <td><span class="badge rounded-pill badge-soft-primary px-3 py-2 fw-bold"><?= strtoupper(htmlspecialchars($u['service_type'])) ?></span></td>
            <td>
                <?php if($u['is_online'] > 0): ?>
                    <div class="d-flex flex-column align-items-center gap-1">
                        <span class="badge rounded-pill badge-soft-success px-3 py-1">Online</span>
                        <small class="text-muted font-monospace" style="font-size: 0.75rem;"><?= htmlspecialchars($u['live_ip']) ?></small>
                    </div>
                <?php else: ?>
                    <span class="badge rounded-pill badge-soft-secondary px-3 py-1">Offline</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if($u['expiry_date']): ?>
                    <?php 
                        $is_expired = strtotime($u['expiry_date']) < time(); 
                        $badge_class = $is_expired ? 'badge-soft-danger' : 'badge-soft-success';
                    ?>
                    <span class="badge <?= $badge_class ?> px-3 py-2"><?= date('d M Y, h:i A', strtotime($u['expiry_date'])) ?></span>
                <?php else: ?>
                    <span class="badge badge-soft-secondary px-3 py-2">N/A</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

                        </div>
                    </div>
                </div>
            </div>

            <!-- Documents -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="fa-solid fa-file-lines"></i> Documents</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-right chevron-icon"></i>
                        <button class="btn btn-warning-custom"><i class="fa-solid fa-file-arrow-up me-1"></i> Add Document</button>
                    </div>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="card overflow-hidden">
                <div class="card-header border-bottom-0 collapsed" data-bs-toggle="collapse" data-bs-target="#collapseActivity" style="background-color: #1e293b;">
                    <h6 style="color: #fff;"><i class="fa-solid fa-chart-line me-2" style="color: #fff;"></i> Activity Log</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-down text-white chevron-icon"></i>
                    </div>
                </div>
                <div id="collapseActivity" class="collapse">
                    <div class="card-body p-4 bg-white border-top">
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered w-100" id="dealerActivityTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Target User</th>
                                        <th>Activity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($dealer_activities as $act): ?>
                                    <tr>
                                        <td class="text-secondary"><?= date('d M Y, h:i A', strtotime($act['created_at'])) ?></td>
                                        <td class="fw-bold text-primary"><?= htmlspecialchars($act['against_to'] ?: 'N/A') ?></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($act['activity']) ?></td>
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
</div>

<!-- Set New Package Modal -->
<div class="modal fade" id="setNewPackageModal" tabindex="-1">
  <div class="modal-dialog modal-lg" style="max-width: 600px;">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-white border-bottom">
        <h5 class="modal-title fw-bold" style="color: #64748b; font-size: 1.15rem;">Set New Package</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="form-horizontal">
        <input type="hidden" name="action" value="set_package">
        <div class="modal-body bg-white px-4 py-4">
            
            <div class="alert mb-4 text-center rounded" style="background-color: #4fa8e0; color: #fff; border: none; font-size: 0.85rem; font-weight: 600;">
                <i class="fa-solid fa-circle-info me-1"></i> You Can Set Custom Price, Higher Than Package Price. Extra Price<br>Will Be Calculated As Franchise Profit.
            </div>

            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="mb-0 fw-bold" style="color: #475569; font-size: 0.9rem;">Dealer Package <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <select name="package_id" class="form-select" required>
                        <option value="">Select Package</option>
                        <?php foreach($operator_packages as $op): ?>
                            <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="mb-0 fw-bold" style="color: #475569; font-size: 0.9rem;">Dealer Price <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="number" step="0.01" name="dealer_price" class="form-control" placeholder="Enter Package Price" required>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="mb-0 fw-bold" style="color: #475569; font-size: 0.9rem;">Dealer Profit <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="number" step="0.01" name="dealer_profit" class="form-control" placeholder="Enter Package Profit" required>
                </div>
            </div>

        </div>
        <div class="modal-footer bg-white border-top">
          <button type="button" class="btn bg-white border text-dark px-4 fw-bold" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn text-white fw-bold px-4" style="background-color: #1e293b;">Submit</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i> Edit Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_profile">
        <div class="modal-body">
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Full Name</label><input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($dealer['full_name']) ?>" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">Username <span class="text-danger">*</span></label><input type="text" name="username" class="form-control" value="<?= htmlspecialchars($dealer['username']) ?>" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">Password</label><input type="text" name="password" class="form-control" placeholder="Leave blank to keep unchanged"></div>
                <div class="col-md-6 mb-3"><label class="form-label">National ID</label><input type="text" name="national_id" class="form-control" value="<?= htmlspecialchars($dealer['national_id']??'') ?>"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($dealer['email']??'') ?>"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($dealer['phone']??'') ?>"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Franchise</label><input type="text" name="franchise" class="form-control" value="<?= htmlspecialchars($dealer['franchise']??'') ?>"></div>
                <div class="col-md-6 mb-3"><label class="form-label">City</label><input type="text" name="city" class="form-control" value="<?= htmlspecialchars($dealer['city']??'') ?>"></div>
                <div class="col-md-12 mb-3"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="<?= htmlspecialchars($dealer['address']??'') ?>"></div>
                <div class="col-md-12 mb-3">
                    <label class="form-label text-muted small fw-bold">Assign MikroTik Routers <span class="text-danger">*</span></label>
                    <?php $curr_routers = explode(',', $dealer['assigned_routers'] ?? ''); ?>
                    <select id="edit_routers_select" name="assigned_routers[]" class="form-select" multiple required>
                          <?php foreach($all_nas as $n): ?>
                              <option value="<?= $n['id'] ?>" <?= (in_array($n['id'], $curr_routers)) ? 'selected' : '' ?>>
                                  <?= htmlspecialchars($n['shortname'] ?: 'Router') ?> (<?= $n['nasname'] ?>)
                              </option>
                          <?php endforeach; ?>
                      </select>
                      
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Change Photo Modal -->
<div class="modal fade" id="changePhotoModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Change Photo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="change_photo">
        <div class="modal-body"><input type="file" name="photo" class="form-control" accept="image/*" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Upload</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Add Note Modal -->
<div class="modal fade" id="addNoteModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Add Note</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="action" value="add_note">
        <div class="modal-body"><textarea name="note" class="form-control" rows="4" placeholder="Type note here..." required></textarea></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Save Note</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="action" value="dealer_payment">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Action</label>
                <select name="payment_type" class="form-select"><option value="add">Add Balance (+)</option><option value="deduct">Deduct Balance (-)</option></select>
            </div>
            <div class="mb-3">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" name="amount" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Confirm</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Change Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="modal-body"><input type="text" name="new_password" class="form-control" placeholder="New Password" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Update Password</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Add Document Modal -->
<div class="modal fade" id="addDocumentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Add Document</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_document">
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Document Title</label><input type="text" name="title" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">File</label><input type="file" name="document" class="form-control" required></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Upload Document</button></div>
      </form>
    </div>
  </div>
</div>
<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1">
  <div class="modal-dialog" style="max-width: 500px;">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-white border-bottom">
        <h5 class="modal-title fw-bold" style="color: #64748b; font-size: 1.15rem;"><i class="fa-solid fa-gear me-2"></i> Dealer Permissions</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="update_permissions">
        <div class="modal-body bg-white px-4 py-4">
            
            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                <div>
                    <div class="fw-bold text-dark">Create User <span class="text-danger">*</span></div>
                    <div class="small text-muted">Allow dealer to add new subscribers.</div>
                </div>
                <div class="form-check form-switch fs-4 mb-0">
                    <input class="form-check-input" type="checkbox" name="perm_create_user" value="1" <?= $dealer['perm_create_user'] ? 'checked' : '' ?>>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                <div>
                    <div class="fw-bold text-dark">Delete User <span class="text-danger">*</span></div>
                    <div class="small text-muted">Allow dealer to delete existing subscribers.</div>
                </div>
                <div class="form-check form-switch fs-4 mb-0">
                    <input class="form-check-input" type="checkbox" name="perm_delete_user" value="1" <?= $dealer['perm_delete_user'] ? 'checked' : '' ?>>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-bold text-dark">Allow Custom Expiry Time <span class="text-danger">*</span></div>
                    <div class="small text-muted">Allow dealer to set custom expiration dates.</div>
                </div>
                <div class="form-check form-switch fs-4 mb-0">
                    <input class="form-check-input" type="checkbox" name="perm_custom_expiry" value="1" <?= $dealer['perm_custom_expiry'] ? 'checked' : '' ?>>
                </div>
            </div>

        </div>
        <div class="modal-footer bg-white border-top">
          <button type="button" class="btn bg-white border text-dark px-4 fw-bold" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn text-white fw-bold px-4" style="background-color: #1e293b;">Save Settings</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
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
    var dtOpts = {
        dom: '<"row align-items-center mb-3"<"col-sm-12 col-md-3"l><"col-sm-12 col-md-6 text-center"B><"col-sm-12 col-md-3"f>>rt<"row align-items-center mt-3"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            { extend: 'print', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'pdf', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'excel', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'csv', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-csv"></i> CSV' }
        ],
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        language: { lengthMenu: "Show _MENU_ entries" }
    };
    $('#dealerPackagesTable').DataTable(dtOpts);
    $('#dealerLedgerTable').DataTable(Object.assign({}, dtOpts, { order: [[0, 'desc']] }));
    $('#dealerUsersTable').DataTable(dtOpts);
    $('#dealerActivityTable').DataTable(Object.assign({}, dtOpts, { order: [[0, 'desc']] }));
    /*
        dom: '<"row align-items-center mb-3"<"col-sm-12 col-md-3"l><"col-sm-12 col-md-6 text-center"B><"col-sm-12 col-md-3"f>>rt<"row align-items-center mt-3"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            { extend: 'print', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'pdf', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'excel', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-excel"></i> Excle' },
            { extend: 'csv', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-csv"></i> CSV' },
            { text: '<i class="fa-solid fa-eye"></i> View', className: 'btn btn-secondary btn-sm' }
        ],
        lengthMenu: [[25, 50, 100, -1], [25, 50, 100, "All"]],
        */
});
</script>


<!-- View Photo Modal -->
<div class="modal fade" id="photoViewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-transparent border-0">
            <div class="modal-header border-0 pb-0 justify-content-end">
                <button type="button" class="btn-close bg-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center pt-0">
                <?php if (!empty($dealer['photo'])): ?>
                    <img src="<?= htmlspecialchars($dealer['photo']) ?>" class="img-fluid rounded shadow-lg" oncontextmenu="return false;" style="max-height: 80vh; pointer-events: none; border: 4px solid white;" alt="Profile View">
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<script>
function editPackage(pkgId, price, profit) {
    $('select[name="package_id"]').val(pkgId).trigger('change');
    $('input[name="dealer_price"]').val(price);
    $('input[name="dealer_profit"]').val(profit);
    $('#setNewPackageModal').modal('show');
}
</script>

<?php require_once 'footer.php'; ?>


<style>
.choices__inner { border-radius: 8px; border: 1px solid #dee2e6; background-color: #fff; padding: 5px 10px; }
.choices__list--multiple .choices__item { background-color: #0d6efd; border: none; border-radius: 5px; }
.choices[data-type*="select-multiple"] .choices__button { border-left: 1px solid rgba(255,255,255,0.3); }
</style>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var editSelect = document.getElementById("edit_routers_select");
    if(editSelect) {
        new Choices(editSelect, {
            removeItemButton: true,
            searchPlaceholderValue: "Search routers...",
            itemSelectText: "",
            placeholderValue: "Select routers..."
        });
    }
});
</script>

<!-- Premium Dropdown UI (Select2) -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
.select2-container--default .select2-selection--multiple {
    border-radius: 8px;
    border: 1px solid #dee2e6;
    padding: 4px;
    min-height: 45px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background-color: #0d6efd;
    border: none;
    color: white;
    border-radius: 5px;
    padding: 5px 10px;
    margin-top: 5px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
    color: white;
    margin-right: 8px;
    border-right: 1px solid rgba(255,255,255,0.3);
    padding-right: 5px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
    color: #f8d7da;
    background: transparent;
}
.select2-dropdown {
    border-radius: 8px;
    border: 1px solid #dee2e6;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}
</style>
<script>
$(document).ready(function() {
    $("#edit_routers_select").select2({
        placeholder: "Select one or more routers",
        allowClear: true,
        width: "100%", dropdownParent: $("#editProfileModal")
    });
});
</script>
