<?php
require_once 'header.php';
require_once __DIR__ . '/staff_salary_helper.php';
init_staff_salary_schema($pdo);

$client_id = (int)$_SESSION['operator_id'];
$lineman_id = (int)($_GET['id'] ?? 0);

if ($lineman_id <= 0) {
    echo "<div class='container py-5'><div class='alert alert-warning'>Invalid Line Man ID specified.</div><a href='line_man' class='btn btn-primary'>Back to Line Men</a></div>";
    require_once 'footer.php';
    exit;
}

// Handle Line Man Edit Action
$flash_msg = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_lineman_profile') {
    $full_name = trim($_POST['full_name'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $city      = trim($_POST['city'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $status    = in_array($_POST['status'] ?? 'active', ['active', 'disabled']) ? $_POST['status'] : 'active';
    $can_create = isset($_POST['can_create_users']) ? (int)$_POST['can_create_users'] : 1;
    $monthly_sal = (int)($_POST['monthly_salary'] ?? 0);

    if (empty($full_name)) {
        $flash_msg = 'Full Name is required.';
        $flash_type = 'danger';
    } else {
        try {
            $upd = $pdo->prepare("UPDATE linemen SET full_name=?, password=?, phone=?, city=?, address=?, status=?, can_create_users=?, monthly_salary=? WHERE id=? AND client_id=?");
            $upd->execute([$full_name, $password, $phone, $city, $address, $status, $can_create, $monthly_sal, $lineman_id, $client_id]);
            $flash_msg = 'Line Man details updated successfully!';
            $flash_type = 'success';
        } catch (Exception $e) {
            $flash_msg = 'Error updating profile: ' . $e->getMessage();
            $flash_type = 'danger';
        }
    }
}

// Fetch Lineman Details
$stmt = $pdo->prepare("SELECT * FROM linemen WHERE id = ? AND client_id = ?");
$stmt->execute([$lineman_id, $client_id]);
$lm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lm) {
    echo "<div class='container py-5'><div class='alert alert-danger'>Line Man not found or access unauthorized.</div><a href='line_man' class='btn btn-primary'>Back to Line Men</a></div>";
    require_once 'footer.php';
    exit;
}
?>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Top Navigation Breadcrumb & Back -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div class="d-flex align-items-center gap-3">
            <a href="line_man" class="btn btn-outline-secondary rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px;" title="Back to Line Men">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h3 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($lm['full_name']) ?></h3>
                <span class="text-muted small">Line Man Profile &amp; Salary Ledger Management</span>
            </div>
        </div>
        <div>
            <button type="button" class="btn btn-outline-primary rounded-pill px-3 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#editLinemanModal">
                <i class="fa-solid fa-pen-to-square"></i> Edit Details
            </button>
        </div>
    </div>

    <?php if ($flash_msg): ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show rounded-4 shadow-sm mb-4" role="alert">
            <i class="fa-solid <?= $flash_type === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> me-2"></i>
            <?= htmlspecialchars($flash_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Profile Overview Card -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <div class="p-4" style="background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff;">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center shadow" style="width: 64px; height: 64px; font-size: 1.8rem; font-weight: 800;">
                        <i class="fa-solid fa-hard-hat"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-1"><?= htmlspecialchars($lm['full_name']) ?></h4>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge bg-white bg-opacity-25 rounded-pill px-3 py-1">@<?= htmlspecialchars($lm['username']) ?></span>
                            <?php if ($lm['status'] === 'active'): ?>
                                <span class="badge bg-success rounded-pill px-3 py-1">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger rounded-pill px-3 py-1">Disabled</span>
                            <?php endif; ?>
                            <?php if (!empty($lm['can_create_users'])): ?>
                                <span class="badge bg-info rounded-pill px-3 py-1"><i class="fa-solid fa-user-plus me-1"></i> User Creation Allowed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-4 text-white-50 small">
                    <div>
                        <div class="text-white-50 text-uppercase fw-semibold" style="font-size: 0.7rem;">Contact</div>
                        <div class="text-white fw-bold"><i class="fa-solid fa-phone me-1"></i> <?= htmlspecialchars($lm['phone'] ?: 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="text-white-50 text-uppercase fw-semibold" style="font-size: 0.7rem;">Location</div>
                        <div class="text-white fw-bold"><i class="fa-solid fa-location-dot me-1"></i> <?= htmlspecialchars($lm['city'] ?: 'N/A') ?></div>
                    </div>
                    <div>
                        <div class="text-white-50 text-uppercase fw-semibold" style="font-size: 0.7rem;">Joined Date</div>
                        <div class="text-white fw-bold"><i class="fa-solid fa-calendar me-1"></i> <?= date('d M Y', strtotime($lm['created_at'])) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Salary & Lifetime Advance Ledger Section -->
    <?php
    $staff_type = 'lineman';
    $staff_id = (int)$lm['id'];
    $staff_name = $lm['full_name'];
    $monthly_sal = (int)($lm['monthly_salary'] ?? 0);
    $joined_date = $lm['created_at'];
    require __DIR__ . '/staff_salary_view.php';
    ?>

</div>

<!-- Modal: Edit Lineman Details -->
<div class="modal fade" id="editLinemanModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 rounded-4 shadow-lg">
            <div class="modal-header border-bottom p-4">
                <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-pen text-primary me-2"></i> Edit Line Man Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_lineman_profile">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($lm['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Username (Read Only)</label>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($lm['username']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Password <span class="text-danger">*</span></label>
                            <input type="text" name="password" class="form-control" value="<?= htmlspecialchars($lm['password']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Monthly Salary (Rs)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                                <input type="number" step="1" min="0" name="monthly_salary" class="form-control" value="<?= (int)($lm['monthly_salary'] ?? 0) ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Phone Number</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($lm['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">City</label>
                            <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($lm['city'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?= ($lm['status'] === 'active') ? 'selected' : '' ?>>Active</option>
                                <option value="disabled" <?= ($lm['status'] === 'disabled') ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">User Creation Access</label>
                            <select name="can_create_users" class="form-select">
                                <option value="1" <?= (!empty($lm['can_create_users'])) ? 'selected' : '' ?>>Allowed</option>
                                <option value="0" <?= (empty($lm['can_create_users'])) ? 'selected' : '' ?>>Restricted</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted">Address</label>
                            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($lm['address'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top p-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
