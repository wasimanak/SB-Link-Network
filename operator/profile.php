<?php
require_once 'header.php';

$client_id = $_SESSION['operator_id'];

// --- HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_router') {
        $nasname = trim($_POST['nasname'] ?? '');
        $shortname = trim($_POST['shortname'] ?? 'Router');
        $secret = trim($_POST['secret'] ?? '');
        $api_user = trim($_POST['api_user'] ?? '');
        $api_pass = trim($_POST['api_password'] ?? '');
        
        $chk = $pdo->prepare("SELECT COUNT(*) FROM nas WHERE client_id = ?");
        $chk->execute([$client_id]);
        $current_routers = $chk->fetchColumn();
        
        $uStmt = $pdo->prepare("SELECT max_routers FROM clients WHERE id = ?");
        $uStmt->execute([$client_id]);
        $max_r = $uStmt->fetchColumn() ?: 1;
        
        if ($current_routers >= $max_r) {
            echo "<script>alert('Router limit reached! You cannot add more routers.'); window.location='profile.php';</script>";
            exit;
        }
        
        try {
            $pdo->prepare("INSERT INTO nas (client_id, nasname, shortname, secret, api_port, api_user, api_password) VALUES (?, ?, ?, ?, 8728, ?, ?)")
                ->execute([$client_id, $nasname, $shortname, $secret, $api_user, $api_pass]);
            
            // Silently restart FreeRADIUS so the new router is active immediately
            exec("sudo systemctl restart freeradius 2>&1");
            exec("systemctl restart freeradius 2>&1");
            echo "<script>alert('Router added successfully!'); window.location='profile.php';</script>";
        } catch(Exception $e) {
            echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location='profile.php';</script>";
        }
        exit;
    }
    
    if ($action === 'update_router') {
        $nas_id = (int)$_POST['nas_id'];
        $nasname = trim($_POST['nasname'] ?? '');
        $shortname = trim($_POST['shortname'] ?? 'Router');
        $secret = trim($_POST['secret'] ?? '');
        $api_user = trim($_POST['api_user'] ?? '');
        $api_pass = trim($_POST['api_password'] ?? '');
        
        try {
            if (empty($api_pass)) {
                $pdo->prepare("UPDATE nas SET nasname=?, shortname=?, secret=?, api_user=? WHERE id=? AND client_id=?")->execute([$nasname, $shortname, $secret, $api_user, $nas_id, $client_id]);
            } else {
                $pdo->prepare("UPDATE nas SET nasname=?, shortname=?, secret=?, api_user=?, api_password=? WHERE id=? AND client_id=?")->execute([$nasname, $shortname, $secret, $api_user, $api_pass, $nas_id, $client_id]);
            }
            echo "<script>alert('Router updated successfully!'); window.location='profile.php';</script>";
        } catch(Exception $e) {
            echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location='profile.php';</script>";
        }
        exit;
    }
    
    if ($action === 'delete_router') {
        $nas_id = (int)$_POST['nas_id'];
        $pdo->prepare("DELETE FROM nas WHERE id=? AND client_id=?")->execute([$nas_id, $client_id]);
        echo "<script>alert('Router deleted successfully!'); window.location='profile.php';</script>";
        exit;
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
$total_advance = $pdo->query("SELECT SUM(amount) FROM user_ledger WHERE client_id = $client_id AND type = 'credit'")->fetchColumn() ?: 0;
$total_dealers = $pdo->query("SELECT COUNT(*) FROM dealers WHERE client_id = $client_id")->fetchColumn() ?: 0;
$total_subdealers = 0; // Placeholder as per UI

// Fetch packages list for the accordion
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ? ORDER BY id DESC");
$pkgStmt->execute([$client_id]);
$packages_list = $pkgStmt->fetchAll();

// Fetch NAS
$nasStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ?");
$nasStmt->execute([$client_id]);
$routers = $nasStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
    .live-dot {
        display: inline-block; width: 8px; height: 8px; background-color: #10b981; border-radius: 50%; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); animation: pulse-green 1.5s infinite;
    }
    @keyframes pulse-green {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
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
                <a href="statement.php" class="text-decoration-none">
                <div class="metric-card-sm py-4" style="transition: all 0.2s; cursor: pointer;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 5px 15px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                    <div class="metric-icon-sm bg-light-grey" style="background:#f1f5f9; color:#475569;"><i class="fa-regular fa-credit-card"></i></div>
                    <div class="metric-data-sm">
                        <h6 class="text-dark">Total Recharge (LifeTime)</h6>
                        <h4 class="<?= $total_advance < 0 ? 'text-danger' : 'text-success' ?>">Rs. <?= number_format($total_advance, 2) ?></h4>
                        <small class="text-muted"><i class="fa-solid fa-arrow-up-right-from-square"></i> View Statement</small>
                    </div>
                </div>
            </a>
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

<!-- Router List Modal -->
<div class="modal fade" id="routerModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-server text-primary me-2"></i> Manage MikroTik Routers</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4 bg-light">
          
          <div class="d-flex justify-content-between align-items-center mb-3">
              <span class="text-secondary fw-bold small text-uppercase"><i class="fa-solid fa-chart-pie me-1"></i> Quota: <?= count($routers) ?> / <?= $user['max_routers'] ?> Routers</span>
              <?php if(count($routers) < $user['max_routers']): ?>
                  <button type="button" class="btn btn-primary btn-sm rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#addRouterModal"><i class="fa-solid fa-plus me-1"></i> Add New Router</button>
              <?php else: ?>
                  <span class="badge bg-danger rounded-pill px-3 py-2"><i class="fa-solid fa-ban me-1"></i> Limit Reached</span>
              <?php endif; ?>
          </div>

          <?php if(empty($routers)): ?>
              <div class="alert alert-warning text-center border-warning border-opacity-25 shadow-sm fw-bold">
                  <i class="fa-solid fa-triangle-exclamation fs-4 mb-2 d-block"></i> No routers linked! Add one to sync users.
              </div>
          <?php endif; ?>

          <div class="row g-3">
              <?php foreach($routers as $r): ?>
              <div class="col-md-6">
                  <div class="card border-0 shadow-sm rounded-4 h-100">
                      <div class="card-body p-3">
                          <div class="d-flex justify-content-between align-items-start mb-2">
                              <div class="d-flex align-items-center gap-2">
                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-microchip text-primary me-1"></i> <?= htmlspecialchars($r['shortname'] ?: 'Router') ?></h6>
                <div class="router-status" data-id="<?= $r['id'] ?>"><span class="small text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Checking...</span></div>
            </div>
                              <div class="dropdown">
                                  <button class="btn btn-light btn-sm rounded-circle shadow-sm" style="width:30px; height:30px; padding:0;" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                  <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                      <li><a class="dropdown-item fw-bold text-primary edit-router-btn" href="#" data-id="<?= $r['id'] ?>" data-ip="<?= htmlspecialchars($r['nasname']) ?>" data-short="<?= htmlspecialchars($r['shortname']) ?>" data-sec="<?= htmlspecialchars($r['secret']) ?>" data-usr="<?= htmlspecialchars($r['api_user']) ?>" data-bs-toggle="modal" data-bs-target="#editRouterModal"><i class="fa-solid fa-pen me-2"></i> Edit Config</a></li>
                                      <li><hr class="dropdown-divider"></li>
                                      <li>
                                          <form method="POST" onsubmit="return confirm('WARNING: Deleting this router will break RADIUS authentication for users connected to it. Continue?');">
                                              <input type="hidden" name="action" value="delete_router">
                                              <input type="hidden" name="nas_id" value="<?= $r['id'] ?>">
                                              <button type="submit" class="dropdown-item fw-bold text-danger"><i class="fa-solid fa-trash me-2"></i> Remove Router</button>
                                          </form>
                                      </li>
                                  </ul>
                              </div>
                          </div>
                          
                          <div class="mb-1"><span class="badge bg-dark bg-opacity-10 text-dark border border-secondary px-2"><i class="fa-solid fa-network-wired me-1"></i> IP: <?= htmlspecialchars($r['nasname']) ?></span></div>
                          <div class="mb-3"><span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fa-solid fa-link me-1"></i> Linked</span></div>
                          
                          <button class="btn btn-sm btn-outline-secondary w-100 rounded-pill script-btn" data-script="/radius add address=<?= $host ?> secret=&quot;<?= $r['secret'] ?>&quot; service=ppp,hotspot\n/radius incoming set accept=yes port=3799\n/ppp aaa set use-radius=yes interim-update=1m\n/ip hotspot profile set [find] use-radius=yes radius-interim-update=1m\n">
                              <i class="fa-solid fa-terminal me-1"></i> Show Setup Script
                          </button>
                      </div>
                  </div>
              </div>
              <?php endforeach; ?>
          </div>
      </div>
    </div>
  </div>
</div>

<!-- Add Router Modal -->
<div class="modal fade" id="addRouterModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-bottom p-3">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus-circle text-success me-2"></i> Add New Router</h5>
        <button type="button" class="btn-close" data-bs-toggle="modal" data-bs-target="#routerModal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_router">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router Name / Location</label>
                <input type="text" name="shortname" class="form-control" placeholder="e.g. Main Area, Tower 2" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router IP Address (NAS)</label>
                <input type="text" name="nasname" class="form-control font-monospace" placeholder="e.g. 10.133.13.69" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">RADIUS Secret</label>
                <input type="text" name="secret" class="form-control font-monospace" placeholder="e.g. 123456" required>
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Username</label>
                    <input type="text" name="api_user" class="form-control font-monospace" placeholder="admin" required>
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Password</label>
                    <input type="password" name="api_password" class="form-control font-monospace" required>
                </div>
            </div>
        </div>
        <div class="modal-footer p-3">
            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#routerModal">Cancel</button>
            <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">Save Router</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Router Modal -->
<div class="modal fade" id="editRouterModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-bottom p-3">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen text-primary me-2"></i> Edit Router</h5>
        <button type="button" class="btn-close" data-bs-toggle="modal" data-bs-target="#routerModal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="update_router">
        <input type="hidden" name="nas_id" id="edit_nas_id">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router Name / Location</label>
                <input type="text" name="shortname" id="edit_shortname" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router IP Address (NAS)</label>
                <input type="text" name="nasname" id="edit_nasname" class="form-control font-monospace" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">RADIUS Secret</label>
                <input type="text" name="secret" id="edit_secret" class="form-control font-monospace" required>
            </div>
            <div class="row">
                <div class="col-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Username</label>
                    <input type="text" name="api_user" id="edit_apiuser" class="form-control font-monospace" required>
                </div>
                <div class="col-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Password</label>
                    <input type="password" name="api_password" class="form-control font-monospace" placeholder="Leave blank to keep">
                </div>
            </div>
        </div>
        <div class="modal-footer p-3">
            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#routerModal">Cancel</button>
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Update Router</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Quick Script Modal -->
<div class="modal fade" id="scriptModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-bottom p-3">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-terminal text-dark me-2"></i> MikroTik Auto-Setup</h5>
        <button type="button" class="btn-close" data-bs-toggle="modal" data-bs-target="#routerModal"></button>
      </div>
      <div class="modal-body p-4">
          <p class="small text-muted mb-3">Copy & paste this into the MikroTik <strong>New Terminal</strong> for this specific router.</p>
          <div class="position-relative">
              <textarea id="mtScriptCode" class="form-control font-monospace bg-dark text-success" rows="6" readonly style="font-size: 13px; resize: none;"></textarea>
              <button type="button" class="btn btn-sm btn-light position-absolute top-0 end-0 m-2 shadow-sm fw-bold" onclick="copyScript()">
                  <i class="fa-regular fa-copy me-1"></i> Copy
              </button>
          </div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Handle Edit Router button clicks
    document.querySelectorAll(".edit-router-btn").forEach(btn => {
        btn.addEventListener("click", function() {
            document.getElementById("edit_nas_id").value = this.dataset.id;
            document.getElementById("edit_nasname").value = this.dataset.ip;
            document.getElementById("edit_shortname").value = this.dataset.short;
            document.getElementById("edit_secret").value = this.dataset.sec;
            document.getElementById("edit_apiuser").value = this.dataset.usr;
        });
    });

    // Handle Quick Script button clicks
    document.querySelectorAll(".script-btn").forEach(btn => {
        btn.addEventListener("click", function() {
            document.getElementById("mtScriptCode").value = this.dataset.script;
            var modal = new bootstrap.Modal(document.getElementById("scriptModal"));
            
            // Hide main router modal temporarily
            var mainModal = bootstrap.Modal.getInstance(document.getElementById("routerModal"));
            mainModal.hide();
            
            modal.show();
        });
    });
});

function checkRouterStatuses() {
    document.querySelectorAll(".router-status").forEach(el => {
        let nasId = el.dataset.id;
        el.innerHTML = '<span class="small text-muted"><i class="fa-solid fa-spinner fa-spin"></i> Checking...</span>';
        
        fetch("check_router_status.php", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "nas_id=" + nasId
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === "success") {
                el.innerHTML = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-50"><span class="live-dot me-1"></span> Connected</span>';
            } else {
                el.innerHTML = '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-50" title="'+data.message+'"><i class="fa-solid fa-circle-xmark me-1"></i> Offline ('+data.message+')</span>';
            }
        })
        .catch(err => {
            el.innerHTML = '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-50"><i class="fa-solid fa-triangle-exclamation me-1"></i> Error</span>';
        });
    });
}

document.getElementById("routerModal").addEventListener("show.bs.modal", function () {
    checkRouterStatuses();
});

function copyScript() {
    var copyText = document.getElementById("mtScriptCode");
    copyText.select();
    document.execCommand("copy");
    alert("Script copied to clipboard!");
}
</script>

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
