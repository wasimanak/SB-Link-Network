<?php
require_once 'header.php';

$client_id = $_SESSION['operator_id'];

// Handle Actions (Create, Update, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $id = $_POST['package_id'] ?? null;
        $name = trim($_POST['name']);
        $rate_up = trim($_POST['rate_up'] ?? '');
        $rate_down = trim($_POST['rate_down'] ?? '');
        
        if (strcasecmp($rate_up, 'Unlimited') === 0 || empty($rate_up) || empty($rate_down)) {
            $rate_limit = 'Unlimited';
        } else {
            $rate_limit = $rate_up . '/' . $rate_down;
        }
        $price = (float)$_POST['price'];
        $validity_days = (int)$_POST['validity_days'];
        $data_limit_gb = (int)$_POST['data_limit_gb'];

        // Handle Speed Scheduler
        $scheduler = null;
        if (!empty($_POST['sched_start']) && !empty($_POST['sched_end']) && (!empty($_POST['sched_up']) || !empty($_POST['sched_down']))) {
            $sched_array = [
                'start_time' => $_POST['sched_start'], // e.g., '22:00'
                'end_time' => $_POST['sched_end'],
                'speed' => trim($_POST['sched_up'] ?? '') . '/' . trim($_POST['sched_down'] ?? '')
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
    <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: 0.5px;">
        <i class="fa-solid fa-box-open text-primary me-2"></i>Manage Packages
    </h3>
    <button class="btn btn-accent rounded-pill px-4 shadow-lg" data-bs-toggle="modal" data-bs-target="#packageModal" onclick="openCreateModal()">
        <i class="fa-solid fa-plus me-2"></i> Create Package
    </button>
</div>

<div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; overflow: hidden;">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-borderless align-middle text-dark mb-0" style="background: white;">
                <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <tr>
                        <th class="py-3 ps-4 text-uppercase text-muted" style="font-size: 0.75rem; letter-spacing: 1px;">Package Name</th>
                        <th class="py-3 text-uppercase text-muted" style="font-size: 0.75rem; letter-spacing: 1px;">Speed</th>
                        <th class="py-3 text-uppercase text-muted" style="font-size: 0.75rem; letter-spacing: 1px;">Price</th>
                        <th class="py-3 text-uppercase text-muted" style="font-size: 0.75rem; letter-spacing: 1px;">Validity</th>
                        <th class="py-3 text-uppercase text-muted" style="font-size: 0.75rem; letter-spacing: 1px;">Data Limit</th>
                        <th class="py-3 text-uppercase text-muted" style="font-size: 0.75rem; letter-spacing: 1px;">Night Scheduler</th>
                        <th class="py-3 pe-4 text-end text-uppercase text-muted" style="font-size: 0.75rem; letter-spacing: 1px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($packages)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-box-open fa-3x mb-3 opacity-25"></i><br>
                            No packages found. Click "Create Package" to add one.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach($packages as $p): 
                            $sched = json_decode($p['speed_scheduler'], true);
                        ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td class="py-3 ps-4 fw-bold text-dark fs-6"><?= htmlspecialchars($p['name']) ?></td>
                            <td class="py-3">
                                <?php if($p['rate_limit'] === 'Unlimited' || empty($p['rate_limit'])): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 rounded-pill"><i class="fa-solid fa-infinity me-1"></i> Unlimited</span>
                                <?php else: ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 rounded-pill"><i class="fa-solid fa-gauge-high me-1"></i> <?= htmlspecialchars($p['rate_limit']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-success fw-bold fs-5"><small class="text-muted fs-6 me-1">Rs</small><?= number_format($p['price'], 0) ?></td>
                            <td class="py-3"><span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25"><i class="fa-regular fa-calendar me-1"></i> <?= $p['validity_days'] ?> Days</span></td>
                            <td class="py-3">
                                <?= $p['data_limit_gb'] > 0 ? '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-2 py-1 rounded-pill"><i class="fa-solid fa-database me-1"></i> ' . $p['data_limit_gb'] . ' GB</span>' : '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 rounded-pill"><i class="fa-solid fa-infinity me-1"></i> Unlimited</span>' ?>
                            </td>
                            <td class="py-3">
                                <?php if ($sched): ?>
                                    <div class="d-flex flex-column">
                                        <small class="text-info fw-bold" style="font-size: 0.75rem;"><i class="fa-regular fa-clock"></i> <?= $sched['start_time'] ?> to <?= $sched['end_time'] ?></small>
                                        <small class="text-dark" style="font-size: 0.75rem;"><i class="fa-solid fa-gauge-high text-primary"></i> <?= htmlspecialchars($sched['speed']) ?></small>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small"><i class="fa-solid fa-ban me-1"></i> None</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 pe-4 text-end">
                                <button class="btn btn-sm btn-outline-info rounded-circle me-1" style="width: 32px; height: 32px; padding: 0;" onclick='openEditModal(<?= json_encode($p) ?>)' title="Edit Package"><i class="fa-solid fa-pen"></i></button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this package?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="package_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle" style="width: 32px; height: 32px; padding: 0;" title="Delete Package"><i class="fa-solid fa-trash"></i></button>
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

<!-- Package Modal (Premium UI) -->
<div class="modal fade" id="packageModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-white border border shadow-lg text-dark" style="border-radius: 16px;">
      <div class="modal-header border-bottom border p-4">
        <h5 class="modal-title fw-bold" id="modalTitle"><i class="fa-solid fa-box text-primary me-2"></i> Create Package</h5>
        <button type="button" class="btn-close btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="packageForm">
        <input type="hidden" name="action" id="modalAction" value="create">
        <input type="hidden" name="package_id" id="modalPackageId" value="">
        <div class="modal-body p-4">
            
            <div class="row g-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1" style="letter-spacing: 1px;">Package Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="pkg_name" class="form-control px-3 py-2" required placeholder="e.g. 10Mbps Unlimited">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1" style="letter-spacing: 1px;">Price (Rs) <span class="text-danger">*</span></label>
                    <input type="number" step="1" name="price" id="pkg_price" class="form-control px-3 py-2" required placeholder="e.g. 1500">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1" style="letter-spacing: 1px;">Validity (Days) <span class="text-danger">*</span></label>
                    <input type="number" name="validity_days" id="pkg_validity" class="form-control px-3 py-2" required placeholder="e.g. 30" value="30">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1" style="letter-spacing: 1px;">Data Limit (GB)</label>
                    <input type="number" name="data_limit_gb" id="pkg_data" class="form-control px-3 py-2" placeholder="e.g. 100" value="0">
                    <small class="text-muted" style="font-size: 0.75rem;">Set <strong class="text-success">0</strong> for Unlimited Data</small>
                </div>
                
                <div class="col-md-12 mt-2">
                    <div class="p-3 rounded border border" style="background: #f8fafc;">
                        <label class="form-label fw-bold text-dark mb-3"><i class="fa-solid fa-gauge-high text-primary me-2"></i>Standard Speed (Rate Limit)</label>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Upload Speed</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border text-danger"><i class="fa-solid fa-upload"></i></span>
                                    <input type="text" name="rate_up" id="pkg_rate_up" class="form-control" placeholder="e.g. 10M, 512k" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted mb-1">Download Speed</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border text-success"><i class="fa-solid fa-download"></i></span>
                                    <input type="text" name="rate_down" id="pkg_rate_down" class="form-control" placeholder="e.g. 10M, 512k" required>
                                </div>
                            </div>
                        </div>
                        <small class="text-muted d-block mt-2" style="font-size: 0.75rem;">Type <strong class="text-info">Unlimited</strong> in any field to remove speed caps entirely.</small>
                    </div>
                </div>
            </div>

            <hr class="border opacity-25 my-4">
            
            <div class="p-3 rounded border border" style="background: #f8fafc;">
                <h6 class="text-primary mb-3 fw-bold"><i class="fa-regular fa-clock me-2"></i> Night / Speed Scheduler (Optional)</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Start Time</label>
                        <input type="time" name="sched_start" id="sched_start" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">End Time</label>
                        <input type="time" name="sched_end" id="sched_end" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Night Upload Speed</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border text-danger"><i class="fa-solid fa-upload"></i></span>
                            <input type="text" name="sched_up" id="sched_up" class="form-control" placeholder="e.g. 20M">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Night Download Speed</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border text-success"><i class="fa-solid fa-download"></i></span>
                            <input type="text" name="sched_down" id="sched_down" class="form-control" placeholder="e.g. 20M">
                        </div>
                    </div>
                    <div class="col-12 mt-2">
                        <small class="text-muted" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-info me-1"></i> If set, FreeRADIUS/MikroTik will change user's speed during these hours automatically.</small>
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer border-top border p-3">
          <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-accent px-4 rounded-pill fw-bold" id="modalBtn">Save Package</button>
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
    
    let rate = pkg.rate_limit || 'Unlimited';
    if (rate.toLowerCase() === 'unlimited' || !rate.includes('/')) {
        document.getElementById('pkg_rate_up').value = 'Unlimited';
        document.getElementById('pkg_rate_down').value = 'Unlimited';
    } else {
        let parts = rate.split('/');
        document.getElementById('pkg_rate_up').value = parts[0];
        document.getElementById('pkg_rate_down').value = parts[1];
    }
    
    if (pkg.speed_scheduler) {
        let sched = JSON.parse(pkg.speed_scheduler);
        document.getElementById('sched_start').value = sched.start_time;
        document.getElementById('sched_end').value = sched.end_time;
        if (sched.speed && sched.speed.includes('/')) {
            let sparts = sched.speed.split('/');
            document.getElementById('sched_up').value = sparts[0];
            document.getElementById('sched_down').value = sparts[1];
        }
    } else {
        document.getElementById('sched_start').value = '';
        document.getElementById('sched_end').value = '';
        document.getElementById('sched_up').value = '';
        document.getElementById('sched_down').value = '';
    }
    
    document.getElementById('modalBtn').innerText = 'Update Package';
    var myModal = new bootstrap.Modal(document.getElementById('packageModal'));
    myModal.show();
}
</script>

<?php require_once 'footer.php'; ?>
