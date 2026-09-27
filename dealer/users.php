<?php
require_once 'header.php';

// Check Permissions
$can_create = (bool)$current_dealer['perm_create_user'];
$can_delete = (bool)$current_dealer['perm_delete_user'];
$can_custom_expiry = (bool)$current_dealer['perm_custom_expiry'];

// Handle Actions (Add, Renew, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // DELETE
    if ($action === 'delete_id' && isset($_POST['id']) && $can_delete) {
        $del = (int)$_POST['id'];
        $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$del, $dealer_id]);
        $u = $uStmt->fetchColumn();
        if ($u) {
            $pdo->prepare("DELETE FROM subscribers WHERE id = ?")->execute([$del]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
            $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Deleted User')")->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);
            echo "<script>alert('User deleted successfully!'); window.location='users.php';</script>";
            exit;
        }
    }

    // RENEW USER
    if ($action === 'renew_user') {
        $id = (int)$_POST['id'];
        $package_id = (int)$_POST['package_id'];
        $expiry_date = $_POST['expiry_date'] ?? null;
        
        if (!$can_custom_expiry || empty($expiry_date)) {
            // Default 30 days if not allowed to set custom, or if left blank
            $expiry_date = date('Y-m-d\TH:i', strtotime('+30 days'));
        }

        $uStmt = $pdo->prepare("SELECT username, package_id as old_package_id, expiry_date as old_expiry FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$id, $dealer_id]);
        $subData = $uStmt->fetch(PDO::FETCH_ASSOC);

        if ($subData) {
            $u = $subData['username'];
            $p = $pdo->prepare("SELECT rate_limit, price FROM packages WHERE id = ?");
            $p->execute([$package_id]);
            $pkg = $p->fetch();

            if ($pkg) {
                try {
                    $pdo->beginTransaction();
                    
                    // Billing Logic
                    $old_remaining_value = 0;
                    if (!empty($subData['old_expiry']) && strtotime($subData['old_expiry']) > time()) {
                        $old_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                        $old_dpStmt->execute([$dealer_id, $subData['old_package_id']]);
                        $old_dp_price = $old_dpStmt->fetchColumn() ?: ($pkg['price'] ?? 0);
                        
                        $old_seconds = strtotime($subData['old_expiry']) - time();
                        $old_days = $old_seconds / 86400;
                        $old_remaining_value = $old_days * ($old_dp_price / 30);
                    }

                    $new_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                    $new_dpStmt->execute([$dealer_id, $package_id]);
                    $new_dp_price = $new_dpStmt->fetchColumn() ?: ($pkg['price'] ?? 0);
                    
                    $new_total_value = 0;
                    $new_seconds = strtotime($expiry_date) - time();
                    if ($new_seconds > 0) {
                        $new_days = $new_seconds / 86400;
                        $new_total_value = $new_days * ($new_dp_price / 30);
                    }

                    $net_deduction = round($new_total_value - $old_remaining_value, 2);

                    // Check Balance First
                    if ($net_deduction > $current_dealer['balance']) {
                        $pdo->rollBack();
                        echo "<script>alert('Insufficient balance to perform this upgrade/renewal!'); window.location='users.php';</script>";
                        exit;
                    }

                    if ($net_deduction > 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $dealer_id]);
                        $note = "Package Renew/Upgrade for $u. Deducted: Rs. $net_deduction";
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                    }

                    $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active' WHERE id = ?")->execute([$package_id, $expiry_date, $id]);
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
                    }
                    $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
                    
                    $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Package Renewed')")->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);
                    $pdo->commit();
                    echo "<script>alert('User renewed successfully!'); window.location='users.php';</script>";
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo "<script>alert('Error renewing user.');</script>";
                }
            }
        }
    }

    // ADD USER
    if ($action === 'add_user' && $can_create) {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $package_id = (int)$_POST['package_id'];
        $full_name = trim($_POST['full_name']);
        $expiry_date = $_POST['expiry_date'] ?? null;
        
        if (!$can_custom_expiry || empty($expiry_date)) {
            $expiry_date = date('Y-m-d\TH:i', strtotime('+30 days'));
        }

        $p = $pdo->prepare("SELECT rate_limit, price FROM packages WHERE id = ?");
        $p->execute([$package_id]);
        $pkg = $p->fetch();

        if ($pkg) {
            try {
                $pdo->beginTransaction();
                
                // Deduct Balance
                $dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                $dpStmt->execute([$dealer_id, $package_id]);
                $dp_price = $dpStmt->fetchColumn() ?: ($pkg['price'] ?? 0);
                
                $deduction = 0;
                $seconds = strtotime($expiry_date) - time();
                if ($seconds > 0) {
                    $days = ceil($seconds / 86400);
                    $deduction = round($days * ($dp_price / 30), 2);
                }
                
                if ($deduction > $current_dealer['balance']) {
                    $pdo->rollBack();
                    echo "<script>alert('Insufficient balance to create this user!'); window.location='users.php';</script>";
                    exit;
                }
                
                if ($deduction > 0) {
                    $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$deduction, $dealer_id]);
                    $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, "Created user $username. Deducted: Rs. $deduction"]);
                }

                $pdo->prepare("INSERT INTO subscribers (client_id, dealer_id, package_id, username, password, service_type, full_name, expiry_date) VALUES (?, ?, ?, ?, ?, 'pppoe', ?, ?)")
                    ->execute([$client_id, $dealer_id, $package_id, $username, $password, $full_name, $expiry_date]);
                
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$username, $password]);
                
                if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$username, $pkg['rate_limit']]);
                }
                
                $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_expiry]);
                
                $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Created New User')")->execute([$client_id, $dealer_id, $current_dealer['username'], $username]);
                
                $pdo->commit();
                echo "<script>alert('User created successfully!'); window.location='users.php';</script>";
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo "<script>alert('Error: Username might already exist.');</script>";
            }
        }
    }
}

// Fetch Dealer Packages for Modals
$dpStmt = $pdo->prepare("SELECT p.id, p.name, p.rate_limit, dp.dealer_price FROM dealer_packages dp JOIN packages p ON dp.package_id = p.id WHERE dp.dealer_id = ? ORDER BY p.name ASC");
$dpStmt->execute([$dealer_id]);
$dealer_packages = $dpStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dealer's Users
$sql = "SELECT s.*, p.name as package_name, 
        (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online
        FROM subscribers s 
        LEFT JOIN packages p ON s.package_id = p.id 
        WHERE s.dealer_id = ? 
        ORDER BY s.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dealer_id]);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold"><i class="fa-solid fa-users text-primary me-2"></i> My Users</h4>
    <?php if($can_create): ?>
    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa-solid fa-user-plus me-1"></i> Add New User</button>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <div class="table-responsive">
            <table id="usersTable" class="table table-hover table-borderless align-middle w-100">
                <thead class="table-light">
                    <tr>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Package</th>
                        <th>Status</th>
                        <th>Expiry</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($subs as $s): ?>
                    <tr class="border-bottom">
                        <td><span class="badge bg-primary fs-6"><?= htmlspecialchars($s['username']) ?></span></td>
                        <td class="fw-bold"><?= htmlspecialchars($s['full_name']) ?></td>
                        <td><?= htmlspecialchars($s['package_name']) ?></td>
                        <td>
                            <?php if($s['is_online'] > 0): ?>
                                <span class="badge bg-success">Online</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Offline</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($s['expiry_date']): ?>
                                <?php if(strtotime($s['expiry_date']) < time()): ?>
                                    <span class="badge bg-danger">Expired<br><small><?= date('d M Y', strtotime($s['expiry_date'])) ?></small></span>
                                <?php else: ?>
                                    <span class="badge bg-success"><?= date('d M Y H:i', strtotime($s['expiry_date'])) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-secondary">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-success" onclick="openRenewModal(<?= $s['id'] ?>, '<?= addslashes($s['username']) ?>')"><i class="fa-solid fa-rotate"></i> Renew</button>
                                <?php if($can_delete): ?>
                                <form method="POST" onsubmit="return confirm('Delete this user?');" class="m-0">
                                    <input type="hidden" name="action" value="delete_id">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if($can_create): ?>
<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Create User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Username <span class="text-danger">*</span></label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <input type="text" name="password" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Package <span class="text-danger">*</span></label>
                <select name="package_id" class="form-select" required>
                    <option value="">Select Package</option>
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (Rs.<?= $p['dealer_price'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if($can_custom_expiry): ?>
            <div class="mb-3">
                <label class="form-label">Custom Expiry (Optional)</label>
                <input type="datetime-local" name="expiry_date" class="form-control">
                <small class="text-muted">Leave blank for standard 30 days.</small>
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Renew Modal -->
<div class="modal fade" id="renewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Renew / Change Package</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="renew_user">
        <input type="hidden" name="id" id="renew_user_id">
        <div class="modal-body p-4">
            <p><strong>Username:</strong> <span id="renew_username_display" class="badge bg-primary"></span></p>
            <div class="mb-3">
                <label class="form-label">New Package <span class="text-danger">*</span></label>
                <select name="package_id" class="form-select" required>
                    <option value="">Select Package</option>
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (Rs.<?= $p['dealer_price'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if($can_custom_expiry): ?>
            <div class="mb-3">
                <label class="form-label">Custom Expiry (Optional)</label>
                <input type="datetime-local" name="expiry_date" class="form-control">
            </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Renew User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#usersTable').DataTable({
        language: { lengthMenu: "Show _MENU_ entries" }
    });
});
function openRenewModal(id, username) {
    document.getElementById('renew_user_id').value = id;
    document.getElementById('renew_username_display').innerText = username;
    var modal = new bootstrap.Modal(document.getElementById('renewModal'));
    modal.show();
}
// Form Validation
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms).forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
    })
})()
</script>

<?php require_once 'footer.php'; ?>