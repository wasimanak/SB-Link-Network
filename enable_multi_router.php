<?php
$f = 'operator/profile.php';
$c = file_get_contents($f);

// 1. Change backend logic to support multiple NAS
$backendOld = 'if ($action === \'update_router\') {
        $nasname = trim($_POST[\'nasname\'] ?? \'\');
        $secret = trim($_POST[\'secret\'] ?? \'\');
        $api_user = trim($_POST[\'api_user\'] ?? \'\');
        $api_pass = trim($_POST[\'api_password\'] ?? \'\');
        
        if (empty($nasname) || empty($secret) || empty($api_user)) {
            echo "<script>alert(\'Router IP, Secret, and API Username are required.\'); window.location=\'profile.php\';</script>";
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
                    $pdo->prepare("INSERT INTO nas (client_id, nasname, shortname, secret, api_port, api_user, api_password) VALUES (?, ?, \'Primary Router\', ?, 8728, ?, ?)")
                        ->execute([$client_id, $nasname, $secret, $api_user, $api_pass]);
                }
                echo "<script>alert(\'MikroTik Router settings updated successfully!\'); window.location=\'profile.php\';</script>";
                exit;
            } catch (PDOException $e) {
                echo "<script>alert(\'Error updating router: " . addslashes($e->getMessage()) . "\'); window.location=\'profile.php\';</script>";
                exit;
            }
        }
    }';

$backendNew = 'if ($action === \'add_router\') {
        $nasname = trim($_POST[\'nasname\'] ?? \'\');
        $shortname = trim($_POST[\'shortname\'] ?? \'Router\');
        $secret = trim($_POST[\'secret\'] ?? \'\');
        $api_user = trim($_POST[\'api_user\'] ?? \'\');
        $api_pass = trim($_POST[\'api_password\'] ?? \'\');
        
        $chk = $pdo->prepare("SELECT COUNT(*) FROM nas WHERE client_id = ?");
        $chk->execute([$client_id]);
        $current_routers = $chk->fetchColumn();
        
        if ($current_routers >= $user[\'max_routers\']) {
            echo "<script>alert(\'Router limit reached! You cannot add more routers.\'); window.location=\'profile.php\';</script>";
            exit;
        }
        
        try {
            $pdo->prepare("INSERT INTO nas (client_id, nasname, shortname, secret, api_port, api_user, api_password) VALUES (?, ?, ?, ?, 8728, ?, ?)")
                ->execute([$client_id, $nasname, $shortname, $secret, $api_user, $api_pass]);
            echo "<script>alert(\'Router added successfully!\'); window.location=\'profile.php\';</script>";
        } catch(Exception $e) {
            echo "<script>alert(\'Error: " . addslashes($e->getMessage()) . "\'); window.location=\'profile.php\';</script>";
        }
        exit;
    }
    
    if ($action === \'update_router\') {
        $nas_id = (int)$_POST[\'nas_id\'];
        $nasname = trim($_POST[\'nasname\'] ?? \'\');
        $shortname = trim($_POST[\'shortname\'] ?? \'Router\');
        $secret = trim($_POST[\'secret\'] ?? \'\');
        $api_user = trim($_POST[\'api_user\'] ?? \'\');
        $api_pass = trim($_POST[\'api_password\'] ?? \'\');
        
        try {
            if (empty($api_pass)) {
                $pdo->prepare("UPDATE nas SET nasname=?, shortname=?, secret=?, api_user=? WHERE id=? AND client_id=?")->execute([$nasname, $shortname, $secret, $api_user, $nas_id, $client_id]);
            } else {
                $pdo->prepare("UPDATE nas SET nasname=?, shortname=?, secret=?, api_user=?, api_password=? WHERE id=? AND client_id=?")->execute([$nasname, $shortname, $secret, $api_user, $api_pass, $nas_id, $client_id]);
            }
            echo "<script>alert(\'Router updated successfully!\'); window.location=\'profile.php\';</script>";
        } catch(Exception $e) {
            echo "<script>alert(\'Error: " . addslashes($e->getMessage()) . "\'); window.location=\'profile.php\';</script>";
        }
        exit;
    }
    
    if ($action === \'delete_router\') {
        $nas_id = (int)$_POST[\'nas_id\'];
        $pdo->prepare("DELETE FROM nas WHERE id=? AND client_id=?")->execute([$nas_id, $client_id]);
        echo "<script>alert(\'Router deleted successfully!\'); window.location=\'profile.php\';</script>";
        exit;
    }';

if (strpos($c, '$action === \'add_router\'') === false) {
    // We need to carefully replace the old block
    // Wait, to make it easier, let's use regex
    $c = preg_replace('/if \(\$action === \'update_router\'\).*?}\s*}\s*}\s*elseif/s', $backendNew . "\n    elseif", $c);
}

// 2. Fix the fetching logic
// Replace `$nas = $nasStmt->fetch();` with `$routers = $nasStmt->fetchAll();`
$c = preg_replace('/\$nasStmt = \$pdo->prepare\("SELECT \* FROM nas WHERE client_id = \?"\);\s*\$nasStmt->execute\(\[\$client_id\]\);\s*\$nas = \$nasStmt->fetch\(\);/s', 
'$nasStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ?");
$nasStmt->execute([$client_id]);
$routers = $nasStmt->fetchAll(PDO::FETCH_ASSOC);', $c);

// 3. Completely replace the HTML Modal
$oldModalRegex = '/<!-- Router Settings Modal -->.*?<div class="modal fade" id="changePasswordModal"/s';

$newModalHtml = '<!-- Router List Modal -->
<div class="modal fade" id="routerModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-server text-primary me-2"></i> Manage MikroTik Routers</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4 bg-light">
          
          <div class="d-flex justify-content-between align-items-center mb-3">
              <span class="text-secondary fw-bold small text-uppercase"><i class="fa-solid fa-chart-pie me-1"></i> Quota: <?= count($routers) ?> / <?= $user[\'max_routers\'] ?> Routers</span>
              <?php if(count($routers) < $user[\'max_routers\']): ?>
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
                              <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-microchip text-primary me-1"></i> <?= htmlspecialchars($r[\'shortname\'] ?: \'Router\') ?></h6>
                              <div class="dropdown">
                                  <button class="btn btn-light btn-sm rounded-circle shadow-sm" style="width:30px; height:30px; padding:0;" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                  <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                      <li><a class="dropdown-item fw-bold text-primary edit-router-btn" href="#" data-id="<?= $r[\'id\'] ?>" data-ip="<?= htmlspecialchars($r[\'nasname\']) ?>" data-short="<?= htmlspecialchars($r[\'shortname\']) ?>" data-sec="<?= htmlspecialchars($r[\'secret\']) ?>" data-usr="<?= htmlspecialchars($r[\'api_user\']) ?>" data-bs-toggle="modal" data-bs-target="#editRouterModal"><i class="fa-solid fa-pen me-2"></i> Edit Config</a></li>
                                      <li><hr class="dropdown-divider"></li>
                                      <li>
                                          <form method="POST" onsubmit="return confirm(\'WARNING: Deleting this router will break RADIUS authentication for users connected to it. Continue?\');">
                                              <input type="hidden" name="action" value="delete_router">
                                              <input type="hidden" name="nas_id" value="<?= $r[\'id\'] ?>">
                                              <button type="submit" class="dropdown-item fw-bold text-danger"><i class="fa-solid fa-trash me-2"></i> Remove Router</button>
                                          </form>
                                      </li>
                                  </ul>
                              </div>
                          </div>
                          
                          <div class="mb-1"><span class="badge bg-dark bg-opacity-10 text-dark border border-secondary px-2"><i class="fa-solid fa-network-wired me-1"></i> IP: <?= htmlspecialchars($r[\'nasname\']) ?></span></div>
                          <div class="mb-3"><span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fa-solid fa-link me-1"></i> Linked</span></div>
                          
                          <button class="btn btn-sm btn-outline-secondary w-100 rounded-pill script-btn" data-script="/radius add address=<?= $host ?> secret=&quot;<?= $r[\'secret\'] ?>&quot; service=ppp,hotspot\n/radius incoming set accept=yes port=3799\n/ppp aaa set use-radius=yes interim-update=1m\n/ip hotspot profile set [find] use-radius=yes radius-interim-update=1m\n">
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

function copyScript() {
    var copyText = document.getElementById("mtScriptCode");
    copyText.select();
    document.execCommand("copy");
    alert("Script copied to clipboard!");
}
</script>

<div class="modal fade" id="changePasswordModal"';

if (strpos($c, 'id="addRouterModal"') === false) {
    $c = preg_replace($oldModalRegex, $newModalHtml, $c);
    file_put_contents($f, $c);
    echo "Multi-router support added to profile.php!\n";
} else {
    echo "Already added.\n";
}
?>
