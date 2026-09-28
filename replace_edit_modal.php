<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/subscriber_view.php';
$content = file_get_contents($file);

// 1. Update the POST handler for 'edit_profile'
$post_pattern = '/elseif \(\$action === \'edit_profile\'\) \{.*?\n\s+exit;\n\s+\}/s';
$new_post = <<<'PHP'
        elseif ($action === 'edit_profile') {
            $full_name = trim($_POST['full_name']);
            $national_id = trim($_POST['national_id']);
            $new_username = trim($_POST['username']);
            $password = trim($_POST['password']);
            $service_type = $_POST['service_type'];
            $package_id = (int)$_POST['package_id'];
            $dealer_id = !empty($_POST['dealer_id']) ? (int)$_POST['dealer_id'] : null;
            $expiry = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            $mobile = trim($_POST['mobile']);
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            $subarea = trim($_POST['subarea']);
            $city = trim($_POST['city']);
            $gps_lat = trim($_POST['gps_lat']);
            $gps_lng = trim($_POST['gps_lng']);
            
            // Check if username is being changed and if it already exists
            if ($new_username !== $u) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE username = ?");
                $chk->execute([$new_username]);
                if ($chk->fetchColumn() > 0) {
                    echo "<script>alert('Error: Username already exists!'); window.location='subscriber_view.php?id=$id';</script>";
                    exit;
                }
            }

            $pdo->beginTransaction();
            
            // Update subscriber
            $pdo->prepare("UPDATE subscribers SET 
                full_name=?, national_id=?, username=?, password=?, service_type=?, 
                package_id=?, dealer_id=?, expiry_date=?, mobile=?, phone=?, email=?, 
                subarea=?, city=?, gps_lat=?, gps_lng=? WHERE id=?")
                ->execute([$full_name, $national_id, $new_username, $password, $service_type, 
                           $package_id, $dealer_id, $expiry, $mobile, $phone, $email, 
                           $subarea, $city, $gps_lat, $gps_lng, $id]);
            
            // Update RADIUS Cleartext-Password & Username
            if ($new_username !== $u) {
                // Changing username in radius is complex; typically we update the radcheck/radreply usernames
                $pdo->prepare("UPDATE radcheck SET username=? WHERE username=?")->execute([$new_username, $u]);
                $pdo->prepare("UPDATE radreply SET username=? WHERE username=?")->execute([$new_username, $u]);
                $pdo->prepare("UPDATE radusergroup SET username=? WHERE username=?")->execute([$new_username, $u]);
            }
            $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Cleartext-Password'")->execute([$password, $new_username]);
            
            // Update Package Group in FreeRADIUS
            $pkgStmt = $pdo->prepare("SELECT name FROM packages WHERE id = ?");
            $pkgStmt->execute([$package_id]);
            $pkg_name = $pkgStmt->fetchColumn();
            
            if ($pkg_name) {
                $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$new_username]);
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$new_username, $pkg_name]);
            }

            $pdo->commit();
            
            // If username changed, also kick the old user
            if ($new_username !== $u) {
                kick_user_mikrotik($pdo, $client_id, $u);
            }
            
            echo "<script>alert('Profile updated successfully!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
PHP;
$content = preg_replace($post_pattern, $new_post, $content);

// 2. Replace the HTML Modal
$html_pattern = '/<!-- Edit Profile Modal -->.*?<!-- Add Note Modal -->/s';
$new_html = <<<'HTML'
<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content light-modal">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-user-pen text-primary"></i> Edit Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="edit_profile">
        <div class="modal-body p-4">
            <div class="add-user-flat-form">
                <!-- Account Info -->
                <div class="mb-4">
                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-user"></i> Account Information</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">National ID (CNIC)</label>
                            <input type="text" name="national_id" class="form-control" value="<?= htmlspecialchars($user['national_id']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="password" id="editPassword" class="form-control" value="<?= htmlspecialchars($user['password']) ?>" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('editPassword').value = Math.random().toString(36).slice(-8);"><i class="fa-solid fa-shuffle"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Service Info -->
                <div class="mb-4">
                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-wifi"></i> Service & Package</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Service Type</label>
                            <select name="service_type" class="form-select">
                                <option value="pppoe" <?= $user['service_type']=='pppoe'?'selected':'' ?>>PPPoE</option>
                                <option value="hotspot" <?= $user['service_type']=='hotspot'?'selected':'' ?>>Hotspot</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Select Package <span class="text-danger">*</span></label>
                            <select name="package_id" class="form-select" required>
                                <option value="">Choose...</option>
                                <?php 
                                $pkgs = $pdo->query("SELECT * FROM packages WHERE client_id=$client_id")->fetchAll();
                                foreach($pkgs as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= $p['id']==$user['package_id']?'selected':'' ?>><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['rate_limit']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Assign Dealer (Optional)</label>
                            <select name="dealer_id" class="form-select">
                                <option value="">None</option>
                                <?php 
                                $dlrs = $pdo->query("SELECT * FROM dealers WHERE client_id=$client_id")->fetchAll();
                                foreach($dlrs as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $d['id']==$user['dealer_id']?'selected':'' ?>><?= htmlspecialchars($d['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Custom Expiry</label>
                            <input type="datetime-local" name="expiry_date" class="form-control" value="<?= $user['expiry_date'] ? date('Y-m-d\TH:i', strtotime($user['expiry_date'])) : '' ?>">
                        </div>
                    </div>
                </div>

                <!-- Contact Info -->
                <div class="mb-4">
                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-address-book"></i> Contact & Location</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Mobile</label>
                            <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($user['mobile']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Area / City</label>
                            <input type="text" name="subarea" class="form-control" value="<?= htmlspecialchars($user['subarea']) ?>">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="col-form-label">Street Address</label>
                            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($user['address']) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Note Modal -->
HTML;
$content = preg_replace($html_pattern, $new_html, $content);

file_put_contents($file, $content);
echo "subscriber_view.php edit profile modal fully replaced.\n";
?>
