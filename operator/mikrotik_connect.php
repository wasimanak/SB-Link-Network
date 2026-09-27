<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_nas' || $action === 'edit_nas') {
        $id = $_POST['id'] ?? 0;
        $nasname = trim($_POST['nasname']);
        $shortname = trim($_POST['shortname']);
        $secret = trim($_POST['secret']);
        $api_user = trim($_POST['api_user']);
        $api_password = trim($_POST['api_password']);
        $api_port = (int)$_POST['api_port'];
        $type = 'other'; // default for FreeRADIUS

        if ($action === 'add_nas') {
            $stmt = $pdo->prepare("INSERT INTO nas (client_id, nasname, shortname, type, secret, api_user, api_password, api_port) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$client_id, $nasname, $shortname, $type, $secret, $api_user, $api_password, $api_port]);
            $_SESSION['msg'] = "Router added successfully!";
        } else {
            $stmt = $pdo->prepare("UPDATE nas SET nasname=?, shortname=?, secret=?, api_user=?, api_password=?, api_port=? WHERE id=? AND client_id=?");
            $stmt->execute([$nasname, $shortname, $secret, $api_user, $api_password, $api_port, $id, $client_id]);
            $_SESSION['msg'] = "Router updated successfully!";
        }
        echo "<script>window.location='mikrotik_connect.php';</script>";
        exit;
    }
    
    if ($action === 'delete_nas') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM nas WHERE id=? AND client_id=?")->execute([$id, $client_id]);
        $_SESSION['msg'] = "Router deleted successfully!";
        echo "<script>window.location='mikrotik_connect.php';</script>";
        exit;
    }
}

$routers = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? ORDER BY id DESC");
$routers->execute([$client_id]);
$routers = $routers->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-secondary fw-bold"><i class="fa-solid fa-network-wired text-primary me-2"></i> MikroTik Routers (NAS)</h4>
    <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#nasModal" onclick="openNasModal()"><i class="fa-solid fa-plus me-1"></i> Add Router</button>
</div>

<?php if(isset($_SESSION['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-pill">
        <i class="fa-solid fa-check-circle me-2"></i> <?= $_SESSION['msg']; unset($_SESSION['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <?php if(!$routers): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4 text-center py-5">
            <div class="card-body">
                <i class="fa-solid fa-server fa-4x text-muted mb-3 opacity-25"></i>
                <h5 class="text-secondary fw-bold">No Routers Configured</h5>
                <p class="text-muted small">You need to add a MikroTik router to enable RADIUS authentication and API sync.</p>
                <button class="btn btn-outline-primary rounded-pill mt-2 px-4" data-bs-toggle="modal" data-bs-target="#nasModal" onclick="openNasModal()">Configure First Router</button>
            </div>
        </div>
    </div>
    <?php else: ?>
        <?php foreach($routers as $r): ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100" style="border-top: 4px solid #3b82f6 !important;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-router text-primary me-2"></i> <?= htmlspecialchars($r['shortname'] ?: 'MikroTik Router') ?></h5>
                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2 py-1"><i class="fa-solid fa-check-circle me-1"></i> Active</span>
                    </div>
                    
                    <ul class="list-unstyled text-secondary small mb-4">
                        <li class="mb-2"><strong class="text-dark">IP Address:</strong> <span class="font-monospace text-primary float-end"><?= htmlspecialchars($r['nasname']) ?></span></li>
                        <li class="mb-2"><strong class="text-dark">RADIUS Secret:</strong> <span class="font-monospace float-end">••••••••</span></li>
                        <li class="mb-2"><strong class="text-dark">API Port:</strong> <span class="float-end"><?= htmlspecialchars($r['api_port']) ?></span></li>
                        <li class="mb-0"><strong class="text-dark">API User:</strong> <span class="float-end"><?= htmlspecialchars($r['api_user']) ?></span></li>
                    </ul>

                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary flex-grow-1 rounded-pill" onclick='openNasModal(<?= json_encode($r) ?>)' data-bs-toggle="modal" data-bs-target="#nasModal"><i class="fa-solid fa-pen me-1"></i> Edit</button>
                        <form method="POST" onsubmit="return confirm('WARNING: Deleting this router will stop all RADIUS authentication for its users! Continue?');" class="m-0">
                            <input type="hidden" name="action" value="delete_nas">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add/Edit NAS Modal -->
<div class="modal fade" id="nasModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow rounded-4">
      <div class="modal-header bg-light border-bottom-0 pb-0">
        <h5 class="modal-title fw-bold" id="nasModalTitle">Configure MikroTik Router</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" id="nasAction" value="add_nas">
        <input type="hidden" name="id" id="nasId" value="">
        
        <div class="modal-body p-4">
            <div class="alert alert-info bg-opacity-10 border-info text-info small mb-4 rounded-3">
                <i class="fa-solid fa-circle-info me-1"></i> These details must exactly match your MikroTik RADIUS client and API service configuration.
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router Name / Location</label>
                <input type="text" name="shortname" id="nasShortname" class="form-control rounded-3" placeholder="e.g. Main Branch" required>
            </div>
            
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">Router IP Address (NAS IP)</label>
                <input type="text" name="nasname" id="nasNasname" class="form-control rounded-3 font-monospace" placeholder="e.g. 192.168.1.1 or Static WAN IP" required>
            </div>
            
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary small">RADIUS Secret</label>
                <input type="password" name="secret" id="nasSecret" class="form-control rounded-3" required>
            </div>

            <hr class="text-muted opacity-25 my-4">
            <h6 class="fw-bold text-dark mb-3">API Configuration</h6>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Username</label>
                    <input type="text" name="api_user" id="nasApiUser" class="form-control rounded-3">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Password</label>
                    <input type="password" name="api_password" id="nasApiPassword" class="form-control rounded-3">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label fw-bold text-secondary small">API Port</label>
                    <input type="number" name="api_port" id="nasApiPort" class="form-control rounded-3" value="8728">
                    <div class="form-text" style="font-size: 0.7rem;">Default is 8728 (or 8729 for SSL).</div>
                </div>
            </div>
        </div>
        
        <div class="modal-footer border-top-0 pt-0 px-4 pb-4">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save Configuration</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openNasModal(data = null) {
    if(data) {
        document.getElementById('nasModalTitle').innerText = 'Edit MikroTik Router';
        document.getElementById('nasAction').value = 'edit_nas';
        document.getElementById('nasId').value = data.id;
        document.getElementById('nasShortname').value = data.shortname;
        document.getElementById('nasNasname').value = data.nasname;
        document.getElementById('nasSecret').value = data.secret;
        document.getElementById('nasApiUser').value = data.api_user;
        document.getElementById('nasApiPassword').value = data.api_password;
        document.getElementById('nasApiPort').value = data.api_port;
    } else {
        document.getElementById('nasModalTitle').innerText = 'Add MikroTik Router';
        document.getElementById('nasAction').value = 'add_nas';
        document.getElementById('nasId').value = '';
        document.getElementById('nasShortname').value = '';
        document.getElementById('nasNasname').value = '';
        document.getElementById('nasSecret').value = '';
        document.getElementById('nasApiUser').value = '';
        document.getElementById('nasApiPassword').value = '';
        document.getElementById('nasApiPort').value = '8728';
    }
}
</script>

<?php require_once 'footer.php'; ?>
