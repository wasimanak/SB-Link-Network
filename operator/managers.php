<?php
require_once 'header.php';
require_once __DIR__ . '/staff_salary_helper.php';
init_staff_salary_schema($pdo);

// Access Control: Only Operator can manage managers (Managers cannot access this page)
if (!empty($_SESSION['is_manager'])) {
    echo "<div class='container py-5'>
            <div class='alert alert-danger shadow-sm'>
                <i class='fa-solid fa-shield-halved me-2'></i> Access Denied: Only the Operator can manage Manager accounts.
            </div>
            <a href='dashboard' class='btn btn-outline-secondary'><i class='fa-solid fa-arrow-left me-1'></i> Return to Dashboard</a>
          </div>";
    require_once 'footer.php';
    exit;
}

$client_id = (int)$_SESSION['operator_id'];
$flash_msg = '';
$flash_type = 'success';

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD MANAGER
    if ($action === 'add_manager') {
        $full_name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $monthly_salary = (int)($_POST['monthly_salary'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        $can_manage_packages = isset($_POST['can_manage_packages']) ? 'yes' : 'no';
        $can_collect_recovery = isset($_POST['can_collect_recovery']) ? 'yes' : 'no';

        if (empty($full_name) || empty($username) || empty($password)) {
            $flash_msg = 'Please fill in all required fields (Name, Username, Password).';
            $flash_type = 'danger';
        } else {
            // Check username uniqueness
            $chk = $pdo->prepare("SELECT COUNT(*) FROM managers WHERE username = ?");
            $chk->execute([$username]);
            if ($chk->fetchColumn() > 0) {
                $flash_msg = "Error: The username '<strong>" . htmlspecialchars($username) . "</strong>' is already in use.";
                $flash_type = 'danger';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $ins = $pdo->prepare("
                        INSERT INTO managers (client_id, full_name, username, password, phone, email, address, city, monthly_salary, status, can_manage_packages, can_collect_recovery)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([
                        $client_id, $full_name, $username, $hash, $phone, $email, $address, $city, $monthly_salary, $status, $can_manage_packages, $can_collect_recovery
                    ]);
                    $new_mgr_id = (int)$pdo->lastInsertId();

                    if ($monthly_salary > 0 && $new_mgr_id > 0) {
                        sync_staff_monthly_salary($pdo, $client_id, 'manager', $new_mgr_id, $full_name, $monthly_salary);
                    }

                    log_staff_activity($pdo, "Created new Manager: {$full_name} ({$username})", $full_name, "Manager");

                    $flash_msg = "Manager '<strong>" . htmlspecialchars($full_name) . "</strong>' added successfully!";
                    $flash_type = 'success';
                } catch (PDOException $e) {
                    $flash_msg = "Database Error: " . htmlspecialchars($e->getMessage());
                    $flash_type = 'danger';
                }
            }
        }
    }

    // 2. EDIT MANAGER
    if ($action === 'edit_manager') {
        $id = (int)($_POST['id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $monthly_salary = (int)($_POST['monthly_salary'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        $can_manage_packages = isset($_POST['can_manage_packages']) ? 'yes' : 'no';
        $can_collect_recovery = isset($_POST['can_collect_recovery']) ? 'yes' : 'no';

        if (empty($full_name) || $id <= 0) {
            $flash_msg = 'Please provide valid manager details.';
            $flash_type = 'danger';
        } else {
            try {
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $upd = $pdo->prepare("
                        UPDATE managers 
                        SET full_name=?, password=?, phone=?, email=?, address=?, city=?, monthly_salary=?, status=?, can_manage_packages=?, can_collect_recovery=?
                        WHERE id=? AND client_id=?
                    ");
                    $upd->execute([
                        $full_name, $hash, $phone, $email, $address, $city, $monthly_salary, $status, $can_manage_packages, $can_collect_recovery, $id, $client_id
                    ]);
                } else {
                    $upd = $pdo->prepare("
                        UPDATE managers 
                        SET full_name=?, phone=?, email=?, address=?, city=?, monthly_salary=?, status=?, can_manage_packages=?, can_collect_recovery=?
                        WHERE id=? AND client_id=?
                    ");
                    $upd->execute([
                        $full_name, $phone, $email, $address, $city, $monthly_salary, $status, $can_manage_packages, $can_collect_recovery, $id, $client_id
                    ]);
                }

                if ($id > 0) {
                    sync_staff_monthly_salary($pdo, $client_id, 'manager', $id, $full_name, $monthly_salary);
                }

                log_staff_activity($pdo, "Updated Manager profile & permissions: {$full_name}", $full_name, "Manager");

                $flash_msg = "Manager '<strong>" . htmlspecialchars($full_name) . "</strong>' updated successfully!";
                $flash_type = 'success';
            } catch (PDOException $e) {
                $flash_msg = "Database Error: " . htmlspecialchars($e->getMessage());
                $flash_type = 'danger';
            }
        }
    }

    // 3. QUICK TOGGLE PERMISSION
    if ($action === 'toggle_permission') {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['permission'] ?? '';
        $val = ($_POST['value'] ?? '') === 'yes' ? 'yes' : 'no';

        if ($id > 0 && in_array($field, ['can_manage_packages', 'can_collect_recovery'])) {
            try {
                $pdo->prepare("UPDATE managers SET {$field} = ? WHERE id = ? AND client_id = ?")->execute([$val, $id, $client_id]);
                $permName = ($field === 'can_manage_packages') ? 'Package Management' : 'Recovery Collection';
                log_staff_activity($pdo, "Updated {$permName} permission to '{$val}' for Manager #{$id}", "Manager #{$id}", "Manager");
                $flash_msg = "Permission updated successfully!";
                $flash_type = 'success';
            } catch (Exception $e) {
                $flash_msg = "Error updating permission.";
                $flash_type = 'danger';
            }
        }
    }

    // 4. DELETE MANAGER
    if ($action === 'delete_manager') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $m_stmt = $pdo->prepare("SELECT full_name, username FROM managers WHERE id = ? AND client_id = ?");
            $m_stmt->execute([$id, $client_id]);
            $mgr = $m_stmt->fetch();

            if ($mgr) {
                $pdo->prepare("DELETE FROM managers WHERE id = ? AND client_id = ?")->execute([$id, $client_id]);
                log_staff_activity($pdo, "Deleted Manager: {$mgr['full_name']} ({$mgr['username']})", $mgr['full_name'], "Manager");
                $flash_msg = "Manager '<strong>" . htmlspecialchars($mgr['full_name']) . "</strong>' deleted successfully.";
                $flash_type = 'success';
            }
        }
    }
}

// Fetch all managers for this Operator
$stmt = $pdo->prepare("
    SELECT m.*, 
    (SELECT COUNT(*) FROM activity_logs al WHERE al.manager_id = m.id AND al.created_at >= NOW() - INTERVAL 30 DAY) as recent_activity_count
    FROM managers m 
    WHERE m.client_id = ? 
    ORDER BY m.id DESC
");
$stmt->execute([$client_id]);
$managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$total_mgrs = count($managers);
$active_mgrs = 0;
$pkg_permitted = 0;
$coll_permitted = 0;
foreach ($managers as $m) {
    if ($m['status'] === 'active') $active_mgrs++;
    if ($m['can_manage_packages'] === 'yes') $pkg_permitted++;
    if ($m['can_collect_recovery'] === 'yes') $coll_permitted++;
}
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

<style>
.mgr-header {
    background: #fff;
    padding: 16px 22px;
    border-radius: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.stat-widget {
    background: #fff;
    border-radius: 14px;
    padding: 18px 20px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: transform 0.2s ease;
}
.stat-widget:hover { transform: translateY(-2px); }
.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
}
.stat-val { font-size: 1.5rem; font-weight: 700; color: #0f172a; line-height: 1.1; }
.stat-lbl { font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-top: 4px; }

.table-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #f1f5f9;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    overflow: hidden;
}
.table-custom thead th {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    color: #475569;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 14px 16px;
}
.table-custom tbody td {
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.88rem;
    padding: 14px 16px;
    color: #334155;
}
.perm-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s;
}
.perm-allowed {
    background: #ecfdf5;
    color: #059669;
    border: 1px solid #a7f3d0;
}
.perm-allowed:hover { background: #d1fae5; color: #047857; }
.perm-locked {
    background: #f8fafc;
    color: #64748b;
    border: 1px solid #e2e8f0;
}
.perm-locked:hover { background: #f1f5f9; color: #475569; }
</style>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Flash Message -->
    <?php if ($flash_msg): ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i> <?= $flash_msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="mgr-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                <i class="fa-solid fa-user-shield text-primary"></i> Team Managers
            </h4>
            <div class="text-muted small">
                Add and manage Team Managers. Managers can access the portal with permissions governed entirely by you.
            </div>
        </div>
        <button class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#addManagerModal">
            <i class="fa-solid fa-user-plus me-1"></i> Add New Manager
        </button>
    </div>

    <!-- Stat Overview -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-widget">
                <div class="stat-icon" style="background: rgba(99, 102, 241, 0.1); color: #6366f1;">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div>
                    <div class="stat-val"><?= $total_mgrs ?></div>
                    <div class="stat-lbl">Total Managers</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-widget">
                <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="stat-val"><?= $active_mgrs ?></div>
                    <div class="stat-lbl">Active Managers</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-widget">
                <div class="stat-icon" style="background: rgba(14, 165, 233, 0.1); color: #0ea5e9;">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <div>
                    <div class="stat-val"><?= $pkg_permitted ?></div>
                    <div class="stat-lbl">Pkg Permitted</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-widget">
                <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
                <div>
                    <div class="stat-val"><?= $coll_permitted ?></div>
                    <div class="stat-lbl">RM Collection Permitted</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Managers Table -->
    <div class="table-card p-3 p-md-4">
        <?php if (empty($managers)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa-solid fa-user-shield fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                <h5 class="fw-bold text-dark">No Managers Found</h5>
                <p class="small text-secondary mb-3">You haven't added any Manager to your team yet.</p>
                <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addManagerModal">
                    <i class="fa-solid fa-user-plus me-1"></i> Add First Manager
                </button>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-custom table-hover align-middle w-100" id="managersTable">
                <thead>
                    <tr>
                        <th style="width: 50px;">#ID</th>
                        <th>Manager Name</th>
                        <th>Username</th>
                        <th>Contact</th>
                        <th>Monthly Salary</th>
                        <th>Package Perm</th>
                        <th>RM Collection</th>
                        <th>Status</th>
                        <th>30-Day Activity</th>
                        <th class="text-end" style="width: 130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($managers as $m): ?>
                        <tr>
                            <td class="fw-bold text-secondary"><?= $m['id'] ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar rounded-circle d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width: 36px; height: 36px; background: linear-gradient(135deg, #8b5cf6, #6366f1); font-size: 0.85rem;">
                                        <?= strtoupper(substr($m['full_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <a href="manager_view?id=<?= $m['id'] ?>" class="text-decoration-none fw-bold text-dark hover-primary" style="font-size: 0.92rem;">
                                            <?= htmlspecialchars($m['full_name']) ?>
                                        </a>
                                        <div class="text-muted small"><?= htmlspecialchars($m['city'] ?: 'No City') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border font-monospace px-2 py-1">
                                    <?= htmlspecialchars($m['username']) ?>
                                </span>
                            </td>
                            <td>
                                <div><i class="fa-solid fa-phone me-1 text-muted small"></i> <?= htmlspecialchars($m['phone'] ?: 'N/A') ?></div>
                                <?php if ($m['email']): ?>
                                    <div class="text-muted small"><i class="fa-regular fa-envelope me-1"></i> <?= htmlspecialchars($m['email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><i class="fa-solid fa-money-bill-wave text-success me-1 small"></i> Rs. <?= number_format($m['monthly_salary'] ?? 0) ?></div>
                                <div class="text-muted small">per month</div>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_permission">
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                    <input type="hidden" name="permission" value="can_manage_packages">
                                    <input type="hidden" name="value" value="<?= $m['can_manage_packages'] === 'yes' ? 'no' : 'yes' ?>">
                                    <?php if ($m['can_manage_packages'] === 'yes'): ?>
                                        <button type="submit" class="perm-badge perm-allowed" title="Click to Revoke Permission">
                                            <i class="fa-solid fa-check-circle"></i> Allowed
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="perm-badge perm-locked" title="Click to Grant Permission">
                                            <i class="fa-solid fa-lock"></i> Locked
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_permission">
                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                    <input type="hidden" name="permission" value="can_collect_recovery">
                                    <input type="hidden" name="value" value="<?= $m['can_collect_recovery'] === 'yes' ? 'no' : 'yes' ?>">
                                    <?php if ($m['can_collect_recovery'] === 'yes'): ?>
                                        <button type="submit" class="perm-badge perm-allowed" title="Click to Revoke Permission">
                                            <i class="fa-solid fa-check-circle"></i> Allowed
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="perm-badge perm-locked" title="Click to Grant Permission">
                                            <i class="fa-solid fa-lock"></i> Locked
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                            <td>
                                <?php if ($m['status'] === 'active'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="manager_view?id=<?= $m['id'] ?>#activityLogs" class="btn btn-sm btn-light border rounded-pill px-3 py-1 shadow-sm text-dark fw-semibold" style="font-size: 0.78rem;">
                                    <i class="fa-solid fa-clock-rotate-left text-primary me-1"></i> <?= $m['recent_activity_count'] ?> action(s)
                                </a>
                            </td>
                            <td class="text-end">
                                <div class="d-flex justify-content-end gap-1">
                                    <a href="manager_view?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary rounded-circle" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;" title="View 30-Day Activity & Profile">
                                        <i class="fa-solid fa-eye"></i>
                                    </a>
                                    <button class="btn btn-sm btn-outline-secondary rounded-circle" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;" title="Edit Manager" onclick='openEditModal(<?= json_encode($m) ?>)'>
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete Manager \'<?= addslashes($m['full_name']) ?>\'? This action cannot be undone.');">
                                        <input type="hidden" name="action" value="delete_manager">
                                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;" title="Delete Manager">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Manager Modal -->
<div class="modal fade" id="addManagerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fa-solid fa-user-plus text-primary me-2"></i> Add New Manager
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="add_manager">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" placeholder="e.g. Ali Ahmed" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control font-monospace" placeholder="e.g. mgr_ali" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Login Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" placeholder="Create strong password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="e.g. 03001234567">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="e.g. ali@domain.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">City</label>
                            <input type="text" name="city" class="form-control" placeholder="e.g. Lahore">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-bold text-secondary">Monthly Salary (Rs)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                                <input type="number" step="1" min="0" name="monthly_salary" class="form-control" placeholder="e.g. 35000" value="0">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-secondary small">Office / Physical Address</label>
                            <textarea name="address" class="form-control" rows="2" placeholder="Manager physical address..."></textarea>
                        </div>
                        
                        <div class="col-12"><hr class="my-2 text-muted opacity-25"></div>

                        <!-- Permissions Controlled by Admin -->
                        <div class="col-12">
                            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-key text-warning me-1"></i> Admin-Controlled Permissions</h6>
                            <p class="text-muted small mb-3">By default, Manager cannot edit/create packages or collect cash from Operators unless explicitly granted below.</p>
                            
                            <div class="p-3 bg-light rounded-3 border mb-3">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="add_can_manage_packages" name="can_manage_packages">
                                    <label class="form-check-label fw-bold text-dark" for="add_can_manage_packages">
                                        Allow Package &amp; Pricing Management
                                    </label>
                                    <div class="text-muted small">Enables manager to create new packages, edit existing packages, and adjust pricing.</div>
                                </div>
                            </div>

                            <div class="p-3 bg-light rounded-3 border">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="add_can_collect_recovery" name="can_collect_recovery">
                                    <label class="form-check-label fw-bold text-dark" for="add_can_collect_recovery">
                                        Allow Cash Collection from Operators
                                    </label>
                                    <div class="text-muted small">Enables manager to collect and receive cash in hand from Operators.</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mt-3">
                            <label class="form-label fw-semibold text-secondary small">Account Status</label>
                            <select name="status" class="form-select">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive / Suspended</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm">Save Manager</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Manager Modal -->
<div class="modal fade" id="editManagerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-bottom px-4 py-3 bg-light">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fa-solid fa-user-pen text-primary me-2"></i> Edit Manager
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="edit_manager">
                <input type="hidden" name="id" id="edit_mgr_id">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" id="edit_mgr_full_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Username</label>
                            <input type="text" id="edit_mgr_username" class="form-control font-monospace bg-light" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">New Password (Leave blank to keep current)</label>
                            <input type="password" name="password" class="form-control" placeholder="••••••••">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Phone Number</label>
                            <input type="text" name="phone" id="edit_mgr_phone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">Email Address</label>
                            <input type="email" name="email" id="edit_mgr_email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary small">City</label>
                            <input type="text" name="city" id="edit_mgr_city" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-secondary">Monthly Salary (Rs)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                                <input type="number" step="1" min="0" name="monthly_salary" id="edit_mgr_monthly_salary" class="form-control" placeholder="e.g. 35000" value="0">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-secondary small">Office / Physical Address</label>
                            <textarea name="address" id="edit_mgr_address" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="col-12"><hr class="my-2 text-muted opacity-25"></div>

                        <!-- Permissions Controlled by Admin -->
                        <div class="col-12">
                            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-key text-warning me-1"></i> Admin-Controlled Permissions</h6>
                            
                            <div class="p-3 bg-light rounded-3 border mb-3">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="edit_can_manage_packages" name="can_manage_packages">
                                    <label class="form-check-label fw-bold text-dark" for="edit_can_manage_packages">
                                        Allow Package &amp; Pricing Management
                                    </label>
                                    <div class="text-muted small">Enables manager to create new packages, edit existing packages, and adjust pricing.</div>
                                </div>
                            </div>

                            <div class="p-3 bg-light rounded-3 border">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="edit_can_collect_recovery" name="can_collect_recovery">
                                    <label class="form-check-label fw-bold text-dark" for="edit_can_collect_recovery">
                                        Allow Cash Collection from Operators
                                    </label>
                                    <div class="text-muted small">Enables manager to collect and receive cash in hand from Operators.</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mt-3">
                            <label class="form-label fw-semibold text-secondary small">Account Status</label>
                            <select name="status" id="edit_mgr_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive / Suspended</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-3 bg-light">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-semibold shadow-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    if ($('#managersTable').length) {
        $('#managersTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search managers..."
            }
        });
    }
});

function openEditModal(mgr) {
    document.getElementById('edit_mgr_id').value = mgr.id;
    document.getElementById('edit_mgr_full_name').value = mgr.full_name || '';
    document.getElementById('edit_mgr_username').value = mgr.username || '';
    document.getElementById('edit_mgr_phone').value = mgr.phone || '';
    document.getElementById('edit_mgr_email').value = mgr.email || '';
    document.getElementById('edit_mgr_city').value = mgr.city || '';
    document.getElementById('edit_mgr_monthly_salary').value = mgr.monthly_salary || 0;
    document.getElementById('edit_mgr_address').value = mgr.address || '';
    document.getElementById('edit_mgr_status').value = mgr.status || 'active';
    document.getElementById('edit_can_manage_packages').checked = (mgr.can_manage_packages === 'yes');
    document.getElementById('edit_can_collect_recovery').checked = (mgr.can_collect_recovery === 'yes');
    
    var modal = new bootstrap.Modal(document.getElementById('editManagerModal'));
    modal.show();
}
</script>

<?php require_once 'footer.php'; ?>
