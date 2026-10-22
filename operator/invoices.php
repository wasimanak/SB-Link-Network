<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Fetch all ledger entries (invoices/receipts)
$stmt = $pdo->prepare("
    SELECT ul.*, s.full_name as subscriber_name 
    FROM user_ledger ul
    LEFT JOIN subscribers s ON ul.subscriber_id = s.id
    WHERE ul.client_id = ?
    ORDER BY ul.id DESC
");
$stmt->execute([$client_id]);
$invoices = $stmt->fetchAll();

// Get operator details for invoice printing
$opStmt = $pdo->prepare("SELECT company_name, email, phone FROM clients WHERE id = ?");
$opStmt->execute([$client_id]);
$operator = $opStmt->fetch();

// Summary calculations
$total_entries = count($invoices);
$total_credits = 0;
$total_debits = 0;
$count_credits = 0;
$count_debits = 0;
foreach($invoices as $inv) {
    if ($inv['type'] === 'credit') {
        $total_credits += (float)$inv['amount'];
        $count_credits++;
    } else {
        $total_debits += (float)$inv['amount'];
        $count_debits++;
    }
}
?>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    body { background-color: #f8fafc; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    
    .card-ui { 
        background: #ffffff; 
        border-radius: 20px; 
        box-shadow: 0 4px 20px rgba(0,0,0,0.03); 
        border: 1px solid #eef2f6; 
    }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 18px;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(0,0,0,0.06);
    }
    .stat-pill-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    .stat-pill-val {
        font-size: 1.35rem;
        font-weight: 800;
        line-height: 1.15;
    }
    .stat-pill-label {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #64748b;
        margin-bottom: 2px;
    }

    /* Filter Pills */
    .filter-pill-btn {
        border-radius: 50px;
        padding: 7px 18px;
        font-weight: 600;
        font-size: 0.86rem;
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
        background: #2563eb !important;
        color: #ffffff !important;
        border-color: #2563eb !important;
        box-shadow: 0 4px 12px rgba(37,99,235,0.22);
    }
    .filter-pill-btn .pill-count {
        background: rgba(0,0,0,0.06);
        padding: 2px 7px;
        border-radius: 20px;
        font-size: 0.75rem;
    }
    .filter-pill-btn.active .pill-count {
        background: rgba(255,255,255,0.25);
        color: #ffffff;
    }

    /* Premium DataTables Styling */
    #invoicesTable {
        border-collapse: separate !important;
        border-spacing: 0 8px !important;
    }
    #invoicesTable thead th {
        background: #f8fafc !important;
        color: #64748b !important;
        font-size: 0.75rem !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.8px !important;
        padding: 12px 14px !important;
        border: none !important;
        white-space: nowrap !important;
    }
    #invoicesTable thead th:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
    #invoicesTable thead th:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
    
    #invoicesTable tbody tr {
        background: #ffffff !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02) !important;
        border-radius: 14px !important;
        transition: transform 0.18s ease, box-shadow 0.18s ease !important;
    }
    #invoicesTable tbody tr:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 6px 18px rgba(0,0,0,0.06) !important;
    }
    #invoicesTable tbody td {
        border-top: 1px solid #f1f5f9 !important;
        border-bottom: 1px solid #f1f5f9 !important;
        border-left: none !important;
        border-right: none !important;
        vertical-align: middle !important;
        padding: 13px 14px !important;
        background: transparent !important;
        font-size: 0.9rem !important;
    }
    #invoicesTable tbody td:first-child {
        border-left: 1px solid #f1f5f9 !important;
        border-top-left-radius: 14px !important;
        border-bottom-left-radius: 14px !important;
    }
    #invoicesTable tbody td:last-child {
        border-right: 1px solid #f1f5f9 !important;
        border-top-right-radius: 14px !important;
        border-bottom-right-radius: 14px !important;
    }

    /* Badges */
    .badge-soft-success { background-color: #ecfdf5 !important; color: #059669 !important; border: 1px solid #a7f3d0 !important; font-weight: 600; }
    .badge-soft-danger { background-color: #fef2f2 !important; color: #dc2626 !important; border: 1px solid #fecaca !important; font-weight: 600; }
    .badge-soft-primary { background-color: #eff6ff !important; color: #2563eb !important; border: 1px solid #bfdbfe !important; font-weight: 600; }

    /* DataTables Buttons & Filter */
    .dt-buttons .btn {
        background: #ffffff !important;
        border: 1px solid #e2e8f0 !important;
        color: #475569 !important;
        border-radius: 50px !important;
        padding: 5px 14px !important;
        font-size: 0.82rem !important;
        font-weight: 600 !important;
        margin-right: 5px !important;
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
        padding: 6px 16px !important;
        font-size: 0.88rem !important;
        background: #ffffff !important;
        box-shadow: 0 1px 4px rgba(0,0,0,0.02) !important;
        outline: none !important;
    }
    .dataTables_filter input:focus {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37,99,235,0.12) !important;
    }
    .dataTables_length select {
        border-radius: 10px !important;
        border: 1px solid #e2e8f0 !important;
        padding: 5px 10px !important;
        font-size: 0.86rem !important;
    }
</style>

<!-- Top Header Section -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div class="d-flex align-items-center">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 48px; height: 48px; font-size: 1.25rem; background: linear-gradient(135deg, #2563eb, #1d4ed8);">
            <i class="fa-solid fa-file-invoice-dollar"></i>
        </div>
        <div>
            <h4 class="fw-bold text-dark mb-0">Invoices & Receipts</h4>
            <div class="text-secondary small">View ledger transaction history, customer invoices, and print receipts</div>
        </div>
    </div>
</div>

<!-- 3 Quick Summary Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                <i class="fa-solid fa-receipt"></i>
            </div>
            <div>
                <div class="stat-pill-label">Total Transactions</div>
                <div class="stat-pill-val text-dark"><?= number_format($total_entries) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #ecfdf5; color: #059669;">
                <i class="fa-solid fa-arrow-down"></i>
            </div>
            <div>
                <div class="stat-pill-label">Recharge / Payments</div>
                <div class="stat-pill-val text-success">Rs. <?= number_format($total_credits, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #fef2f2; color: #dc2626;">
                <i class="fa-solid fa-arrow-up"></i>
            </div>
            <div>
                <div class="stat-pill-label">Billing / Deductions</div>
                <div class="stat-pill-val text-danger">Rs. <?= number_format($total_debits, 2) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Pills -->
<div class="d-flex flex-wrap gap-2 mb-4">
    <button type="button" class="filter-pill-btn active" onclick="filterTransactions('all', this)">
        <i class="fa-solid fa-list-ul"></i> All Transactions <span class="pill-count"><?= number_format($total_entries) ?></span>
    </button>
    <button type="button" class="filter-pill-btn" onclick="filterTransactions('credit', this)">
        <i class="fa-solid fa-arrow-down text-success"></i> Recharge / Payments <span class="pill-count"><?= number_format($count_credits) ?></span>
    </button>
    <button type="button" class="filter-pill-btn" onclick="filterTransactions('debit', this)">
        <i class="fa-solid fa-arrow-up text-danger"></i> Billing / Deductions <span class="pill-count"><?= number_format($count_debits) ?></span>
    </button>
</div>

<!-- Main Table Card Container -->
<div class="card-ui p-4 mb-4 position-relative">
    <!-- Loading State Dialog -->
    <div id="invoicesLoadingBox" class="text-center py-5">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <h5 class="fw-bold text-dark mt-3 mb-1">Loading Invoices...</h5>
        <div class="text-secondary small">Please wait while ledger records are being loaded.</div>
    </div>

    <!-- Table Container (Initially Hidden) -->
    <div id="invoicesTableContainer" class="table-responsive" style="display: none;">
        <table id="invoicesTable" class="table table-hover w-100">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date & Time</th>
                    <th>Subscriber</th>
                    <th>Description</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($invoices as $inv): ?>
                <tr data-type="<?= $inv['type'] ?>">
                    <td>
                        <span class="badge rounded-pill bg-light text-secondary border font-monospace px-3 py-2 fw-bold">
                            INV-<?= str_pad($inv['id'], 6, '0', STR_PAD_LEFT) ?>
                        </span>
                    </td>
                    <td>
                        <div class="small text-dark fw-semibold"><?= date('d M Y', strtotime($inv['created_at'])) ?></div>
                        <div class="small text-muted font-monospace"><?= date('h:i A', strtotime($inv['created_at'])) ?></div>
                    </td>
                    <td>
                        <div class="fw-bold text-dark">
                            <i class="fa-solid fa-user text-primary me-1"></i> <?= htmlspecialchars($inv['username']) ?>
                        </div>
                        <?php if(!empty($inv['subscriber_name'])): ?>
                            <div class="small text-muted"><?= htmlspecialchars($inv['subscriber_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="text-secondary small"><?= htmlspecialchars($inv['description']) ?></span>
                    </td>
                    <td>
                        <?php if($inv['type'] == 'debit'): ?>
                            <span class="badge rounded-pill badge-soft-danger px-3 py-2 fw-bold">
                                <i class="fa-solid fa-arrow-up me-1"></i> Deduction / Bill
                            </span>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-success px-3 py-2 fw-bold">
                                <i class="fa-solid fa-arrow-down me-1"></i> Payment / Recharge
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fw-bold <?= $inv['type'] == 'credit' ? 'text-success' : 'text-danger' ?>" style="font-size: 1rem;">
                            Rs. <?= number_format($inv['amount'], 2) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-semibold" onclick='printInvoice(<?= json_encode($inv) ?>, <?= json_encode($operator) ?>)'>
                            <i class="fa-solid fa-print me-1"></i> Print
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Hidden Printable Invoice Layout -->
<div id="printArea" style="display:none;">
    <div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 30px; border: 1px solid #ddd; background: #fff;">
        <div style="display: flex; justify-content: space-between; border-bottom: 2px solid #2563eb; padding-bottom: 20px; margin-bottom: 30px;">
            <div>
                <h2 style="color: #2563eb; margin: 0; font-size: 28px;" id="p_company"><?= htmlspecialchars($operator['company_name'] ?? 'ISP Provider') ?></h2>
                <p style="margin: 5px 0; color: #555;">Email: <span id="p_email"><?= htmlspecialchars($operator['email'] ?? 'N/A') ?></span></p>
                <p style="margin: 5px 0; color: #555;">Phone: <span id="p_phone"><?= htmlspecialchars($operator['phone'] ?? 'N/A') ?></span></p>
            </div>
            <div style="text-align: right;">
                <h1 style="color: #333; margin: 0; font-size: 34px; text-transform: uppercase;" id="p_title">INVOICE</h1>
                <p style="margin: 5px 0; font-weight: bold; color: #555;">Invoice #: <span id="p_inv_num"></span></p>
                <p style="margin: 5px 0; color: #555;">Date: <span id="p_date"></span></p>
            </div>
        </div>

        <div style="margin-bottom: 30px;">
            <h4 style="color: #64748b; margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 5px;">Billed To:</h4>
            <p style="margin: 4px 0; font-weight: bold; font-size: 18px;" id="p_sub_name"></p>
            <p style="margin: 4px 0; color: #555;">Username: <span id="p_sub_user"></span></p>
        </div>

        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
            <thead>
                <tr style="background-color: #f8fafc; border-bottom: 2px solid #cbd5e1;">
                    <th style="padding: 12px; text-align: left; color: #333;">Description</th>
                    <th style="padding: 12px; text-align: center; color: #333;">Type</th>
                    <th style="padding: 12px; text-align: right; color: #333;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 15px 12px; color: #555;" id="p_desc"></td>
                    <td style="padding: 15px 12px; text-align: center; color: #555;" id="p_type"></td>
                    <td style="padding: 15px 12px; text-align: right; font-weight: bold; color: #333;" id="p_amount"></td>
                </tr>
            </tbody>
        </table>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 40px;">
            <div>
                <p style="color: #777; font-size: 12px; margin: 3px 0;">This is a computer-generated invoice and requires no signature.</p>
                <p style="color: #777; font-size: 12px; margin: 3px 0;">Thank you for your business!</p>
            </div>
            <div style="text-align: right; background-color: #f8fafc; padding: 18px 24px; border-radius: 12px; border: 1px solid #e2e8f0;">
                <p style="margin: 0; font-size: 14px; color: #64748b; font-weight: 600;">Total Amount:</p>
                <h2 style="margin: 4px 0 0 0; color: #2563eb; font-size: 26px;" id="p_total"></h2>
            </div>
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
var invoicesDT;

$(document).ready(function() {
    invoicesDT = $('#invoicesTable').DataTable({
        dom: '<"row align-items-center mb-3"<"col-md-2"l><"col-md-6 dt-buttons"B><"col-md-4"f>>rtip',
        buttons: [
            { extend: 'print', exportOptions: { columns: [0, 1, 2, 3, 4, 5] }, text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', exportOptions: { columns: [0, 1, 2, 3, 4, 5] }, text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'pdf', exportOptions: { columns: [0, 1, 2, 3, 4, 5] }, text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'excel', exportOptions: { columns: [0, 1, 2, 3, 4, 5] }, text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'csv', exportOptions: { columns: [0, 1, 2, 3, 4, 5] }, text: '<i class="fa-solid fa-file-csv"></i> CSV' }
        ],
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            search: "Search:",
            searchPlaceholder: "Type & Submit",
            emptyTable: '<div class="text-center py-4"><i class="fa-solid fa-receipt fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No invoices found.</div></div>',
            zeroRecords: '<div class="text-center py-4"><i class="fa-solid fa-magnifying-glass fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No matching invoices found.</div></div>'
        },
        initComplete: function() {
            $('#invoicesLoadingBox').fadeOut(120, function() {
                $('#invoicesTableContainer').fadeIn(180, function() {
                    invoicesDT.columns.adjust().draw();
                });
            });
        }
    });
});

function filterTransactions(type, btn) {
    document.querySelectorAll('.filter-pill-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    if (type === 'all') {
        invoicesDT.column(4).search('').draw();
    } else if (type === 'credit') {
        invoicesDT.column(4).search('Payment|Recharge', true, false).draw();
    } else if (type === 'debit') {
        invoicesDT.column(4).search('Deduction|Bill', true, false).draw();
    }
}

function printInvoice(inv, op) {
    // Format Invoice Data
    document.getElementById('p_inv_num').innerText = 'INV-' + String(inv.id).padStart(6, '0');
    
    let dt = new Date(inv.created_at);
    document.getElementById('p_date').innerText = dt.toLocaleDateString() + ' ' + dt.toLocaleTimeString();
    
    document.getElementById('p_sub_name').innerText = inv.subscriber_name || 'Valued Customer';
    document.getElementById('p_sub_user').innerText = inv.username;
    
    document.getElementById('p_desc').innerText = inv.description;
    
    let tType = inv.type === 'debit' ? 'Deduction / Bill' : 'Payment / Receipt';
    document.getElementById('p_type').innerText = tType;
    document.getElementById('p_title').innerText = inv.type === 'debit' ? 'INVOICE' : 'RECEIPT';
    
    document.getElementById('p_amount').innerText = 'Rs ' + parseFloat(inv.amount).toFixed(2);
    document.getElementById('p_total').innerText = 'Rs ' + parseFloat(inv.amount).toFixed(2);

    // Get Print Content
    let printContent = document.getElementById('printArea').innerHTML;
    
    // Create iframe
    let printFrame = document.createElement('iframe');
    printFrame.name = "print_frame";
    printFrame.style.position = "absolute";
    printFrame.style.top = "-1000000px";
    document.body.appendChild(printFrame);
    
    let frameDoc = printFrame.contentWindow ? printFrame.contentWindow : printFrame.contentDocument.document ? printFrame.contentDocument.document : printFrame.contentDocument;
    frameDoc.document.open();
    frameDoc.document.write('<html><head><title>Invoice / Receipt</title>');
    frameDoc.document.write('</head><body>');
    frameDoc.document.write(printContent);
    frameDoc.document.write('</body></html>');
    frameDoc.document.close();
    
    setTimeout(function() {
        window.frames["print_frame"].focus();
        window.frames["print_frame"].print();
        document.body.removeChild(printFrame);
    }, 500);
}
</script>

<?php require_once 'footer.php'; ?>
