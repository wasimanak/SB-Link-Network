<?php
// 1. operator/line_man.php patch
$f1 = 'operator/line_man.php';
if (file_exists($f1)) {
    $c = file_get_contents($f1);
    
    // Auto schema upgrade
    $schemaAdditions = "
try { \$pdo->exec(\"ALTER TABLE `linemen` ADD COLUMN `status` ENUM('active', 'disabled') DEFAULT 'active'\"); } catch (Exception \$e) {}
try { \$pdo->exec(\"ALTER TABLE `linemen` ADD COLUMN `can_create_users` TINYINT(1) DEFAULT 1\"); } catch (Exception \$e) {}
";
    if (strpos($c, "ADD COLUMN `status`") === false) {
        $c = str_replace("try {", $schemaAdditions . "try {", $c);
    }
    
    // Update ADD PHP logic
    $oldAdd = "\$stmt = \$pdo->prepare(\"INSERT INTO linemen (client_id, full_name, username, password, phone, address, city) VALUES (?, ?, ?, ?, ?, ?, ?)\");
            \$stmt->execute([\$client_id, \$full_name, \$username, \$password, \$phone, \$address, \$city]);";
    $newAdd = "\$status = \$_POST['status'] ?? 'active';
            \$can_create = isset(\$_POST['can_create_users']) ? (int)\$_POST['can_create_users'] : 1;
            \$stmt = \$pdo->prepare(\"INSERT INTO linemen (client_id, full_name, username, password, phone, address, city, status, can_create_users) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)\");
            \$stmt->execute([\$client_id, \$full_name, \$username, \$password, \$phone, \$address, \$city, \$status, \$can_create]);";
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
        \$can_create = isset(\$_POST['can_create_users']) ? (int)\$_POST['can_create_users'] : 1;
        
        try {
            \$stmt = \$pdo->prepare(\"UPDATE linemen SET full_name=?, password=?, phone=?, address=?, city=?, status=?, can_create_users=? WHERE id=? AND client_id=?\");
            \$stmt->execute([\$full_name, \$password, \$phone, \$address, \$city, \$status, \$can_create, \$id, \$client_id]);
            echo \"<script>alert('Line Man updated successfully!'); window.location='\$_SERVER[PHP_SELF]';</script>\";
            exit;
        } catch (PDOException \$e) {
            echo \"<script>alert('Error: ' + \" . json_encode(\$e->getMessage()) . \"); window.history.back();</script>\";
        }
    }
";
        $c = str_replace("// Delete Member", $editPHP . "    // Delete Member", $c);
    }

    // Replace table headers to include Status & Permissions
    $c = str_replace("<th>Phone</th>\n                        <th class=\"text-end\">Actions</th>", "<th>Phone</th>\n                        <th>Access Control</th>\n                        <th class=\"text-end\">Actions</th>", $c);
    
    // Replace table row rendering
    $oldTr = "<td><?= htmlspecialchars(\$m['phone'] ?: 'N/A') ?></td>
                            <td class=\"text-end\">
                                <form method=\"POST\" class=\"d-inline\" onsubmit=\"return confirm('Delete this Line Man?');\">
                                    <input type=\"hidden\" name=\"action\" value=\"delete_member\">
                                    <input type=\"hidden\" name=\"id\" value=\"<?= \$m['id'] ?>\">
                                    <button type=\"submit\" class=\"btn btn-sm btn-outline-danger\"><i class=\"fa-solid fa-trash\"></i></button>
                                </form>
                            </td>";
    
    $newTr = "<td><?= htmlspecialchars(\$m['phone'] ?: 'N/A') ?></td>
                            <td>
                                <?php if(isset(\$m['status']) && \$m['status'] === 'active'): ?>
                                    <span class=\"badge bg-success bg-opacity-10 text-success border border-success border-opacity-25\">Active</span>
                                <?php else: ?>
                                    <span class=\"badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25\">Disabled</span>
                                <?php endif; ?>
                                <?php if(isset(\$m['can_create_users']) && \$m['can_create_users']): ?>
                                    <span class=\"badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 ms-1\"><i class=\"fa-solid fa-user-plus\"></i> Yes</span>
                                <?php else: ?>
                                    <span class=\"badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 ms-1\"><i class=\"fa-solid fa-ban\"></i> No</span>
                                <?php endif; ?>
                            </td>
                            <td class=\"text-end\">
                                <button type=\"button\" class=\"btn btn-sm btn-outline-primary me-1 btn-edit\" 
                                    data-id=\"<?= \$m['id'] ?>\"
                                    data-fullname=\"<?= htmlspecialchars(\$m['full_name']) ?>\"
                                    data-username=\"<?= htmlspecialchars(\$m['username']) ?>\"
                                    data-password=\"<?= htmlspecialchars(\$m['password']) ?>\"
                                    data-phone=\"<?= htmlspecialchars(\$m['phone']) ?>\"
                                    data-city=\"<?= htmlspecialchars(\$m['city']) ?>\"
                                    data-address=\"<?= htmlspecialchars(\$m['address']) ?>\"
                                    data-status=\"<?= \$m['status'] ?? 'active' ?>\"
                                    data-create=\"<?= \$m['can_create_users'] ?? 1 ?>\">
                                    <i class=\"fa-solid fa-pen\"></i>
                                </button>
                                <form method=\"POST\" class=\"d-inline\" onsubmit=\"return confirm('Delete this Line Man?');\">
                                    <input type=\"hidden\" name=\"action\" value=\"delete_member\">
                                    <input type=\"hidden\" name=\"id\" value=\"<?= \$m['id'] ?>\">
                                    <button type=\"submit\" class=\"btn btn-sm btn-outline-danger\"><i class=\"fa-solid fa-trash\"></i></button>
                                </form>
                            </td>";
    $c = str_replace($oldTr, $newTr, $c);

    // Form inputs for Add Modal
    $accessHtml = '
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold small text-muted">Status</label>
                <select name="status" class="form-select bg-light" required>
                    <option value="active">Active (Can Login)</option>
                    <option value="disabled">Disabled (Blocked)</option>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label fw-bold small text-muted">Create Users?</label>
                <select name="can_create_users" class="form-select bg-light" required>
                    <option value="1">Yes, Allow Creation</option>
                    <option value="0">No, View Only</option>
                </select>
            </div>
    ';
    // Inject into Add modal before the city/address block ends
    $c = str_replace('<div class="col-12 mb-3">', $accessHtml . '<div class="col-12 mb-3">', $c);

    // Add Edit Modal and JS
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
        
        $js = '
<script>
$(document).ready(function() {
    $("#membersTable").DataTable({ order: [[0, "desc"]] });
    
    // Robust delegation for edit button
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
        $("#edit_can_create").val($(this).attr("data-create"));
        
        var m = new bootstrap.Modal(document.getElementById("editMemberModal"));
        m.show();
    });
    
    $(".modal").appendTo("body");
});
</script>
        ';
        
        // Remove existing simplistic script
        $c = preg_replace('/<script>\s*\$\(document\)\.ready\(function\(\)\{\s*\$\(\'#membersTable\'\)\.DataTable.*?\};\s*\n<\/script>/s', $js, $c);
        $c = str_replace('</body>', $editModal . "\n</body>", $c);
    }
    
    file_put_contents($f1, $c);
}

// 2. lineman/dashboard.php patch
$f2 = 'lineman/dashboard.php';
if (file_exists($f2)) {
    $c = file_get_contents($f2);
    
    // Fetch can_create_users during login/session initialization
    $sessionLogic = "
\$lineman_id = \$_SESSION['lineman_id'];
\$client_id = \$_SESSION['client_id'];
\$lineman_name = \$_SESSION['lineman_name'];

// Check lineman permissions
\$chkStmt = \$pdo->prepare(\"SELECT status, can_create_users FROM linemen WHERE id = ?\");
\$chkStmt->execute([\$lineman_id]);
\$lm_data = \$chkStmt->fetch();
if (!\$lm_data || \$lm_data['status'] === 'disabled') {
    session_destroy();
    header(\"Location: login.php\");
    exit;
}
\$can_create = (isset(\$lm_data['can_create_users']) && \$lm_data['can_create_users'] == 1) ? true : false;
";
    $c = preg_replace('/\$lineman_id = \$_SESSION\[\'lineman_id\'\];.*\$lineman_name = \$_SESSION\[\'lineman_name\'\];/s', $sessionLogic, $c);
    
    // Block POST if can_create is false
    $c = str_replace(
        "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['action']) && \$_POST['action'] === 'add_user') {",
        "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['action']) && \$_POST['action'] === 'add_user') {\n    if (!\$can_create) { echo \"<script>alert('Permission Denied: You do not have permission to create users.'); window.location='dashboard.php';</script>\"; exit; }",
        $c
    );
    
    // Hide UI
    $oldCreateUI = '<!-- Create User -->
        <div class="col-md-5">';
    $newCreateUI = '<!-- Create User -->
        <?php if($can_create): ?>
        <div class="col-md-5">';
    
    $c = str_replace($oldCreateUI, $newCreateUI, $c);
    
    // Also expand history to full width if they can't create users
    $oldHistoryUI = '<!-- 7 Days History -->
        <div class="col-md-7">';
    $newHistoryUI = '<?php endif; ?>
        
        <!-- 7 Days History -->
        <div class="col-md-<?= $can_create ? \'7\' : \'12\' ?>">';
    $c = str_replace($oldHistoryUI, $newHistoryUI, $c);
    
    file_put_contents($f2, $c);
}

echo "Line Man permissions updated globally.\n";
?>
