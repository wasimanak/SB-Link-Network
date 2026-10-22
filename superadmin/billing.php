<?php
require_once 'header.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_balance') {
        $client_id = (int)$_POST['client_id'];
        $amount = (float)$_POST['amount'];
        $notes = trim($_POST['notes']);
        
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE clients SET balance = balance + ? WHERE id = ?")->execute([$amount, $client_id]);
            $pdo->commit();
            $success = "Balance of Rs " . number_format($amount, 0) . " successfully added to operator wallet.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error adding balance: " . htmlspecialchars($e->getMessage());
        }
    }
}

// Fetch all operators
$operators = $pdo->query("SELECT id, company_name, phone, status, balance, expiry_date FROM clients ORDER BY company_name")->fetchAll();

// Metrics
$totalTenants = count($operators);
$totalBalanceHeld = 0;
$activeCount = 0;
$suspendedCount = 0;

foreach ($operators as $op) {
    $totalBalanceHeld += (float)($op['balance'] ?? 0);
    if ($op['status'] === 'active') {
        $activeCount++;
    } else {
        $suspendedCount++;
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

    <!-- Flash Messages -->
    <?php if($success): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-check fs-5"></i> <div><?= $success ?></div>
        </div>
    <?php endif; ?>
    <?php if($error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4 d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-exclamation fs-5"></i> <div><?= $error ?></div>
        </div>
    <?php endif; ?>

    <!-- Top Capsule Header -->
    <div class="page-header-card">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                    <i class="fa-solid fa-file-invoice-dollar"></i> Finance & Tenant Ledger
                </div>
                <h3 class="fw-bold text-dark mb-1">Billing & Tenant Balances</h3>
                <p class="text-muted small mb-0">Monitor ISP tenant pre-paid wallets, credit balances, and manage recurring subscription renewals.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                </a>
                <a href="operators.php" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm">
                    <i class="fa-solid fa-users me-1"></i> View Operators
                </a>
            </div>
        </div>
    </div>

    <!-- 3 Stat Summary Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Tenant Wallet Balance -->
        <div class="col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #f0fdf4; color: #16a34a;">
                    <i class="fa-solid fa-wallet"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Total Operator Balances</div>
                    <div class="stat-pill-val">Rs <?= number_format($totalBalanceHeld, 0) ?></div>
                    <div class="text-success small fw-semibold" style="font-size: 0.74rem;"><i class="fa-solid fa-circle-check"></i> Aggregate Pre-paid Funds</div>
                </div>
            </div>
        </div>

        <!-- 2. Active Accounts -->
        <div class="col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Active Tenants</div>
                    <div class="stat-pill-val"><?= number_format($activeCount) ?> / <?= number_format($totalTenants) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Active subscription accounts</div>
                </div>
            </div>
        </div>

        <!-- 3. Suspended Accounts -->
        <div class="col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fefce8; color: #ca8a04;">
                    <i class="fa-solid fa-pause"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Suspended Portals</div>
                    <div class="stat-pill-val"><?= number_format($suspendedCount) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Zero or blocked access</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Pills Row -->
    <div class="d-flex align-items-center gap-2 mb-3 overflow-x-auto pb-1">
        <button type="button" class="filter-pill-btn active" onclick="filterBilling('all', this)">
            <span>All Tenants</span>
            <span class="badge-count"><?= $totalTenants ?></span>
        </button>
        <button type="button" class="filter-pill-btn" onclick="filterBilling('active', this)">
            <span><i class="fa-solid fa-circle text-success me-1" style="font-size: 0.6rem;"></i> Active</span>
            <span class="badge-count"><?= $activeCount ?></span>
        </button>
        <button type="button" class="filter-pill-btn" onclick="filterBilling('suspended', this)">
            <span><i class="fa-solid fa-circle text-warning me-1" style="font-size: 0.6rem;"></i> Suspended</span>
            <span class="badge-count"><?= $suspendedCount ?></span>
        </button>
    </div>

    <!-- Billing Table Card -->
    <div class="card-ui overflow-hidden mb-5">
        
        <!-- Loading Dialog State -->
        <div id="billingLoadingBox" class="text-center py-5">
            <div class="spinner-border text-primary mb-3" style="width: 2.75rem; height: 2.75rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h6 class="fw-bold text-dark mb-1">Loading Tenant Balances...</h6>
            <div class="text-muted small">Synchronizing operator wallets and renewal cycles</div>
        </div>

        <!-- Hidden Table Container until DataTables initComplete -->
        <div id="billingTableContainer" style="display: none;">
            <div class="table-responsive">
                <table class="modern-table" id="billingTable">
                    <thead>
                        <tr>
                            <th class="ps-4">ISP Company / Tenant</th>
                            <th>Status</th>
                            <th>Current Wallet Balance</th>
                            <th>Subscription Expiry</th>
                            <th class="pe-4 text-end">Quick Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($operators as $op): 
                            $status = strtolower($op['status'] ?? 'active');
                        ?>
                        <tr class="billing-row" data-status="<?= $status ?>">
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
                                <?php if($status === 'active'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">
                                        <i class="fa-solid fa-circle-check me-1"></i>Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">
                                        <i class="fa-solid fa-pause me-1"></i>Suspended
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="fw-bold text-primary fs-6">
                                    Rs <?= number_format($op['balance'], 0) ?>
                                </div>
                                <div class="text-muted" style="font-size: 0.72rem;">Pre-paid balance</div>
                            </td>

                            <td>
                                <?php if($op['expiry_date']): ?>
                                    <div class="text-dark small fw-semibold"><?= date('d M Y', strtotime($op['expiry_date'])) ?></div>
                                    <div class="text-muted" style="font-size: 0.72rem;">Renewal Cycle</div>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border rounded-pill px-2 py-1">Lifetime / No Expiry</span>
                                <?php endif; ?>
                            </td>

                            <td class="pe-4 text-end">
                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#fundModal<?= $op['id'] ?>">
                                    <i class="fa-solid fa-plus me-1"></i> Add Funds
                                </button>

                                <!-- Fund Modal -->
                                <div class="modal fade" id="fundModal<?= $op['id'] ?>" tabindex="-1" aria-hidden="true">
                                  <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content clean-modal text-start">
                                      <div class="modal-header">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                                <i class="fa-solid fa-wallet"></i>
                                            </div>
                                            <h5 class="modal-title fw-bold text-dark">Credit Funds: <?= htmlspecialchars($op['company_name']) ?></h5>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                      </div>
                                      
                                      <form method="POST">
                                        <div class="modal-body p-4">
                                            <input type="hidden" name="action" value="add_balance">
                                            <input type="hidden" name="client_id" value="<?= $op['id'] ?>">
                                            
                                            <div class="mb-3">
                                                <label class="form-label text-dark fw-bold small mb-1">Credit Amount (Rs) <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                                                    <input type="number" step="1" name="amount" class="form-control" placeholder="e.g. 5000" required>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label text-dark fw-bold small mb-1">Notes / Transaction Reference ID</label>
                                                <input type="text" name="notes" class="form-control" placeholder="e.g. Bank Transfer TRX#847291">
                                            </div>

                                            <div class="alert alert-light border small text-muted mb-0">
                                                <i class="fa-solid fa-circle-info text-success me-1"></i> The credited balance will be available immediately for this operator to activate subscribers and packages.
                                            </div>
                                        </div>
                                        <div class="modal-footer d-flex justify-content-between">
                                            <button type="button" class="btn btn-light px-4 rounded-pill fw-semibold" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-success px-4 rounded-pill fw-bold shadow-sm">
                                                <i class="fa-solid fa-plus me-1"></i> Add Balance
                                            </button>
                                        </div>
                                      </form>
                                    </div>
                                  </div>
                                </div>
                                <!-- End Fund Modal -->

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
var billTable;

$(document).ready(function() {
    billTable = $('#billingTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        order: [[0, 'asc']],
        responsive: true,
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search operator company, id...",
            lengthMenu: "Show _MENU_ entries"
        },
        initComplete: function() {
            $('#billingLoadingBox').fadeOut(120, function() {
                $('#billingTableContainer').fadeIn(180, function() {
                    billTable.columns.adjust().draw();
                });
            });
        }
    });
});

function filterBilling(status, btnElement) {
    $('.filter-pill-btn').removeClass('active');
    $(btnElement).addClass('active');

    if (status === 'all') {
        billTable.column(1).search('').draw();
    } else if (status === 'active') {
        billTable.column(1).search('Active').draw();
    } else if (status === 'suspended') {
        billTable.column(1).search('Suspended').draw();
    }
}
</script>

<?php require_once 'footer.php'; ?>
