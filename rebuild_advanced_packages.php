<?php
$f = 'superadmin/packages_master.php';

$content = <<<'PHP'
<?php
require_once 'header.php';

// Auto-upgrade schema for Master/Child packages & scheduler
try {
    $pdo->exec("ALTER TABLE packages ADD COLUMN is_master TINYINT(1) DEFAULT 0");
    $pdo->exec("ALTER TABLE packages ADD COLUMN master_id INT(11) DEFAULT NULL");
    $pdo->exec("ALTER TABLE packages ADD COLUMN speed_scheduler TEXT DEFAULT NULL");
    // Convert old global packages to master
    $pdo->exec("UPDATE packages SET is_master = 1 WHERE client_id = 0 AND is_master = 0");
} catch (PDOException $e) {}

// Fetch all operators for dropdowns
$opStmt = $pdo->query("SELECT id, company_name as username FROM clients ORDER BY company_name ASC");
$operators = $opStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle Add / Edit / Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        try {
            if ($action === 'add' || $action === 'edit') {
                $name = trim($_POST['name']);
                
                // Advanced Speed Options
                $rate_up = trim($_POST['rate_up'] ?? '');
                $rate_down = trim($_POST['rate_down'] ?? '');
                $rate_limit = (strcasecmp($rate_up, 'Unlimited') === 0 || empty($rate_up) || empty($rate_down)) ? 'Unlimited' : $rate_up . '/' . $rate_down;

                $scheduler = null;
                if (!empty($_POST['sched_start']) && !empty($_POST['sched_end']) && (!empty($_POST['sched_up']) || !empty($_POST['sched_down']))) {
                    $sched_array = [
                        'start_time' => $_POST['sched_start'],
                        'end_time' => $_POST['sched_end'],
                        'speed' => trim($_POST['sched_up'] ?? '') . '/' . trim($_POST['sched_down'] ?? '')
                    ];
                    $scheduler = json_encode($sched_array);
                }

                $validity_days = (int)$_POST['validity_days'];
                $price = (float)$_POST['price'];
                $data_limit_gb = empty($_POST['data_limit_gb']) ? 0 : (int)$_POST['data_limit_gb'];
                $operator_ids = $_POST['operator_ids'] ?? [];

                $is_global = in_array('0', $operator_ids);
                
                if ($action === 'add') {
                    // Insert Master Package
                    $stmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb, is_master, speed_scheduler) VALUES (0, :name, :rate_limit, :validity, :price, :data_limit, 1, :scheduler)");
                    $stmt->execute([
                        'name' => $name,
                        'rate_limit' => $rate_limit,
                        'validity' => $validity_days,
                        'price' => $price,
                        'data_limit' => $data_limit_gb,
                        'scheduler' => $scheduler
                    ]);
                    
                    $master_id = $pdo->lastInsertId();

                    // If not global, insert child packages for selected operators
                    if (!$is_global && !empty($operator_ids)) {
                        $childStmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb, is_master, master_id, speed_scheduler) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                        foreach ($operator_ids as $op_id) {
                            if ($op_id > 0) {
                                $childStmt->execute([$op_id, $name, $rate_limit, $validity_days, $price, $data_limit_gb, $master_id, $scheduler]);
                            }
                        }
                    }
                    echo "<div class='alert alert-success mt-3'>Master package created and assigned successfully.</div>";
                    
                } elseif ($action === 'edit') {
                    $master_id = (int)$_POST['package_id'];

                    // Update Master Package (Force is_master = 1)
                    $stmt = $pdo->prepare("UPDATE packages SET is_master = 1, name = :name, rate_limit = :rate_limit, validity_days = :validity, price = :price, data_limit_gb = :data_limit, speed_scheduler = :scheduler WHERE id = :id");
                    $stmt->execute([
                        'name' => $name,
                        'rate_limit' => $rate_limit,
                        'validity' => $validity_days,
                        'price' => $price,
                        'data_limit' => $data_limit_gb,
                        'scheduler' => $scheduler,
                        'id' => $master_id
                    ]);

                    // Sync Child Packages
                    if ($is_global) {
                        // Delete all children because it's now global
                        $pdo->prepare("DELETE FROM packages WHERE master_id = ?")->execute([$master_id]);
                    } else {
                        // Update existing children's details
                        $updateChild = $pdo->prepare("UPDATE packages SET name = ?, rate_limit = ?, validity_days = ?, price = ?, data_limit_gb = ?, speed_scheduler = ? WHERE master_id = ?");
                        $updateChild->execute([$name, $rate_limit, $validity_days, $price, $data_limit_gb, $scheduler, $master_id]);
                        
                        // Fetch currently assigned operators
                        $currStmt = $pdo->prepare("SELECT client_id FROM packages WHERE master_id = ?");
                        $currStmt->execute([$master_id]);
                        $current_ops = $currStmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        $new_ops = array_map('intval', $operator_ids);
                        
                        // Delete removed operators
                        $removed_ops = array_diff($current_ops, $new_ops);
                        if (!empty($removed_ops)) {
                            $placeholders = implode(',', array_fill(0, count($removed_ops), '?'));
                            $delParams = array_merge([$master_id], $removed_ops);
                            $pdo->prepare("DELETE FROM packages WHERE master_id = ? AND client_id IN ($placeholders)")->execute($delParams);
                        }
                        
                        // Add new operators
                        $added_ops = array_diff($new_ops, $current_ops);
                        if (!empty($added_ops)) {
                            $childStmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb, is_master, master_id, speed_scheduler) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                            foreach ($added_ops as $op_id) {
                                if ($op_id > 0) {
                                    $childStmt->execute([$op_id, $name, $rate_limit, $validity_days, $price, $data_limit_gb, $master_id, $scheduler]);
                                }
                            }
                        }
                    }
                    echo "<div class='alert alert-success mt-3'>Master package updated and synced successfully.</div>";
                }
            } elseif ($action === 'delete') {
                $id = (int)$_POST['delete_id'];
                // Delete master and all children
                $pdo->prepare("DELETE FROM packages WHERE id = ? OR master_id = ?")->execute([$id, $id]);
                echo "<div class='alert alert-success mt-3'>Master package and all assigned operator packages deleted successfully.</div>";
            }
        } catch (PDOException $e) {
            echo "<div class='alert alert-danger mt-3'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Fetch Master & Operator Packages
try {
    $stmt = $pdo->query("SELECT p.*, c.company_name as owner_name FROM packages p LEFT JOIN clients c ON p.client_id = c.id WHERE p.is_master = 1 OR p.master_id IS NULL ORDER BY p.id DESC");
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch Assignments
    $assignedStmt = $pdo->query("SELECT p.master_id, c.id, c.company_name as username FROM packages p JOIN clients c ON p.client_id = c.id WHERE p.master_id IS NOT NULL");
    $assignments = [];
    while($row = $assignedStmt->fetch(PDO::FETCH_ASSOC)) {
        $assignments[$row['master_id']][] = $row;
    }
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Error loading packages: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>

<div class="d-flex justify-content-between align-items-center mb-4 mt-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-box-open me-2 text-primary"></i> Master Packages</h4>
    <button class="btn btn-primary px-4" data-bs-toggle="modal" data-bs-target="#addPackageModal">
        <i class="fa-solid fa-plus me-1"></i> Add Master Package
    </button>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">ID</th>
                        <th>Package Name</th>
                        <th>Assigned To</th>
                        <th>Rate Limit</th>
                        <th>Validity</th>
                        <th>Price</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($packages)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No packages found.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach($packages as $p): 
                            $is_global = empty($assignments[$p['id']]) && $p['client_id'] == 0; 
                            $is_operator_specific = ($p['client_id'] > 0 && $p['is_master'] == 0);
                        ?>
                        <tr>
                            <td class="ps-4 fw-bold text-secondary">#<?= $p['id'] ?></td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($p['name']) ?></td>
                            <td>
                                <?php if($is_operator_specific): ?>
                                    <span class="badge bg-info text-dark"><i class="fa-solid fa-user-gear me-1"></i> <?= htmlspecialchars($p['owner_name'] ?? 'Operator') ?> (Owner)</span>
                                <?php elseif($is_global): ?>
                                    <span class="badge bg-success">Global (All Operators)</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1">
                                    <?php if(!empty($assignments[$p['id']])): ?>
                                        <?php foreach($assignments[$p['id']] as $asg): ?>
                                            <span class="badge bg-primary"><i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($asg['username']) ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Not Assigned</span>
                                    <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(strcasecmp($p['rate_limit'], 'Unlimited') === 0): ?>
                                    <span class="badge bg-secondary">Unlimited</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25"><?= htmlspecialchars($p['rate_limit']) ?></span>
                                <?php endif; ?>
                                
                                <?php if(!empty($p['speed_scheduler'])): ?>
                                    <i class="fa-solid fa-moon text-primary ms-1" title="Night/Speed Scheduler Active"></i>
                                <?php endif; ?>
                            </td>
                            <td><?= $p['validity_days'] ?> Days</td>
                            <td class="text-success fw-bold">Rs. <?= number_format($p['price'], 2) ?></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editPackageModal<?= $p['id'] ?>">
                                    <i class="fa-solid fa-edit"></i> Edit
                                </button>
                                
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this master package AND remove it from all assigned operators?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </button>
                                </form>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editPackageModal<?= $p['id'] ?>" tabindex="-1">
                                  <div class="modal-dialog modal-lg">
                                    <div class="modal-content text-start">
                                      <div class="modal-header border-bottom p-4">
                                        <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen text-primary me-2"></i> Edit Master Package</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                      </div>
                                      <form method="POST">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="package_id" value="<?= $p['id'] ?>">
                                        <div class="modal-body p-4">
                                            
                                            <div class="mb-4">
                                                <label class="form-label fw-bold text-primary">Assign To</label>
                                                <div class="small text-muted mb-2">Check the boxes to assign this package.</div>
                                                <div class="border border-primary border-opacity-25 rounded p-3 bg-light shadow-sm" style="max-height: 200px; overflow-y: auto;">
                                                    <div class="form-check border-bottom pb-2 mb-2">
                                                        <input class="form-check-input shadow-sm" type="checkbox" name="operator_ids[]" value="0" id="op_global_edit_<?= $p['id'] ?>" <?= $is_global ? 'checked' : '' ?>>
                                                        <label class="form-check-label fw-bold text-success" for="op_global_edit_<?= $p['id'] ?>">
                                                            <i class="fa-solid fa-earth-americas me-1"></i> Global (Available to All Operators)
                                                        </label>
                                                    </div>
                                                    <?php foreach($operators as $op): 
                                                        $selected = '';
                                                        if (!empty($assignments[$p['id']])) {
                                                            foreach($assignments[$p['id']] as $asg) {
                                                                if($asg['id'] == $op['id']) $selected = 'checked';
                                                            }
                                                        }
                                                        if ($p['client_id'] == $op['id']) {
                                                            $selected = 'checked';
                                                        }
                                                    ?>
                                                        <div class="form-check mb-1">
                                                            <input class="form-check-input" type="checkbox" name="operator_ids[]" value="<?= $op['id'] ?>" id="op_edit_<?= $p['id'] ?>_<?= $op['id'] ?>" <?= $selected ?>>
                                                            <label class="form-check-label fw-bold" for="op_edit_<?= $p['id'] ?>_<?= $op['id'] ?>">
                                                                <?= htmlspecialchars($op['username']) ?>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                            <div class="row g-4">
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Package Name <span class="text-danger">*</span></label>
                                                    <input type="text" name="name" class="form-control px-3 py-2" value="<?= htmlspecialchars($p['name']) ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Price (Rs) <span class="text-danger">*</span></label>
                                                    <input type="number" step="0.01" name="price" class="form-control px-3 py-2" value="<?= $p['price'] ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Validity (Days) <span class="text-danger">*</span></label>
                                                    <input type="number" name="validity_days" class="form-control px-3 py-2" value="<?= $p['validity_days'] ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Data Limit (GB)</label>
                                                    <input type="number" name="data_limit_gb" class="form-control px-3 py-2" value="<?= $p['data_limit_gb'] ?>">
                                                    <small class="text-muted" style="font-size: 0.75rem;">Set <strong class="text-success">0</strong> for Unlimited Data</small>
                                                </div>
                                            </div>
                                            
                                            <?php 
                                            // Parse existing rate limit (e.g. 10M/10M)
                                            $r_up = ''; $r_down = '';
                                            if(strcasecmp($p['rate_limit'], 'Unlimited') !== 0 && strpos($p['rate_limit'], '/') !== false) {
                                                list($r_up, $r_down) = explode('/', $p['rate_limit']);
                                            } elseif(strcasecmp($p['rate_limit'], 'Unlimited') !== 0) {
                                                $r_up = $p['rate_limit']; $r_down = $p['rate_limit'];
                                            } else {
                                                $r_up = 'Unlimited'; $r_down = 'Unlimited';
                                            }

                                            // Parse existing scheduler
                                            $s_start = ''; $s_end = ''; $s_up = ''; $s_down = '';
                                            if(!empty($p['speed_scheduler'])) {
                                                $s_data = json_decode($p['speed_scheduler'], true);
                                                if($s_data) {
                                                    $s_start = $s_data['start_time'] ?? '';
                                                    $s_end = $s_data['end_time'] ?? '';
                                                    if(isset($s_data['speed']) && strpos($s_data['speed'], '/') !== false) {
                                                        list($s_up, $s_down) = explode('/', $s_data['speed']);
                                                    }
                                                }
                                            }
                                            ?>

                                            <div class="col-md-12 mt-4">
                                                <div class="p-3 rounded border" style="background: #f8fafc;">
                                                    <label class="form-label fw-bold text-dark mb-3"><i class="fa-solid fa-gauge-high text-primary me-2"></i>Standard Speed (Rate Limit)</label>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted mb-1">Upload Speed</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text bg-light border text-danger"><i class="fa-solid fa-upload"></i></span>
                                                                <input type="text" name="rate_up" class="form-control" value="<?= htmlspecialchars($r_up) ?>" required>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted mb-1">Download Speed</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text bg-light border text-success"><i class="fa-solid fa-download"></i></span>
                                                                <input type="text" name="rate_down" class="form-control" value="<?= htmlspecialchars($r_down) ?>" required>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <hr class="border opacity-25 my-4">
                                            
                                            <div class="p-3 rounded border" style="background: #f8fafc;">
                                                <h6 class="text-primary mb-3 fw-bold"><i class="fa-regular fa-clock me-2"></i> Night / Speed Scheduler (Optional)</h6>
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label small text-muted mb-1">Start Time</label>
                                                        <input type="time" name="sched_start" class="form-control" value="<?= htmlspecialchars($s_start) ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small text-muted mb-1">End Time</label>
                                                        <input type="time" name="sched_end" class="form-control" value="<?= htmlspecialchars($s_end) ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small text-muted mb-1">Night Upload Speed</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text bg-light border text-danger"><i class="fa-solid fa-upload"></i></span>
                                                            <input type="text" name="sched_up" class="form-control" placeholder="e.g. 20M" value="<?= htmlspecialchars($s_up) ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small text-muted mb-1">Night Download Speed</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text bg-light border text-success"><i class="fa-solid fa-download"></i></span>
                                                            <input type="text" name="sched_down" class="form-control" placeholder="e.g. 20M" value="<?= htmlspecialchars($s_down) ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>
                                        <div class="modal-footer border-top p-3">
                                          <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                                          <button type="submit" class="btn btn-primary px-4 rounded-pill fw-bold shadow-sm">Save Changes</button>
                                        </div>
                                      </form>
                                    </div>
                                  </div>
                                </div>
                                <!-- End Edit Modal -->

                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Package Modal -->
<div class="modal fade" id="addPackageModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header border-bottom p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-box text-primary me-2"></i> Add Master Package</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="modal-body p-4">
            
            <div class="mb-4">
                <label class="form-label fw-bold text-primary">Assign To</label>
                <div class="small text-muted mb-2">Check the boxes to assign this package.</div>
                <div class="border border-primary border-opacity-25 rounded p-3 bg-light shadow-sm" style="max-height: 200px; overflow-y: auto;">
                    <div class="form-check border-bottom pb-2 mb-2">
                        <input class="form-check-input shadow-sm" type="checkbox" name="operator_ids[]" value="0" id="op_global_add" checked>
                        <label class="form-check-label fw-bold text-success" for="op_global_add">
                            <i class="fa-solid fa-earth-americas me-1"></i> Global (Available to All Operators)
                        </label>
                    </div>
                    <?php foreach($operators as $op): ?>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" name="operator_ids[]" value="<?= $op['id'] ?>" id="op_add_<?= $op['id'] ?>">
                            <label class="form-check-label fw-bold" for="op_add_<?= $op['id'] ?>">
                                <?= htmlspecialchars($op['username']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Package Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control px-3 py-2" placeholder="e.g. 10 Mbps Standard" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Price (Rs) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="price" class="form-control px-3 py-2" placeholder="e.g. 1500" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Validity (Days) <span class="text-danger">*</span></label>
                    <input type="number" name="validity_days" class="form-control px-3 py-2" placeholder="30" value="30" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Data Limit (GB)</label>
                    <input type="number" name="data_limit_gb" class="form-control px-3 py-2" value="0">
                    <small class="text-muted" style="font-size: 0.75rem;">Set <strong class="text-success">0</strong> for Unlimited Data</small>
                </div>
            </div>

            <div class="col-md-12 mt-4">
                <div class="p-3 rounded border" style="background: #f8fafc;">
                    <label class="form-label fw-bold text-dark mb-3"><i class="fa-solid fa-gauge-high text-primary me-2"></i>Standard Speed (Rate Limit)</label>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Upload Speed</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border text-danger"><i class="fa-solid fa-upload"></i></span>
                                <input type="text" name="rate_up" class="form-control" placeholder="e.g. 10M" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Download Speed</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border text-success"><i class="fa-solid fa-download"></i></span>
                                <input type="text" name="rate_down" class="form-control" placeholder="e.g. 10M" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <hr class="border opacity-25 my-4">
            
            <div class="p-3 rounded border" style="background: #f8fafc;">
                <h6 class="text-primary mb-3 fw-bold"><i class="fa-regular fa-clock me-2"></i> Night / Speed Scheduler (Optional)</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Start Time</label>
                        <input type="time" name="sched_start" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">End Time</label>
                        <input type="time" name="sched_end" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Night Upload Speed</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border text-danger"><i class="fa-solid fa-upload"></i></span>
                            <input type="text" name="sched_up" class="form-control" placeholder="e.g. 20M">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">Night Download Speed</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border text-success"><i class="fa-solid fa-download"></i></span>
                            <input type="text" name="sched_down" class="form-control" placeholder="e.g. 20M">
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer border-top p-3">
          <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 rounded-pill fw-bold shadow-sm">Create Package</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once 'footer.php'; ?>
PHP;

file_put_contents($f, $content);
echo "Rebuilt packages_master.php with advanced features.\n";
?>
