<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

$routers = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? ORDER BY id DESC");
$routers->execute([$client_id]);
$routers = $routers->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-secondary fw-bold"><i class="fa-solid fa-laptop-code text-warning me-2"></i> DHCP Leases</h4>
    <button class="btn btn-outline-warning text-dark border-warning rounded-pill px-4" onclick="location.reload()"><i class="fa-solid fa-rotate-right me-1"></i> Refresh Leases</button>
</div>

<?php if(!$routers): ?>
    <div class="alert alert-warning border-warning rounded-pill">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> Please configure a MikroTik Router first in <strong>Connection Settings</strong> to view DHCP Leases.
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-5 text-center">
            <i class="fa-solid fa-network-wired fa-4x text-muted mb-3 opacity-25"></i>
            <h5 class="text-secondary fw-bold">Live DHCP Leases</h5>
            <p class="text-muted small mb-4">This page connects to <code>/ip dhcp-server lease</code> on your MikroTik to show all devices connected via DHCP (useful for Hotspot setups or diagnosing connected devices).<br>Connect your API to load the leases here.</p>
            <button class="btn btn-outline-warning text-dark border-warning rounded-pill px-4" onclick="location.href='mikrotik_sync.php'"><i class="fa-solid fa-plug me-1"></i> Test Connection First</button>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
