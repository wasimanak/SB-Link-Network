<?php
$f = 'dealer/users.php';
$c = file_get_contents($f);

// 1. Add Edit User PHP Logic
$editLogic = '
    // EDIT USER (Update profile & optionally extend expiry with balance deduction)
    if ($action === \'edit_user\') {
        $id = (int)$_POST[\'id\'];
        $full_name = trim($_POST[\'full_name\']);
        $password = trim($_POST[\'password\']);
        $new_expiry = $_POST[\'expiry_date\'] ?? null;

        $uStmt = $pdo->prepare("SELECT username, package_id, expiry_date as old_expiry FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$id, $dealer_id]);
        $subData = $uStmt->fetch(PDO::FETCH_ASSOC);

        if ($subData) {
            $u = $subData[\'username\'];
            $pkg_id = $subData[\'package_id\'];
            
            try {
                $pdo->beginTransaction();
                
                $net_deduction = 0;
                $final_expiry = $subData[\'old_expiry\']; // default to unchanged
                
                if (!empty($new_expiry) && $new_expiry !== $subData[\'old_expiry\']) {
                    // Calculate deduction based on current package price
                    $pStmt = $pdo->prepare("SELECT price FROM packages WHERE id = ?");
                    $pStmt->execute([$pkg_id]);
                    $pkgPrice = $pStmt->fetchColumn() ?: 0;
                    
                    $dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                    $dpStmt->execute([$dealer_id, $pkg_id]);
                    $dealerPrice = $dpStmt->fetchColumn() ?: $pkgPrice;

                    // Old value
                    $old_remaining_value = 0;
                    if (!empty($subData[\'old_expiry\']) && strtotime($subData[\'old_expiry\']) > time()) {
                        $old_seconds = strtotime($subData[\'old_expiry\']) - time();
                        $old_days = $old_seconds / 86400;
                        $old_remaining_value = $old_days * ($dealerPrice / 30);
                    }

                    // New value
                    $new_total_value = 0;
                    $new_seconds = strtotime($new_expiry) - time();
                    if ($new_seconds > 0) {
                        $new_days = $new_seconds / 86400;
                        $new_total_value = $new_days * ($dealerPrice / 30);
                    }

                    $net_deduction = round($new_total_value - $old_remaining_value, 2);
                    $final_expiry = date(\'Y-m-d H:i:s\', strtotime($new_expiry));

                    if ($net_deduction > $current_dealer[\'balance\']) {
                        $pdo->rollBack();
                        echo "<script>alert(\'Insufficient balance to extend expiry to that date! Need Rs. $net_deduction\'); window.location=\'users.php\';</script>";
                        exit;
                    }

                    if ($net_deduction > 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $dealer_id]);
                        $note = "Expiry Extension for $u. Deducted: Rs. $net_deduction";
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                    } elseif ($net_deduction < 0) {
                        // If they shortened the expiry, maybe refund? Let\'s just not refund for now to avoid abuse, or refund if strictly requested.
                        // We will allow the date change but not refund.
                    }
                }

                $pdo->prepare("UPDATE subscribers SET full_name = ?, password = ?, expiry_date = ? WHERE id = ?")->execute([$full_name, $password, $final_expiry, $id]);
                
                // Also update radcheck password
                $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = \'Cleartext-Password\'")->execute([$password, $u]);
                
                $pdo->commit();
                echo "<script>alert(\'User profile updated successfully!\'); window.location=\'users.php\';</script>";
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert(\'Error updating user.\'); window.location=\'users.php\';</script>";
                exit;
            }
        }
    }
';

if (strpos($c, '$action === \'edit_user\'') === false) {
    $c = str_replace('// RENEW USER', $editLogic . "\n    // RENEW USER", $c);
}

// 2. Make Username clickable in table
$oldBadge = '<td><span class="badge bg-primary fs-6"><?= htmlspecialchars($s[\'username\']) ?></span></td>
                        <td class="fw-bold"><?= htmlspecialchars($s[\'full_name\']) ?></td>';
$newBadge = '<td><a href="#" class="badge bg-primary fs-6 text-decoration-none edit-user-btn" data-id="<?= $s[\'id\'] ?>" data-username="<?= htmlspecialchars($s[\'username\']) ?>" data-fullname="<?= htmlspecialchars($s[\'full_name\']) ?>" data-password="<?= htmlspecialchars($s[\'password\']) ?>" data-expiry="<?= $s[\'expiry_date\'] ? date(\'Y-m-d\TH:i\', strtotime($s[\'expiry_date\'])) : \'\' ?>"><i class="fa-solid fa-pen me-1"></i> <?= htmlspecialchars($s[\'username\']) ?></a></td>
                        <td class="fw-bold"><?= htmlspecialchars($s[\'full_name\']) ?></td>';
if (strpos($c, 'edit-user-btn') === false) {
    $c = str_replace($oldBadge, $newBadge, $c);
}

// 3. Add Edit User Modal HTML
$editModal = '
<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-pen text-primary me-2"></i> Edit User Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="id" id="edit_user_id">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Username</label>
                <input type="text" id="edit_username" class="form-control bg-light" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Full Name</label>
                <input type="text" name="full_name" id="edit_fullname" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Password</label>
                <input type="text" name="password" id="edit_password" class="form-control font-monospace" required>
            </div>
            <?php if($can_custom_expiry): ?>
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Expiry Date</label>
                <input type="datetime-local" name="expiry_date" id="edit_expiry" class="form-control">
                <div class="form-text text-warning"><i class="fa-solid fa-circle-info"></i> Extending the date will automatically deduct balance based on the user\'s current package price.</div>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
';

if (strpos($c, 'id="editUserModal"') === false) {
    $c = str_replace('<!-- Add User Modal -->', $editModal . "\n<!-- Add User Modal -->", $c);
}

// 4. Add JS to trigger modal
$js = '
    // Edit User Modal triggers
    $("#usersTable tbody").on("click", ".edit-user-btn", function(e) {
        e.preventDefault();
        $("#edit_user_id").val($(this).data("id"));
        $("#edit_username").val($(this).data("username"));
        $("#edit_fullname").val($(this).data("fullname"));
        $("#edit_password").val($(this).data("password"));
        
        if ($("#edit_expiry").length) {
            $("#edit_expiry").val($(this).data("expiry"));
        }
        
        var modal = new bootstrap.Modal(document.getElementById("editUserModal"));
        modal.show();
    });
';

if (strpos($c, '.edit-user-btn') === false) {
    $c = str_replace('$(document).ready(function() {', "$(document).ready(function() {\n" . $js, $c);
    file_put_contents($f, $c);
    echo "Added Edit User modal with balance calculation.\n";
} else {
    echo "Already added.\n";
}
?>
