<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Strictly for Admin
if (!empty($_SESSION['is_manager'])) {
    header("Location: dashboard.php");
    exit;
}

require_once 'header.php';
$client_id = (int)($_SESSION['operator_id'] ?? 1);

// Helper function to extract package name from transaction description
if (!function_exists('extract_history_package')) {
    function extract_history_package($desc, $default_pkg) {
        if (!empty($desc)) {
            if (preg_match('/\((.*?Mbps.*?|.*?MB.*?|.*?Package.*?)\)/i', $desc, $m)) {
                return trim($m[1]);
            }
            if (preg_match('/(?:Auto-Renew|Renewed|Plan|Package):\s*([^\,\;\|\(\)]+)/i', $desc, $m)) {
                return trim($m[1]);
            }
        }
        return !empty($default_pkg) ? $default_pkg : 'Standard';
    }
}

// Fetch 2 Years of Admin Renewals and Cash Collections
$stmt = $pdo->prepare("
    SELECT 
        ul.id,
        ul.client_id,
        ul.username,
        ul.type,
        ul.amount,
        ul.balance_after,
        ul.description,
        ul.created_at,
        COALESCE(s.full_name, 'N/A') as customer_name,
        s.id as subscriber_id,
        COALESCE(p.name, 'Standard') as subscriber_package
    FROM user_ledger ul
    LEFT JOIN subscribers s ON (ul.subscriber_id = s.id OR (ul.username IS NOT NULL AND ul.username != '' AND ul.username = s.username COLLATE utf8mb4_general_ci)) AND s.client_id = ul.client_id
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE ul.client_id = ? 
      AND ul.created_at >= DATE_SUB(NOW(), INTERVAL 2 YEAR)
    ORDER BY ul.id DESC
");
$stmt->execute([$client_id]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summaries
$total_cash_collected = 0;
$total_renewal_amount = 0;
$count_cash_collected = 0;
$count_renewals = 0;

foreach ($records as $r) {
    if ($r['type'] === 'credit') {
        $total_cash_collected += (float)$r['amount'];
        $count_cash_collected++;
    } else {
        $total_renewal_amount += (float)$r['amount'];
        $count_renewals++;
    }
}

// Two year window dates
$two_years_ago = date('d M Y', strtotime('-2 years'));
$today_date = date('d M Y');
?>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    body { background-color: #f8fafc; font-family: 'Inter', system-ui, -apple-system, sans-serif; }

    .card-ui { 
        background: #ffffff; 
        border-radius: 14px; 
        box-shadow: 0 2px 12px rgba(0,0,0,0.02); 
        border: 1px solid #eef2f6; 
    }

    /* Compact Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 12px;
        padding: 12px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 1px 6px rgba(0,0,0,0.02);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 14px rgba(0,0,0,0.05);
    }
    .stat-pill-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .stat-pill-val {
        font-size: 1.25rem;
        font-weight: 800;
        line-height: 1.2;
    }
    .stat-pill-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 2px;
    }

    /* Filter Pills */
    .filter-pill-btn {
        border-radius: 50px;
        padding: 6px 16px;
        font-weight: 600;
        font-size: 0.82rem;
        border: 1px solid #e2e8f0;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #ffffff;
        color: #64748b;
        cursor: pointer;
    }
    .filter-pill-btn:hover {
        background: #f1f5f9;
        color: #1e293b;
        border-color: #cbd5e1;
    }
    .filter-pill-btn.active {
        background: #1e293b !important;
        color: #ffffff !important;
        border-color: #1e293b !important;
        box-shadow: 0 2px 8px rgba(15,23,42,0.18);
    }
    .filter-pill-btn.active.credit {
        background: #059669 !important;
        border-color: #059669 !important;
        box-shadow: 0 2px 8px rgba(5,150,105,0.2);
    }
    .filter-pill-btn.active.debit {
        background: #2563eb !important;
        border-color: #2563eb !important;
        box-shadow: 0 2px 8px rgba(37,99,235,0.2);
    }
    .filter-pill-btn .pill-count {
        background: rgba(0, 0, 0, 0.08);
        padding: 1px 7px;
        border-radius: 50px;
        font-size: 0.72rem;
        font-weight: 700;
    }
    .filter-pill-btn.active .pill-count {
        background: rgba(255, 255, 255, 0.25);
        color: #ffffff;
    }

    /* Compact Responsive Table */
    .table-container-responsive {
        width: 100%;
        overflow-x: auto;
    }
    #renewalsLogTable {
        width: 100% !important;
        border-collapse: separate;
        border-spacing: 0 3px;
        font-size: 0.81rem;
        table-layout: auto;
    }
    #renewalsLogTable thead th {
        background: #f1f5f9 !important;
        color: #475569;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.70rem;
        letter-spacing: 0.5px;
        border: none;
        padding: 8px 10px;
        white-space: nowrap;
    }
    #renewalsLogTable tbody tr {
        background: #ffffff !important;
        box-shadow: 0 1px 2px rgba(0,0,0,0.02) !important;
        border-radius: 8px !important;
        transition: background-color 0.15s ease !important;
    }
    #renewalsLogTable tbody tr:hover {
        background-color: #f8fafc !important;
    }
    #renewalsLogTable tbody td {
        border-top: 1px solid #f1f5f9 !important;
        border-bottom: 1px solid #f1f5f9 !important;
        border-left: none !important;
        border-right: none !important;
        vertical-align: middle !important;
        padding: 6px 10px !important;
        background: transparent !important;
        font-size: 0.81rem !important;
        line-height: 1.25;
    }
    #renewalsLogTable tbody td:first-child {
        border-left: 1px solid #f1f5f9 !important;
        border-top-left-radius: 8px !important;
        border-bottom-left-radius: 8px !important;
    }
    #renewalsLogTable tbody td:last-child {
        border-right: 1px solid #f1f5f9 !important;
        border-top-right-radius: 8px !important;
        border-bottom-right-radius: 8px !important;
    }

    /* DataTables Export Buttons */
    .dt-buttons .btn {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        color: #334155 !important;
        border-radius: 50px !important;
        padding: 5px 14px !important;
        font-size: 0.78rem !important;
        font-weight: 600 !important;
        margin-right: 4px !important;
        box-shadow: 0 1px 2px rgba(0,0,0,0.02) !important;
        transition: all 0.2s ease !important;
    }
    .dt-buttons .btn:hover {
        background: #f1f5f9 !important;
        color: #0f172a !important;
        border-color: #cbd5e1 !important;
    }
    .dataTables_filter input {
        border-radius: 50px !important;
        border: 1px solid #e2e8f0 !important;
        padding: 5px 14px !important;
        font-size: 0.82rem !important;
        background: #ffffff !important;
        outline: none !important;
    }
    .dataTables_filter input:focus {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12) !important;
    }
    .dataTables_length select {
        border-radius: 8px !important;
        border: 1px solid #e2e8f0 !important;
        padding: 4px 8px !important;
        font-size: 0.80rem !important;
    }
    .dataTables_info, .dataTables_paginate {
        font-size: 0.80rem !important;
        margin-top: 10px !important;
    }
</style>

<div class="container-fluid px-3 py-3">

    <!-- Top Navigation Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div class="d-flex align-items-center">
            <div class="rounded-circle text-white d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 44px; height: 44px; font-size: 1.2rem; background: linear-gradient(135deg, #2563eb, #1d4ed8);">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div>
                <h4 class="fw-bold text-dark mb-0" style="font-size: 1.25rem;">Package Renewals & Cash Collections Log</h4>
                <div class="text-secondary" style="font-size: 0.78rem;">
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill me-1 px-2 py-0.5" style="font-size: 0.70rem;">2-Year Archive</span>
                    Showing records from <strong><?= $two_years_ago ?></strong> to <strong><?= $today_date ?></strong>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="profile.php" class="btn btn-light border fw-bold text-secondary rounded-pill px-3 py-1.5 shadow-sm" style="font-size: 0.82rem;">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to Profile
            </a>
        </div>
    </div>

    <!-- 3 Compact Metric Cards -->
    <div class="row g-2 mb-3">
        <div class="col-12 col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #ecfdf5; color: #059669;">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
                <div>
                    <div class="stat-pill-label">Cash Collected (2 Yrs)</div>
                    <div class="stat-pill-val text-success">Rs. <?= number_format($total_cash_collected, 0) ?></div>
                    <small class="text-muted fw-semibold" style="font-size: 0.72rem;"><?= number_format($count_cash_collected) ?> Payments Recorded</small>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </div>
                <div>
                    <div class="stat-pill-label">Packages Renewed (2 Yrs)</div>
                    <div class="stat-pill-val text-primary">Rs. <?= number_format($total_renewal_amount, 0) ?></div>
                    <small class="text-muted fw-semibold" style="font-size: 0.72rem;"><?= number_format($count_renewals) ?> Package Debits</small>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-4">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #f8fafc; color: #475569; border: 1px solid #e2e8f0;">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <div class="stat-pill-label">Total Logged Entries</div>
                    <div class="stat-pill-val text-dark"><?= number_format(count($records)) ?></div>
                    <small class="text-muted fw-semibold" style="font-size: 0.72rem;">2-Year User Ledger Log</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Buttons Row -->
    <div class="d-flex flex-wrap gap-2 mb-3">
        <button type="button" class="filter-pill-btn active" onclick="filterLog('all', this)">
            <i class="fa-solid fa-list-ul"></i> All Records <span class="pill-count"><?= number_format(count($records)) ?></span>
        </button>
        <button type="button" class="filter-pill-btn" onclick="filterLog('credit', this)">
            <i class="fa-solid fa-arrow-down text-success"></i> Cash Collected <span class="pill-count"><?= number_format($count_cash_collected) ?></span>
        </button>
        <button type="button" class="filter-pill-btn" onclick="filterLog('debit', this)">
            <i class="fa-solid fa-arrows-rotate text-primary"></i> Packages Renewed <span class="pill-count"><?= number_format($count_renewals) ?></span>
        </button>
    </div>

    <!-- Main Table Card Container -->
    <div class="card-ui p-3 mb-4">
        <div class="table-container-responsive">
            <table id="renewalsLogTable" class="table table-hover w-100">
                <thead>
                    <tr>
                        <th style="width: 65px;">INV #</th>
                        <th style="width: 95px;">Date & Time</th>
                        <th style="min-width: 120px;">Customer Name</th>
                        <th style="width: 105px;">Username</th>
                        <th style="width: 95px;">Package</th>
                        <th style="width: 110px;">Type</th>
                        <th style="width: 95px;">Amount</th>
                        <th style="width: 85px;">Balance</th>
                        <th style="min-width: 140px;">Description</th>
                        <th class="text-end" style="width: 75px;">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($records as $row): 
                        $pkg_name = extract_history_package($row['description'], $row['subscriber_package']);
                        $is_credit = ($row['type'] === 'credit');
                    ?>
                    <tr data-type="<?= $row['type'] ?>">
                        <td>
                            <span class="badge bg-light text-secondary border font-monospace px-1.5 py-0.5 fw-bold" style="font-size: 0.70rem;">
                                #<?= str_pad($row['id'], 5, '0', STR_PAD_LEFT) ?>
                            </span>
                        </td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap" style="font-size: 0.78rem;"><?= date('d M Y', strtotime($row['created_at'])) ?></div>
                            <div class="text-muted font-monospace text-nowrap" style="font-size: 0.70rem;"><?= date('h:i A', strtotime($row['created_at'])) ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark text-truncate" style="max-width: 130px;" title="<?= htmlspecialchars($row['customer_name']) ?>">
                                <?php if (!empty($row['subscriber_id'])): ?>
                                    <a href="subscriber_view.php?id=<?= $row['subscriber_id'] ?>" class="text-dark text-decoration-none" target="_blank">
                                        <?= htmlspecialchars($row['customer_name']) ?> <i class="fa-solid fa-arrow-up-right-from-square text-muted opacity-50" style="font-size: 0.65rem;"></i>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($row['customer_name']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-light text-primary border font-monospace px-1.5 py-0.5 fw-bold text-truncate" style="max-width: 105px; font-size: 0.73rem;" title="<?= htmlspecialchars($row['username']) ?>">
                                <?= htmlspecialchars($row['username']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-1.5 py-0.5 fw-semibold text-truncate" style="max-width: 95px; font-size: 0.71rem;" title="<?= htmlspecialchars($pkg_name) ?>">
                                <?= htmlspecialchars($pkg_name) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($is_credit): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2 py-0.5 fw-semibold text-nowrap" style="font-size: 0.70rem;">
                                    <i class="fa-solid fa-arrow-down me-1"></i> Cash Collected
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2 py-0.5 fw-semibold text-nowrap" style="font-size: 0.70rem;">
                                    <i class="fa-solid fa-arrows-rotate me-1"></i> Renewed
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="fw-bold text-nowrap <?= $is_credit ? 'text-success' : 'text-danger' ?>" style="font-size: 0.84rem;">
                                <?= $is_credit ? '+' : '-' ?> Rs. <?= number_format($row['amount'], 0) ?>
                            </span>
                        </td>
                        <td>
                            <span class="font-monospace text-muted text-nowrap" style="font-size: 0.74rem;">
                                Rs. <?= number_format($row['balance_after'], 0) ?>
                            </span>
                        </td>
                        <td>
                            <div class="small text-secondary text-truncate" style="max-width: 170px; font-size: 0.73rem;" title="<?= htmlspecialchars($row['description']) ?>">
                                <?= htmlspecialchars($row['description']) ?>
                            </div>
                        </td>
                        <td class="text-end text-nowrap pe-2">
                            <a href="print_receipt.php?ledger_id=<?= $row['id'] ?>&return=renewals_log.php" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-2 py-0.5 fw-semibold" style="font-size: 0.72rem;" title="Print 80mm Receipt">
                                <i class="fa-solid fa-receipt me-1"></i> Receipt
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- jQuery & DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<!-- DataTables Buttons JS -->
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
var renewalsDT;

$(document).ready(function() {
    renewalsDT = $('#renewalsLogTable').DataTable({
        dom: '<"row align-items-center mb-2"<"col-md-3"l><"col-md-5 dt-buttons"B><"col-md-4"f>>rtip',
        buttons: [
            { 
                extend: 'csv', 
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] }, 
                text: '<i class="fa-solid fa-file-csv text-success"></i> CSV',
                className: 'btn btn-sm btn-outline-secondary'
            },
            { 
                extend: 'pdf', 
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] }, 
                text: '<i class="fa-solid fa-file-pdf text-danger"></i> PDF',
                className: 'btn btn-sm btn-outline-secondary',
                orientation: 'landscape',
                pageSize: 'A4',
                title: 'Package Renewals & Cash Collections Log (2 Years)'
            },
            { 
                extend: 'excel', 
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] }, 
                text: '<i class="fa-solid fa-file-excel text-primary"></i> Excel',
                className: 'btn btn-sm btn-outline-secondary'
            },
            { 
                extend: 'print', 
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7, 8] }, 
                text: '<i class="fa-solid fa-print"></i> Print',
                className: 'btn btn-sm btn-outline-secondary'
            }
        ],
        pageLength: 50,
        lengthMenu: [[25, 50, 100, 250, -1], [25, 50, 100, 250, "All"]],
        order: [[1, 'desc']],
        autoWidth: false,
        language: {
            search: "Search:",
            searchPlaceholder: "Customer, user, package...",
            emptyTable: '<div class="text-center py-3"><i class="fa-solid fa-receipt fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No records found for the past 2 years.</div></div>',
            zeroRecords: '<div class="text-center py-3"><i class="fa-solid fa-magnifying-glass fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No matching records found.</div></div>'
        }
    });
});

function filterLog(type, btn) {
    document.querySelectorAll('.filter-pill-btn').forEach(b => {
        b.classList.remove('active', 'credit', 'debit');
    });

    btn.classList.add('active');
    if (type === 'credit') {
        btn.classList.add('credit');
        renewalsDT.column(5).search('Cash Collected').draw();
    } else if (type === 'debit') {
        btn.classList.add('debit');
        renewalsDT.column(5).search('Renewed').draw();
    } else {
        renewalsDT.column(5).search('').draw();
    }
}
</script>

<?php require_once 'footer.php'; ?>
