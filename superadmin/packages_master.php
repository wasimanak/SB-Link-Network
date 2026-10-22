<?php
require_once 'header.php';
require_once '../config/speed_scheduler_helper.php';

// Auto-upgrade schema for Master/Child packages & scheduler
try {
    $pdo->exec("ALTER TABLE packages ADD COLUMN is_master TINYINT(1) DEFAULT 0");
    $pdo->exec("ALTER TABLE packages ADD COLUMN master_id INT(11) DEFAULT NULL");
    $pdo->exec("ALTER TABLE packages ADD COLUMN speed_scheduler TEXT DEFAULT NULL");
    // Convert old global packages to master
    $pdo->exec("UPDATE packages SET is_master = 1 WHERE client_id = 0 AND is_master = 0");
} catch (PDOException $e) {}

// Fetch all operators for dropdowns
$opStmt = $pdo->query("SELECT id, company_name as username FROM clients ORDER BY company_name ASC");
$operators = $opStmt->fetchAll(PDO::FETCH_ASSOC);

$flashMsg = '';

// Handle Add / Edit / Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        try {
            if ($action === 'add' || $action === 'edit') {
                $name = trim($_POST['name']);
                
                // Advanced Speed Options
                $rate_up = normalize_single_speed($_POST['rate_up'] ?? '');
                $rate_down = normalize_single_speed($_POST['rate_down'] ?? '');
                if (strcasecmp($rate_up, 'Unlimited') === 0 && strcasecmp($rate_down, 'Unlimited') === 0) {
                    $rate_limit = 'Unlimited';
                } elseif (empty($rate_up) || empty($rate_down)) {
                    $rate_limit = 'Unlimited';
                } else {
                    $rate_limit = $rate_up . '/' . $rate_down;
                }

                $scheduler = null;
                if (!empty($_POST['sched_start']) && !empty($_POST['sched_end']) && (!empty($_POST['sched_up']) || !empty($_POST['sched_down']))) {
                    $s_up = normalize_single_speed($_POST['sched_up'] ?? $rate_up);
                    $s_down = normalize_single_speed($_POST['sched_down'] ?? $rate_down);
                    $sched_array = [
                        'start_time' => trim($_POST['sched_start']),
                        'end_time'   => trim($_POST['sched_end']),
                        'speed'      => $s_up . '/' . $s_down
                    ];
                    $scheduler = json_encode($sched_array);
                }

                $validity_days = (int)$_POST['validity_days'];
                $price = (float)$_POST['price'];
                $data_limit_gb = empty($_POST['data_limit_gb']) ? 0 : (int)$_POST['data_limit_gb'];
                $operator_ids = $_POST['operator_ids'] ?? [];

                $is_global = in_array('0', $operator_ids);
                
                if ($action === 'add') {
                    // Insert Master Package
                    $stmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb, is_master, speed_scheduler) VALUES (0, :name, :rate_limit, :validity, :price, :data_limit, 1, :scheduler)");
                    $stmt->execute([
                        'name' => $name,
                        'rate_limit' => $rate_limit,
                        'validity' => $validity_days,
                        'price' => $price,
                        'data_limit' => $data_limit_gb,
                        'scheduler' => $scheduler
                    ]);
                    
                    $master_id = $pdo->lastInsertId();

                    // If not global, insert child packages for selected operators
                    if (!$is_global && !empty($operator_ids)) {
                        $childStmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb, is_master, master_id, speed_scheduler) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                        foreach ($operator_ids as $op_id) {
                            if ($op_id > 0) {
                                $childStmt->execute([$op_id, $name, $rate_limit, $validity_days, $price, $data_limit_gb, $master_id, $scheduler]);
                            }
                        }
                    }
                    $flashMsg = "<div class='alert alert-success border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-check fs-5'></i> <div>Master package created and distributed successfully.</div></div>";
                    
                } elseif ($action === 'edit') {
                    $master_id = (int)$_POST['package_id'];

                    // Update Master Package (Force is_master = 1)
                    $stmt = $pdo->prepare("UPDATE packages SET is_master = 1, name = :name, rate_limit = :rate_limit, validity_days = :validity, price = :price, data_limit_gb = :data_limit, speed_scheduler = :scheduler WHERE id = :id");
                    $stmt->execute([
                        'name' => $name,
                        'rate_limit' => $rate_limit,
                        'validity' => $validity_days,
                        'price' => $price,
                        'data_limit' => $data_limit_gb,
                        'scheduler' => $scheduler,
                        'id' => $master_id
                    ]);

                    // Sync Child Packages
                    if ($is_global) {
                        // Delete all children because it's now global
                        $pdo->prepare("DELETE FROM packages WHERE master_id = ?")->execute([$master_id]);
                    } else {
                        // Update existing children's details
                        $updateChild = $pdo->prepare("UPDATE packages SET name = ?, rate_limit = ?, validity_days = ?, price = ?, data_limit_gb = ?, speed_scheduler = ? WHERE master_id = ?");
                        $updateChild->execute([$name, $rate_limit, $validity_days, $price, $data_limit_gb, $scheduler, $master_id]);
                        
                        // Fetch currently assigned operators
                        $currStmt = $pdo->prepare("SELECT client_id FROM packages WHERE master_id = ?");
                        $currStmt->execute([$master_id]);
                        $current_ops = $currStmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        $new_ops = array_map('intval', $operator_ids);
                        
                        // Delete removed operators
                        $removed_ops = array_diff($current_ops, $new_ops);
                        if (!empty($removed_ops)) {
                            $placeholders = implode(',', array_fill(0, count($removed_ops), '?'));
                            $delParams = array_merge([$master_id], $removed_ops);
                            $pdo->prepare("DELETE FROM packages WHERE master_id = ? AND client_id IN ($placeholders)")->execute($delParams);
                        }
                        
                        // Add new operators
                        $added_ops = array_diff($new_ops, $current_ops);
                        if (!empty($added_ops)) {
                            $childStmt = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price, data_limit_gb, is_master, master_id, speed_scheduler) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                            foreach ($added_ops as $op_id) {
                                if ($op_id > 0) {
                                    $childStmt->execute([$op_id, $name, $rate_limit, $validity_days, $price, $data_limit_gb, $master_id, $scheduler]);
                                }
                            }
                        }
                    }
                    $flashMsg = "<div class='alert alert-success border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-check fs-5'></i> <div>Master package updated and synced with tenant operators successfully.</div></div>";
                }
            } elseif ($action === 'delete') {
                $id = (int)$_POST['delete_id'];
                // Delete master and all children
                $pdo->prepare("DELETE FROM packages WHERE id = ? OR master_id = ?")->execute([$id, $id]);
                $flashMsg = "<div class='alert alert-success border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-check fs-5'></i> <div>Master package and all associated tenant packages deleted successfully.</div></div>";
            }
        } catch (PDOException $e) {
            $flashMsg = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-exclamation fs-5'></i> <div>Error: " . htmlspecialchars($e->getMessage()) . "</div></div>";
        }
    }
}

// Fetch Master & Operator Packages
try {
    $stmt = $pdo->query("SELECT p.*, c.company_name as owner_name FROM packages p LEFT JOIN clients c ON p.client_id = c.id WHERE p.is_master = 1 OR p.master_id IS NULL ORDER BY p.id DESC");
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch Assignments
    $assignedStmt = $pdo->query("SELECT p.master_id, c.id, c.company_name as username FROM packages p JOIN clients c ON p.client_id = c.id WHERE p.master_id IS NOT NULL");
    $assignments = [];
    while($row = $assignedStmt->fetch(PDO::FETCH_ASSOC)) {
        $assignments[$row['master_id']][] = $row;
    }
} catch (PDOException $e) {
    die("<div class='alert alert-danger m-4'>Error loading packages: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// Compute metrics
$totalPackages = count($packages);
$countGlobal = 0;
$countAssigned = 0;
$countWithScheduler = 0;

foreach ($packages as $p) {
    $is_global = empty($assignments[$p['id']]) && $p['client_id'] == 0;
    if ($is_global) {
        $countGlobal++;
    } else {
        $countAssigned++;
    }
    if (!empty($p['speed_scheduler'])) {
        $countWithScheduler++;
    }
}
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    body {
        background-color: #f8fafc !important;
        font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
        color: #0f172a !important;
    }

    .card-ui {
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        border: 1px solid #eef2f6;
    }

    /* Page Header */
    .page-header-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        margin-bottom: 24px;
    }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 20px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
    }
    .stat-pill-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .stat-pill-val {
        font-size: 1.45rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
    }
    .stat-pill-lbl {
        font-size: 0.74rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 2px;
    }

    /* Filter Pills */
    .filter-pill-btn {
        background: #ffffff;
        border: 1.5px solid #e2e8f0;
        color: #64748b;
        font-size: 0.85rem;
        font-weight: 600;
        padding: 8px 18px;
        border-radius: 50px;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }
    .filter-pill-btn:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
        color: #0f172a;
    }
    .filter-pill-btn.active {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
    }
    .filter-pill-btn.active .badge-count {
        background: rgba(255, 255, 255, 0.25);
        color: #ffffff;
    }
    .badge-count {
        background: #f1f5f9;
        color: #475569;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 700;
    }

    /* Modern Table */
    .modern-table {
        margin-bottom: 0;
        width: 100% !important;
    }
    .modern-table thead th {
        background: #f8fafc !important;
        color: #475569 !important;
        font-size: 0.74rem !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        font-weight: 700 !important;
        padding: 14px 20px !important;
        border-bottom: 1px solid #e2e8f0 !important;
        white-space: nowrap !important;
    }
    .modern-table tbody td {
        padding: 16px 20px !important;
        color: #1e293b !important;
        font-size: 0.88rem !important;
        border-bottom: 1px solid #f1f5f9 !important;
        vertical-align: middle !important;
    }
    .modern-table tbody tr:hover {
        background-color: #f8fafc !important;
    }
    .modern-table tbody tr:last-child td {
        border-bottom: none !important;
    }

    /* Action Buttons in Table */
    .btn-action-icon {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.88rem;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #475569;
        transition: all 0.15s ease;
    }
    .btn-action-icon:hover {
        transform: translateY(-2px);
    }
    .btn-action-edit:hover {
        background: #eff6ff;
        border-color: #2563eb;
        color: #2563eb;
    }
    .btn-action-delete:hover {
        background: #fef2f2;
        border-color: #ef4444;
        color: #dc2626;
    }

    /* Clean Modal */
    .modal-content.clean-modal {
        background: #ffffff !important;
        color: #0f172a !important;
        border-radius: 24px !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 20px 60px rgba(0,0,0,0.15) !important;
    }
    .clean-modal .modal-header {
        border-bottom: 1px solid #f1f5f9;
        padding: 20px 24px;
    }
    .clean-modal .modal-footer {
        border-top: 1px solid #f1f5f9;
        padding: 16px 24px;
    }
</style>

<div class="container-fluid px-4 py-4">

    <?php if(!empty($flashMsg)) echo $flashMsg; ?>

    <!-- Top Capsule Header -->
    <div class="page-header-card">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                    <i class="fa-solid fa-box-open"></i> Bandwidth Profiles & Master Catalog
                </div>
                <h3 class="fw-bold text-dark mb-1">Master Internet Packages</h3>
                <p class="text-muted small mb-0">Create global speed plans, define daytime & night boost schedulers, and synchronize offerings across all ISP portals.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                </a>
                <button class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addPackageModal">
                    <i class="fa-solid fa-plus me-1"></i> Add Master Package
                </button>
            </div>
        </div>
    </div>

    <!-- 4 Stat Summary Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Master Plans -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-box-archive"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Master Plans</div>
                    <div class="stat-pill-val"><?= number_format($totalPackages) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Active bandwidth profiles</div>
                </div>
            </div>
        </div>

        <!-- 2. Global Broadcast Plans -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #f0fdf4; color: #16a34a;">
                    <i class="fa-solid fa-earth-americas"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Global Broadcast</div>
                    <div class="stat-pill-val"><?= number_format($countGlobal) ?></div>
                    <div class="text-success small fw-semibold" style="font-size: 0.74rem;"><i class="fa-solid fa-circle-check"></i> Available to all ISPs</div>
                </div>
            </div>
        </div>

        <!-- 3. Operator Assigned -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fdf4ff; color: #a855f7;">
                    <i class="fa-solid fa-users-gear"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Custom Assigned</div>
                    <div class="stat-pill-val"><?= number_format($countAssigned) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Specific tenant plans</div>
                </div>
            </div>
        </div>

        <!-- 4. Speed Schedulers Active -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fff7ed; color: #ea580c;">
                    <i class="fa-solid fa-moon"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Night Boost Plans</div>
                    <div class="stat-pill-val"><?= number_format($countWithScheduler) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Automated time schedulers</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Pills Row -->
    <div class="d-flex align-items-center gap-2 mb-3 overflow-x-auto pb-1">
        <button type="button" class="filter-pill-btn active" onclick="filterPackages('all', this)">
            <span>All Packages</span>
            <span class="badge-count"><?= $totalPackages ?></span>
        </button>
        <button type="button" class="filter-pill-btn" onclick="filterPackages('global', this)">
            <span><i class="fa-solid fa-earth-americas text-success me-1" style="font-size: 0.75rem;"></i> Global Plans</span>
            <span class="badge-count"><?= $countGlobal ?></span>
        </button>
        <button type="button" class="filter-pill-btn" onclick="filterPackages('assigned', this)">
            <span><i class="fa-solid fa-users text-primary me-1" style="font-size: 0.75rem;"></i> Tenant Assigned</span>
            <span class="badge-count"><?= $countAssigned ?></span>
        </button>
    </div>

    <!-- Packages Card & Table -->
    <div class="card-ui overflow-hidden mb-5">
        
        <!-- Loading Dialog State -->
        <div id="packagesLoadingBox" class="text-center py-5">
            <div class="spinner-border text-primary mb-3" style="width: 2.75rem; height: 2.75rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h6 class="fw-bold text-dark mb-1">Loading Master Packages...</h6>
            <div class="text-muted small">Synchronizing bandwidth profiles and operator assignments</div>
        </div>

        <!-- Hidden Table Container until DataTables initComplete -->
        <div id="packagesTableContainer" style="display: none;">
            <div class="table-responsive">
                <table class="modern-table" id="packagesTable">
                    <thead>
                        <tr>
                            <th class="ps-4">Package Profile</th>
                            <th>Availability / Assignment</th>
                            <th>Rate Limit Speed</th>
                            <th>Validity</th>
                            <th>Price</th>
                            <th class="pe-4 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($packages as $p): 
                            $is_global = empty($assignments[$p['id']]) && $p['client_id'] == 0; 
                            $is_operator_specific = ($p['client_id'] > 0 && $p['is_master'] == 0);
                            $rowType = $is_global ? 'global' : 'assigned';
                        ?>
                        <tr class="package-row" data-type="<?= $rowType ?>">
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle bg-light text-primary fw-bold d-flex align-items-center justify-content-center border" style="width: 40px; height: 40px; font-size: 0.95rem; flex-shrink: 0;">
                                        <i class="fa-solid fa-box-archive"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($p['name']) ?></div>
                                        <div class="text-muted font-monospace" style="font-size: 0.72rem;">Profile ID: #<?= $p['id'] ?></div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <?php if($is_operator_specific): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">
                                        <i class="fa-solid fa-user-gear me-1"></i> <?= htmlspecialchars($p['owner_name'] ?? 'Operator') ?> (Owner)
                                    </span>
                                <?php elseif($is_global): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">
                                        <i class="fa-solid fa-earth-americas me-1"></i> Global (All Operators)
                                    </span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-1">
                                    <?php if(!empty($assignments[$p['id']])): ?>
                                        <?php foreach($assignments[$p['id']] as $asg): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2 py-1 font-monospace" style="font-size: 0.72rem;">
                                                <i class="fa-solid fa-building me-1"></i> <?= htmlspecialchars($asg['username']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1" style="font-size: 0.72rem;">Not Assigned</span>
                                    <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if(strcasecmp($p['rate_limit'], 'Unlimited') === 0): ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-1">Unlimited Speed</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border rounded-pill px-3 py-1 font-monospace fw-bold" style="font-size: 0.78rem;">
                                            <i class="fa-solid fa-gauge-high text-primary me-1"></i> <?= htmlspecialchars($p['rate_limit']) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if(!empty($p['speed_scheduler'])): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1" title="Night/Time Speed Scheduler Active">
                                            <i class="fa-solid fa-moon"></i> Boost
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <span class="badge bg-light text-dark border rounded-pill px-3 py-1 font-monospace" style="font-size: 0.75rem;">
                                    <i class="fa-regular fa-calendar-check text-muted me-1"></i> <?= $p['validity_days'] ?> Days
                                </span>
                            </td>

                            <td>
                                <div class="fw-bold text-success fs-6">
                                    Rs <?= number_format($p['price'], 0) ?>
                                </div>
                                <?php if($p['data_limit_gb'] > 0): ?>
                                    <div class="text-muted" style="font-size: 0.72rem;"><?= $p['data_limit_gb'] ?> GB Quota</div>
                                <?php else: ?>
                                    <div class="text-muted" style="font-size: 0.72rem;">Unlimited Data</div>
                                <?php endif; ?>
                            </td>

                            <td class="pe-4 text-end">
                                <div class="d-inline-flex gap-1 align-items-center">
                                    
                                    <!-- Edit Trigger -->
                                    <button type="button" class="btn-action-icon btn-action-edit" data-bs-toggle="modal" data-bs-target="#editPackageModal<?= $p['id'] ?>" title="Edit Master Plan">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>

                                    <!-- Delete Form -->
                                    <form method="POST" class="d-inline m-0" onsubmit="return confirm('DANGER: Delete master package #<?= $p['id'] ?> (<?= htmlspecialchars($p['name']) ?>)? This will also remove it from all assigned operators!');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn-action-icon btn-action-delete" title="Delete Package">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>

                                </div>

                                <!-- Edit Package Modal -->
                                <div class="modal fade" id="editPackageModal<?= $p['id'] ?>" tabindex="-1" aria-hidden="true">
                                  <div class="modal-dialog modal-lg modal-dialog-centered">
                                    <div class="modal-content clean-modal text-start">
                                      <div class="modal-header">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                                <i class="fa-solid fa-pen"></i>
                                            </div>
                                            <h5 class="modal-title fw-bold text-dark">Edit Master Package #<?= $p['id'] ?></h5>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                      </div>

                                      <form method="POST">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="package_id" value="<?= $p['id'] ?>">
                                        <div class="modal-body p-4">
                                            
                                            <!-- Operator Assignment Checklist -->
                                            <div class="mb-4">
                                                <label class="form-label fw-bold text-dark small text-uppercase mb-1">Assign Availability</label>
                                                <div class="small text-muted mb-2">Check operators allowed to sell or renew this package:</div>
                                                <div class="border rounded-4 p-3 bg-light shadow-sm" style="max-height: 200px; overflow-y: auto;">
                                                    <div class="form-check border-bottom pb-2 mb-2">
                                                        <input class="form-check-input" type="checkbox" name="operator_ids[]" value="0" id="op_global_edit_<?= $p['id'] ?>" <?= $is_global ? 'checked' : '' ?>>
                                                        <label class="form-check-label fw-bold text-success" for="op_global_edit_<?= $p['id'] ?>">
                                                            <i class="fa-solid fa-earth-americas me-1"></i> Global (Available to All Operators)
                                                        </label>
                                                    </div>
                                                    <?php foreach($operators as $op): 
                                                        $selected = '';
                                                        if (!empty($assignments[$p['id']])) {
                                                            foreach($assignments[$p['id']] as $asg) {
                                                                if($asg['id'] == $op['id']) $selected = 'checked';
                                                            }
                                                        }
                                                        if ($p['client_id'] == $op['id']) {
                                                            $selected = 'checked';
                                                        }
                                                    ?>
                                                        <div class="form-check mb-1">
                                                            <input class="form-check-input" type="checkbox" name="operator_ids[]" value="<?= $op['id'] ?>" id="op_edit_<?= $p['id'] ?>_<?= $op['id'] ?>" <?= $selected ?>>
                                                            <label class="form-check-label fw-semibold text-dark" for="op_edit_<?= $p['id'] ?>_<?= $op['id'] ?>">
                                                                <?= htmlspecialchars($op['username']) ?>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Package Name <span class="text-danger">*</span></label>
                                                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($p['name']) ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Price (Rs) <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                                                        <input type="number" step="1" name="price" class="form-control" value="<?= $p['price'] ?>" required>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Validity (Days) <span class="text-danger">*</span></label>
                                                    <input type="number" name="validity_days" class="form-control" value="<?= $p['validity_days'] ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Data Limit (GB)</label>
                                                    <input type="number" name="data_limit_gb" class="form-control" value="<?= $p['data_limit_gb'] ?>">
                                                    <small class="text-muted" style="font-size: 0.72rem;">Set <strong class="text-success">0</strong> for Unlimited Bandwidth</small>
                                                </div>
                                            </div>
                                            
                                            <?php 
                                            // Parse existing rate limit (e.g. 10M/10M)
                                            $r_up = ''; $r_down = '';
                                            if(strcasecmp($p['rate_limit'], 'Unlimited') !== 0 && strpos($p['rate_limit'], '/') !== false) {
                                                list($r_up, $r_down) = explode('/', $p['rate_limit']);
                                            } elseif(strcasecmp($p['rate_limit'], 'Unlimited') !== 0) {
                                                $r_up = $p['rate_limit']; $r_down = $p['rate_limit'];
                                            } else {
                                                $r_up = 'Unlimited'; $r_down = 'Unlimited';
                                            }

                                            // Parse existing scheduler
                                            $s_start = ''; $s_end = ''; $s_up = ''; $s_down = '';
                                            if(!empty($p['speed_scheduler'])) {
                                                $s_data = json_decode($p['speed_scheduler'], true);
                                                if($s_data) {
                                                    $s_start = $s_data['start_time'] ?? '';
                                                    $s_end = $s_data['end_time'] ?? '';
                                                    if(isset($s_data['speed']) && strpos($s_data['speed'], '/') !== false) {
                                                        list($s_up, $s_down) = explode('/', $s_data['speed']);
                                                    }
                                                }
                                            }
                                            ?>

                                            <div class="col-12 mt-4">
                                                <div class="p-3 rounded-4 border bg-light">
                                                    <label class="form-label fw-bold text-dark mb-2"><i class="fa-solid fa-gauge-high text-primary me-2"></i>Standard Speed Rate Limit</label>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted mb-1">Upload Speed</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text bg-white border text-danger"><i class="fa-solid fa-upload"></i></span>
                                                                <input type="text" name="rate_up" class="form-control" value="<?= htmlspecialchars($r_up) ?>" placeholder="e.g. 10M" required>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted mb-1">Download Speed</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text bg-white border text-success"><i class="fa-solid fa-download"></i></span>
                                                                <input type="text" name="rate_down" class="form-control" value="<?= htmlspecialchars($r_down) ?>" placeholder="e.g. 10M" required>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-12 mt-3">
                                                <div class="p-3 rounded-4 border" style="background: #fffbeb;">
                                                    <h6 class="text-warning-emphasis mb-2 fw-bold"><i class="fa-regular fa-clock me-2"></i> Night / Time Speed Scheduler (Optional)</h6>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted mb-1">Start Time</label>
                                                            <input type="time" name="sched_start" class="form-control" value="<?= htmlspecialchars($s_start) ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted mb-1">End Time</label>
                                                            <input type="time" name="sched_end" class="form-control" value="<?= htmlspecialchars($s_end) ?>">
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted mb-1">Night Upload Speed</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text bg-white border text-danger"><i class="fa-solid fa-upload"></i></span>
                                                                <input type="text" name="sched_up" class="form-control" placeholder="e.g. 20M" value="<?= htmlspecialchars($s_up) ?>">
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label small text-muted mb-1">Night Download Speed</label>
                                                            <div class="input-group">
                                                                <span class="input-group-text bg-white border text-success"><i class="fa-solid fa-download"></i></span>
                                                                <input type="text" name="sched_down" class="form-control" placeholder="e.g. 20M" value="<?= htmlspecialchars($s_down) ?>">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>
                                        <div class="modal-footer d-flex justify-content-between">
                                          <button type="button" class="btn btn-light px-4 rounded-pill fw-semibold" data-bs-dismiss="modal">Cancel</button>
                                          <button type="submit" class="btn btn-primary px-4 rounded-pill fw-bold shadow-sm">
                                            <i class="fa-solid fa-check me-1"></i> Save Changes
                                          </button>
                                        </div>
                                      </form>
                                    </div>
                                  </div>
                                </div>
                                <!-- End Edit Package Modal -->

                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<!-- Add Package Modal -->
<div class="modal fade" id="addPackageModal" tabindex="-1" aria-labelledby="addPackageModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content clean-modal">
      <div class="modal-header">
        <div class="d-flex align-items-center gap-2">
            <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                <i class="fa-solid fa-box-open"></i>
            </div>
            <h5 class="modal-title fw-bold text-dark" id="addPackageModalLabel">Add New Master Package</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="modal-body p-4">
            
            <div class="mb-4">
                <label class="form-label fw-bold text-dark small text-uppercase mb-1">Assign Availability</label>
                <div class="small text-muted mb-2">Check the operators allowed to distribute this package:</div>
                <div class="border rounded-4 p-3 bg-light shadow-sm" style="max-height: 200px; overflow-y: auto;">
                    <div class="form-check border-bottom pb-2 mb-2">
                        <input class="form-check-input" type="checkbox" name="operator_ids[]" value="0" id="op_global_add" checked>
                        <label class="form-check-label fw-bold text-success" for="op_global_add">
                            <i class="fa-solid fa-earth-americas me-1"></i> Global (Available to All Operators)
                        </label>
                    </div>
                    <?php foreach($operators as $op): ?>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" name="operator_ids[]" value="<?= $op['id'] ?>" id="op_add_<?= $op['id'] ?>">
                            <label class="form-check-label fw-semibold text-dark" for="op_add_<?= $op['id'] ?>">
                                <?= htmlspecialchars($op['username']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Package Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. 15 Mbps Fast Fiber" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Price (Rs) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                        <input type="number" step="1" name="price" class="form-control" placeholder="e.g. 1800" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Validity (Days) <span class="text-danger">*</span></label>
                    <input type="number" name="validity_days" class="form-control" placeholder="30" value="30" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold text-muted small text-uppercase mb-1">Data Limit (GB)</label>
                    <input type="number" name="data_limit_gb" class="form-control" value="0">
                    <small class="text-muted" style="font-size: 0.72rem;">Set <strong class="text-success">0</strong> for Unlimited Bandwidth</small>
                </div>
            </div>

            <div class="col-12 mt-4">
                <div class="p-3 rounded-4 border bg-light">
                    <label class="form-label fw-bold text-dark mb-2"><i class="fa-solid fa-gauge-high text-primary me-2"></i>Standard Speed Rate Limit</label>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Upload Speed</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border text-danger"><i class="fa-solid fa-upload"></i></span>
                                <input type="text" name="rate_up" class="form-control" placeholder="e.g. 15M" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Download Speed</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border text-success"><i class="fa-solid fa-download"></i></span>
                                <input type="text" name="rate_down" class="form-control" placeholder="e.g. 15M" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 mt-3">
                <div class="p-3 rounded-4 border" style="background: #fffbeb;">
                    <h6 class="text-warning-emphasis mb-2 fw-bold"><i class="fa-regular fa-clock me-2"></i> Night / Time Speed Scheduler (Optional)</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Start Time</label>
                            <input type="time" name="sched_start" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">End Time</label>
                            <input type="time" name="sched_end" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Night Upload Speed</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border text-danger"><i class="fa-solid fa-upload"></i></span>
                                <input type="text" name="sched_up" class="form-control" placeholder="e.g. 30M">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted mb-1">Night Download Speed</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border text-success"><i class="fa-solid fa-download"></i></span>
                                <input type="text" name="sched_down" class="form-control" placeholder="e.g. 30M">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn btn-light px-4 rounded-pill fw-semibold" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 rounded-pill fw-bold shadow-sm">
            <i class="fa-solid fa-plus me-1"></i> Create Master Package
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
var pkgTable;

$(document).ready(function() {
    pkgTable = $('#packagesTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'desc']],
        responsive: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search packages, speeds, tenant...",
            lengthMenu: "Show _MENU_ entries"
        },
        initComplete: function() {
            $('#packagesLoadingBox').fadeOut(120, function() {
                $('#packagesTableContainer').fadeIn(180, function() {
                    pkgTable.columns.adjust().draw();
                });
            });
        }
    });
});

function filterPackages(type, btnElement) {
    $('.filter-pill-btn').removeClass('active');
    $(btnElement).addClass('active');

    if (type === 'all') {
        pkgTable.column(1).search('').draw();
    } else if (type === 'global') {
        pkgTable.column(1).search('Global').draw();
    } else if (type === 'assigned') {
        pkgTable.column(1).search('^(?!.*Global).*$', true, false).draw();
    }
}
</script>

<?php require_once 'footer.php'; ?>