<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $req_id = (int)$_POST['request_id'];
    $status = $_POST['status']; // 'approved' or 'rejected'

    if ($status === 'approved') {
        // Fetch request details
        $stmt = $pdo->prepare("SELECT pr.*, p.rate_limit, p.validity_days, s.username FROM package_requests pr JOIN packages p ON pr.package_id = p.id JOIN subscribers s ON pr.subscriber_id = s.id WHERE pr.id = ? AND pr.client_id = ?");
        $stmt->execute([$req_id, $client_id]);
        $req = $stmt->fetch();

        if ($req) {
            // Update subscriber package & expiry
            $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = DATE_ADD(IFNULL(expiry_date, CURDATE()), INTERVAL ? DAY), status = 'active' WHERE id = ?")->execute([$req['package_id'], $req['validity_days'], $req['subscriber_id']]);
            
            // Update radreply Rate Limit
            $pdo->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$req['rate_limit'], $req['username']]);
            
            // Remove Reject from radcheck if present
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$req['username']]);

            // Update request status
            $pdo->prepare("UPDATE package_requests SET status = 'approved' WHERE id = ?")->execute([$req_id]);

            $_SESSION['msg'] = "Package request approved and applied to subscriber successfully.";
        }
    } else {
        $pdo->prepare("UPDATE package_requests SET status = 'rejected' WHERE id = ?")->execute([$req_id]);
        $_SESSION['msg'] = "Package request rejected.";
    }
    header("Location: requests.php");
    exit;
}

$stmt = $pdo->prepare("SELECT pr.*, s.username, s.full_name, p.name as pkg_name, p.rate_limit, p.price FROM package_requests pr JOIN subscribers s ON pr.subscriber_id = s.id JOIN packages p ON pr.package_id = p.id WHERE pr.client_id = ? ORDER BY pr.id DESC");
$stmt->execute([$client_id]);
$requests = $stmt->fetchAll();

// Summary counts
$total_requests = count($requests);
$count_pending = 0;
$count_approved = 0;
$count_rejected = 0;

foreach ($requests as $r) {
    if ($r['status'] === 'pending') {
        $count_pending++;
    } elseif ($r['status'] === 'approved') {
        $count_approved++;
    } elseif ($r['status'] === 'rejected') {
        $count_rejected++;
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
    #requestsTable {
        border-collapse: separate !important;
        border-spacing: 0 8px !important;
    }
    #requestsTable thead th {
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
    #requestsTable thead th:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
    #requestsTable thead th:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
    
    #requestsTable tbody tr {
        background: #ffffff !important;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02) !important;
        border-radius: 14px !important;
        transition: transform 0.18s ease, box-shadow 0.18s ease !important;
    }
    #requestsTable tbody tr:hover {
        transform: translateY(-2px) !important;
        box-shadow: 0 6px 18px rgba(0,0,0,0.06) !important;
    }
    #requestsTable tbody td {
        border-top: 1px solid #f1f5f9 !important;
        border-bottom: 1px solid #f1f5f9 !important;
        border-left: none !important;
        border-right: none !important;
        vertical-align: middle !important;
        padding: 13px 14px !important;
        background: transparent !important;
        font-size: 0.9rem !important;
    }
    #requestsTable tbody td:first-child {
        border-left: 1px solid #f1f5f9 !important;
        border-top-left-radius: 14px !important;
        border-bottom-left-radius: 14px !important;
    }
    #requestsTable tbody td:last-child {
        border-right: 1px solid #f1f5f9 !important;
        border-top-right-radius: 14px !important;
        border-bottom-right-radius: 14px !important;
    }

    /* Badges */
    .badge-soft-success { background-color: #ecfdf5 !important; color: #059669 !important; border: 1px solid #a7f3d0 !important; font-weight: 600; }
    .badge-soft-danger { background-color: #fef2f2 !important; color: #dc2626 !important; border: 1px solid #fecaca !important; font-weight: 600; }
    .badge-soft-primary { background-color: #eff6ff !important; color: #2563eb !important; border: 1px solid #bfdbfe !important; font-weight: 600; }
    .badge-soft-warning { background-color: #fffbeb !important; color: #d97706 !important; border: 1px solid #fde68a !important; font-weight: 600; }
    .badge-soft-secondary { background-color: #f1f5f9 !important; color: #475569 !important; border: 1px solid #e2e8f0 !important; font-weight: 600; }
    .badge-soft-info { background-color: #f0fdfa !important; color: #0d9488 !important; border: 1px solid #99f6e4 !important; font-weight: 600; }

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
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 48px; height: 48px; font-size: 1.25rem; background: linear-gradient(135deg, #0ea5e9, #0284c7);">
            <i class="fa-solid fa-bolt"></i>
        </div>
        <div>
            <h4 class="fw-bold text-dark mb-0">Package & Renewal Requests</h4>
            <div class="text-secondary small">Review, approve, and track subscriber package change or renewal requests</div>
        </div>
    </div>
</div>

<?php if(isset($_SESSION['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4">
        <i class="fa-solid fa-circle-check me-2 text-success"></i> <?= $_SESSION['msg']; unset($_SESSION['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- 3 Quick Summary Metric Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #fffbeb; color: #d97706;">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div>
                <div class="stat-pill-label">Pending Requests</div>
                <div class="stat-pill-val text-warning"><?= number_format($count_pending) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #ecfdf5; color: #059669;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <div class="stat-pill-label">Approved Packages</div>
                <div class="stat-pill-val text-success"><?= number_format($count_approved) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                <i class="fa-solid fa-inbox"></i>
            </div>
            <div>
                <div class="stat-pill-label">Total Requests</div>
                <div class="stat-pill-val text-dark"><?= number_format($total_requests) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Pills -->
<div class="d-flex flex-wrap gap-2 mb-4">
    <button type="button" class="filter-pill-btn active" onclick="filterRequests('all', this)">
        <i class="fa-solid fa-list-ul"></i> All Requests <span class="pill-count"><?= number_format($total_requests) ?></span>
    </button>
    <button type="button" class="filter-pill-btn" onclick="filterRequests('pending', this)">
        <i class="fa-solid fa-clock text-warning"></i> Pending <span class="pill-count"><?= number_format($count_pending) ?></span>
    </button>
    <button type="button" class="filter-pill-btn" onclick="filterRequests('approved', this)">
        <i class="fa-solid fa-check text-success"></i> Approved <span class="pill-count"><?= number_format($count_approved) ?></span>
    </button>
    <button type="button" class="filter-pill-btn" onclick="filterRequests('rejected', this)">
        <i class="fa-solid fa-xmark text-danger"></i> Rejected <span class="pill-count"><?= number_format($count_rejected) ?></span>
    </button>
</div>

<!-- Main Table Card Container -->
<div class="card-ui p-4 mb-4 position-relative">
    <!-- Loading State Dialog -->
    <div id="requestsLoadingBox" class="text-center py-5">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <h5 class="fw-bold text-dark mt-3 mb-1">Loading Package Requests...</h5>
        <div class="text-secondary small">Please wait while subscriber requests are being initialized.</div>
    </div>

    <!-- Table Container (Initially Hidden) -->
    <div id="requestsTableContainer" class="table-responsive" style="display: none;">
        <table id="requestsTable" class="table table-hover w-100">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Subscriber</th>
                    <th>Requested Package</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($requests as $r): ?>
                <tr>
                    <td>
                        <div class="small text-dark fw-semibold"><?= date('d M Y', strtotime($r['created_at'])) ?></div>
                        <div class="small text-muted font-monospace"><?= date('h:i A', strtotime($r['created_at'])) ?></div>
                    </td>
                    <td>
                        <div class="fw-bold text-dark">
                            <i class="fa-solid fa-user text-primary me-1"></i> <?= htmlspecialchars($r['username']) ?>
                        </div>
                        <?php if(!empty($r['full_name'])): ?>
                            <div class="small text-muted"><?= htmlspecialchars($r['full_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge rounded-pill badge-soft-info px-3 py-2 fw-bold" style="font-size: 0.86rem;">
                            <i class="fa-solid fa-box-open me-1"></i> <?= htmlspecialchars($r['pkg_name']) ?>
                        </span>
                        <?php if(!empty($r['rate_limit'])): ?>
                            <div class="small text-muted font-monospace mt-1"><?= htmlspecialchars($r['rate_limit']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($r['status'] === 'pending'): ?>
                            <span class="badge rounded-pill badge-soft-warning px-3 py-1 fw-bold">
                                <i class="fa-solid fa-hourglass-half me-1"></i> Pending
                            </span>
                        <?php elseif($r['status'] === 'approved'): ?>
                            <span class="badge rounded-pill badge-soft-success px-3 py-1 fw-bold">
                                <i class="fa-solid fa-check-double me-1"></i> Approved
                            </span>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-danger px-3 py-1 fw-bold">
                                <i class="fa-solid fa-xmark me-1"></i> Rejected
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if($r['status'] === 'pending'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="status" value="approved">
                                <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 py-1 fw-bold shadow-sm" onclick="return confirm('Approve this package request and update subscriber?')">
                                    <i class="fa-solid fa-check me-1"></i> Approve
                                </button>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="status" value="rejected">
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 py-1 fw-bold ms-1" onclick="return confirm('Reject this package request?')">
                                    <i class="fa-solid fa-xmark me-1"></i> Reject
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-1 small">
                                <i class="fa-solid fa-circle-check text-success me-1"></i> Processed
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
var requestsDT;

$(document).ready(function() {
    requestsDT = $('#requestsTable').DataTable({
        dom: '<"row align-items-center mb-3"<"col-md-2"l><"col-md-6 dt-buttons"B><"col-md-4"f>>rtip',
        buttons: [
            { extend: 'print', exportOptions: { columns: [0, 1, 2, 3] }, text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', exportOptions: { columns: [0, 1, 2, 3] }, text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'pdf', exportOptions: { columns: [0, 1, 2, 3] }, text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'excel', exportOptions: { columns: [0, 1, 2, 3] }, text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'csv', exportOptions: { columns: [0, 1, 2, 3] }, text: '<i class="fa-solid fa-file-csv"></i> CSV' }
        ],
        pageLength: 25,
        order: [[0, 'desc']],
        columnDefs: [
            { orderable: false, targets: [4] } // Disable sorting on Actions
        ],
        language: {
            search: "Search:",
            searchPlaceholder: "Type & Submit",
            emptyTable: '<div class="text-center py-4"><i class="fa-solid fa-bolt fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No package requests found.</div></div>',
            zeroRecords: '<div class="text-center py-4"><i class="fa-solid fa-magnifying-glass fa-2x text-muted mb-2"></i><div class="text-muted fw-semibold">No matching package requests found.</div></div>'
        },
        initComplete: function() {
            $('#requestsLoadingBox').fadeOut(120, function() {
                $('#requestsTableContainer').fadeIn(180, function() {
                    requestsDT.columns.adjust().draw();
                });
            });
        }
    });
});

function filterRequests(status, btn) {
    document.querySelectorAll('.filter-pill-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    if (status === 'all') {
        requestsDT.column(3).search('').draw();
    } else if (status === 'pending') {
        requestsDT.column(3).search('Pending', true, false).draw();
    } else if (status === 'approved') {
        requestsDT.column(3).search('Approved', true, false).draw();
    } else if (status === 'rejected') {
        requestsDT.column(3).search('Rejected', true, false).draw();
    }
}
</script>

<?php require_once 'footer.php'; ?>
