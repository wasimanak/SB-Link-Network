<?php
require_once 'header.php';

$client_id = $_SESSION['operator_id'];

// Handle Actions (Create, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $id = $_POST['package_id'] ?? null;
        $name = trim($_POST['name']);
        $rate_limit = trim($_POST['rate_limit']);
        $price = (float)$_POST['price'];
        $validity_days = (int)$_POST['validity_days'];
        $data_limit_gb = (int)$_POST['data_limit_gb'];

        // Handle Speed Scheduler
        $scheduler = null;
        if (!empty($_POST['sched_start']) && !empty($_POST['sched_end']) && !empty($_POST['sched_speed'])) {
            $sched_array = [
                'start_time' => $_POST['sched_start'], // e.g., '22:00'
                'end_time' => $_POST['sched_end'],     // e.g., '06:00'
                'speed' => $_POST['sched_speed']       // e.g., '20M/20M'
            ];
            $scheduler = json_encode($sched_array);
        }

        if ($action === 'create') {
            // Check if package name already exists for this operator
            $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE name = ? AND client_id = ?");
            $chkStmt->execute([$name, $client_id]);
            if ($chkStmt->fetchColumn() > 0) {
                echo "<script>alert('Error: A package with the exact same name already exists. Please use a different name!'); window.location='packages.php';</script>";
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb, speed_scheduler) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$client_id, $name, $rate_limit, $validity_days, $price, $data_limit_gb, $scheduler]);
            echo "<script>alert('Package created successfully!'); window.location='packages.php';</script>";
            exit;
        } else {
            // Check if another package with this name exists (excluding the current one)
            $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM packages WHERE name = ? AND client_id = ? AND id != ?");
            $chkStmt->execute([$name, $client_id, $id]);
            if ($chkStmt->fetchColumn() > 0) {
                echo "<script>alert('Error: Another package with this name already exists!'); window.location='packages.php';</script>";
                exit;
            }

            $stmt = $pdo->prepare("UPDATE packages SET name=?, rate_limit=?, validity_days=?, price=?, data_limit_gb=?, speed_scheduler=? WHERE id=? AND client_id=?");
            $stmt->execute([$name, $rate_limit, $validity_days, $price, $data_limit_gb, $scheduler, $id, $client_id]);
            echo "<script>alert('Package updated successfully!'); window.location='packages.php';</script>";
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['package_id'];
        $stmt = $pdo->prepare("DELETE FROM packages WHERE id=? AND client_id=?");
        $stmt->execute([$id, $client_id]);
        echo "<script>alert('Package deleted successfully!'); window.location='packages.php';</script>";
        exit;
    }
}

// Fetch all packages
$stmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ? ORDER BY id DESC");
$stmt->execute([$client_id]);
$packages = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4><i class="fa-solid fa-box-open text-primary"></i> Manage Packages</h4>
    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#packageModal" onclick="openCreateModal()">
        <i class="fa-solid fa-plus me-1"></i> Create New Package
    </button>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Package Name</th>
                        <th>Speed (Rate Limit)</th>
                        <th>Price (Rs)</th>
                        <th>Validity (Days)</th>
                        <th>Data Limit</th>
                        <th>Speed Scheduler</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($packages)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No packages found. Click "Create New Package" to add one.</td></tr>
                    <?php else: ?>
                        <?php foreach($packages as $p): 
                            $sched = json_decode($p['speed_scheduler'], true);
                        ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($p['name']) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($p['rate_limit'] ?: 'Unlimited') ?></span></td>
                            <td class="text-success fw-bold"><?= number_format($p['price'], 2) ?></td>
                            <td><?= $p['validity_days'] ?> Days</td>
                            <td><?= $p['data_limit_gb'] > 0 ? $p['data_limit_gb'] . ' GB' : 'Unlimited' ?></td>
                            <td>
                                <?php if ($sched): ?>
                                    <small class="text-info"><i class="fa-regular fa-clock"></i> <?= $sched['start_time'] ?> to <?= $sched['end_time'] ?> <br> <i class="fa-solid fa-gauge-high"></i> <?= htmlspecialchars($sched['speed']) ?></small>
                                <?php else: ?>
                                    <span class="text-muted small">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-light border text-primary me-1" onclick='openEditModal(<?= json_encode($p) ?>)'><i class="fa-solid fa-pen"></i></button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this package?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="package_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-light border text-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Package Modal (Create & Edit) -->
<div class="modal fade" id="packageModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalTitle"><i class="fa-solid fa-box text-primary"></i> Create Package</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="packageForm">
        <input type="hidden" name="action" id="modalAction" value="create">
        <input type="hidden" name="package_id" id="modalPackageId" value="">
        <div class="modal-body p-4">
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Package Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="pkg_name" class="form-control" required placeholder="e.g. 10Mbps Unlimited">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Price (Amount) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="price" id="pkg_price" class="form-control" required placeholder="e.g. 1500.00">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Validity (Days) <span class="text-danger">*</span></label>
                    <input type="number" name="validity_days" id="pkg_validity" class="form-control" required placeholder="e.g. 30" value="30">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Data Limit (GB)</label>
                    <input type="number" name="data_limit_gb" id="pkg_data" class="form-control" placeholder="e.g. 100" value="0">
                    <small class="text-muted">Set 0 for Unlimited Data</small>
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label fw-bold">Standard Rate Limit (Speed) <span class="text-danger">*</span></label>
                    <input type="text" name="rate_limit" id="pkg_rate" class="form-control" required placeholder="e.g. 10M/10M">
                    <small class="text-muted">MikroTik Format: RX/TX (Upload/Download)</small>
                </div>
            </div>

            <hr>
            <h6 class="text-primary mb-3"><i class="fa-regular fa-clock"></i> Night / Speed Scheduler (Optional)</h6>
            <div class="row bg-light p-3 rounded border">
                <div class="col-md-4 mb-2">
                    <label class="form-label small fw-bold">Start Time</label>
                    <input type="time" name="sched_start" id="sched_start" class="form-control">
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label small fw-bold">End Time</label>
                    <input type="time" name="sched_end" id="sched_end" class="form-control">
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label small fw-bold">Scheduled Speed</label>
                    <input type="text" name="sched_speed" id="sched_speed" class="form-control" placeholder="e.g. 20M/20M">
                </div>
                <div class="col-12"><small class="text-muted">If set, FreeRADIUS/MikroTik will change user's speed during these hours automatically.</small></div>
            </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="modalBtn">Save Package</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-box text-primary"></i> Create Package';
    document.getElementById('modalAction').value = 'create';
    document.getElementById('modalPackageId').value = '';
    document.getElementById('packageForm').reset();
    document.getElementById('pkg_validity').value = 30;
    document.getElementById('pkg_data').value = 0;
    document.getElementById('modalBtn').innerText = 'Create Package';
}

function openEditModal(pkg) {
    document.getElementById('modalTitle').innerHTML = '<i class="fa-solid fa-pen text-primary"></i> Edit Package';
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalPackageId').value = pkg.id;
    
    document.getElementById('pkg_name').value = pkg.name;
    document.getElementById('pkg_price').value = pkg.price;
    document.getElementById('pkg_validity').value = pkg.validity_days;
    document.getElementById('pkg_data').value = pkg.data_limit_gb;
    document.getElementById('pkg_rate').value = pkg.rate_limit;
    
    if (pkg.speed_scheduler) {
        let sched = JSON.parse(pkg.speed_scheduler);
        document.getElementById('sched_start').value = sched.start_time;
        document.getElementById('sched_end').value = sched.end_time;
        document.getElementById('sched_speed').value = sched.speed;
    } else {
        document.getElementById('sched_start').value = '';
        document.getElementById('sched_end').value = '';
        document.getElementById('sched_speed').value = '';
    }
    
    document.getElementById('modalBtn').innerText = 'Update Package';
    var myModal = new bootstrap.Modal(document.getElementById('packageModal'));
    myModal.show();
}
</script>

<?php require_once 'footer.php'; ?>
