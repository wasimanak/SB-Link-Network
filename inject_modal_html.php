<?php
$f = 'operator/line_man.php';
$c = file_get_contents($f);

if (strpos($c, 'id="editMemberModal"') === false) {
    $editModal = '
<!-- Edit Member Modal -->
<div class="modal fade" id="editMemberModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-white border-0 shadow-lg" style="border-radius: 16px;">
      <div class="modal-header border-bottom p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen text-primary me-2"></i> Edit Line Man</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_member">
        <input type="hidden" name="id" id="edit_id" value="">
        <div class="modal-body p-4">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Full Name</label>
                    <input type="text" name="full_name" id="edit_fullname" class="form-control bg-light" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Username (Read Only)</label>
                    <input type="text" id="edit_username" class="form-control bg-light" readonly>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Password</label>
                    <input type="text" name="password" id="edit_password" class="form-control bg-light" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Phone Number</label>
                    <input type="text" name="phone" id="edit_phone" class="form-control bg-light">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">City</label>
                    <input type="text" name="city" id="edit_city" class="form-control bg-light">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Status</label>
                    <select name="status" id="edit_status" class="form-select bg-light" required>
                        <option value="active">Active (Can Login)</option>
                        <option value="disabled">Disabled (Blocked)</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold small text-muted">Create Users?</label>
                    <select name="can_create_users" id="edit_can_create" class="form-select bg-light" required>
                        <option value="1">Yes, Allow Creation</option>
                        <option value="0">No, View Only</option>
                    </select>
                </div>
                <div class="col-12 mb-3">
                    <label class="form-label fw-bold small text-muted">Address</label>
                    <textarea name="address" id="edit_address" class="form-control bg-light" rows="2"></textarea>
                </div>
            </div>
        </div>
        <div class="modal-footer border-top p-3">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
';

    // Inject right before footer inclusion
    $c = str_replace("<?php require_once 'footer.php'; ?>", $editModal . "\n<?php require_once 'footer.php'; ?>", $c);
    file_put_contents($f, $c);
    echo "Edit modal HTML injected successfully!\n";
} else {
    echo "Modal already exists.\n";
}
?>
