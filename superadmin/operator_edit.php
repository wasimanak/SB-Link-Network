<?php
require_once 'header.php';

$id = (int)($_GET['id'] ?? 0);
$error = '';
$success = '';

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_operator';
    
    if ($action === 'update_operator') {
        $company = trim($_POST['company_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $max_routers = (int)($_POST['max_routers'] ?? 1);
        $max_subscribers = (int)($_POST['max_subscribers'] ?? 100);
        $expiry = $_POST['expiry_date'] ?: null;

        if (empty($company)) {
            $error = "Company Name is required.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE clients SET company_name=:comp, phone=:phone, expiry_date=:exp, max_routers=:mr, max_subscribers=:ms WHERE id=:id");
                $stmt->execute([
                    'comp' => $company,
                    'phone' => $phone,
                    'exp' => $expiry,
                    'mr' => $max_routers,
                    'ms' => $max_subscribers,
                    'id' => $id
                ]);
                $success = "Operator details updated successfully.";
            } catch (Exception $e) {
                $error = "Error updating operator: " . $e->getMessage();
            }
        }
    } 
    elseif ($action === 'update_router') {
        $nasname = trim($_POST['nasname'] ?? '');
        $secret = trim($_POST['secret'] ?? '');
        $api_user = trim($_POST['api_user'] ?? '');
        $api_pass = trim($_POST['api_password'] ?? '');
        $auto_sync = isset($_POST['auto_sync']) ? true : false;
        
        if (empty($nasname) || empty($secret) || empty($api_user)) {
            $error = "Router IP, Secret, and API Username are required.";
        } else {
            try {
                // Check if NAS already exists for this client
                $chk = $pdo->prepare("SELECT id FROM nas WHERE client_id = ?");
                $chk->execute([$id]);
                $existing_nas = $chk->fetch();
                
                if ($existing_nas) {
                    // Update
                    $upd = $pdo->prepare("UPDATE nas SET nasname=?, secret=?, api_user=?, api_password=? WHERE client_id=?");
                    // If password is blank, don't update it
                    if (empty($api_pass)) {
                        $upd = $pdo->prepare("UPDATE nas SET nasname=?, secret=?, api_user=? WHERE client_id=?");
                        $upd->execute([$nasname, $secret, $api_user, $id]);
                    } else {
                        $upd->execute([$nasname, $secret, $api_user, $api_pass, $id]);
                    }
                    $success = "MikroTik connection settings updated.";
                } else {
                    // Insert
                    $pdo->prepare("INSERT INTO nas (client_id, nasname, shortname, secret, api_port, api_user, api_password) VALUES (?, ?, 'Primary Router', ?, 8728, ?, ?)")
                        ->execute([$id, $nasname, $secret, $api_user, $api_pass]);
                    $success = "MikroTik router linked to operator.";
                }

                // Handle Sync if requested
                if ($auto_sync) {
                    require_once '../config/routeros_api.class.php';
                    $api = new RouterosAPI();
                    
                    // Fetch real password if it was blank
                    if (empty($api_pass) && $existing_nas) {
                        $pStmt = $pdo->prepare("SELECT api_password FROM nas WHERE client_id = ?");
                        $pStmt->execute([$id]);
                        $api_pass = $pStmt->fetchColumn();
                    }
                    
                    if ($api->connect($nasname, $api_user, $api_pass, 8728)) {
                        // Profiles
                        $api->write('/ppp/profile/print');
                        $ppp_profiles = $api->read();
                        $api->write('/ip/hotspot/user/profile/print');
                        $hs_profiles = $api->read();
                        
                        foreach (array_merge($ppp_profiles, $hs_profiles) as $p) {
                            $name = $p['name'] ?? '';
                            if (!$name || $name === 'default' || $name === 'default-encryption') continue;
                            $rate = $p['rate-limit'] ?? 'Unlimited';
                            
                            $pkgChk = $pdo->prepare("SELECT id FROM packages WHERE name = ? AND client_id = ?");
                            $pkgChk->execute([$name, $id]);
                            if (!$pkgChk->fetch()) {
                                $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, price) VALUES (?, ?, ?, 0)")->execute([$id, $name, $rate]);
                                if ($rate !== 'Unlimited') {
                                    $pdo->prepare("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', ?)")->execute([$name, $rate]);
                                }
                                
                            }
                        }
                        
                        // Users
                        $api->write('/ppp/secret/print');
                        $ppp_users = $api->read();
                        $api->write('/ip/hotspot/user/print');
                        $hs_users = $api->read();
                        
                        $total_users = 0;
                        foreach (array_merge($ppp_users, $hs_users) as $u) {
                            $uname = $u['name'] ?? '';
                            $upass = $u['password'] ?? '';
                            $uprof = $u['profile'] ?? 'default';
                            if (!$uname || $uname === 'default') continue;
                            
                            $uChk = $pdo->prepare("SELECT id FROM subscribers WHERE username = ?");
                            $uChk->execute([$uname]);
                            if (!$uChk->fetch()) {
                                $pkgStmt = $pdo->prepare("SELECT id FROM packages WHERE name = ? AND client_id = ?");
                                $pkgStmt->execute([$uprof, $id]);
                                $pkg = $pkgStmt->fetch();
                                $pkg_id = $pkg ? $pkg['id'] : null;
                                
                                $pdo->prepare("INSERT INTO subscribers (client_id, username, password, full_name, package_id, status) VALUES (?, ?, ?, ?, ?, 'active')")
                                    ->execute([$id, $uname, $upass, $uname, $pkg_id]);
                                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$uname, $upass]);
                                
                                if ($uprof !== 'default') {
                                    $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$uname, $uprof]);
                                }
                                $total_users++;
                            }
                        }
                        $api->disconnect();
                        $success .= "<br><i class='fa-solid fa-check-circle'></i> Sync Complete! Imported $total_users new users and their profiles.";
                    } else {
                        $error = "Router details saved, but failed to connect to MikroTik API for sync.";
                    }
                }
            } catch (Exception $e) {
                $error = "Error saving router: " . $e->getMessage();
            }
        }
    }
}

// Fetch Operator
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id");
$stmt->execute(['id' => $id]);
$op = $stmt->fetch();
if (!$op) { die("Operator not found."); }

// Fetch linked NAS
$nasStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ?");
$nasStmt->execute([$id]);
$nas = $nasStmt->fetch();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-user-tie text-primary me-2"></i> Operator Profile: <?= htmlspecialchars($op['company_name']) ?></h4>
    <a href="operators.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to List</a>
</div>

<?php if($error): ?><div class="alert alert-danger shadow-sm border-0"><i class="fa-solid fa-triangle-exclamation me-2"></i> <?= $error ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success shadow-sm border-0"><i class="fa-solid fa-circle-check me-2"></i> <?= $success ?></div><?php endif; ?>

<div class="row">
    <!-- Operator Basic Details -->
    <div class="col-md-5 mb-4">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-address-card text-success me-2"></i> Basic Details</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <input type="hidden" name="action" value="update_operator">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small">Company Name</label>
                        <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($op['company_name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($op['phone'] ?? '') ?>">
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold text-secondary small">Max Routers</label>
                            <input type="number" name="max_routers" class="form-control" value="<?= $op['max_routers'] ?>" min="1">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold text-secondary small">Max Subscribers</label>
                            <input type="number" name="max_subscribers" class="form-control" value="<?= $op['max_subscribers'] ?>" min="1">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold text-secondary small">Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control" value="<?= $op['expiry_date'] ? date('Y-m-d', strtotime($op['expiry_date'])) : '' ?>">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm"><i class="fa-solid fa-save me-1"></i> Update Details</button>
                </form>
            </div>
        </div>
    </div>

    <!-- MikroTik Settings -->
    <div class="col-md-7 mb-4">
        <div class="card border-0 shadow-sm rounded-4 h-100 border-primary border-opacity-25" style="border-width: 2px !important;">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-router text-primary me-2"></i> Linked MikroTik Router</h5>
                <?php if($nas): ?>
                    <span class="badge bg-success rounded-pill px-3"><i class="fa-solid fa-link me-1"></i> Linked</span>
                <?php else: ?>
                    <span class="badge bg-danger rounded-pill px-3"><i class="fa-solid fa-unlink me-1"></i> Not Linked</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-4">
                <p class="text-muted small mb-4">Manage the MikroTik connection for this operator. Pushing changes here will allow the operator to manage the router's users without seeing the connection details.</p>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_router">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-secondary small">Router IP Address (NAS)</label>
                            <input type="text" name="nasname" class="form-control font-monospace" placeholder="e.g. 10.133.13.69" value="<?= htmlspecialchars($nas['nasname'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-secondary small">RADIUS Secret</label>
                            <input type="text" name="secret" class="form-control font-monospace" placeholder="e.g. 123456" value="<?= htmlspecialchars($nas['secret'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-secondary small">API Username</label>
                            <input type="text" name="api_user" class="form-control font-monospace" placeholder="e.g. admin" value="<?= htmlspecialchars($nas['api_user'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-secondary small">API Password</label>
                            <input type="password" name="api_password" class="form-control font-monospace" placeholder="<?= $nas ? 'Leave blank to keep unchanged' : 'Required' ?>" <?= $nas ? '' : 'required' ?>>
                        </div>
                    </div>

                    <div class="bg-light p-3 rounded-3 mt-2 mb-4 border">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="auto_sync" id="autoSync" value="1" checked>
                            <label class="form-check-label fw-bold text-dark" for="autoSync">Sync all Profiles and Users from MikroTik immediately after saving</label>
                        </div>
                        <small class="text-muted d-block mt-1 ms-5">This will import any new PPPoE/Hotspot users and packages directly into this Operator's account.</small>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary fw-bold shadow-sm px-4">
                            <i class="fa-solid fa-save me-2"></i> <?= $nas ? 'Update Router Settings' : 'Link Router' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if($nas): ?>
        <div class="card border-0 shadow-sm rounded-4 border-dark border-opacity-25 mt-4">
            <div class="card-header bg-dark text-white border-bottom-0 pt-3 pb-3 rounded-top-4">
                <h6 class="fw-bold mb-0"><i class="fa-solid fa-terminal me-2"></i> MikroTik Quick Setup Script</h6>
            </div>
            <div class="card-body p-4 bg-light rounded-bottom-4">
                <p class="small text-muted mb-3">Copy and paste the following script into the Operator's MikroTik <strong>New Terminal</strong>. It will automatically configure the router to connect to this RADIUS server and enable AAA for Hotspot and PPPoE.</p>
                <div class="position-relative">
                    <?php 
                        // The RADIUS server IP is typically the database host (Ubuntu VM)
                        global $host; 
                        $radius_ip = $host; 
                        $secret = $nas['secret'];
                        
                        $mt_script = "/radius add address=$radius_ip secret=\"$secret\" service=ppp,hotspot\n";
                        $mt_script .= "/radius incoming set accept=yes port=3799\n";
                        $mt_script .= "/ppp aaa set use-radius=yes interim-update=1m\n";
                        $mt_script .= "/ip hotspot profile set [find] use-radius=yes radius-interim-update=1m\n";
                    ?>
                    <textarea id="mtScript" class="form-control font-monospace bg-dark text-success" rows="5" readonly style="font-size: 13px; resize: none;"><?= htmlspecialchars($mt_script) ?></textarea>
                    <button type="button" class="btn btn-sm btn-light position-absolute top-0 end-0 m-2 shadow-sm fw-bold" onclick="copyScript()">
                        <i class="fa-regular fa-copy me-1"></i> Copy
                    </button>
                </div>
            </div>
        </div>
        <script>
        function copyScript() {
            var copyText = document.getElementById("mtScript");
            copyText.select();
            copyText.setSelectionRange(0, 99999); // For mobile devices
            navigator.clipboard.writeText(copyText.value);
            alert("Script copied to clipboard!");
        }
        </script>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>