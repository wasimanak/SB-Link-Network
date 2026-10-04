<?php
$f = 'dealer/users.php';
$c = file_get_contents($f);

// 1. Rewrite the edit_user backend logic
$editLogicOld = '// EDIT USER (Update profile & optionally extend expiry with balance deduction)
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
    }';

$editLogicNew = '// EDIT USER (Update profile, package, and expiry with unified balance deduction)
    if ($action === \'edit_user\') {
        $id = (int)$_POST[\'id\'];
        $full_name = trim($_POST[\'full_name\']);
        $password = trim($_POST[\'password\']);
        $new_package_id = (int)($_POST[\'package_id\'] ?? 0);
        $new_expiry = $_POST[\'expiry_date\'] ?? null;

        $uStmt = $pdo->prepare("SELECT username, package_id, expiry_date as old_expiry FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$id, $dealer_id]);
        $subData = $uStmt->fetch(PDO::FETCH_ASSOC);

        if ($subData) {
            $u = $subData[\'username\'];
            $old_package_id = $subData[\'package_id\'];
            
            if ($new_package_id === 0) {
                $new_package_id = $old_package_id;
            }

            try {
                $pdo->beginTransaction();
                
                $net_deduction = 0;
                $final_expiry = $subData[\'old_expiry\']; // default to unchanged
                
                $package_changed = ($new_package_id != $old_package_id);
                $expiry_changed = (!empty($new_expiry) && $new_expiry !== $subData[\'old_expiry\']);
                
                if ($package_changed || $expiry_changed) {
                    if ($expiry_changed) {
                        $final_expiry = date(\'Y-m-d H:i:s\', strtotime($new_expiry));
                    }
                    
                    // Fetch Old Package Dealer Price
                    $old_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                    $old_dpStmt->execute([$dealer_id, $old_package_id]);
                    $old_dealerPrice = $old_dpStmt->fetchColumn();
                    if ($old_dealerPrice === false) {
                        $p = $pdo->prepare("SELECT price FROM packages WHERE id = ?"); $p->execute([$old_package_id]);
                        $old_dealerPrice = $p->fetchColumn() ?: 0;
                    }

                    // Fetch New Package Dealer Price
                    $new_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                    $new_dpStmt->execute([$dealer_id, $new_package_id]);
                    $new_dealerPrice = $new_dpStmt->fetchColumn();
                    if ($new_dealerPrice === false) {
                        $p = $pdo->prepare("SELECT price FROM packages WHERE id = ?"); $p->execute([$new_package_id]);
                        $new_dealerPrice = $p->fetchColumn() ?: 0;
                    }

                    // Calculate old value
                    $old_remaining_value = 0;
                    if (!empty($subData[\'old_expiry\']) && strtotime($subData[\'old_expiry\']) > time()) {
                        $old_seconds = strtotime($subData[\'old_expiry\']) - time();
                        $old_days = $old_seconds / 86400;
                        $old_remaining_value = $old_days * ($old_dealerPrice / 30);
                    }

                    // Calculate new value
                    $new_total_value = 0;
                    $new_seconds = strtotime($final_expiry) - time();
                    if ($new_seconds > 0) {
                        $new_days = $new_seconds / 86400;
                        $new_total_value = $new_days * ($new_dealerPrice / 30);
                    }

                    $net_deduction = round($new_total_value - $old_remaining_value, 2);

                    if ($net_deduction > $current_dealer[\'balance\']) {
                        $pdo->rollBack();
                        echo "<script>alert(\'Insufficient balance! Need Rs. $net_deduction\'); window.location=\'users.php\';</script>";
                        exit;
                    }

                    if ($net_deduction > 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $dealer_id]);
                        $note = "Package/Expiry updated for $u. Deducted: Rs. $net_deduction";
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                    }
                }

                $pdo->prepare("UPDATE subscribers SET full_name = ?, password = ?, package_id = ?, expiry_date = ? WHERE id = ?")->execute([$full_name, $password, $new_package_id, $final_expiry, $id]);
                
                // Update Mikrotik-Rate-Limit if package changed
                if ($package_changed) {
                    $pStmt = $pdo->prepare("SELECT rate_limit FROM packages WHERE id = ?");
                    $pStmt->execute([$new_package_id]);
                    $rate_limit = $pStmt->fetchColumn();
                    
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = \'Mikrotik-Rate-Limit\'")->execute([$u]);
                    if (!empty($rate_limit) && $rate_limit !== \'No Limit\') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, \'Mikrotik-Rate-Limit\', \'=\', ?)")->execute([$u, $rate_limit]);
                    }
                }

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
    }';

if (strpos($c, 'EDIT USER (Update profile, package, and expiry with unified balance deduction)') === false) {
    $c = str_replace($editLogicOld, $editLogicNew, $c);
}


// 2. Update the Edit button data attributes in the table
$oldBadge = 'data-expiry="<?= $s[\'expiry_date\'] ? date(\'Y-m-d\TH:i\', strtotime($s[\'expiry_date\'])) : \'\' ?>"><i class="fa-solid fa-pen me-1"></i>';
$newBadge = 'data-expiry="<?= $s[\'expiry_date\'] ? date(\'Y-m-d\TH:i\', strtotime($s[\'expiry_date\'])) : \'\' ?>" data-old-ts="<?= $s[\'expiry_date\'] ? strtotime($s[\'expiry_date\']) : 0 ?>" data-pkg="<?= $s[\'package_id\'] ?>"><i class="fa-solid fa-pen me-1"></i>';
if (strpos($c, 'data-old-ts') === false) {
    $c = str_replace($oldBadge, $newBadge, $c);
}


// 3. Rewrite the Edit User Modal HTML
$oldModalHtml = '<!-- Edit User Modal -->
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
</div>';

$newModalHtml = '<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-pen text-primary me-2"></i> Edit User Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="id" id="edit_user_id">
        <div class="modal-body p-4 bg-light">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Username</label>
                    <input type="text" id="edit_username" class="form-control bg-white" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Full Name</label>
                    <input type="text" name="full_name" id="edit_fullname" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Password</label>
                    <input type="text" name="password" id="edit_password" class="form-control font-monospace" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Package</label>
                    <select name="package_id" id="edit_package_id" class="form-select">
                        <?php foreach($dealer_packages as $p): ?>
                            <option value="<?= $p[\'id\'] ?>"><?= htmlspecialchars($p[\'name\']) ?> (Rs.<?= $p[\'dealer_price\'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <?php if($can_custom_expiry): ?>
            <div class="mt-4 pt-3 border-top">
                <label class="form-label text-muted small fw-bold">Expiry Date</label>
                <input type="datetime-local" name="expiry_date" id="edit_expiry" class="form-control">
            </div>
            <?php else: ?>
                <!-- If dealer cannot set custom expiry, we use a hidden input for JS to know the value -->
                <input type="hidden" id="edit_expiry">
            <?php endif; ?>
            
            <div class="mt-4 p-3 bg-white border rounded shadow-sm d-flex justify-content-between align-items-center">
                <div class="text-secondary fw-bold small text-uppercase">Est. Balance Deduction</div>
                <h4 class="mb-0 fw-bold text-danger" id="live_deduction_amount">Rs. 0.00</h4>
            </div>
        </div>
        <div class="modal-footer bg-white border-top">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>';

if (strpos($c, 'live_deduction_amount') === false) {
    $c = str_replace($oldModalHtml, $newModalHtml, $c);
}


// 4. Update JS logic for the Modal
$oldJs = '// Edit User Modal triggers
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
    });';

$newJs = '// Package prices dictionary for live calculation
    const pkgPrices = {
        <?php foreach($dealer_packages as $p) echo $p[\'id\'] . \': \' . $p[\'dealer_price\'] . \',\'; ?>
    };
    
    let current_old_ts = 0;
    let current_old_pkg = 0;

    function calculateLiveDeduction() {
        let new_pkg = document.getElementById("edit_package_id").value;
        let new_expiry_val = document.getElementById("edit_expiry").value;
        
        if (!new_pkg || !new_expiry_val) {
            document.getElementById("live_deduction_amount").innerText = "Rs. 0.00";
            return;
        }

        let old_price = parseFloat(pkgPrices[current_old_pkg] || 0);
        let new_price = parseFloat(pkgPrices[new_pkg] || 0);
        
        let now_sec = Math.floor(Date.now() / 1000);
        let new_ts = Math.floor(new Date(new_expiry_val).getTime() / 1000);
        
        let old_remaining_value = 0;
        if (current_old_ts > now_sec) {
            let old_days = (current_old_ts - now_sec) / 86400;
            old_remaining_value = old_days * (old_price / 30);
        }
        
        let new_total_value = 0;
        if (new_ts > now_sec) {
            let new_days = (new_ts - now_sec) / 86400;
            new_total_value = new_days * (new_price / 30);
        }
        
        let deduction = new_total_value - old_remaining_value;
        if (deduction < 0) deduction = 0;
        
        document.getElementById("live_deduction_amount").innerText = "Rs. " + deduction.toFixed(2);
    }

    // Bind events
    $("#edit_package_id, #edit_expiry").on("change input", calculateLiveDeduction);

    // Edit User Modal triggers
    $("#usersTable tbody").on("click", ".edit-user-btn", function(e) {
        e.preventDefault();
        $("#edit_user_id").val($(this).data("id"));
        $("#edit_username").val($(this).data("username"));
        $("#edit_fullname").val($(this).data("fullname"));
        $("#edit_password").val($(this).data("password"));
        
        current_old_pkg = $(this).data("pkg");
        current_old_ts = $(this).data("old-ts");
        
        $("#edit_package_id").val(current_old_pkg);
        $("#edit_expiry").val($(this).data("expiry"));
        
        calculateLiveDeduction();
        
        var modal = new bootstrap.Modal(document.getElementById("editUserModal"));
        modal.show();
    });';

if (strpos($c, 'const pkgPrices =') === false) {
    $c = str_replace($oldJs, $newJs, $c);
    file_put_contents($f, $c);
    echo "Live cost deduction tracking and Package Change features added.\n";
} else {
    echo "Already added.\n";
}
?>
