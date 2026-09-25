<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Handle Actions (Add, Renew, Delete, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
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
            $p = $pdo->prepare("SELECT rate_limit FROM packages WHERE id = ? AND client_id = ?");
            $p->execute([$package_id, $client_id]);
            $pkg = $p->fetch();

            if ($pkg) {
                try {
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
            $p = $pdo->prepare("SELECT rate_limit FROM packages WHERE id = ? AND client_id = ?");
            $p->execute([$package_id, $client_id]);
            $pkg = $p->fetch();

            if ($pkg) {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, service_type, full_name, national_id, mobile, phone, email, address, subarea, latitude, longitude) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$client_id, $package_id, $username, $password, $service_type, $full_name, $national_id, $mobile, $phone, $email, $address, $subarea, $latitude, $longitude]);
                    
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")
                        ->execute([$username, $password]);
                    
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")
                            ->execute([$username, $pkg['rate_limit']]);
                    }
                    
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

// Fetch Packages for Dropdown
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ?");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll();

// Advanced Fetch for Export Data
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
        WHERE s.client_id = ? 
        ORDER BY s.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);
$subs = $stmt->fetchAll();

function formatUptime($seconds) {
    if (!$seconds) return '0d 00h 00m 00s';
    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf("%dd %02dh %02dm %02ds", $d, $h, $m, $s);
}
?>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    /* Light Theme Button UI */
    .action-bar-top .btn {
        background-color: #334155;
        border: none;
        color: #fff;
        margin-right: 5px;
        margin-bottom: 10px;
        border-radius: 6px;
        padding: 8px 16px;
        font-weight: 500;
    }
    .action-bar-top .btn:hover { background-color: #1e293b; color: #fff; }
    
    .dt-buttons .btn {
        background-color: #475569;
        border: none;
        color: #fff;
        border-radius: 20px;
        padding: 4px 14px;
        font-size: 0.85rem;
        margin-right: 4px;
    }
    .dt-buttons .btn:hover { background-color: #334155; }
    
    .table-custom-ui {
        background-color: #ffffff;
        border-radius: 8px;
        overflow: hidden;
    }
    .table-custom-ui thead th {
        background-color: #f8f9fa;
        color: #64748b;
        border-bottom: 2px solid #e5e7eb;
        font-weight: 600;
        font-size: 0.9rem;
        white-space: nowrap;
    }
    .table-custom-ui tbody td {
        vertical-align: middle;
        border-bottom: 1px solid #e5e7eb;
        color: #334155;
        font-size: 0.9rem;
    }
    .avatar-circle {
        width: 40px; height: 40px;
        background-color: #334155;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        color: #cbd5e1; font-size: 1.2rem;
    }
    .badge-soft-success { background-color: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); }
    .badge-soft-secondary { background-color: rgba(148, 163, 184, 0.15); color: #64748b; border: 1px solid rgba(148, 163, 184, 0.2); }
    .badge-soft-primary { background-color: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.2); }
    .badge-soft-warning { background-color: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.2); }
    
    .dataTables_wrapper .row { margin-bottom: 15px; }
    .dataTables_filter input { background-color: #ffffff; border: 1px solid #ced4da; color: #333; border-radius: 20px; padding: 4px 15px; }
    .dataTables_length select { background-color: #ffffff; border: 1px solid #ced4da; color: #333; border-radius: 6px; }

    .modal-content.light-modal {
        background-color: #fff;
        color: #333;
        border-radius: 8px;
    }
    .light-modal .modal-header {
        border-bottom: 1px solid #eaeaea;
        background-color: #fcfcfc;
        border-radius: 8px 8px 0 0;
    }
    .light-modal .modal-title { color: #64748b; font-size: 1.1rem; font-weight: 600; }
    .light-modal .accordion-button {
        background-color: #f1f5f9;
        color: #334155;
        font-weight: 600;
        border: 1px solid #e2e8f0;
    }
    .light-modal .accordion-button:not(.collapsed) {
        background-color: #f1f5f9;
        color: #334155;
        box-shadow: none;
    }
    .light-modal .accordion-item { border: none; margin-bottom: 15px; }
    .light-modal .col-form-label {
        color: #64748b;
        font-weight: 700;
        font-size: 0.9rem;
    }
    .light-modal .form-control, .light-modal .form-select {
        border: 1px solid #cbd5e1;
        border-radius: 4px;
        color: #333;
        background-color: #fff;
        padding: 0.4rem 0.75rem;
    }
    .light-modal .form-control:focus, .light-modal .form-select:focus {
        border-color: #94a3b8;
        box-shadow: none;
    }
    .light-modal .btn-submit { background-color: #1e293b; color: #fff; border: none; padding: 6px 20px; }
    .light-modal .btn-submit:hover { background-color: #0f172a; color: #fff; }
</style>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 me-3"><i class="fa-solid fa-users text-primary"></i> Users</h4>
</div>

<!-- Top Action Bar -->
<div class="action-bar-top">
    <button class="btn shadow-sm"><i class="fa-solid fa-trash me-1"></i> Mass Delete</button>
    <button class="btn shadow-sm"><i class="fa-brands fa-paypal me-1"></i> Mass Payment</button>
    <button class="btn shadow-sm"><i class="fa-solid fa-user-check me-1"></i> Mass Activation/Renew</button>
    <button class="btn shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa-solid fa-user-plus me-1"></i> Add New User</button>
    <a href="mikrotik_sync.php" class="btn shadow-sm text-decoration-none"><i class="fa-solid fa-file-import me-1"></i> Import Users</a>
</div>

<div class="card table-custom-ui p-3 mt-3 shadow-sm border-0">
    <div class="table-responsive">
        <table id="usersTable" class="table table-hover table-borderless w-100">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll"></th>
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
                    <th>On/Off</th>
                    <th>On/Off Time</th>
                    <th>Usage Data/Time</th>
                    <th>Expiry</th>
                    <th>NAS</th>
                    <th>Created</th>
                    <th>Action</th>
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
                    <td><input type="checkbox" class="row-checkbox" value="<?= $s['id'] ?>"></td>
                    <td><?= $s['id'] ?></td>
                    <td>
                        <div class="avatar-circle">
                            <i class="fa-solid fa-user"></i>
                        </div>
                    </td>
                    <td>
                        <a href="subscriber_view.php?id=<?= $s['id'] ?>">
                            <span class="badge rounded-pill badge-soft-success px-3 py-2"><?= htmlspecialchars($s['username']) ?></span>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($s['password']) ?></td>
                    <td><?= htmlspecialchars($s['full_name']) ?></td>
                    <td><?= htmlspecialchars($s['national_id']) ?></td>
                    <td><?= htmlspecialchars($s['mobile'] ?: ($s['phone'] ?: 'N/A')) ?></td>
                    <td><?= htmlspecialchars($s['address']) ?></td>
                    <td>
                        <?= htmlspecialchars($s['package_name'] ?? 'N/A') ?>
                        <br><small class="text-muted">(Local)</small>
                    </td>
                    <td>
                        <span class="badge rounded-pill badge-soft-warning px-3 py-2"><?= number_format($s['balance'], 2) ?></span>
                    </td>
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
                    <td><?= $on_time ?><br><?= $off_time ?></td>
                    <td><?= $usage_gb ?> GB<br><?= $uptime_fmt ?></td>
                    <td>
                        <?php if($s['expiry_date']): ?>
                            <span class="badge rounded-pill badge-soft-success px-3 py-2">
                                <?= date('d M Y h:i A', strtotime($s['expiry_date'])) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-2">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($s['nas_ip'] ?: 'N/A') ?></td>
                    <td><?= $s['created_at'] ? date('Y-m-d H:i:s', strtotime($s['created_at'])) : 'N/A' ?></td>
                    <td>
                        <div class="d-flex flex-column gap-1">
                            <a href="#" class="badge rounded-pill badge-soft-primary text-decoration-none px-3 py-2"><i class="fa-brands fa-paypal"></i> Payment</a>
                            <div class="d-flex gap-1">
                                <!-- Trigger Renew Modal -->
                                <button type="button" class="badge rounded-pill badge-soft-success border-0 px-2 py-2 w-100 btn-renew" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#renewModal" 
                                    data-id="<?= $s['id'] ?>" 
                                    data-username="<?= htmlspecialchars($s['username']) ?>" 
                                    data-pkg="<?= $s['package_id'] ?>" 
                                    data-expiry="<?= $s['expiry_date'] ? date('Y-m-d\TH:i', strtotime($s['expiry_date'])) : '' ?>">
                                    <i class="fa-solid fa-rotate"></i> Renew
                                </button>
                                
                                <form action="subscriber_action.php" method="POST" class="d-inline" onsubmit="return confirm('Disconnect this user now?');">
                                    <input type="hidden" name="action" value="kick">
                                    <input type="hidden" name="username" value="<?= htmlspecialchars($s['username']) ?>">
                                    <button type="submit" class="badge rounded-pill badge-soft-success border-0 px-2 py-2" title="Live Kick"><i class="fa-solid fa-check"></i></button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user permanently?');">
                                    <input type="hidden" name="action" value="delete_id">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="badge rounded-pill badge-soft-secondary border-0 px-2 py-2" title="Delete"><i class="fa-solid fa-trash"></i></button>
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
      <form method="POST">
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body p-4">
            
            <div class="accordion" id="addUserAccordion">
                
                <!-- Account Info -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAccount">
                            <i class="fa-solid fa-user"></i> Account Information
                        </button>
                    </h2>
                    <div id="collapseAccount" class="accordion-collapse collapse show" data-bs-parent="#addUserAccordion">
                        <div class="accordion-body">
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
                    </div>
                </div>

                <!-- Service Info -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseService">
                            <i class="fa-solid fa-wifi"></i> Service & Package
                        </button>
                    </h2>
                    <div id="collapseService" class="accordion-collapse collapse" data-bs-parent="#addUserAccordion">
                        <div class="accordion-body">
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
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact Info -->
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseContact">
                            <i class="fa-solid fa-address-book"></i> Contact & Location
                        </button>
                    </h2>
                    <div id="collapseContact" class="accordion-collapse collapse" data-bs-parent="#addUserAccordion">
                        <div class="accordion-body">
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
        dom: '<"row align-items-center"<"col-md-2"l><"col-md-6 dt-buttons"B><"col-md-4"f>>rtip',
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
            searchPlaceholder: "Type & Submit"
        }
    });

    $('#selectAll').on('click', function(){
        var rows = $('#usersTable').DataTable().rows({ 'search': 'applied' }).nodes();
        $('input[type="checkbox"]', rows).prop('checked', this.checked);
    });

    $('#usersTable tbody').on('click', '.btn-renew', function() {
        var id = $(this).data('id');
        var username = $(this).data('username');
        var pkg = $(this).data('pkg');
        var expiry = $(this).data('expiry'); 

        $('#renew_user_id').val(id);
        $('#renew_username_display').text(username);
        $('#renew_package_id').val(pkg);
        
        if(expiry) {
            $('#renew_expiry_date').val(expiry);
        } else {
            $('#renew_expiry_date').val('');
        }
    });
});
</script>

<?php require_once 'footer.php'; ?>
