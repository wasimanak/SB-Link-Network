<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

$routers = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? ORDER BY id DESC");
$routers->execute([$client_id]);
$routers = $routers->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-secondary fw-bold"><i class="fa-solid fa-water text-info me-2"></i> IP Pools</h4>
    <button class="btn btn-primary rounded-pill px-4" onclick="alert('This feature requires RouterOS API sync.')"><i class="fa-solid fa-plus me-1"></i> Add Pool</button>
</div>

<?php if(!$routers): ?>
    <div class="alert alert-warning border-warning rounded-pill">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> Please configure a MikroTik Router first in <strong>Connection Settings</strong> to view IP Pools.
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-5 text-center">
            <i class="fa-solid fa-network-wired fa-4x text-muted mb-3 opacity-25"></i>
            <h5 class="text-secondary fw-bold">Live API View</h5>
            <p class="text-muted small mb-4">IP Pools are fetched directly from your MikroTik router (<code>/ip pool</code>) via API.<br>Connect your API to load the live pools here.</p>
            <button class="btn btn-outline-info rounded-pill px-4" onclick="location.href='mikrotik_sync.php'"><i class="fa-solid fa-plug me-1"></i> Test Connection First</button>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
