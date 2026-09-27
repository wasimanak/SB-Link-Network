<?php
require_once 'header.php';

$client_id = $_SESSION['operator_id'] ?? 0;
$dealer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$dealer_id) {
    echo "<script>window.location.href='dealers.php';</script>";
    exit;
}

// Handle Set New Package
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_package') {
    $pkg_id = (int)$_POST['package_id'];
    $price = (float)$_POST['dealer_price'];
    $profit = (float)$_POST['dealer_profit'];
    
    try {
        $stmt = $pdo->prepare("INSERT INTO dealer_packages (dealer_id, package_id, dealer_price, dealer_profit) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE dealer_price = ?, dealer_profit = ?");
        $stmt->execute([$dealer_id, $pkg_id, $price, $profit, $price, $profit]);
        echo "<script>alert('Package assigned to dealer successfully!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
    } catch(PDOException $e) {
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}

// Handle Permissions Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_permissions') {
    $perm_create = isset($_POST['perm_create_user']) ? 1 : 0;
    $perm_delete = isset($_POST['perm_delete_user']) ? 1 : 0;
    $perm_custom = isset($_POST['perm_custom_expiry']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare("UPDATE dealers SET perm_create_user = ?, perm_delete_user = ?, perm_custom_expiry = ? WHERE id = ?");
        $stmt->execute([$perm_create, $perm_delete, $perm_custom, $dealer_id]);
        echo "<script>alert('Permissions updated successfully!'); window.location.href='dealer_view.php?id=$dealer_id';</script>";
    } catch(PDOException $e) {
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}

$stmt = $pdo->prepare("
    SELECT d.*, 
    (SELECT COUNT(*) FROM subscribers s WHERE s.dealer_id = d.id) as user_count 
    FROM dealers d 
    WHERE d.id = ? AND d.client_id = ?
");
$stmt->execute([$dealer_id, $client_id]);
$dealer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dealer) {
    echo "<div class='alert alert-danger m-4'>Dealer not found.</div>";
    require_once 'footer.php';
    exit;
}

// Fetch all operator packages
$pkgStmt = $pdo->prepare("SELECT id, name FROM packages WHERE client_id = ? OR client_id = 0 ORDER BY name ASC");
$pkgStmt->execute([$client_id]);
$operator_packages = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
/* Dealer Profile Styles */
body { background-color: #f1f5f9; }

.card-sidebar {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.profile-header {
    display: flex;
    align-items: center;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 15px;
    margin-bottom: 15px;
}

.sidebar-avatar {
    width: 65px; height: 65px;
    background: #2563eb;
    border-radius: 12px;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px;
    margin-right: 15px;
    flex-shrink: 0;
}
.sidebar-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 12px; }

.profile-name { font-weight: 700; color: #1e293b; font-size: 1.1rem; margin: 0 0 2px 0; }
.profile-role { color: #64748b; font-size: 0.8rem; margin: 0 0 5px 0; }
.status-badge {
    background-color: #f1f5f9;
    color: #0f172a;
    font-weight: 700;
    font-size: 0.7rem;
    padding: 3px 12px;
    border-radius: 20px;
    display: inline-block;
}

.info-list { list-style: none; padding: 0; margin: 0; }
.info-list li {
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
    color: #64748b;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
}
.info-list li:last-child { border-bottom: none; }
.info-list li i { width: 25px; color: #94a3b8; text-align: center; margin-right: 10px; font-size: 1rem; }

.action-btns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: 20px;
}
.action-btns .btn {
    font-size: 0.75rem;
    font-weight: 600;
    text-align: left;
    padding: 8px 10px;
    border-radius: 6px;
    display: flex;
    align-items: center;
}
.action-btns .btn i { width: 20px; text-align: center; margin-right: 5px; }

.btn-outline-custom { border: 1px solid #e2e8f0; color: #475569; background: #fff; }
.btn-outline-custom:hover { background: #f8fafc; color: #0f172a; }
.btn-dark-custom { background: #0f172a; color: #fff; border: 1px solid #0f172a; }
.btn-dark-custom:hover { background: #1e293b; color: #fff; }
.btn-delete { grid-column: span 2; } /* Make delete button span full width if needed, or keep it half */

.stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 18px 20px;
    display: flex;
    align-items: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    height: 100%;
}
.stat-icon {
    width: 48px; height: 48px;
    border-radius: 10px;
    background: #f0fdf4; /* Light green tint */
    color: #10b981;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.35rem;
    margin-right: 15px;
    flex-shrink: 0;
}
.stat-icon.dealer-icon { background: #eff6ff; color: #3b82f6; }
.stat-icon.balance-icon { background: #fffbeb; color: #f59e0b; }
.stat-title { font-size: 0.75rem; color: #94a3b8; font-weight: 600; margin-bottom: 2px; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin: 0; }

.accordion-custom { margin-top: 20px; }
.accordion-custom .card {
    border: none;
    border-radius: 8px;
    margin-bottom: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.accordion-custom .card-header {
    background: #fff;
    border-radius: 8px !important;
    border: none;
    padding: 12px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
}
.accordion-custom .card-header h6 { margin: 0; font-weight: 700; color: #1e293b; font-size: 0.95rem; display: flex; align-items: center; }
.accordion-custom .card-header h6 i { color: #3b82f6; width: 25px; font-size: 1.1rem; }

.btn-warning-custom { background: #f59e0b; color: #fff; border: none; font-weight: 700; font-size: 0.75rem; border-radius: 20px; padding: 6px 14px; box-shadow: 0 2px 4px rgba(245, 158, 11, 0.2); }
.btn-warning-custom:hover { background: #d97706; color: #fff; }

.header-controls { display: flex; align-items: center; gap: 10px; }
.chevron-icon { color: #cbd5e1; font-size: 0.8rem; }
</style>

<div class="row g-4 mt-2">
    <!-- Left Sidebar -->
    <div class="col-lg-4 col-xl-3">
        <div class="card-sidebar">
            <div class="profile-header">
                <div class="sidebar-avatar">
                    <?php if($dealer['photo']): ?>
                        <img src="<?= htmlspecialchars($dealer['photo']) ?>" alt="Avatar">
                    <?php else: ?>
                        <i class="fa-solid fa-user"></i>
                    <?php endif; ?>
                </div>
                <div>
                    <h5 class="profile-name"><?= htmlspecialchars($dealer['full_name']) ?></h5>
                    <p class="profile-role"><?= htmlspecialchars($dealer['username']) ?> - Dealer</p>
                    <span class="status-badge"><?= ucfirst($dealer['status'] ?? 'Active') ?></span>
                </div>
            </div>

            <ul class="info-list">
                <li><i class="fa-solid fa-building"></i> <?= htmlspecialchars($dealer['franchise'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-user-tie"></i> <?= htmlspecialchars($dealer['admin_name']) ?></li>
                <li><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($dealer['national_id'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($dealer['phone'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($dealer['email'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-map-location-dot"></i> <?= htmlspecialchars($dealer['address'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($dealer['city'] ?: ($dealer['area'] ?: 'N/A')) ?></li>
                <li><i class="fa-solid fa-calendar-days"></i> <?= $dealer['created_at'] ? date('Y-m-d H:i:s', strtotime($dealer['created_at'])) : 'N/A' ?></li>
            </ul>

            <div class="action-btns">
                <button class="btn btn-dark-custom"><i class="fa-solid fa-pen-to-square"></i> Edit Profile</button>
                <button class="btn btn-outline-custom"><i class="fa-regular fa-image"></i> Change Photo</button>
                <button class="btn btn-outline-custom"><i class="fa-solid fa-file-lines"></i> Add Note</button>
                <button class="btn btn-outline-custom"><i class="fa-brands fa-paypal"></i> Payment</button>
                <button class="btn btn-outline-custom"><i class="fa-solid fa-lock"></i> Change Password</button>
                <button class="btn btn-outline-custom" data-bs-toggle="modal" data-bs-target="#setNewPackageModal"><i class="fa-solid fa-plus-square"></i> Set New Package</button>
                <button class="btn btn-outline-custom"><i class="fa-solid fa-file-arrow-up"></i> Add Document</button>
                <button class="btn btn-outline-custom" data-bs-toggle="modal" data-bs-target="#settingsModal"><i class="fa-solid fa-gear"></i> Settings</button>
                <button class="btn btn-outline-custom"><i class="fa-solid fa-ban"></i> Delete Profile</button>
            </div>
        </div>
    </div>

    <!-- Right Content Area -->
    <div class="col-lg-8 col-xl-9">
        
        <!-- Stat Cards -->
        <div class="row g-3 mb-2">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div class="stat-title">Total Users</div>
                        <div class="stat-value"><?= number_format($dealer['user_count'], 2) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon dealer-icon"><i class="fa-solid fa-user-group"></i></div>
                    <div>
                        <div class="stat-title">Dealer Package</div>
                        <div class="stat-value">0.00</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-icon balance-icon"><i class="fa-regular fa-credit-card"></i></div>
                    <div>
                        <div class="stat-title">Current Balance</div>
                        <div class="stat-value"><?= number_format($dealer['balance'], 2) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accordions / Tabs -->
        <div class="accordion-custom">
            
            <!-- Packages -->
            <div class="card overflow-hidden">
                <div class="card-header border-bottom-0 collapsed" data-bs-toggle="collapse" data-bs-target="#collapsePackages" style="background-color: #1e293b;">
                    <h6 style="color: #fff;"><i class="fa-solid fa-box-open me-2" style="color: #fff;"></i> Packages</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-down text-white chevron-icon"></i>
                        <button class="btn btn-warning-custom" onclick="event.stopPropagation();" data-bs-toggle="modal" data-bs-target="#setNewPackageModal"><i class="fa-solid fa-plus me-1"></i> Set New Package</button>
                        <button class="btn btn-warning-custom" onclick="event.stopPropagation();"><i class="fa-solid fa-gear me-1"></i> Settings</button>
                    </div>
                </div>
                <div id="collapsePackages" class="collapse">
                    <div class="card-body p-4 bg-white border-top">
                        <table class="table table-hover table-borderless w-100" id="dealerPackagesTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="fw-bold text-secondary ps-3">Package</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($operator_packages as $p): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td class="py-3 ps-3">
                                        <i class="fa-solid fa-circle-plus text-success me-2" style="cursor:pointer; font-size: 1.1rem; vertical-align: middle;" title="Assign to Dealer"></i> 
                                        <span class="text-secondary fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($p['name']) ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Ledger -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="fa-solid fa-chart-simple"></i> Ledger</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-right chevron-icon"></i>
                    </div>
                </div>
            </div>

            <!-- All Users -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="fa-solid fa-users"></i> All Users</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-right chevron-icon"></i>
                    </div>
                </div>
            </div>

            <!-- Documents -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="fa-solid fa-file-lines"></i> Documents</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-right chevron-icon"></i>
                        <button class="btn btn-warning-custom"><i class="fa-solid fa-file-arrow-up me-1"></i> Add Document</button>
                    </div>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="fa-solid fa-chart-line"></i> Activity Log</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-right chevron-icon"></i>
                    </div>
                </div>
            </div>

        </div>

    </div>
    </div>
</div>

<!-- Set New Package Modal -->
<div class="modal fade" id="setNewPackageModal" tabindex="-1">
  <div class="modal-dialog modal-lg" style="max-width: 600px;">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-white border-bottom">
        <h5 class="modal-title fw-bold" style="color: #64748b; font-size: 1.15rem;">Set New Package</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="form-horizontal">
        <input type="hidden" name="action" value="set_package">
        <div class="modal-body bg-white px-4 py-4">
            
            <div class="alert mb-4 text-center rounded" style="background-color: #4fa8e0; color: #fff; border: none; font-size: 0.85rem; font-weight: 600;">
                <i class="fa-solid fa-circle-info me-1"></i> You Can Set Custom Price, Higher Than Package Price. Extra Price<br>Will Be Calculated As Franchise Profit.
            </div>

            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="mb-0 fw-bold" style="color: #475569; font-size: 0.9rem;">Dealer Package <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <select name="package_id" class="form-select" required>
                        <option value="">Select Package</option>
                        <?php foreach($operator_packages as $op): ?>
                            <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="mb-0 fw-bold" style="color: #475569; font-size: 0.9rem;">Dealer Price <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="number" step="0.01" name="dealer_price" class="form-control" placeholder="Enter Package Price" required>
                </div>
            </div>
            
            <div class="row mb-3 align-items-center">
                <div class="col-sm-4 text-end">
                    <label class="mb-0 fw-bold" style="color: #475569; font-size: 0.9rem;">Dealer Profit <span class="text-danger">*</span></label>
                </div>
                <div class="col-sm-8">
                    <input type="number" step="0.01" name="dealer_profit" class="form-control" placeholder="Enter Package Profit" required>
                </div>
            </div>

        </div>
        <div class="modal-footer bg-white border-top">
          <button type="button" class="btn bg-white border text-dark px-4 fw-bold" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn text-white fw-bold px-4" style="background-color: #1e293b;">Submit</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1">
  <div class="modal-dialog" style="max-width: 500px;">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-white border-bottom">
        <h5 class="modal-title fw-bold" style="color: #64748b; font-size: 1.15rem;"><i class="fa-solid fa-gear me-2"></i> Dealer Permissions</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="update_permissions">
        <div class="modal-body bg-white px-4 py-4">
            
            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                <div>
                    <div class="fw-bold text-dark">Create User <span class="text-danger">*</span></div>
                    <div class="small text-muted">Allow dealer to add new subscribers.</div>
                </div>
                <div class="form-check form-switch fs-4 mb-0">
                    <input class="form-check-input" type="checkbox" name="perm_create_user" value="1" <?= $dealer['perm_create_user'] ? 'checked' : '' ?>>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                <div>
                    <div class="fw-bold text-dark">Delete User <span class="text-danger">*</span></div>
                    <div class="small text-muted">Allow dealer to delete existing subscribers.</div>
                </div>
                <div class="form-check form-switch fs-4 mb-0">
                    <input class="form-check-input" type="checkbox" name="perm_delete_user" value="1" <?= $dealer['perm_delete_user'] ? 'checked' : '' ?>>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-bold text-dark">Allow Custom Expiry Time <span class="text-danger">*</span></div>
                    <div class="small text-muted">Allow dealer to set custom expiration dates.</div>
                </div>
                <div class="form-check form-switch fs-4 mb-0">
                    <input class="form-check-input" type="checkbox" name="perm_custom_expiry" value="1" <?= $dealer['perm_custom_expiry'] ? 'checked' : '' ?>>
                </div>
            </div>

        </div>
        <div class="modal-footer bg-white border-top">
          <button type="button" class="btn bg-white border text-dark px-4 fw-bold" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn text-white fw-bold px-4" style="background-color: #1e293b;">Save Settings</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script>
$(document).ready(function() {
    $('#dealerPackagesTable').DataTable({
        dom: '<"row align-items-center mb-3"<"col-sm-12 col-md-3"l><"col-sm-12 col-md-6 text-center"B><"col-sm-12 col-md-3"f>>rt<"row align-items-center mt-3"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            { extend: 'print', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'pdf', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'excel', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-excel"></i> Excle' },
            { extend: 'csv', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-csv"></i> CSV' },
            { text: '<i class="fa-solid fa-eye"></i> View', className: 'btn btn-secondary btn-sm' }
        ],
        lengthMenu: [[25, 50, 100, -1], [25, 50, 100, "All"]],
        language: {
            lengthMenu: "Show _MENU_ entries"
        }
    });
});
</script>

<?php require_once 'footer.php'; ?>
