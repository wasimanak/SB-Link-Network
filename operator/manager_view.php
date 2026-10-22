<?php
require_once 'header.php';

// Access Control: Only Operator can view manager profiles & activity logs
if (!empty($_SESSION['is_manager'])) {
    echo "<div class='container py-5'>
            <div class='alert alert-danger shadow-sm'>
                <i class='fa-solid fa-shield-halved me-2'></i> Access Denied: Managers are not authorized to view manager audit logs.
            </div>
            <a href='dashboard' class='btn btn-outline-secondary'><i class='fa-solid fa-arrow-left me-1'></i> Return to Dashboard</a>
          </div>";
    require_once 'footer.php';
    exit;
}

$client_id = (int)$_SESSION['operator_id'];
$manager_id = (int)($_GET['id'] ?? 0);

if ($manager_id <= 0) {
    echo "<div class='container py-5'><div class='alert alert-warning'>Invalid Manager ID specified.</div><a href='managers' class='btn btn-primary'>Back to Managers</a></div>";
    require_once 'footer.php';
    exit;
}

// Handle Direct Quick Actions (Toggle permissions or reset password)
$flash_msg = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_permission') {
        $field = $_POST['permission'] ?? '';
        $val = ($_POST['value'] ?? '') === 'yes' ? 'yes' : 'no';

        if (in_array($field, ['can_manage_packages', 'can_collect_recovery'])) {
            $pdo->prepare("UPDATE managers SET {$field} = ? WHERE id = ? AND client_id = ?")->execute([$val, $manager_id, $client_id]);
            $permLabel = ($field === 'can_manage_packages') ? 'Package Management' : 'Recovery Collection';
            log_staff_activity($pdo, "Changed {$permLabel} permission to '{$val}' for Manager #{$manager_id}", "Manager #{$manager_id}", "Manager");
            $flash_msg = "Permission updated successfully!";
        }
    }

    if ($action === 'toggle_status') {
        $new_status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
        $pdo->prepare("UPDATE managers SET status = ? WHERE id = ? AND client_id = ?")->execute([$new_status, $manager_id, $client_id]);
        log_staff_activity($pdo, "Changed status to '{$new_status}' for Manager #{$manager_id}", "Manager #{$manager_id}", "Manager");
        $flash_msg = "Account status updated to {$new_status}!";
    }

    if ($action === 'change_password') {
        $new_pwd = trim($_POST['new_password'] ?? '');
        if (!empty($new_pwd)) {
            $hash = password_hash($new_pwd, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE managers SET password = ? WHERE id = ? AND client_id = ?")->execute([$hash, $manager_id, $client_id]);
            log_staff_activity($pdo, "Reset password for Manager #{$manager_id}", "Manager #{$manager_id}", "Manager");
            $flash_msg = "Password reset successfully!";
        }
    }
}

// Fetch Manager Details
$stmt = $pdo->prepare("SELECT * FROM managers WHERE id = ? AND client_id = ?");
$stmt->execute([$manager_id, $client_id]);
$mgr = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$mgr) {
    echo "<div class='container py-5'><div class='alert alert-danger'>Manager not found or access unauthorized.</div><a href='managers' class='btn btn-primary'>Back to Managers</a></div>";
    require_once 'footer.php';
    exit;
}

// Fetch 30-Day Activity History
$actStmt = $pdo->prepare("
    SELECT * 
    FROM activity_logs 
    WHERE manager_id = ? 
      AND created_at >= NOW() - INTERVAL 30 DAY 
    ORDER BY id DESC
");
$actStmt->execute([$manager_id]);
$activities = $actStmt->fetchAll(PDO::FETCH_ASSOC);

// Activity Breakdown stats
$total_acts = count($activities);
$renew_count = 0;
$user_create_count = 0;
$login_count = 0;

foreach ($activities as $act) {
    $txt = strtolower($act['activity']);
    if (strpos($txt, 'renew') !== false) $renew_count++;
    if (strpos($txt, 'created new user') !== false || strpos($txt, 'create user') !== false) $user_create_count++;
    if (strpos($txt, 'login') !== false || strpos($txt, 'logged in') !== false) $login_count++;
}
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

<style>
.profile-card {
    background: #fff;
    border-radius: 16px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    overflow: hidden;
}
.profile-banner {
    height: 90px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6, #d946ef);
}
.profile-avatar-wrap {
    margin-top: -45px;
    padding: 0 24px;
}
.profile-avatar {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: #fff;
    border: 4px solid #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.2rem;
    font-weight: 800;
    color: #7c3aed;
}
.perm-card {
    background: #f8fafc;
    border-radius: 12px;
    padding: 16px;
    border: 1px solid #e2e8f0;
    height: 100%;
}
.mini-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 14px 18px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    display: flex;
    align-items: center;
    gap: 14px;
}
.mini-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.badge-soft-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge-soft-info { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.badge-soft-warning { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.badge-soft-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
.dt-buttons .btn {
    background: #475569;
    color: #fff;
    border: none;
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 0.8rem;
    margin-right: 4px;
}
.dt-buttons .btn:hover { background: #334155; }
</style>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Flash Message -->
    <?php if ($flash_msg): ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i> <?= $flash_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Breadcrumb & Back button -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="managers" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Managers
        </a>
        <div class="text-muted small">
            <i class="fa-solid fa-shield-halved text-primary me-1"></i> Operator Governance Panel
        </div>
    </div>

    <!-- Manager Profile Overview -->
    <div class="profile-card mb-4">
        <div class="profile-banner"></div>
        <div class="profile-avatar-wrap d-flex flex-wrap justify-content-between align-items-end gap-3 pb-3">
            <div class="d-flex align-items-end gap-3">
                <div class="profile-avatar">
                    <?= strtoupper(substr($mgr['full_name'], 0, 1)) ?>
                </div>
                <div>
                    <h4 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($mgr['full_name']) ?></h4>
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <span class="badge bg-purple-subtle text-primary border rounded-pill px-2 py-1 font-monospace" style="background:#ede9fe; color:#7c3aed !important; border-color:#ddd6fe !important;">
                            <i class="fa-solid fa-user-shield me-1"></i> <?= htmlspecialchars($mgr['username']) ?>
                        </span>
                        <?php if ($mgr['status'] === 'active'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1">Inactive</span>
                        <?php endif; ?>
                        <span class="text-muted small ms-2"><i class="fa-regular fa-calendar me-1"></i> Joined <?= date('d M Y', strtotime($mgr['created_at'])) ?></span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <!-- Change Password Button -->
                <button class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    <i class="fa-solid fa-key me-1"></i> Reset Password
                </button>
                <!-- Status Toggle -->
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="status" value="<?= $mgr['status'] === 'active' ? 'inactive' : 'active' ?>">
                    <?php if ($mgr['status'] === 'active'): ?>
                        <button type="submit" class="btn btn-outline-warning btn-sm rounded-pill px-3" onclick="return confirm('Suspend this Manager account?');">
                            <i class="fa-solid fa-pause me-1"></i> Deactivate
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-success btn-sm rounded-pill px-3">
                            <i class="fa-solid fa-play me-1"></i> Activate Account
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="p-4 pt-2 border-top">
            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Phone Number</div>
                    <div class="fw-bold text-dark mt-1"><i class="fa-solid fa-phone text-muted me-1 small"></i> <?= htmlspecialchars($mgr['phone'] ?: 'N/A') ?></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Email Address</div>
                    <div class="fw-bold text-dark mt-1"><i class="fa-regular fa-envelope text-muted me-1 small"></i> <?= htmlspecialchars($mgr['email'] ?: 'N/A') ?></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">City / Region</div>
                    <div class="fw-bold text-dark mt-1"><i class="fa-solid fa-city text-muted me-1 small"></i> <?= htmlspecialchars($mgr['city'] ?: 'N/A') ?></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="text-secondary small fw-semibold text-uppercase">Address</div>
                    <div class="fw-bold text-dark mt-1 text-truncate" title="<?= htmlspecialchars($mgr['address'] ?: 'N/A') ?>"><i class="fa-solid fa-location-dot text-muted me-1 small"></i> <?= htmlspecialchars($mgr['address'] ?: 'N/A') ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Operator-Controlled Permissions & Real-time Toggles -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="perm-card d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-1 text-dark">
                        <i class="fa-solid fa-box-open text-primary me-2"></i> Package &amp; Pricing Management
                    </h6>
                    <p class="text-muted small mb-0">Allow manager to create new packages, edit existing packages, and change rates.</p>
                </div>
                <form method="POST" class="ms-3 flex-shrink-0">
                    <input type="hidden" name="action" value="toggle_permission">
                    <input type="hidden" name="permission" value="can_manage_packages">
                    <input type="hidden" name="value" value="<?= $mgr['can_manage_packages'] === 'yes' ? 'no' : 'yes' ?>">
                    <?php if ($mgr['can_manage_packages'] === 'yes'): ?>
                        <button type="submit" class="btn btn-success rounded-pill px-3 py-1 btn-sm fw-semibold shadow-sm" title="Click to Revoke">
                            <i class="fa-solid fa-check-circle me-1"></i> Permitted
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-secondary rounded-pill px-3 py-1 btn-sm fw-semibold" title="Click to Grant Permission">
                            <i class="fa-solid fa-lock me-1"></i> Locked
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-md-6">
            <div class="perm-card d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-1 text-dark">
                        <i class="fa-solid fa-hand-holding-dollar text-warning me-2"></i> Recovery Man Cash Collection
                    </h6>
                    <p class="text-muted small mb-0">Allow manager to receive and collect cash in hand from field Recovery Men.</p>
                </div>
                <form method="POST" class="ms-3 flex-shrink-0">
                    <input type="hidden" name="action" value="toggle_permission">
                    <input type="hidden" name="permission" value="can_collect_recovery">
                    <input type="hidden" name="value" value="<?= $mgr['can_collect_recovery'] === 'yes' ? 'no' : 'yes' ?>">
                    <?php if ($mgr['can_collect_recovery'] === 'yes'): ?>
                        <button type="submit" class="btn btn-success rounded-pill px-3 py-1 btn-sm fw-semibold shadow-sm" title="Click to Revoke">
                            <i class="fa-solid fa-check-circle me-1"></i> Permitted
                        </button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-outline-secondary rounded-pill px-3 py-1 btn-sm fw-semibold" title="Click to Grant Permission">
                            <i class="fa-solid fa-lock me-1"></i> Locked
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- 30-Day Activity Stat Overview -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(99, 102, 241, 0.1); color: #6366f1;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $total_acts ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Total 30-Day Actions</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $renew_count ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Package Renewals</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(14, 165, 233, 0.1); color: #0ea5e9;">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $user_create_count ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Users Created</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="mini-stat-card">
                <div class="mini-stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                    <i class="fa-solid fa-right-to-bracket"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-dark"><?= $login_count ?></div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem;">Logins Recorded</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Manager Salary & Lifetime Advance Ledger Section -->
    <div class="mb-4">
        <?php
        $staff_type = 'manager';
        $staff_id = (int)$mgr['id'];
        $staff_name = $mgr['full_name'];
        $monthly_sal = (int)($mgr['monthly_salary'] ?? 0);
        $joined_date = $mgr['created_at'];
        require __DIR__ . '/staff_salary_view.php';
        ?>
    </div>

    <!-- 30-Day Activity Audit Table -->
    <div class="card border-0 shadow-sm rounded-4 p-3 p-md-4" id="activityLogs">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div>
                <h5 class="fw-bold mb-1 text-dark">
                    <i class="fa-solid fa-list-check text-primary me-2"></i> 30-Day Manager Activity Audit Trail
                </h5>
                <div class="text-muted small">
                    Every action taken by this Manager is permanently timestamped with station IP and target information.
                </div>
            </div>
            <span class="badge bg-light text-dark border px-3 py-2 rounded-pill font-monospace">
                Showing Last 30 Days History
            </span>
        </div>

        <?php if (empty($activities)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-circle-check fs-1 text-success opacity-50 mb-3 d-block"></i>
                <h6 class="fw-bold text-dark">No Activities Recorded Yet</h6>
                <p class="small text-secondary mb-0">Activities performed by this Manager within the last 30 days will appear here automatically.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table id="managerLogsTable" class="table table-hover table-borderless align-middle w-100">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">#ID</th>
                        <th>Datetime</th>
                        <th>Activity Description</th>
                        <th>Against To</th>
                        <th>Against Role</th>
                        <th>Station IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $act): ?>
                        <tr>
                            <td class="fw-bold text-secondary"><?= $act['id'] ?></td>
                            <td style="white-space: nowrap;">
                                <div class="fw-semibold text-dark"><?= date('d M Y', strtotime($act['created_at'])) ?></div>
                                <div class="text-muted small"><?= date('h:i:s A', strtotime($act['created_at'])) ?></div>
                            </td>
                            <td>
                                <?php 
                                    $act_text = htmlspecialchars($act['activity']);
                                    if (stripos($act_text, 'renew') !== false) {
                                        echo "<span class='badge badge-soft-success px-2 py-1 rounded-pill me-1'><i class='fa-solid fa-arrows-rotate me-1'></i>Renew</span> " . $act_text;
                                    } elseif (stripos($act_text, 'create') !== false) {
                                        echo "<span class='badge badge-soft-info px-2 py-1 rounded-pill me-1'><i class='fa-solid fa-plus-circle me-1'></i>Create</span> " . $act_text;
                                    } elseif (stripos($act_text, 'login') !== false) {
                                        echo "<span class='badge badge-soft-secondary px-2 py-1 rounded-pill me-1'><i class='fa-solid fa-arrow-right-to-bracket me-1'></i>Login</span> " . $act_text;
                                    } else {
                                        echo "<span class='fw-medium text-dark'>" . $act_text . "</span>";
                                    }
                                ?>
                            </td>
                            <td>
                                <?php if ($act['against_to'] && $act['against_to'] !== 'N/A'): ?>
                                    <span class="badge bg-light text-dark border px-2 py-1 rounded-pill">
                                        <?= htmlspecialchars($act['against_to']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1">
                                    <?= htmlspecialchars($act['against_role'] ?: 'System') ?>
                                </span>
                            </td>
                            <td class="font-monospace text-muted small">
                                <?= htmlspecialchars($act['station_ip'] ?: '127.0.0.1') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-key text-warning me-2"></i> Reset Manager Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">Enter a new secure password for <strong><?= htmlspecialchars($mgr['full_name']) ?></strong> (<?= htmlspecialchars($mgr['username']) ?>).</p>
                    <label class="form-label fw-semibold text-secondary small">New Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Enter new password" required minlength="6">
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold">Save New Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    if ($('#managerLogsTable').length) {
        $('#managerLogsTable').DataTable({
            dom: "<'row mb-3'<'col-sm-6'B><'col-sm-6 text-end'f>>" +
                 "<'row'<'col-sm-12'tr>>" +
                 "<'row mt-3'<'col-sm-5'i><'col-sm-7'p>>",
            buttons: [
                { extend: 'excel', text: '<i class="fa-solid fa-file-excel me-1"></i> Excel', className: 'btn btn-sm btn-dark' },
                { extend: 'csv', text: '<i class="fa-solid fa-file-csv me-1"></i> CSV', className: 'btn btn-sm btn-dark' },
                { extend: 'print', text: '<i class="fa-solid fa-print me-1"></i> Print', className: 'btn btn-sm btn-dark' }
            ],
            order: [[0, 'desc']],
            pageLength: 25,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search activities..."
            }
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
