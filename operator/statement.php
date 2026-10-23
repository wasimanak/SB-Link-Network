<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'header.php';

$client_id = $_SESSION['operator_id'] ?? 0;

// Fetch all users with their ledger summaries and dealer details
$stmt = $pdo->prepare("
    SELECT 
        s.id, s.username, s.full_name,
        d.username as dealer_username,
        COALESCE(SUM(CASE WHEN l.type = 'credit' THEN l.amount ELSE 0 END), 0) as total_credit,
        MAX(CASE WHEN l.type = 'credit' THEN l.created_at ELSE NULL END) as last_recharge_date,
        (SELECT amount FROM user_ledger ul WHERE ul.username = s.username COLLATE utf8mb4_general_ci AND ul.client_id = s.client_id AND ul.type = 'credit' ORDER BY ul.id DESC LIMIT 1) as last_recharge_amount
    FROM subscribers s
    LEFT JOIN dealers d ON s.dealer_id = d.id
    LEFT JOIN user_ledger l ON s.username = l.username COLLATE utf8mb4_general_ci AND s.client_id = l.client_id
    WHERE s.client_id = ?
    GROUP BY s.id, s.username, s.full_name, d.username
    HAVING total_credit > 0
    ORDER BY last_recharge_date DESC
");
$stmt->execute([$client_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all pending payment users (balance < 0)
$pendingStmt = $pdo->prepare("
    SELECT 
        s.id, s.username, s.full_name, s.mobile, s.phone, s.address, s.balance, s.status, s.expiry_date,
        p.name as package_name, p.price as package_price,
        d.username as dealer_username,
        (SELECT MAX(created_at) FROM user_ledger ul WHERE ul.username = s.username COLLATE utf8mb4_general_ci AND ul.client_id = s.client_id AND ul.type = 'credit') as last_recharge_date,
        (SELECT amount FROM user_ledger ul WHERE ul.username = s.username COLLATE utf8mb4_general_ci AND ul.client_id = s.client_id AND ul.type = 'credit' ORDER BY ul.id DESC LIMIT 1) as last_recharge_amount
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    LEFT JOIN dealers d ON s.dealer_id = d.id
    WHERE s.client_id = ? AND s.balance < 0
    ORDER BY s.balance ASC
");
$pendingStmt->execute([$client_id]);
$pending_users = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$total_pending_count = count($pending_users);
$total_all_pending = 0;
foreach ($pending_users as $pu) {
    $total_all_pending += abs((float)$pu['balance']);
}

$total_balance = 0;
$total_all_credit = 0;
$total_all_debit = 0;
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    .card-ui { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; }
    .dt-buttons .btn { margin-right: 5px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; }
    .table-custom-ui th { background-color: #f8fafc !important; color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; }
    .table-custom-ui td { vertical-align: middle; border-bottom: 1px solid #f1f5f9; color: #475569; font-size: 0.9rem; }
    .table-custom-ui tbody tr:hover { background-color: #f8fafc; }
    .totals-row { background-color: #f1f5f9; font-weight: bold; color: #1e293b; }
    .custom-pill-nav .nav-link {
        border-radius: 10px;
        color: #475569;
        background: #e2e8f0;
        transition: all 0.2s ease;
    }
    .custom-pill-nav .nav-link:hover {
        background: #cbd5e1;
    }
    .custom-pill-nav .nav-link.active#recharge-tab {
        background: #2563eb !important;
        color: #fff !important;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
    }
    .custom-pill-nav .nav-link.active#pending-tab {
        background: #dc2626 !important;
        color: #fff !important;
        box-shadow: 0 4px 10px rgba(220, 38, 38, 0.25);
    }
    .custom-pill-nav .nav-link.active .badge {
        background: rgba(255, 255, 255, 0.25) !important;
        color: #fff !important;
    }
</style>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-file-invoice-dollar text-primary me-2"></i> Admin Statement & Dues</h4>
            <p class="text-secondary mb-0">Lifetime recharge statement and pending customer balances.</p>
        </div>
        <a href="profile.php" class="btn btn-light border fw-bold text-secondary shadow-sm"><i class="fa-solid fa-arrow-left me-2"></i> Back to Profile</a>
    </div>

    <!-- Navigation Tabs -->
    <ul class="nav nav-pills custom-pill-nav mb-4 gap-2" id="statementTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold px-4 py-2" id="recharge-tab" data-bs-toggle="pill" data-bs-target="#recharge-pane" type="button" role="tab" aria-controls="recharge-pane" aria-selected="true">
                <i class="fa-solid fa-clock-rotate-left me-2"></i> Recharge Statement
                <span class="badge bg-secondary ms-2"><?= count($users) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold px-4 py-2" id="pending-tab" data-bs-toggle="pill" data-bs-target="#pending-pane" type="button" role="tab" aria-controls="pending-pane" aria-selected="false">
                <i class="fa-solid fa-file-circle-exclamation me-2"></i> Pending Payments (Minus Balance)
                <span class="badge bg-danger text-white ms-2"><?= $total_pending_count ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="statementTabContent">
        <!-- Tab 1: Recharge Statement -->
        <div class="tab-pane fade show active" id="recharge-pane" role="tabpanel" aria-labelledby="recharge-tab" tabindex="0">
            <div class="card-ui p-4">
                <div class="table-responsive">
                    <table id="statementTable" class="table table-hover table-custom-ui table-borderless w-100 align-middle">
                        <thead>
                            <tr>
                                <th>User Details</th>
                                <th>Managed By</th>
                                <th>Last Recharge Date</th>
                                <th>Last R. Amount</th>
                                <th class="text-end pe-4">Lifetime Recharged</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $u): 
                                $cr = (float)$u['total_credit'];
                                $total_all_credit += $cr;
                                
                                $lr_date = $u['last_recharge_date'] ? date('d M Y', strtotime($u['last_recharge_date'])) : 'N/A';
                                $lr_time = $u['last_recharge_date'] ? date('h:i A', strtotime($u['last_recharge_date'])) : '';
                                $lr_amt = (float)$u['last_recharge_amount'];
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-primary" style="font-size: 1.05rem;">
                                        <a href="subscriber_view.php?id=<?= $u['id'] ?>" class="text-decoration-none text-primary">
                                            <?= htmlspecialchars($u['username']) ?>
                                        </a>
                                    </div>
                                    <div class="text-secondary" style="font-size: 0.85rem;"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($u['full_name']) ?></div>
                                </td>
                                <td>
                                    <?php if($u['dealer_username']): ?>
                                        <span class="badge bg-secondary"><i class="fa-solid fa-store me-1"></i> <?= htmlspecialchars($u['dealer_username']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-primary"><i class="fa-solid fa-user-shield me-1"></i> Admin</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= $lr_date ?></div>
                                    <div class="small text-muted font-monospace"><?= $lr_time ?></div>
                                </td>
                                <td><div class="fw-bold fs-6" style="color: #000 !important;">Rs. <?= number_format($lr_amt) ?></div></td>
                                <td class="text-success fw-bold fs-5 text-end pe-4">Rs. <?= number_format($cr) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="totals-row" style="border-top: 2px solid #e2e8f0;">
                                <td colspan="4" class="text-end text-dark fs-6 pt-3">GRAND TOTAL LIFETIME RECHARGES:</td>
                                <td class="text-success fs-3 fw-bold text-end pe-4 pt-3">Rs. <?= number_format($total_all_credit) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 2: Pending Payments -->
        <div class="tab-pane fade" id="pending-pane" role="tabpanel" aria-labelledby="pending-tab" tabindex="0">
            <!-- Pending Summary Row -->
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="card-ui p-3 bg-danger bg-opacity-10 border-danger border-opacity-25 d-flex flex-row align-items-center">
                        <div class="rounded-circle bg-danger text-white d-flex align-items-center justify-content-center me-3" style="width: 52px; height: 52px; font-size: 1.4rem;">
                            <i class="fa-solid fa-users-slash"></i>
                        </div>
                        <div>
                            <div class="text-secondary small fw-bold text-uppercase">Total Pending Users</div>
                            <h3 class="fw-bold text-danger mb-0"><?= number_format($total_pending_count) ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card-ui p-3 bg-dark text-white d-flex flex-row align-items-center">
                        <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center me-3" style="width: 52px; height: 52px; font-size: 1.4rem;">
                            <i class="fa-solid fa-money-bill-wave"></i>
                        </div>
                        <div>
                            <div class="text-white-50 small fw-bold text-uppercase">Total Outstanding Balance (Pending)</div>
                            <h3 class="fw-bold text-warning mb-0">Rs. <?= number_format($total_all_pending) ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-ui p-4">
                <div class="table-responsive">
                    <table id="pendingTable" class="table table-hover table-custom-ui table-borderless w-100 align-middle">
                        <thead>
                            <tr>
                                <th>Subscriber Details</th>
                                <th>Contact & Address</th>
                                <th>Managed By</th>
                                <th>Package</th>
                                <th>Status / Expiry</th>
                                <th>Last Payment</th>
                                <th class="text-end pe-3">Pending Amount</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pending_users as $pu): 
                                $pending_due = abs((float)$pu['balance']);
                                $pu_lr_date = $pu['last_recharge_date'] ? date('d M Y', strtotime($pu['last_recharge_date'])) : 'Never';
                                $contact_num = $pu['mobile'] ?: ($pu['phone'] ?: '');
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-primary" style="font-size: 1.05rem;">
                                        <a href="subscriber_view.php?id=<?= $pu['id'] ?>" class="text-decoration-none text-primary">
                                            <?= htmlspecialchars($pu['username']) ?>
                                        </a>
                                    </div>
                                    <div class="text-secondary small"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($pu['full_name'] ?: 'No Name') ?></div>
                                </td>
                                <td>
                                    <?php if ($contact_num): ?>
                                        <div><i class="fa-solid fa-phone text-success me-1"></i> <?= htmlspecialchars($contact_num) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small">No Phone</span>
                                    <?php endif; ?>
                                    <?php if ($pu['address']): ?>
                                        <div class="text-muted small text-truncate" style="max-width: 220px;" title="<?= htmlspecialchars($pu['address']) ?>">
                                            <i class="fa-solid fa-location-dot text-danger me-1"></i> <?= htmlspecialchars($pu['address']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($pu['dealer_username']): ?>
                                        <span class="badge bg-secondary"><i class="fa-solid fa-store me-1"></i> <?= htmlspecialchars($pu['dealer_username']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-primary"><i class="fa-solid fa-user-shield me-1"></i> Admin</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($pu['package_name'] ?? 'N/A') ?></div>
                                    <?php if(!empty($pu['package_price'])): ?>
                                        <div class="text-muted small">Rs. <?= number_format($pu['package_price']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $pu['status'] === 'active' ? 'bg-success' : 'bg-danger' ?> mb-1 text-uppercase" style="font-size: 0.72rem;">
                                        <?= htmlspecialchars($pu['status']) ?>
                                    </span>
                                    <?php if ($pu['expiry_date']): ?>
                                        <div class="small text-muted font-monospace"><?= date('d M Y', strtotime($pu['expiry_date'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark small"><?= $pu_lr_date ?></div>
                                    <?php if (!empty($pu['last_recharge_amount'])): ?>
                                        <div class="text-muted small font-monospace">Rs. <?= number_format($pu['last_recharge_amount']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle fs-6 fw-bold px-3 py-2">
                                        - Rs. <?= number_format($pending_due) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="subscriber_view.php?id=<?= $pu['id'] ?>" class="btn btn-sm btn-outline-primary fw-bold" title="View Profile & Collect">
                                        <i class="fa-solid fa-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="totals-row" style="border-top: 2px solid #e2e8f0;">
                                <td colspan="6" class="text-end text-dark fs-6 pt-3">GRAND TOTAL OUTSTANDING PENDING:</td>
                                <td class="text-danger fs-3 fw-bold text-end pe-3 pt-3">- Rs. <?= number_format($total_all_pending) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
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
    $('#statementTable').DataTable({
        "order": [[ 4, "desc" ]],
        "pageLength": 50,
        "dom": '<"d-flex justify-content-between align-items-center mb-3"Bf>rt<"d-flex justify-content-between align-items-center mt-3"ip>',
        "buttons": [
            { extend: 'copy', className: 'btn btn-light border text-secondary shadow-sm' },
            { extend: 'csv', className: 'btn btn-light border text-secondary shadow-sm', text: '<i class="fa-solid fa-file-csv"></i> Download CSV' },
            { extend: 'excel', className: 'btn btn-light border text-success shadow-sm', text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'pdfHtml5', className: 'btn btn-danger shadow-sm text-white', text: '<i class="fa-solid fa-file-pdf"></i> Download PDF (A4)', orientation: 'portrait', pageSize: 'A4' },
            { extend: 'print', className: 'btn btn-primary shadow-sm text-white', text: '<i class="fa-solid fa-print"></i> Print' }
        ],
        "language": {
            "search": "",
            "searchPlaceholder": "Search Statement..."
        }
    });

    $('#pendingTable').DataTable({
        "order": [[ 6, "desc" ]],
        "pageLength": 50,
        "dom": '<"d-flex justify-content-between align-items-center mb-3"Bf>rt<"d-flex justify-content-between align-items-center mt-3"ip>',
        "buttons": [
            { extend: 'copy', className: 'btn btn-light border text-secondary shadow-sm' },
            { extend: 'csv', className: 'btn btn-light border text-secondary shadow-sm', text: '<i class="fa-solid fa-file-csv"></i> Download CSV' },
            { extend: 'excel', className: 'btn btn-light border text-success shadow-sm', text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'pdfHtml5', className: 'btn btn-danger shadow-sm text-white', text: '<i class="fa-solid fa-file-pdf"></i> Download PDF (A4)', orientation: 'portrait', pageSize: 'A4' },
            { extend: 'print', className: 'btn btn-primary shadow-sm text-white', text: '<i class="fa-solid fa-print"></i> Print' }
        ],
        "language": {
            "search": "",
            "searchPlaceholder": "Search Pending Payments..."
        }
    });

    // Auto adjust table layout when switching tabs
    $('button[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    });
});
</script>

<?php require_once 'footer.php'; ?>
