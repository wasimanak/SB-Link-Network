<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

if (!isset($_GET['id'])) {
    header("Location: subscribers.php");
    exit;
}
$id = (int)$_GET['id'];

// --- HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
    $uStmt->execute([$id, $client_id]);
    $u = $uStmt->fetchColumn();

    if ($u) {
        if ($action === 'delete_user') {
            $pdo->prepare("DELETE FROM subscribers WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
            echo "<script>alert('Profile deleted successfully!'); window.location='subscribers.php';</script>";
            exit;
        }
        elseif ($action === 'disable_net' || $action === 'profile_disable') {
            $pdo->prepare("UPDATE subscribers SET status = 'disabled' WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$u]);
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Profile Disabled')")->execute([$client_id, $u]);
            echo "<script>alert('Internet Disabled!'); window.location='subscriber_view.php?id=$id';</script>";
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
            $pdo->prepare("UPDATE subscribers SET full_name=?, national_id=?, mobile=?, address=? WHERE id=?")
                ->execute([$_POST['full_name'], $_POST['national_id'], $_POST['mobile'], $_POST['address'], $id]);
            echo "<script>alert('Profile updated successfully!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'add_note') {
            $pdo->prepare("UPDATE subscribers SET notes=? WHERE id=?")->execute([$_POST['notes'], $id]);
            echo "<script>alert('Note saved!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'add_balance') {
            $amount = (float)$_POST['amount'];
            $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id=?")->execute([$amount, $id]);
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Added Balance: $amount')")->execute([$client_id, $u]);
            echo "<script>alert('Balance added!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'renew_user') {
            $package_id = (int)$_POST['package_id'];
            $expiry_date = $_POST['expiry_date'];
            
            $p = $pdo->prepare("SELECT rate_limit FROM packages WHERE id = ? AND client_id = ?");
            $p->execute([$package_id, $client_id]);
            $pkg = $p->fetch();

            if ($pkg) {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active' WHERE id = ?")->execute([$package_id, $expiry_date, $id]);
                $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
                }
                $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
                $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', 'Package Renewed & Expiry Updated')")->execute([$client_id, $u]);
                $pdo->commit();
                echo "<script>alert('Renewed successfully!'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }
        }
    }
}
// --- END ACTIONS ---

// Fetch user data
$sql = "SELECT s.*, p.name as pkg_name, c.company_name 
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

// Fetch metrics from radacct
$volStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down, MAX(acctstoptime) as last_seen FROM radacct WHERE username = ?");
$volStmt->execute([$user['username']]);
$vol = $volStmt->fetch();

$total_bytes = ($vol['up'] ?? 0) + ($vol['down'] ?? 0);
$used_gb = $total_bytes > 0 ? round($total_bytes / 1073741824, 2) : 0.00;

// Check if currently online
$onStmt = $pdo->prepare("SELECT acctstarttime FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1");
$onStmt->execute([$user['username']]);
$online = $onStmt->fetch();

$uptime_str = "0.00";
if ($online) {
    $diff = time() - strtotime($online['acctstarttime']);
    $hours = floor($diff / 3600);
    $mins = floor(($diff % 3600) / 60);
    $uptime_str = "{$hours}h {$mins}m";
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
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
                <div class="avatar-large"><i class="fa-solid fa-user"></i></div>
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
                <button type="button" class="btn-pill"><i class="fa-regular fa-image"></i> Change Photo</button>
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
                    
                    <button class="btn-pill danger"><i class="fa-solid fa-plug-circle-xmark"></i> Disconnect</button>
                    
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="enable_net">
                        <button type="submit" class="btn-pill" style="color: #10b981; border-color: rgba(16, 185, 129, 0.3);"><i class="fa-solid fa-user-check"></i> Profile Enable</button>
                    </form>
                <?php else: ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Disable internet for this user?');">
                        <input type="hidden" name="action" value="disable_net">
                        <button type="submit" class="btn-pill danger"><i class="fa-solid fa-wifi"></i> Disable Net</button>
                    </form>
                    
                    <button class="btn-pill danger"><i class="fa-solid fa-plug-circle-xmark"></i> Disconnect</button>

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
                        <h4><?= $uptime_str ?></h4>
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
                        <h4><?= $used_gb ?> GB</h4>
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
                <div id="accService" class="accordion-collapse collapse" data-bs-parent="#userDetailsAccordion">
                    <div class="accordion-body text-secondary">
                        <p><strong>Package:</strong> <?= htmlspecialchars($user['pkg_name'] ?? 'N/A') ?></p>
                        <p><strong>Service Type:</strong> <?= strtoupper(htmlspecialchars($user['service_type'])) ?></p>
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
                    <div class="accordion-body text-secondary">
                        <p>No ledger transactions found yet.</p>
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
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-user-pen"></i> Edit Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_profile">
        <div class="modal-body">
            <div class="mb-2">
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>">
            </div>
            <div class="mb-2">
                <label class="form-label">National ID</label>
                <input type="text" name="national_id" class="form-control" value="<?= htmlspecialchars($user['national_id']) ?>">
            </div>
            <div class="mb-2">
                <label class="form-label">Mobile</label>
                <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($user['mobile']) ?>">
            </div>
            <div class="mb-2">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($user['address']) ?>">
            </div>
        </div>
        <div class="modal-footer">
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

<script>
const ctxLedger = document.getElementById('ledgerChart').getContext('2d');
new Chart(ctxLedger, {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        datasets: [
            { label: 'Payment', data: [2500, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], borderColor: '#64748b', tension: 0.1 },
            { label: 'Balance', data: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], borderColor: '#38bdf8', tension: 0.1 }
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'top', labels: { boxWidth: 12 } } }, scales: { y: { beginAtZero: true } } }
});

const ctxBw = document.getElementById('bwChart').getContext('2d');
new Chart(ctxBw, {
    type: 'line',
    data: {
        labels: ['0', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0'],
        datasets: [
            { label: 'Tx/Down (MB)', data: [0,0,0,0,0,0,0,0,0,0,0], borderColor: '#475569', tension: 0.1 },
            { label: 'Rx/Up (MB)', data: [0,0,0,0,0,0,0,0,0,0,0], borderColor: '#ef4444', tension: 0.1 }
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'top', labels: { boxWidth: 12 } } }, scales: { y: { min: -1.0, max: 1.0 } } }
});
</script>

<?php require_once 'footer.php'; ?>
