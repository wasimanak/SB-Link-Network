<?php
$f = 'dealer/users.php';
$c = file_get_contents($f);

$start = strpos($c, '<!-- Add User Modal -->');
$end = strpos($c, '<!-- Renew Modal -->', $start);

if ($start !== false && $end !== false) {
    $oldAddUserModal = substr($c, $start, $end - $start);

    $newAddUserModal = '<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-light border-bottom-0">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus text-primary me-2"></i> Create New User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body p-4 bg-light">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control bg-white shadow-none" placeholder="e.g. Ali Khan" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control bg-white shadow-none" placeholder="e.g. ali123" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Password <span class="text-danger">*</span></label>
                    <input type="text" name="password" class="form-control bg-white shadow-none font-monospace" placeholder="Password" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Package <span class="text-danger">*</span></label>
                    <select name="package_id" id="add_package_id" class="form-select bg-white shadow-none" required>
                        <option value="">Select Package</option>
                        <?php foreach($dealer_packages as $p): ?>
                            <option value="<?= $p[\'id\'] ?>"><?= htmlspecialchars($p[\'name\']) ?> (Rs.<?= $p[\'dealer_price\'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label text-muted small fw-bold">Select Router / City <span class="text-danger">*</span></label>
                    <select name="router_nasname" class="form-select bg-white shadow-none" required>
                        <?php if(empty($dealer_routers)): ?>
                            <option value="">No Routers Assigned (Contact Operator)</option>
                        <?php else: ?>
                            <?php foreach($dealer_routers as $r): ?>
                                <option value="<?= $r[\'nasname\'] ?>"><?= htmlspecialchars($r[\'shortname\'] ?: \'Router\') ?> (<?= $r[\'nasname\'] ?>)</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            
            <?php if($can_custom_expiry): ?>
            <div class="mt-4 pt-3 border-top">
                <label class="form-label text-muted small fw-bold">Custom Expiry (Optional)</label>
                <input type="datetime-local" name="expiry_date" id="add_expiry" class="form-control bg-white shadow-none">
                <div class="form-text small"><i class="fa-solid fa-circle-info me-1"></i>Leave blank for standard 30 days.</div>
            </div>
            <?php else: ?>
                <input type="hidden" id="add_expiry" value="">
            <?php endif; ?>
            
            <div class="mt-4 p-3 bg-white border rounded shadow-sm d-flex justify-content-between align-items-center">
                <div class="text-secondary fw-bold small text-uppercase">Est. Initial Deduction</div>
                <h4 class="mb-0 fw-bold text-danger" id="add_live_deduction">Rs. 0.00</h4>
            </div>
        </div>
        <div class="modal-footer bg-white border-top-0 pt-0 pb-4 pe-4">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"><i class="fa-solid fa-check me-2"></i>Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>

';

    $c = substr_replace($c, $newAddUserModal, $start, $end - $start);
    file_put_contents($f, $c);
    echo "Premium UI successfully applied to addUserModal.\n";
} else {
    echo "Failed to find modal boundaries.\n";
}
?>
