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

    
    // EDIT USER (Update profile, package, and expiry with unified balance deduction)
    if ($action === 'edit_user') {
        $id = (int)$_POST['id'];
        $full_name = trim($_POST['full_name']);
        $password = trim($_POST['password']);
        $new_package_id = (int)($_POST['package_id'] ?? 0);
        $new_expiry = $_POST['expiry_date'] ?? null;

        $uStmt = $pdo->prepare("SELECT username, package_id, expiry_date as old_expiry FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$id, $dealer_id]);
        $subData = $uStmt->fetch(PDO::FETCH_ASSOC);

        if ($subData) {
            $u = $subData['username'];
            $old_package_id = $subData['package_id'];
            
            if ($new_package_id === 0) {
                $new_package_id = $old_package_id;
            }

            try {
                $pdo->beginTransaction();
                
                $net_deduction = 0;
                $final_expiry = $subData['old_expiry']; // default to unchanged
                
                $package_changed = ($new_package_id != $old_package_id);
                $expiry_changed = (!empty($new_expiry) && $new_expiry !== $subData['old_expiry']);
                
                if ($package_changed || $expiry_changed) {
                    if ($expiry_changed) {
                        $final_expiry = date('Y-m-d H:i:s', strtotime($new_expiry));
                    }
                    
                    // Fetch Old Package Dealer Price
                    $old_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                    $old_dpStmt->execute([$dealer_id, $old_package_id]);
                    $old_dealerPrice = $old_dpStmt->fetchColumn();
                    if ($old_dealerPrice === false) {
                        $p = $pdo->prepare("SELECT price FROM packages WHERE id = ?"); $p->execute([$old_package_id]);
                        $old_dealerPrice = $p->fetchColumn() ?: 0;
                    }

                    // Fetch New Package Dealer Price
                    $new_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                    $new_dpStmt->execute([$dealer_id, $new_package_id]);
                    $new_dealerPrice = $new_dpStmt->fetchColumn();
                    if ($new_dealerPrice === false) {
                        $p = $pdo->prepare("SELECT price FROM packages WHERE id = ?"); $p->execute([$new_package_id]);
                        $new_dealerPrice = $p->fetchColumn() ?: 0;
                    }

                    // Calculate old value
                    $old_remaining_value = 0;
                    if (!empty($subData['old_expiry']) && strtotime($subData['old_expiry']) > time()) {
                        $old_seconds = strtotime($subData['old_expiry']) - time();
                        $old_days = $old_seconds / 86400;
                        $old_remaining_value = $old_days * ($old_dealerPrice / 30);
                    }

                    // Calculate new value
                    $new_total_value = 0;
                    $new_seconds = strtotime($final_expiry) - time();
                    if ($new_seconds > 0) {
                        $new_days = $new_seconds / 86400;
                        $new_total_value = $new_days * ($new_dealerPrice / 30);
                    }

                    $net_deduction = round($new_total_value - $old_remaining_value, 2);

                    if ($net_deduction > $current_dealer['balance']) {
                        $pdo->rollBack();
                        echo "<script>alert('Insufficient balance! Need Rs. $net_deduction'); window.location='users.php';</script>";
                        exit;
                    }

                    if ($net_deduction > 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $dealer_id]);
                        $note = "Package/Expiry updated for $u. Deducted: Rs. $net_deduction";
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                    }
                }

                $pdo->prepare("UPDATE subscribers SET full_name = ?, password = ?, package_id = ?, expiry_date = ? WHERE id = ?")->execute([$full_name, $password, $new_package_id, $final_expiry, $id]);
                
                // Update Mikrotik-Rate-Limit if package changed
                if ($package_changed) {
                    $pStmt = $pdo->prepare("SELECT rate_limit FROM packages WHERE id = ?");
                    $pStmt->execute([$new_package_id]);
                    $rate_limit = $pStmt->fetchColumn();
                    
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    if (!empty($rate_limit) && $rate_limit !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $rate_limit]);
                    }
                }

                $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$password, $u]);
                
                $pdo->commit();
                echo "<script>alert('User profile updated successfully!'); window.location='users.php';</script>";
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error updating user.'); window.location='users.php';</script>";
                exit;
            }
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

    
    if ($action === 'toggle_user') {
        $id = (int)$_POST['id'];
        
        $subStmt = $pdo->prepare("SELECT username, status, client_id FROM subscribers WHERE id = ? AND dealer_id = ?");
        $subStmt->execute([$id, $dealer_id]);
        $sub = $subStmt->fetch();

        if ($sub) {
            $newStatus = $sub['status'] === 'active' ? 'disabled' : 'active';
            
            $pdo->prepare("UPDATE subscribers SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            
            if ($newStatus === 'disabled') {
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject') ON DUPLICATE KEY UPDATE value='Reject'")->execute([$sub['username']]);
                
                // Disconnect active session
                $nasStmt = $pdo->prepare("SELECT n.nasname, n.coa_port, n.secret FROM nas n 
                                        JOIN radacct r ON n.nasname = r.nasipaddress 
                                        WHERE r.username = ? AND r.acctstoptime IS NULL LIMIT 1");
                $nasStmt->execute([$sub['username']]);
                $nas = $nasStmt->fetch();
                if (!$nas) {
                    $nasStmt = $pdo->prepare("SELECT nasname, coa_port, secret FROM nas WHERE client_id = ? LIMIT 1");
                    $nasStmt->execute([$sub['client_id']]);
                    $nas = $nasStmt->fetch();
                }
                if ($nas) {
                    $ip = escapeshellarg($nas['nasname'] . ":" . $nas['coa_port']);
                    $secret = escapeshellarg($nas['secret']);
                    $user = escapeshellarg("User-Name=" . $sub['username']);
                    $cmd = "echo $user | radclient -x $ip disconnect $secret 2>&1";
                    exec($cmd);
                    $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Admin-Reset' WHERE username = ? AND acctstoptime IS NULL")->execute([$sub['username']]);
                }
                echo "<script>alert('User disabled and disconnected!'); window.location='users.php';</script>";
            } else {
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$sub['username']]);
                echo "<script>alert('User enabled!'); window.location='users.php';</script>";
            }
            exit;
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
// Handle Filter Logic
$filter = $_GET['filter'] ?? '';
$filter_sql = "";
$params = [$dealer_id];

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
        WHERE s.dealer_id = ? $filter_sql
        ORDER BY s.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
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
                        <th>Access</th>
                        <th>Status</th>
                        <th>Expiry</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($subs as $s): ?>
                    <tr class="border-bottom">
                        <td><a href="#" class="badge bg-primary fs-6 text-decoration-none edit-user-btn" data-id="<?= $s['id'] ?>" data-username="<?= htmlspecialchars($s['username']) ?>" data-fullname="<?= htmlspecialchars($s['full_name']) ?>" data-password="<?= htmlspecialchars($s['password']) ?>" data-expiry="<?= $s['expiry_date'] ? date('Y-m-d\TH:i', strtotime($s['expiry_date'])) : '' ?>" data-old-ts="<?= $s['expiry_date'] ? strtotime($s['expiry_date']) : 0 ?>" data-pkg="<?= $s['package_id'] ?>"><i class="fa-solid fa-pen me-1"></i> <?= htmlspecialchars($s['username']) ?></a></td>
                        <td class="fw-bold"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td><?= htmlspecialchars($s['package_name']) ?></td>
                        <td>
                            <?php if(isset($s['status']) && $s['status'] === 'active'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25">Disabled</span>
                            <?php endif; ?>
                        </td>
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
                                <form method="POST" onsubmit="return confirm('Are you sure you want to change this users access status?');" class="m-0">
                                    <input type="hidden" name="action" value="toggle_user">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <?php if($s['status'] === 'active'): ?>
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Disable User"><i class="fa-solid fa-ban"></i></button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Enable User"><i class="fa-solid fa-check"></i></button>
                                    <?php endif; ?>
                                </form>
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

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-pen text-primary me-2"></i> Edit User Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="id" id="edit_user_id">
        <div class="modal-body p-4 bg-light">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Username</label>
                    <input type="text" id="edit_username" class="form-control bg-white" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Full Name</label>
                    <input type="text" name="full_name" id="edit_fullname" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Password</label>
                    <input type="text" name="password" id="edit_password" class="form-control font-monospace" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Package</label>
                    <select name="package_id" id="edit_package_id" class="form-select">
                        <?php foreach($dealer_packages as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (Rs.<?= $p['dealer_price'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <?php if($can_custom_expiry): ?>
            <div class="mt-4 pt-3 border-top">
                <label class="form-label text-muted small fw-bold">Expiry Date</label>
                <input type="datetime-local" name="expiry_date" id="edit_expiry" class="form-control">
            </div>
            <?php else: ?>
                <!-- If dealer cannot set custom expiry, we use a hidden input for JS to know the value -->
                <input type="hidden" id="edit_expiry">
            <?php endif; ?>
            
            <div class="mt-4 p-3 bg-white border rounded shadow-sm d-flex justify-content-between align-items-center">
                <div class="text-secondary fw-bold small text-uppercase">Est. Balance Deduction</div>
                <h4 class="mb-0 fw-bold text-danger" id="live_deduction_amount">Rs. 0.00</h4>
            </div>
        </div>
        <div class="modal-footer bg-white border-top">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

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
                <select name="package_id" id="add_package_id" class="form-select" required>
                    <option value="">Select Package</option>
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (Rs.<?= $p['dealer_price'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if($can_custom_expiry): ?>
            <div class="mb-3">
                <label class="form-label">Custom Expiry (Optional)</label>
                <input type="datetime-local" name="expiry_date" id="add_expiry" class="form-control">
                <small class="text-muted">Leave blank for standard 30 days.</small>
            </div>
            <?php endif; ?>
        
            <?php if(!$can_custom_expiry): ?>
                <input type="hidden" id="add_expiry" value="">
            <?php endif; ?>
            <div class="mt-4 p-3 bg-light border rounded shadow-sm d-flex justify-content-between align-items-center">
                <div class="text-secondary fw-bold small text-uppercase">Est. Initial Deduction</div>
                <h4 class="mb-0 fw-bold text-danger" id="add_live_deduction">Rs. 0.00</h4>
            </div>

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
                <select name="package_id" id="add_package_id" class="form-select" required>
                    <option value="">Select Package</option>
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (Rs.<?= $p['dealer_price'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if($can_custom_expiry): ?>
            <div class="mb-3">
                <label class="form-label">Custom Expiry (Optional)</label>
                <input type="datetime-local" name="expiry_date" id="add_expiry" class="form-control">
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

    // Package prices dictionary for live calculation
    const pkgPrices = {
        <?php foreach($dealer_packages as $p) echo $p['id'] . ': ' . $p['dealer_price'] . ','; ?>
    };
    
    let current_old_ts = 0;
    let current_old_pkg = 0;

    function calculateLiveDeduction() {
        let new_pkg = document.getElementById("edit_package_id").value;
        let new_expiry_val = document.getElementById("edit_expiry").value;
        
        if (!new_pkg || !new_expiry_val) {
            document.getElementById("live_deduction_amount").innerText = "Rs. 0.00";
            return;
        }

        let old_price = parseFloat(pkgPrices[current_old_pkg] || 0);
        let new_price = parseFloat(pkgPrices[new_pkg] || 0);
        
        let now_sec = Math.floor(Date.now() / 1000);
        let new_ts = Math.floor(new Date(new_expiry_val).getTime() / 1000);
        
        let old_remaining_value = 0;
        if (current_old_ts > now_sec) {
            let old_days = (current_old_ts - now_sec) / 86400;
            old_remaining_value = old_days * (old_price / 30);
        }
        
        let new_total_value = 0;
        if (new_ts > now_sec) {
            let new_days = (new_ts - now_sec) / 86400;
            new_total_value = new_days * (new_price / 30);
        }
        
        let deduction = new_total_value - old_remaining_value;
        if (deduction < 0) deduction = 0;
        
        document.getElementById("live_deduction_amount").innerText = "Rs. " + deduction.toFixed(2);
    }

    
    function calculateAddDeduction() {
        let pkg = document.getElementById("add_package_id").value;
        let expiry_el = document.getElementById("add_expiry");
        let expiry_val = expiry_el ? expiry_el.value : "";

        let price = parseFloat(pkgPrices[pkg] || 0);
        if (price === 0) {
            document.getElementById("add_live_deduction").innerText = "Rs. 0.00";
            return;
        }

        let now_sec = Math.floor(Date.now() / 1000);
        let target_ts = 0;

        if (expiry_val) {
            target_ts = Math.floor(new Date(expiry_val).getTime() / 1000);
        } else {
            target_ts = now_sec + (30 * 86400);
        }

        let deduction = 0;
        if (target_ts > now_sec) {
            let days = Math.ceil((target_ts - now_sec) / 86400);
            deduction = days * (price / 30);
        }

        document.getElementById("add_live_deduction").innerText = "Rs. " + deduction.toFixed(2);
    }

    $("#add_package_id, #add_expiry").on("change input", calculateAddDeduction);

    // Bind events
    $("#edit_package_id, #edit_expiry").on("change input", calculateLiveDeduction);

    // Edit User Modal triggers
    $("#usersTable tbody").on("click", ".edit-user-btn", function(e) {
        e.preventDefault();
        $("#edit_user_id").val($(this).data("id"));
        $("#edit_username").val($(this).data("username"));
        $("#edit_fullname").val($(this).data("fullname"));
        $("#edit_password").val($(this).data("password"));
        
        current_old_pkg = $(this).data("pkg");
        current_old_ts = $(this).data("old-ts");
        
        $("#edit_package_id").val(current_old_pkg);
        $("#edit_expiry").val($(this).data("expiry"));
        
        calculateLiveDeduction();
        
        var modal = new bootstrap.Modal(document.getElementById("editUserModal"));
        modal.show();
    });

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