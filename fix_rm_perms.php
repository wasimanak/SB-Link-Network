<?php
// 1. operator/recovery_man.php patch
$f1 = 'operator/recovery_man.php';
if (file_exists($f1)) {
    $c = file_get_contents($f1);
    
    // Auto schema upgrade for status
    $schemaAdditions = "
try { \$pdo->exec(\"ALTER TABLE `recovery_men` ADD COLUMN `status` ENUM('active', 'disabled') DEFAULT 'active'\"); } catch (Exception \$e) {}
";
    if (strpos($c, "ADD COLUMN `status`") === false) {
        $c = str_replace("try { \$pdo->exec(\"ALTER TABLE `recovery_men` ADD COLUMN `cash_in_hand`", $schemaAdditions . "try { \$pdo->exec(\"ALTER TABLE `recovery_men` ADD COLUMN `cash_in_hand`", $c);
    }
    
    // Update ADD PHP logic
    $oldAdd = "\$stmt = \$pdo->prepare(\"INSERT INTO recovery_men (client_id, full_name, username, password, phone, address, city) VALUES (?, ?, ?, ?, ?, ?, ?)\");
            \$stmt->execute([\$client_id, \$full_name, \$username, \$password, \$phone, \$address, \$city]);";
    $newAdd = "\$status = \$_POST['status'] ?? 'active';
            \$stmt = \$pdo->prepare(\"INSERT INTO recovery_men (client_id, full_name, username, password, phone, address, city, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)\");
            \$stmt->execute([\$client_id, \$full_name, \$username, \$password, \$phone, \$address, \$city, \$status]);";
    $c = str_replace($oldAdd, $newAdd, $c);
    
    // Add EDIT PHP logic
    if (strpos($c, "if (\$action === 'edit_member')") === false) {
        $editPHP = "
    // Edit Member
    if (\$action === 'edit_member' && isset(\$_POST['id'])) {
        \$id = (int)\$_POST['id'];
        \$full_name = trim(\$_POST['full_name']);
        \$password = trim(\$_POST['password']);
        \$phone = trim(\$_POST['phone'] ?? '');
        \$address = trim(\$_POST['address'] ?? '');
        \$city = trim(\$_POST['city'] ?? '');
        \$status = \$_POST['status'] ?? 'active';
        
        try {
            \$stmt = \$pdo->prepare(\"UPDATE recovery_men SET full_name=?, password=?, phone=?, address=?, city=?, status=? WHERE id=? AND client_id=?\");
            \$stmt->execute([\$full_name, \$password, \$phone, \$address, \$city, \$status, \$id, \$client_id]);
            echo \"<script>alert('Recovery Man updated successfully!'); window.location='\$_SERVER[PHP_SELF]';</script>\";
            exit;
        } catch (PDOException \$e) {
            echo \"<script>alert('Error: ' + \" . json_encode(\$e->getMessage()) . \"); window.history.back();</script>\";
        }
    }
";
        $c = str_replace("// Delete Member", $editPHP . "    // Delete Member", $c);
    }

    // Replace table headers to include Status
    if (strpos($c, '<th>Access Control</th>') === false) {
        $c = str_replace("<th>Cash in Hand</th>", "<th>Access Control</th>\n                          <th>Cash in Hand</th>", $c);
    }
    
    // Replace table row rendering
    $oldTr = '<td>
                            <div class="fw-bold <?= ($m[\'cash_in_hand\']>0)?\'text-success\':\'text-muted\' ?>">';
    $newTr = '<td>
                              <?php if(isset($m[\'status\']) && $m[\'status\'] === \'active\'): ?>
                                  <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">Active</span>
                              <?php else: ?>
                                  <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25">Disabled</span>
                              <?php endif; ?>
                          </td>
                          <td>
                            <div class="fw-bold <?= ($m[\'cash_in_hand\']>0)?\'text-success\':\'text-muted\' ?>">';
    if (strpos($c, 'badge bg-success bg-opacity-10') === false) {
        $c = str_replace($oldTr, $newTr, $c);
    }

    // Fix the Actions column to add Edit Button
    $oldActions = '<td class="text-secondary small"><?= date(\'d M Y\', strtotime($m[\'created_at\'])) ?></td>
                          <td>
                              <form method="POST" onsubmit="return confirm(\'Are you sure you want to delete this Recovery Man?\');" class="m-0">';
    $newActions = '<td class="text-secondary small"><?= date(\'d M Y\', strtotime($m[\'created_at\'])) ?></td>
                          <td>
                              <button type="button" class="btn btn-sm btn-outline-primary me-1 btn-edit" 
                                  data-id="<?= $m[\'id\'] ?>"
                                  data-fullname="<?= htmlspecialchars($m[\'full_name\']) ?>"
                                  data-username="<?= htmlspecialchars($m[\'username\']) ?>"
                                  data-password="<?= htmlspecialchars($m[\'password\']) ?>"
                                  data-phone="<?= htmlspecialchars($m[\'phone\']) ?>"
                                  data-city="<?= htmlspecialchars($m[\'city\']) ?>"
                                  data-address="<?= htmlspecialchars($m[\'address\']) ?>"
                                  data-status="<?= $m[\'status\'] ?? \'active\' ?>">
                                  <i class="fa-solid fa-pen"></i>
                              </button>
                              <form method="POST" onsubmit="return confirm(\'Are you sure you want to delete this Recovery Man?\');" class="d-inline">';
    if (strpos($c, 'btn-edit') === false) {
        $c = str_replace($oldActions, $newActions, $c);
    }

    // Form inputs for Add Modal
    $accessHtml = '
            <div class="col-12 mb-3">
                <label class="form-label fw-bold small text-muted">Status</label>
                <select name="status" class="form-select bg-light" required>
                    <option value="active">Active (Can Login)</option>
                    <option value="disabled">Disabled (Blocked)</option>
                </select>
            </div>
    ';
    // Inject into Add modal before the city/address block ends
    if (strpos($c, 'name="status"') === false) {
        $c = str_replace('<div class="col-12 mb-3">
                        <label class="form-label fw-bold small text-muted">Address</label>', $accessHtml . '<div class="col-12 mb-3">
                        <label class="form-label fw-bold small text-muted">Address</label>', $c);
    }

    // Add Edit Modal and JS at the end before footer
    if (strpos($c, 'id="editMemberModal"') === false) {
        $editModal = '
<!-- Edit Member Modal -->
<div class="modal fade" id="editMemberModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-white border-0 shadow-lg" style="border-radius: 16px;">
      <div class="modal-header border-bottom p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen text-primary me-2"></i> Edit Recovery Man</h5>
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

<script>
$(document).ready(function() {
    $("#membersTable tbody").on("click", ".btn-edit", function(e) {
        e.preventDefault();
        $("#edit_id").val($(this).attr("data-id"));
        $("#edit_fullname").val($(this).attr("data-fullname"));
        $("#edit_username").val($(this).attr("data-username"));
        $("#edit_password").val($(this).attr("data-password"));
        $("#edit_phone").val($(this).attr("data-phone"));
        $("#edit_city").val($(this).attr("data-city"));
        $("#edit_address").val($(this).attr("data-address"));
        $("#edit_status").val($(this).attr("data-status"));
        
        var m = new bootstrap.Modal(document.getElementById("editMemberModal"));
        m.show();
    });
    
    $(".modal").appendTo("body");
});
</script>
';
        $c = str_replace("<?php require_once 'footer.php'; ?>", $editModal . "\n<?php require_once 'footer.php'; ?>", $c);
    }
    
    file_put_contents($f1, $c);
}

// 2. recoveryman/login.php patch
$f2 = 'recoveryman/login.php';
if (file_exists($f2)) {
    $c = file_get_contents($f2);
    
    // Block disabled login
    if (strpos($c, "status = 'active'") === false) {
        $c = str_replace(
            "SELECT * FROM recovery_men WHERE username = ?",
            "SELECT * FROM recovery_men WHERE username = ? AND status = 'active'",
            $c
        );
        $c = str_replace(
            "Invalid credentials.",
            "Invalid credentials or inactive account.",
            $c
        );
        file_put_contents($f2, $c);
    }
}

// 3. recoveryman/dashboard.php patch (kick out if disabled during session)
$f3 = 'recoveryman/dashboard.php';
if (file_exists($f3)) {
    $c = file_get_contents($f3);
    
    $sessionLogic = "
\$rm_id = \$_SESSION['rm_id'];
\$client_id = \$_SESSION['client_id'];
\$rm_name = \$_SESSION['rm_name'];

// Check RM permissions
\$chkStmt = \$pdo->prepare(\"SELECT status FROM recovery_men WHERE id = ?\");
\$chkStmt->execute([\$rm_id]);
\$rm_data = \$chkStmt->fetch();
if (!\$rm_data || \$rm_data['status'] === 'disabled') {
    session_destroy();
    header(\"Location: login.php\");
    exit;
}
";
    if (strpos($c, "status'] === 'disabled'") === false) {
        $c = preg_replace('/\$rm_id = \$_SESSION\[\'rm_id\'\];.*\$rm_name = \$_SESSION\[\'rm_name\'\];/s', $sessionLogic, $c);
        file_put_contents($f3, $c);
    }
}

echo "Recovery Man permissions updated globally.\n";
?>
