<?php
require_once 'header.php';

// Handle Delete or Status Change
$flashMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $delId = (int)$_POST['delete_id'];
        try {
            $pdo->beginTransaction();

            // 1. Fetch all subscribers of this operator to remove from RADIUS
            $subStmt = $pdo->prepare("SELECT username FROM subscribers WHERE client_id = ?");
            $subStmt->execute([$delId]);
            $subs = $subStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($subs as $sub) {
                $u = $sub['username'];
                // Delete from FreeRADIUS tables
                $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
                $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
                $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$u]);
            }

            // 2. Fetch all packages to remove from RADIUS (radgroupreply, etc)
            $pkgStmt = $pdo->prepare("SELECT name FROM packages WHERE client_id = ?");
            $pkgStmt->execute([$delId]);
            $pkgs = $pkgStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($pkgs as $pkg) {
                $p = $pkg['name'];
                $pdo->prepare("DELETE FROM radgroupreply WHERE groupname = ?")->execute([$p]);
                $pdo->prepare("DELETE FROM radgroupcheck WHERE groupname = ?")->execute([$p]);
            }

            // 3. Delete from application tables
            $dealerStmt = $pdo->prepare("SELECT id FROM dealers WHERE client_id = ?");
            $dealerStmt->execute([$delId]);
            $dealers = $dealerStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dealers as $d) {
                $pdo->prepare("DELETE FROM dealer_notes WHERE dealer_id = ?")->execute([$d['id']]);
            }
            
            // Cascade delete by client_id
            $tables_to_clean = [
                'subscribers',
                'packages',
                'dealers',
                'linemen',
                'recovery_men',
                'nas',
                'user_ledger',
                'activity_logs',
                'fund_requests'
            ];

            foreach ($tables_to_clean as $tbl) {
                try {
                    $pdo->prepare("DELETE FROM `$tbl` WHERE client_id = ?")->execute([$delId]);
                } catch(Exception $e) {}
            }

            // Finally, delete the operator (client)
            $stmt = $pdo->prepare("DELETE FROM clients WHERE id = :id");
            $stmt->execute(['id' => $delId]);

            $pdo->commit();
            $flashMsg = "<div class='alert alert-success border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-check fs-5'></i> <div>Admin and all associated records (Subscribers, RADIUS data, Routers, Dealers) deleted successfully.</div></div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $flashMsg = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-exclamation fs-5'></i> <div>Error deleting admin: " . htmlspecialchars($e->getMessage()) . "</div></div>";
        }
    }

    if (isset($_POST['toggle_status_id'])) {
        $id = (int)$_POST['toggle_status_id'];
        $newStatus = $_POST['new_status'];
        try {
            $pdo->beginTransaction();
            
            // Update operator status
            $stmt = $pdo->prepare("UPDATE clients SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $newStatus, 'id' => $id]);
            
            if ($newStatus === 'suspended') {
                // Block all users in RADIUS
                $subs = $pdo->prepare("SELECT username FROM subscribers WHERE client_id = ?");
                $subs->execute([$id]);
                foreach ($subs->fetchAll() as $sub) {
                    $u = $sub['username'];
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
                }
                $flashMsg = "<div class='alert alert-warning border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-triangle-exclamation fs-5'></i> <div>Admin suspended. All user authentications and sub-portals are now blocked.</div></div>";
            } else {
                // Unblock users in RADIUS (except individually disabled)
                $subs = $pdo->prepare("SELECT username FROM subscribers WHERE client_id = ? AND status != 'disabled'");
                $subs->execute([$id]);
                foreach ($subs->fetchAll() as $sub) {
                    $u = $sub['username'];
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'")->execute([$u]);
                }
                $flashMsg = "<div class='alert alert-success border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-check fs-5'></i> <div>Admin activated. Sub-portals and active subscribers restored.</div></div>";
            }
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $flashMsg = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2'><i class='fa-solid fa-circle-exclamation fs-5'></i> <div>Error toggling status: " . htmlspecialchars($e->getMessage()) . "</div></div>";
        }
    }
}

try {
    $stmt = $pdo->query("SELECT * FROM clients ORDER BY id DESC");
    $operators = $stmt->fetchAll();
} catch (PDOException $e) {
    die("<div class='alert alert-danger m-4'>Error loading admins.</div>");
}

// Compute metrics
$totalOps = count($operators);
$activeOps = 0;
$suspendedOps = 0;
$totalSubCapacity = 0;
$totalRouterCapacity = 0;

foreach ($operators as $op) {
    if ($op['status'] === 'active') {
        $activeOps++;
    } else {
        $suspendedOps++;
    }
    $totalSubCapacity += (int)($op['max_subscribers'] ?? 0);
    $totalRouterCapacity += (int)($op['max_routers'] ?? 0);
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
    .btn-action-impersonate:hover {
        background: #eff6ff;
        border-color: #2563eb;
        color: #2563eb;
    }
    .btn-action-edit:hover {
        background: #f1f5f9;
        border-color: #475569;
        color: #0f172a;
    }
    .btn-action-power:hover {
        background: #fefce8;
        border-color: #eab308;
        color: #ca8a04;
    }
    .btn-action-delete:hover {
        background: #fef2f2;
        border-color: #ef4444;
        color: #dc2626;
    }
</style>

<div class="container-fluid px-4 py-4">

    <?php if(!empty($flashMsg)) echo $flashMsg; ?>

    <!-- Top Capsule Header -->
    <div class="page-header-card">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                    <i class="fa-solid fa-users-gear"></i> Multi-Tenant Administration
                </div>
                <h3 class="fw-bold text-dark mb-1">Manage Admins (ISPs)</h3>
                <p class="text-muted small mb-0">Configure tenant portals, manage system limits, toggle service suspension, or log in directly to tenant portals.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                </a>
                <a href="admin_add.php" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm">
                    <i class="fa-solid fa-plus me-1"></i> Add New Admin
                </a>
            </div>
        </div>
    </div>

    <!-- 4 Stat Summary Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Operators -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-building-user"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Total Admins</div>
                    <div class="stat-pill-val"><?= number_format($totalOps) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Active & registered tenants</div>
                </div>
            </div>
        </div>

        <!-- 2. Active Operators -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #f0fdf4; color: #16a34a;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Active Tenants</div>
                    <div class="stat-pill-val"><?= number_format($activeOps) ?></div>
                    <div class="text-success small fw-semibold" style="font-size: 0.74rem;"><i class="fa-solid fa-wifi"></i> Live Service Access</div>
                </div>
            </div>
        </div>

        <!-- 3. Suspended Operators -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fefce8; color: #ca8a04;">
                    <i class="fa-solid fa-pause"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Suspended</div>
                    <div class="stat-pill-val"><?= number_format($suspendedOps) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Blocked sub-portals & users</div>
                </div>
            </div>
        </div>

        <!-- 4. Total Capacity Allocated -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fdf4ff; color: #a855f7;">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Allocated Capacity</div>
                    <div class="stat-pill-val"><?= number_format($totalSubCapacity) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;"><?= $totalRouterCapacity ?> Total Routers Allowed</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Pills Row -->
    <div class="d-flex align-items-center gap-2 mb-3 overflow-x-auto pb-1">
        <button type="button" class="filter-pill-btn active" onclick="filterOperators('all', this)">
            <span>All Admins</span>
            <span class="badge-count"><?= $totalOps ?></span>
        </button>
        <button type="button" class="filter-pill-btn" onclick="filterOperators('active', this)">
            <span><i class="fa-solid fa-circle text-success me-1" style="font-size: 0.6rem;"></i> Active</span>
            <span class="badge-count"><?= $activeOps ?></span>
        </button>
        <button type="button" class="filter-pill-btn" onclick="filterOperators('suspended', this)">
            <span><i class="fa-solid fa-circle text-warning me-1" style="font-size: 0.6rem;"></i> Suspended</span>
            <span class="badge-count"><?= $suspendedOps ?></span>
        </button>
    </div>

    <!-- Operators Card & Table -->
    <div class="card-ui overflow-hidden mb-5">
        
        <!-- Loading Dialog State -->
        <div id="operatorsLoadingBox" class="text-center py-5">
            <div class="spinner-border text-primary mb-3" style="width: 2.75rem; height: 2.75rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h6 class="fw-bold text-dark mb-1">Loading Admins Directory...</h6>
            <div class="text-muted small">Synchronizing tenant records and service quotas</div>
        </div>

        <!-- Hidden Table Container until DataTables initComplete -->
        <div id="operatorsTableContainer" style="display: none;">
            <div class="table-responsive">
                <table class="modern-table" id="operatorsTable">
                    <thead>
                        <tr>
                            <th class="ps-4">Admin / Tenant</th>
                            <th>Contact & Email</th>
                            <th>Status</th>
                            <th>Service Limits</th>
                            <th>Subscription Expiry</th>
                            <th class="pe-4 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($operators as $op): 
                            $status = strtolower($op['status'] ?? 'active');
                        ?>
                        <tr class="operator-row" data-status="<?= $status ?>">
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle bg-light text-primary fw-bold d-flex align-items-center justify-content-center border" style="width: 40px; height: 40px; font-size: 0.95rem; flex-shrink: 0;">
                                        <?= strtoupper(substr($op['company_name'] ?: 'O', 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($op['company_name']) ?></div>
                                        <div class="text-muted font-monospace" style="font-size: 0.72rem;">Tenant ID: #<?= $op['id'] ?></div>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <div class="text-dark small fw-semibold">
                                    <i class="fa-solid fa-envelope text-muted me-1 small"></i> <?= htmlspecialchars($op['email']) ?>
                                </div>
                                <?php if(!empty($op['phone'])): ?>
                                <div class="text-muted" style="font-size: 0.76rem;">
                                    <i class="fa-solid fa-phone text-muted me-1 small"></i> <?= htmlspecialchars($op['phone']) ?>
                                </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if($status === 'active'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">
                                        <i class="fa-solid fa-circle-check me-1"></i>Active
                                    </span>
                                <?php elseif($status === 'suspended'): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">
                                        <i class="fa-solid fa-pause me-1"></i>Suspended
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">
                                        <i class="fa-solid fa-triangle-exclamation me-1"></i>Expired
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="d-flex gap-2">
                                    <span class="badge bg-light text-dark border rounded-pill px-2 py-1 font-monospace" style="font-size: 0.75rem;" title="Max Routers">
                                        <i class="fa-solid fa-server text-primary me-1"></i><?= $op['max_routers'] ?> NAS
                                    </span>
                                    <span class="badge bg-light text-dark border rounded-pill px-2 py-1 font-monospace" style="font-size: 0.75rem;" title="Max Subscribers">
                                        <i class="fa-solid fa-users text-success me-1"></i><?= $op['max_subscribers'] ?> Users
                                    </span>
                                </div>
                            </td>

                            <td>
                                <?php if($op['expiry_date']): ?>
                                    <div class="text-dark small fw-semibold"><?= date('d M Y', strtotime($op['expiry_date'])) ?></div>
                                    <div class="text-muted" style="font-size: 0.72rem;">Renewal Date</div>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border rounded-pill px-2 py-1">Lifetime / No Expiry</span>
                                <?php endif; ?>
                            </td>

                            <td class="pe-4 text-end">
                                <div class="d-inline-flex gap-1 align-items-center">
                                    
                                    <!-- Impersonate Form -->
                                    <form method="POST" action="impersonate.php" class="d-inline m-0">
                                        <input type="hidden" name="client_id" value="<?= $op['id'] ?>">
                                        <button type="submit" class="btn-action-icon btn-action-impersonate" title="Login as Admin (Impersonate)">
                                            <i class="fa-solid fa-user-secret"></i>
                                        </button>
                                    </form>

                                    <!-- Edit Button -->
                                    <a href="admin_edit.php?id=<?= $op['id'] ?>" class="btn-action-icon btn-action-edit" title="Edit Admin Profile">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>

                                    <!-- Toggle Status Form -->
                                    <form method="POST" class="d-inline m-0" onsubmit="return confirm('Change status for this admin? This will toggle login and RADIUS access.');">
                                        <input type="hidden" name="toggle_status_id" value="<?= $op['id'] ?>">
                                        <input type="hidden" name="new_status" value="<?= $status === 'active' ? 'suspended' : 'active' ?>">
                                        <button type="submit" class="btn-action-icon btn-action-power" title="Toggle Active / Suspended">
                                            <i class="fa-solid fa-power-off"></i>
                                        </button>
                                    </form>

                                    <!-- Delete Form -->
                                    <form method="POST" class="d-inline m-0" onsubmit="return confirm('DANGER: Are you sure you want to delete <?= htmlspecialchars($op['company_name']) ?>? All associated subscribers, routers, and data will be permanently purged!');">
                                        <input type="hidden" name="delete_id" value="<?= $op['id'] ?>">
                                        <button type="submit" class="btn-action-icon btn-action-delete" title="Delete Admin">
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
        </div>

    </div>

</div>

<!-- DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
var opTable;

$(document).ready(function() {
    opTable = $('#operatorsTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'desc']],
        responsive: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search admins, email, id...",
            lengthMenu: "Show _MENU_ entries"
        },
        initComplete: function() {
            $('#operatorsLoadingBox').fadeOut(120, function() {
                $('#operatorsTableContainer').fadeIn(180, function() {
                    opTable.columns.adjust().draw();
                });
            });
        }
    });
});

function filterOperators(status, btnElement) {
    $('.filter-pill-btn').removeClass('active');
    $(btnElement).addClass('active');

    if (status === 'all') {
        opTable.column(2).search('').draw();
    } else if (status === 'active') {
        opTable.column(2).search('Active').draw();
    } else if (status === 'suspended') {
        opTable.column(2).search('Suspended').draw();
    }
}
</script>

<?php require_once 'footer.php'; ?>
