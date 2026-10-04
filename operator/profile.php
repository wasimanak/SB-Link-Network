<?php
require_once 'header.php';

$client_id = $_SESSION['operator_id'];

// --- HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_router') {
        $nasname = trim($_POST['nasname'] ?? '');
        $secret = trim($_POST['secret'] ?? '');
        $api_user = trim($_POST['api_user'] ?? '');
        $api_pass = trim($_POST['api_password'] ?? '');
        
        if (empty($nasname) || empty($secret) || empty($api_user)) {
            echo "<script>alert('Router IP, Secret, and API Username are required.'); window.location='profile.php';</script>";
            exit;
        } else {
            try {
                $chk = $pdo->prepare("SELECT id FROM nas WHERE client_id = ?");
                $chk->execute([$client_id]);
                $existing_nas = $chk->fetch();
                
                if ($existing_nas) {
                    if (empty($api_pass)) {
                        $upd = $pdo->prepare("UPDATE nas SET nasname=?, secret=?, api_user=? WHERE client_id=?");
                        $upd->execute([$nasname, $secret, $api_user, $client_id]);
                    } else {
                        $upd = $pdo->prepare("UPDATE nas SET nasname=?, secret=?, api_user=?, api_password=? WHERE client_id=?");
                        $upd->execute([$nasname, $secret, $api_user, $api_pass, $client_id]);
                    }
                } else {
                    $pdo->prepare("INSERT INTO nas (client_id, nasname, shortname, secret, api_port, api_user, api_password) VALUES (?, ?, 'Primary Router', ?, 8728, ?, ?)")
                        ->execute([$client_id, $nasname, $secret, $api_user, $api_pass]);
                }
                echo "<script>alert('MikroTik Router settings updated successfully!'); window.location='profile.php';</script>";
                exit;
            } catch (PDOException $e) {
                echo "<script>alert('Error updating router: " . addslashes($e->getMessage()) . "'); window.location='profile.php';</script>";
                exit;
            }
        }
    }
    elseif ($action === 'change_password') {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        
        $stmt = $pdo->prepare("SELECT password FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client_pass = $stmt->fetchColumn();
        
        if (password_verify($current, $client_pass)) {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE clients SET password = ? WHERE id = ?")->execute([$hashed, $client_id]);
            echo "<script>alert('Password updated successfully!'); window.location='profile.php';</script>";
        } else {
            echo "<script>alert('Current password is incorrect.');</script>";
        }
    }
    elseif ($action === 'add_note') {
        $notes = trim($_POST['notes']);
        $pdo->prepare("UPDATE clients SET notes = ? WHERE id = ?")->execute([$notes, $client_id]);
        echo "<script>alert('Notes updated successfully!'); window.location='profile.php';</script>";
        exit;
    }
}

// --- FETCH DATA ---
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$user = $stmt->fetch();

$total_users = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE client_id = $client_id")->fetchColumn();
$total_packages = $pdo->query("SELECT COUNT(*) FROM packages WHERE client_id = $client_id")->fetchColumn();
$total_advance = $pdo->query("SELECT SUM(balance) FROM subscribers WHERE client_id = $client_id")->fetchColumn() ?: 0;
$total_dealers = $pdo->query("SELECT COUNT(*) FROM dealers WHERE client_id = $client_id")->fetchColumn() ?: 0;
$total_subdealers = 0; // Placeholder as per UI

// Fetch packages list for the accordion
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ? ORDER BY id DESC");
$pkgStmt->execute([$client_id]);
$packages_list = $pkgStmt->fetchAll();

// Fetch NAS
$nasStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ?");
$nasStmt->execute([$client_id]);
$nas = $nasStmt->fetch();

?>

<style>
    .view-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; margin-bottom: 20px; }
    
    .profile-header { display: flex; align-items: center; gap: 15px; padding: 20px; border-bottom: 1px solid #f1f5f9; }
    .avatar-large { width: 60px; height: 60px; background-color: #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #94a3b8; }
    .profile-info h5 { margin: 0; font-weight: 700; color: #1e293b; font-size: 1.1rem; }
    .profile-info p { margin: 0; color: #64748b; font-size: 0.85rem; }
    .status-badge { display: inline-block; background: rgba(16, 185, 129, 0.15); color: #10b981; padding: 2px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; margin-top: 5px; border: 1px solid rgba(16, 185, 129, 0.2); }
    
    .profile-list { list-style: none; padding: 0; margin: 0; }
    .profile-list li { padding: 12px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #f8fafc; color: #475569; font-size: 0.9rem; }
    .profile-list li i { color: #64748b; width: 16px; text-align: center; }
    
    .action-grid { padding: 20px; display: flex; flex-wrap: wrap; gap: 10px; }
    .btn-pill { background: #fff; border: 1px solid #e2e8f0; color: #334155; border-radius: 8px; padding: 8px 14px; font-size: 0.85rem; font-weight: 500; transition: 0.2s; cursor: pointer; }
    .btn-pill:hover { background: #f8fafc; border-color: #cbd5e1; }
    .btn-pill.dark { background: #1e293b; color: #fff; border-color: #1e293b; }
    .btn-pill.dark:hover { background: #0f172a; }

    /* Top Metric Cards */
    .metric-card-sm { background: #fff; border-radius: 12px; padding: 15px 20px; display: flex; align-items: center; gap: 15px; border: 1px solid #f1f5f9; box-shadow: 0 2px 10px rgba(0,0,0,0.02); height: 100%; }
    .metric-icon-sm { width: 45px; height: 45px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
    
    .bg-light-cyan { background: #e0f2fe; color: #0284c7; }
    .bg-light-blue { background: #e0e7ff; color: #4f46e5; }
    .bg-light-orange { background: #ffedd5; color: #c2410c; }
    .bg-light-green { background: #dcfce7; color: #15803d; }
    
    .metric-data-sm h6 { margin: 0; font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
    .metric-data-sm h4 { margin: 0; font-size: 1.4rem; font-weight: 700; color: #1e293b; }

    /* Accordions */
    .custom-accordion .accordion-item { border: none; background: #fff; border-radius: 12px !important; margin-bottom: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); }
    .custom-accordion .accordion-button { background: #fff; color: #1e293b; font-weight: 600; padding: 15px 20px; box-shadow: none; border-radius: 12px !important; }
    .custom-accordion .accordion-button:not(.collapsed) { background: #f8fafc; border-bottom: 1px solid #f1f5f9; border-bottom-left-radius: 0 !important; border-bottom-right-radius: 0 !important; }
    .custom-accordion .accordion-button i { color: #64748b; margin-right: 12px; width: 16px; text-align: center; }
    .btn-acc-action { background: #f59e0b; color: #1e293b; border: none; font-size: 0.8rem; padding: 6px 14px; border-radius: 8px; font-weight: 600; text-decoration: none; position: absolute; right: 50px; z-index: 5; }
    .btn-acc-action:hover { background: #d97706; color: #fff; }
</style>

<div class="row">
    <!-- Left Column: Profile Card -->
    <div class="col-lg-4 col-md-5">
        <div class="view-card">
            <div class="profile-header">
                <div class="avatar-large"><i class="fa-solid fa-user"></i></div>
                <div class="profile-info">
                    <h5><?= htmlspecialchars($user['full_name'] ?: $user['company_name']) ?></h5>
                    <p><?= htmlspecialchars($user['username'] ?: $user['email']) ?> &bull; Franchise</p>
                    <span class="status-badge">Active</span>
                </div>
            </div>
            <ul class="profile-list">
                <li><i class="fa-solid fa-building"></i> <?= htmlspecialchars($user['company_name']) ?></li>
                <li><i class="fa-regular fa-id-card"></i> <?= htmlspecialchars($user['national_id'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($user['phone'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($user['email']) ?></li>
                <li><i class="fa-solid fa-map-pin"></i> <?= htmlspecialchars($user['subarea'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($user['address'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-calendar-alt"></i> <?= date('Y-m-d H:i:s', strtotime($user['created_at'])) ?></li>
            </ul>
            <div class="action-grid">
                <button type="button" class="btn-pill dark" data-bs-toggle="modal" data-bs-target="#routerModal"><i class="fa-solid fa-server"></i> Router Settings</button>
                <button type="button" class="btn-pill"><i class="fa-regular fa-image"></i> Change Photo</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="fa-solid fa-lock"></i> Change Password</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#noteModal"><i class="fa-regular fa-note-sticky"></i> Add Note</button>
                <button type="button" class="btn-pill"><i class="fa-solid fa-file-circle-plus"></i> Add Document</button>
            </div>
        </div>
    </div>

    <!-- Right Column: Metrics & Accordions -->
    <div class="col-lg-8 col-md-7">
        
        <!-- Top Metrics Row 1 -->
        <div class="row mb-3">
            <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
                <div class="metric-card-sm">
                    <div class="metric-icon-sm bg-light-cyan"><i class="fa-solid fa-users"></i></div>
                    <div class="metric-data-sm">
                        <h6>Total Users</h6>
                        <h4><?= number_format($total_users) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
                <div class="metric-card-sm">
                    <div class="metric-icon-sm bg-light-blue"><i class="fa-solid fa-user-tie"></i></div>
                    <div class="metric-data-sm">
                        <h6>Total Dealers</h6>
                        <h4><?= number_format($total_dealers) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-3 mb-sm-0">
                <div class="metric-card-sm">
                    <div class="metric-icon-sm bg-light-orange"><i class="fa-solid fa-users-gear"></i></div>
                    <div class="metric-data-sm">
                        <h6>Total Subdealers</h6>
                        <h4><?= number_format($total_subdealers) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="metric-card-sm">
                    <div class="metric-icon-sm bg-light-green"><i class="fa-solid fa-box-open"></i></div>
                    <div class="metric-data-sm">
                        <h6>Total Packages</h6>
                        <h4><?= number_format($total_packages) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Metrics Row 2 -->
        <div class="row mb-4">
            <div class="col-xl-4 col-md-6">
                <div class="metric-card-sm py-4">
                    <div class="metric-icon-sm bg-light-grey" style="background:#f1f5f9; color:#475569;"><i class="fa-regular fa-credit-card"></i></div>
                    <div class="metric-data-sm">
                        <h6>Total Advance / Balance</h6>
                        <h4 class="<?= $total_advance < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= number_format($total_advance, 2) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accordions -->
        <div class="accordion custom-accordion" id="profileAccordion">
            
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accPackages">
                        <i class="fa-solid fa-box"></i> All Packages
                    </button>
                </h2>
                <div id="accPackages" class="accordion-collapse collapse" data-bs-parent="#profileAccordion">
                    <div class="accordion-body text-secondary p-4">
                        <?php if(empty($packages_list)): ?>
                            <p>No packages found.</p>
                        <?php else: ?>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm table-hover border">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Package Name</th>
                                            <th>Rate Limit</th>
                                            <th>Price</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($packages_list as $p): ?>
                                        <tr>
                                            <td><span class="badge rounded-pill bg-light text-dark border"><?= htmlspecialchars($p['name']) ?></span></td>
                                            <td><?= htmlspecialchars($p['rate_limit'] ?: 'N/A') ?></td>
                                            <td><span class="text-success fw-bold"><?= number_format($p['price'], 2) ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <a href="packages.php" class="btn btn-outline-primary btn-sm">Manage Packages</a>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accLedger">
                        <i class="fa-solid fa-chart-line"></i> Ledger
                    </button>
                </h2>
                <div id="accLedger" class="accordion-collapse collapse" data-bs-parent="#profileAccordion">
                    <div class="accordion-body text-secondary p-4">
                        No ledger transactions found.
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accUsers">
                        <i class="fa-solid fa-users"></i> All Users
                    </button>
                </h2>
                <div id="accUsers" class="accordion-collapse collapse" data-bs-parent="#profileAccordion">
                    <div class="accordion-body text-secondary p-4">
                        <a href="subscribers.php" class="btn btn-outline-primary btn-sm">Manage Users</a>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header d-flex align-items-center">
                    <button class="accordion-button collapsed flex-grow-1" type="button" data-bs-toggle="collapse" data-bs-target="#accDocs">
                        <i class="fa-regular fa-file"></i> Documents
                    </button>
                    <a href="#" class="btn-acc-action"><i class="fa-solid fa-file-circle-plus"></i> Add Document</a>
                </h2>
                <div id="accDocs" class="accordion-collapse collapse" data-bs-parent="#profileAccordion">
                    <div class="accordion-body text-secondary p-4">
                        No documents uploaded.
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accLog">
                        <i class="fa-solid fa-list-ul"></i> Activity Log
                    </button>
                </h2>
                <div id="accLog" class="accordion-collapse collapse" data-bs-parent="#profileAccordion">
                    <div class="accordion-body text-secondary p-4">
                        <a href="activity_logs.php" class="btn btn-outline-primary btn-sm">View Full Logs</a>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<!-- ================= MODALS ================= -->

<!-- Router Settings Modal -->
<div class="modal fade" id="routerModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-server text-primary"></i> MikroTik Router Settings</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="update_router">
        <div class="modal-body">
            <div class="alert alert-info" style="font-size: 0.85rem;">
                Enter your MikroTik Router connection details here to sync Hotspot/PPPoE profiles and manage users.
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router IP Address (NAS)</label>
                <input type="text" name="nasname" class="form-control font-monospace" placeholder="e.g. 10.133.13.69" value="<?= htmlspecialchars($nas['nasname'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">RADIUS Secret</label>
                <input type="text" name="secret" class="form-control font-monospace" placeholder="e.g. 123456" value="<?= htmlspecialchars($nas['secret'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">API Username</label>
                <input type="text" name="api_user" class="form-control font-monospace" placeholder="e.g. admin" value="<?= htmlspecialchars($nas['api_user'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">API Password</label>
                <input type="password" name="api_password" class="form-control font-monospace" placeholder="<?= $nas ? 'Leave blank to keep unchanged' : 'Required' ?>" <?= $nas ? '' : 'required' ?>>
            </div>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <div>
            <?php if($nas): ?>
                <span class="badge bg-success rounded-pill px-3 py-2"><i class="fa-solid fa-link me-1"></i> Linked</span>
            <?php else: ?>
                <span class="badge bg-danger rounded-pill px-3 py-2"><i class="fa-solid fa-unlink me-1"></i> Not Linked</span>
            <?php endif; ?>
          </div>
          <div>
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Save Router</button>
          </div>
        </div>
      </form>
      
      <?php if($nas): ?>
        <div class="p-3 bg-light border-top">
            <h6 class="fw-bold mb-2"><i class="fa-solid fa-terminal me-2"></i> MikroTik Quick Setup Script</h6>
            <p class="small text-muted mb-2">Copy & paste this into the MikroTik <strong>New Terminal</strong> to auto-configure RADIUS.</p>
            <div class="position-relative">
                <?php 
                    global $host; 
                    $radius_ip = $host; 
                    $secret = $nas['secret'];
                    
                    $mt_script = "/radius add address=$radius_ip secret=\"$secret\" service=ppp,hotspot\n";
                    $mt_script .= "/radius incoming set accept=yes port=3799\n";
                    $mt_script .= "/ppp aaa set use-radius=yes interim-update=1m\n";
                    $mt_script .= "/ip hotspot profile set [find] use-radius=yes radius-interim-update=1m\n";
                ?>
                <textarea id="mtScript" class="form-control font-monospace bg-dark text-success" rows="4" readonly style="font-size: 13px; resize: none;"><?= htmlspecialchars($mt_script) ?></textarea>
                <button type="button" class="btn btn-sm btn-light position-absolute top-0 end-0 m-2 shadow-sm fw-bold" onclick="copyScript()">
                    <i class="fa-regular fa-copy me-1"></i> Copy
                </button>
            </div>
            <script>
            function copyScript() {
                var copyText = document.getElementById("mtScript");
                copyText.select();
                copyText.setSelectionRange(0, 99999);
                navigator.clipboard.writeText(copyText.value);
                alert("Script copied to clipboard!");
            }
            </script>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-lock"></i> Change Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Current Password</label>
                <input type="password" name="current_password" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-dark">Update Password</button>
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
        <h5 class="modal-title"><i class="fa-regular fa-note-sticky"></i> Profile Notes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_note">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Notes / Remarks</label>
                <textarea name="notes" class="form-control" rows="5"><?= htmlspecialchars($user['notes'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-dark">Save Note</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once 'footer.php'; ?>
