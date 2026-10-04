<?php
$f = 'operator/packages.php';
$c = file_get_contents($f);

// Find where HTML starts
$htmlStart = strpos($c, '<div class="d-flex justify-content-between align-items-center mb-4">');
$scriptStart = strpos($c, '<script>', $htmlStart);

if ($htmlStart !== false && $scriptStart !== false) {
    
    $newUI = '<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="fw-bold mb-0 text-light" style="letter-spacing: 0.5px;">
        <i class="fa-solid fa-box-open text-accent me-2"></i>Manage Packages
    </h3>
    <button class="btn btn-accent rounded-pill px-4 shadow-lg" data-bs-toggle="modal" data-bs-target="#packageModal" onclick="openCreateModal()">
        <i class="fa-solid fa-plus me-2"></i> Create Package
    </button>
</div>

<div class="card-ui mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-borderless align-middle text-light mb-0" style="background: transparent;">
                <thead style="background: rgba(255, 255, 255, 0.05); border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                    <tr>
                        <th class="py-3 ps-4 text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 1px;">Package Name</th>
                        <th class="py-3 text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 1px;">Speed</th>
                        <th class="py-3 text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 1px;">Price</th>
                        <th class="py-3 text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 1px;">Validity</th>
                        <th class="py-3 text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 1px;">Data Limit</th>
                        <th class="py-3 text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 1px;">Night Scheduler</th>
                        <th class="py-3 pe-4 text-end text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 1px;">Actions</th>
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
                            $sched = json_decode($p[\'speed_scheduler\'], true);
                        ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td class="py-3 ps-4 fw-bold text-light fs-6"><?= htmlspecialchars($p[\'name\']) ?></td>
                            <td class="py-3">
                                <?php if($p[\'rate_limit\'] === \'Unlimited\' || empty($p[\'rate_limit\'])): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 rounded-pill"><i class="fa-solid fa-infinity me-1"></i> Unlimited</span>
                                <?php else: ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 rounded-pill"><i class="fa-solid fa-gauge-high me-1"></i> <?= htmlspecialchars($p[\'rate_limit\']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-success fw-bold fs-5"><small class="text-secondary fs-6 me-1">Rs</small><?= number_format($p[\'price\'], 0) ?></td>
                            <td class="py-3"><span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25"><i class="fa-regular fa-calendar me-1"></i> <?= $p[\'validity_days\'] ?> Days</span></td>
                            <td class="py-3">
                                <?= $p[\'data_limit_gb\'] > 0 ? \'<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-2 py-1 rounded-pill"><i class="fa-solid fa-database me-1"></i> \' . $p[\'data_limit_gb\'] . \' GB</span>\' : \'<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1 rounded-pill"><i class="fa-solid fa-infinity me-1"></i> Unlimited</span>\' ?>
                            </td>
                            <td class="py-3">
                                <?php if ($sched): ?>
                                    <div class="d-flex flex-column">
                                        <small class="text-info fw-bold" style="font-size: 0.75rem;"><i class="fa-regular fa-clock"></i> <?= $sched[\'start_time\'] ?> to <?= $sched[\'end_time\'] ?></small>
                                        <small class="text-light" style="font-size: 0.75rem;"><i class="fa-solid fa-gauge-high text-primary"></i> <?= htmlspecialchars($sched[\'speed\']) ?></small>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small"><i class="fa-solid fa-ban me-1"></i> None</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 pe-4 text-end">
                                <button class="btn btn-sm btn-outline-info rounded-circle me-1" style="width: 32px; height: 32px; padding: 0;" onclick=\'openEditModal(<?= json_encode($p) ?>)\' title="Edit Package"><i class="fa-solid fa-pen"></i></button>
                                <form method="POST" class="d-inline" onsubmit="return confirm(\'Are you sure you want to delete this package?\');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="package_id" value="<?= $p[\'id\'] ?>">
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
    <div class="modal-content bg-dark border border-secondary shadow-lg text-light" style="border-radius: 16px;">
      <div class="modal-header border-bottom border-secondary border-opacity-50 p-4">
        <h5 class="modal-title fw-bold" id="modalTitle"><i class="fa-solid fa-box text-accent me-2"></i> Create Package</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="packageForm">
        <input type="hidden" name="action" id="modalAction" value="create">
        <input type="hidden" name="package_id" id="modalPackageId" value="">
        <div class="modal-body p-4">
            
            <div class="row g-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold text-secondary small text-uppercase mb-1" style="letter-spacing: 1px;">Package Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="pkg_name" class="form-control bg-dark border-secondary text-light px-3 py-2" required placeholder="e.g. 10Mbps Unlimited">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-secondary small text-uppercase mb-1" style="letter-spacing: 1px;">Price (Rs) <span class="text-danger">*</span></label>
                    <input type="number" step="1" name="price" id="pkg_price" class="form-control bg-dark border-secondary text-light px-3 py-2" required placeholder="e.g. 1500">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-secondary small text-uppercase mb-1" style="letter-spacing: 1px;">Validity (Days) <span class="text-danger">*</span></label>
                    <input type="number" name="validity_days" id="pkg_validity" class="form-control bg-dark border-secondary text-light px-3 py-2" required placeholder="e.g. 30" value="30">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-secondary small text-uppercase mb-1" style="letter-spacing: 1px;">Data Limit (GB)</label>
                    <input type="number" name="data_limit_gb" id="pkg_data" class="form-control bg-dark border-secondary text-light px-3 py-2" placeholder="e.g. 100" value="0">
                    <small class="text-muted" style="font-size: 0.75rem;">Set <strong class="text-success">0</strong> for Unlimited Data</small>
                </div>
                
                <div class="col-md-12 mt-2">
                    <div class="p-3 rounded border border-secondary border-opacity-50" style="background: rgba(255,255,255,0.02);">
                        <label class="form-label fw-bold text-light mb-3"><i class="fa-solid fa-gauge-high text-primary me-2"></i>Standard Speed (Rate Limit)</label>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small text-secondary mb-1">Upload Speed</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-secondary text-danger"><i class="fa-solid fa-upload"></i></span>
                                    <input type="text" name="rate_up" id="pkg_rate_up" class="form-control bg-dark border-secondary text-light" placeholder="e.g. 10M, 512k" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-secondary mb-1">Download Speed</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-secondary text-success"><i class="fa-solid fa-download"></i></span>
                                    <input type="text" name="rate_down" id="pkg_rate_down" class="form-control bg-dark border-secondary text-light" placeholder="e.g. 10M, 512k" required>
                                </div>
                            </div>
                        </div>
                        <small class="text-muted d-block mt-2" style="font-size: 0.75rem;">Type <strong class="text-info">Unlimited</strong> in any field to remove speed caps entirely.</small>
                    </div>
                </div>
            </div>

            <hr class="border-secondary opacity-25 my-4">
            
            <div class="p-3 rounded border border-secondary border-opacity-50" style="background: rgba(255,255,255,0.02);">
                <h6 class="text-accent mb-3 fw-bold"><i class="fa-regular fa-clock me-2"></i> Night / Speed Scheduler (Optional)</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-secondary mb-1">Start Time</label>
                        <input type="time" name="sched_start" id="sched_start" class="form-control bg-dark border-secondary text-light">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-secondary mb-1">End Time</label>
                        <input type="time" name="sched_end" id="sched_end" class="form-control bg-dark border-secondary text-light">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-secondary mb-1">Night Upload Speed</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-danger"><i class="fa-solid fa-upload"></i></span>
                            <input type="text" name="sched_up" id="sched_up" class="form-control bg-dark border-secondary text-light" placeholder="e.g. 20M">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-secondary mb-1">Night Download Speed</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark border-secondary text-success"><i class="fa-solid fa-download"></i></span>
                            <input type="text" name="sched_down" id="sched_down" class="form-control bg-dark border-secondary text-light" placeholder="e.g. 20M">
                        </div>
                    </div>
                    <div class="col-12 mt-2">
                        <small class="text-muted" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-info me-1"></i> If set, FreeRADIUS/MikroTik will change user\'s speed during these hours automatically.</small>
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer border-top border-secondary border-opacity-50 p-3">
          <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-accent px-4 rounded-pill fw-bold" id="modalBtn">Save Package</button>
        </div>
      </form>
    </div>
  </div>
</div>
';

    $before = substr($c, 0, $htmlStart);
    $after = substr($c, $scriptStart);
    
    file_put_contents($f, $before . $newUI . "\n" . $after);
    echo "UI Updated successfully!\n";
} else {
    echo "Failed to find replace boundaries.\n";
}
?>
