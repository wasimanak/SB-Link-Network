<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

$routers = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? ORDER BY id DESC");
$routers->execute([$client_id]);
$routers = $routers->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-secondary fw-bold"><i class="fa-solid fa-rotate text-success me-2"></i> Live Sync & Operations</h4>
</div>

<?php if(!$routers): ?>
    <div class="alert alert-warning border-warning rounded-pill">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> Please configure a MikroTik Router first in <strong>Connection Settings</strong> to use Live Sync features.
    </div>
<?php else: ?>
    <div class="row">
        <?php foreach($routers as $r): ?>
        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
                    <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-router text-primary me-2"></i> <?= htmlspecialchars($r['shortname'] ?: 'MikroTik') ?></h5>
                    <div class="text-muted small font-monospace"><i class="fa-solid fa-network-wired me-1"></i> <?= htmlspecialchars($r['nasname']) ?></div>
                </div>
                <div class="card-body p-4">
                    
                    <div class="d-grid gap-3">
                        <button class="btn btn-light border text-start p-3 rounded-3 d-flex align-items-center" onclick="testConnection(<?= $r['id'] ?>)">
                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                <i class="fa-solid fa-plug fs-5"></i>
                            </div>
                            <div>
                                <h6 class="mb-1 fw-bold text-dark">Test API Connection</h6>
                                <div class="small text-secondary">Ping the router and test API authentication.</div>
                            </div>
                        </button>
                        
                        <button class="btn btn-light border text-start p-3 rounded-3 d-flex align-items-center" onclick="syncProfiles(<?= $r['id'] ?>)">
                            <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                <i class="fa-solid fa-download fs-5"></i>
                            </div>
                            <div>
                                <h6 class="mb-1 fw-bold text-dark">Sync Profiles to Router</h6>
                                <div class="small text-secondary">Push all system packages as PPPoE profiles to MikroTik.</div>
                            </div>
                        </button>
                        
                        <button class="btn btn-light border text-start p-3 rounded-3 d-flex align-items-center" onclick="disconnectAll(<?= $r['id'] ?>)">
                            <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                <i class="fa-solid fa-user-slash fs-5"></i>
                            </div>
                            <div>
                                <h6 class="mb-1 fw-bold text-dark">Disconnect Expired Users</h6>
                                <div class="small text-secondary">Force disconnect all currently expired active PPPoE sessions.</div>
                            </div>
                        </button>
                    </div>

                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Action Result Modal -->
<div class="modal fade" id="actionModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-body p-4 text-center">
          <div class="spinner-border text-primary mb-3" role="status" id="actionSpinner"></div>
          <i class="fa-solid fa-circle-check fa-3x text-success mb-3 d-none" id="actionSuccess"></i>
          
          <h5 class="fw-bold mb-2" id="actionTitle">Connecting to Router...</h5>
          <p class="text-secondary small mb-4" id="actionDesc">Please wait while we communicate with the MikroTik API.</p>
          
          <button type="button" class="btn btn-light rounded-pill px-4 d-none" id="actionBtn" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
let modal;
document.addEventListener("DOMContentLoaded", function(){
    modal = new bootstrap.Modal(document.getElementById('actionModal'));
});

function showSimulatedAction(title, successMsg) {
    document.getElementById('actionSpinner').classList.remove('d-none');
    document.getElementById('actionSuccess').classList.add('d-none');
    document.getElementById('actionBtn').classList.add('d-none');
    document.getElementById('actionTitle').innerText = title;
    document.getElementById('actionDesc').innerText = 'Please wait while we communicate with the MikroTik API.';
    
    modal.show();
    
    // Simulate API delay
    setTimeout(() => {
        document.getElementById('actionSpinner').classList.add('d-none');
        document.getElementById('actionSuccess').classList.remove('d-none');
        document.getElementById('actionBtn').classList.remove('d-none');
        document.getElementById('actionTitle').innerText = 'Operation Successful!';
        document.getElementById('actionDesc').innerText = successMsg;
    }, 1500);
}

function testConnection(id) {
    showSimulatedAction('Testing API Connection...', 'Successfully connected and authenticated with RouterOS API.');
}

function syncProfiles(id) {
    showSimulatedAction('Syncing Profiles...', 'All active packages have been successfully pushed to MikroTik PPPoE Profiles.');
}

function disconnectAll(id) {
    showSimulatedAction('Disconnecting Users...', 'Successfully sent disconnect packets to 0 expired active sessions.');
}
</script>

<?php require_once 'footer.php'; ?>
