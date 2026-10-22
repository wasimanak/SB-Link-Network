<?php
// =========================================================================
// Staff Salary View & Lifetime Ledger UI Component
// Renders KPI Cards (Remaining Salary, Monthly Salary, Advance Paid),
// the Advance/Salary Payment Modal, and the complete Lifetime Ledger.
// =========================================================================

require_once __DIR__ . '/staff_salary_helper.php';

// Variables expected before including this view:
// $client_id   - (int)
// $staff_type  - 'recoveryman' | 'lineman' | 'manager'
// $staff_id    - (int)
// $staff_name  - string
// $monthly_sal - (int)
// $joined_date - (string or null)

// Ensure schema & monthly 1st auto-accrual is synced
sync_staff_monthly_salary($pdo, $client_id, $staff_type, $staff_id, $staff_name, $monthly_sal, $joined_date);

// Handle POST actions for this staff member
$salaryFlash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $salAction = $_POST['action'] ?? '';
    
    if ($salAction === 'record_staff_payment') {
        $actor = $_SESSION['operator_username'] ?? 'Operator';
        $res = record_staff_payment($pdo, $client_id, $staff_type, $staff_id, $staff_name, $_POST, $actor);
        $salaryFlash = ['type' => $res['success'] ? 'success' : 'danger', 'msg' => $res['message']];
    }

    if ($salAction === 'delete_staff_transaction') {
        $transId = (int)($_POST['transaction_id'] ?? 0);
        $actor = $_SESSION['operator_username'] ?? 'Operator';
        $res = delete_staff_transaction($pdo, $client_id, $transId, $actor);
        $salaryFlash = ['type' => $res['success'] ? 'success' : 'danger', 'msg' => $res['message']];
    }
}

// Fetch up-to-date stats
$stats = get_staff_salary_summary($pdo, $client_id, $staff_type, $staff_id, $monthly_sal);
$curBal = $stats['balance'];

// Fetch Lifetime Ledger Entries
$ledgerStmt = $pdo->prepare("SELECT * FROM staff_salary_ledger 
    WHERE client_id = ? AND staff_type = ? AND staff_id = ? 
    ORDER BY trans_date DESC, id DESC");
$ledgerStmt->execute([$client_id, $staff_type, $staff_id]);
$ledgerEntries = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.salary-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 20px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.03);
    transition: transform 0.2s, box-shadow 0.2s;
    height: 100%;
}
.salary-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.06);
}
.salary-kpi-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}
.badge-salary-accrual { background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; }
.badge-advance-payment { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
.badge-salary-payment { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.badge-bonus { background: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; }
.badge-deduction { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
</style>

<!-- Flash Alert for Salary Actions -->
<?php if ($salaryFlash): ?>
    <div class="alert alert-<?= htmlspecialchars($salaryFlash['type']) ?> alert-dismissible fade show rounded-4 shadow-sm mb-4" role="alert">
        <div class="d-flex align-items-center gap-2">
            <i class="fa-solid <?= $salaryFlash['type'] === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> fs-5"></i>
            <div class="fw-semibold"><?= htmlspecialchars($salaryFlash['msg']) ?></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- 4 KPI Cards: Salary, Advance Paid, Remaining Balance, Lifetime -->
<div class="row g-3 mb-4">
    <!-- 1. Monthly Salary -->
    <div class="col-sm-6 col-xl-3">
        <div class="salary-kpi-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small fw-bold text-uppercase">Monthly Salary</span>
                <div class="salary-kpi-icon" style="background: #e0f2fe; color: #0284c7;">
                    <i class="fa-solid fa-money-bill-wave"></i>
                </div>
            </div>
            <h3 class="fw-bold text-dark mb-1">Rs. <?= number_format($stats['monthly_salary'], 0) ?></h3>
            <span class="text-muted small">Fixed Monthly Base</span>
        </div>
    </div>

    <!-- 2. Advance Paid This Month -->
    <div class="col-sm-6 col-xl-3">
        <div class="salary-kpi-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small fw-bold text-uppercase">Advance This Month</span>
                <div class="salary-kpi-icon" style="background: #fef3c7; color: #d97706;">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>
            </div>
            <h3 class="fw-bold text-warning mb-1">Rs. <?= number_format($stats['advance_this_month'], 0) ?></h3>
            <span class="text-muted small">Total Advance Drawn (<?= date('M Y') ?>)</span>
        </div>
    </div>

    <!-- 3. Remaining Salary / Net Balance (Red Minus if Overdrawn) -->
    <div class="col-sm-6 col-xl-3">
        <div class="salary-kpi-card" style="<?= ($curBal < 0) ? 'border-color: #fca5a5; background: #fff5f5;' : (($curBal > 0) ? 'border-color: #86efac; background: #f0fdf4;' : '') ?>">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="small fw-bold text-uppercase <?= ($curBal < 0) ? 'text-danger' : 'text-success' ?>">
                    <?= ($curBal < 0) ? 'Advance Exceeded' : 'Remaining Payable' ?>
                </span>
                <div class="salary-kpi-icon" style="<?= ($curBal < 0) ? 'background: #fee2e2; color: #dc2626;' : 'background: #dcfce7; color: #16a34a;' ?>">
                    <i class="fa-solid <?= ($curBal < 0) ? 'fa-circle-exclamation' : 'fa-wallet' ?>"></i>
                </div>
            </div>
            <h3 class="fw-bold mb-1 <?= ($curBal < 0) ? 'text-danger' : (($curBal > 0) ? 'text-success' : 'text-muted') ?>">
                <?php if ($curBal < 0): ?>
                    - Rs. <?= number_format(abs($curBal), 0) ?>
                <?php else: ?>
                    Rs. <?= number_format($curBal, 0) ?>
                <?php endif; ?>
            </h3>
            <span class="small <?= ($curBal < 0) ? 'text-danger fw-semibold' : 'text-muted' ?>">
                <?= ($curBal < 0) ? 'Staff owes company (Excess Advance)' : (($curBal > 0) ? 'Company owes staff (Payable)' : 'All accounts settled') ?>
            </span>
        </div>
    </div>

    <!-- 4. Action Button Card: Pay Salary / Advance -->
    <div class="col-sm-6 col-xl-3">
        <div class="salary-kpi-card d-flex flex-column justify-content-center align-items-center text-center p-3" style="background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff;">
            <div class="small text-white-50 mb-2">Payroll Quick Action</div>
            <button type="button" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm d-inline-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#payStaffSalaryModal" style="background: linear-gradient(135deg, #10b981, #059669); border: none;">
                <i class="fa-solid fa-plus-circle"></i>
                <span>Pay Salary / Advance</span>
            </button>
            <span class="text-white-50 small mt-2" style="font-size: 0.72rem;">Auto-logged to Daily Expenses</span>
        </div>
    </div>
</div>

<!-- Main Salary Ledger Card -->
<div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
    <div class="card-header bg-white py-3 px-4 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                <i class="fa-solid fa-book-open text-primary"></i> Lifetime Salary &amp; Advance Ledger
            </h5>
            <span class="text-muted small">Complete historical statement of monthly salary accruals, advance payouts, and balance adjustments.</span>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a href="staff_salary_print.php?type=<?= urlencode($staff_type) ?>&id=<?= (int)$staff_id ?>" target="_blank" class="btn btn-danger rounded-pill btn-sm px-3 fw-bold shadow-sm d-inline-flex align-items-center gap-1" title="Open A4 Size PDF Printable Statement">
                <i class="fa-solid fa-file-pdf"></i> Download PDF (A4)
            </a>
            <a href="staff_salary_export.php?type=<?= urlencode($staff_type) ?>&id=<?= (int)$staff_id ?>" class="btn btn-success rounded-pill btn-sm px-3 fw-bold shadow-sm d-inline-flex align-items-center gap-1" title="Download Formatted Excel Spreadsheet">
                <i class="fa-solid fa-file-excel"></i> Export Excel
            </a>
            <button type="button" class="btn btn-primary rounded-pill btn-sm px-3 fw-bold shadow-sm d-inline-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#payStaffSalaryModal">
                <i class="fa-solid fa-hand-holding-dollar me-1"></i> Give Payment / Advance
            </button>
        </div>
    </div>

    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table align-middle table-hover" id="staffSalaryLedgerTable">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Date</th>
                        <th>Particulars / Description</th>
                        <th>Type</th>
                        <th>Method / Ref</th>
                        <th class="text-end">Credit (+)</th>
                        <th class="text-end">Debit (-)</th>
                        <th class="text-end">Balance After</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ledgerEntries as $entry): 
                            $balAfter = (int)$entry['balance_after'];
                            $typeBadge = 'badge-salary-accrual';
                            $typeText = 'Salary Accrual';
                            if ($entry['trans_type'] === 'advance_payment') {
                                $typeBadge = 'badge-advance-payment';
                                $typeText = 'Advance Paid';
                            } elseif ($entry['trans_type'] === 'salary_payment') {
                                $typeBadge = 'badge-salary-payment';
                                $typeText = 'Salary Payout';
                            } elseif ($entry['trans_type'] === 'bonus') {
                                $typeBadge = 'badge-bonus';
                                $typeText = 'Bonus';
                            } elseif ($entry['trans_type'] === 'deduction') {
                                $typeBadge = 'badge-deduction';
                                $typeText = 'Deduction';
                            }
                        ?>
                        <tr>
                            <td class="text-muted fw-semibold">#<?= $entry['id'] ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= date('d M Y', strtotime($entry['trans_date'])) ?></div>
                                <div class="text-muted small" style="font-size: 0.72rem;"><?= date('h:i A', strtotime($entry['created_at'])) ?></div>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($entry['notes'] ?: $typeText) ?></div>
                                <?php if (!empty($entry['expense_id'])): ?>
                                    <span class="badge bg-light text-secondary border rounded-pill" style="font-size: 0.68rem;">
                                        <i class="fa-solid fa-receipt text-danger me-1"></i> In Expenses (EXP-<?= $entry['expense_id'] ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge rounded-pill px-3 py-1 <?= $typeBadge ?>">
                                    <?= $typeText ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-dark small fw-semibold"><?= htmlspecialchars($entry['payment_method'] ?: 'Cash') ?></span>
                                <?php if (!empty($entry['reference_no'])): ?>
                                    <div class="text-muted small font-monospace" style="font-size: 0.72rem;">Ref: <?= htmlspecialchars($entry['reference_no']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($entry['direction'] === 'credit'): ?>
                                    <span class="fw-bold text-success fs-6">+ Rs. <?= number_format($entry['amount'], 0) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($entry['direction'] === 'debit'): ?>
                                    <span class="fw-bold text-danger fs-6">- Rs. <?= number_format($entry['amount'], 0) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($balAfter < 0): ?>
                                    <span class="fw-bold text-danger fs-6">- Rs. <?= number_format(abs($balAfter), 0) ?></span>
                                <?php else: ?>
                                    <span class="fw-bold text-success fs-6">Rs. <?= number_format($balAfter, 0) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: PAY SALARY / ADVANCE                                    -->
<!-- ============================================================== -->
<div class="modal fade" id="payStaffSalaryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header text-white py-3 px-4" style="background: linear-gradient(135deg, #0f172a, #1e293b);">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-hand-holding-dollar fs-5 text-warning"></i>
                    <h5 class="modal-title fw-bold mb-0">Record Staff Payment / Advance</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="record_staff_payment">

                <div class="modal-body p-4">
                    <!-- Staff Overview Header Banner -->
                    <div class="bg-light border rounded-3 p-3 mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <div class="text-muted small">Paying Team Member:</div>
                            <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($staff_name) ?> <span class="badge bg-secondary rounded-pill small ms-1"><?= ucfirst($staff_type) ?></span></h5>
                        </div>
                        <div class="text-end">
                            <div class="text-muted small">Current Ledger Balance:</div>
                            <h5 class="fw-bold mb-0 <?= ($curBal < 0) ? 'text-danger' : 'text-success' ?>">
                                <?= ($curBal < 0) ? '- Rs. ' . number_format(abs($curBal), 0) : 'Rs. ' . number_format($curBal, 0) ?>
                            </h5>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Transaction Type -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Payment Type <span class="text-danger">*</span>
                            </label>
                            <select name="trans_type" class="form-select rounded-3" required>
                                <option value="advance_payment" selected>Advance Salary (Debit -)</option>
                                <option value="salary_payment">Salary Payout (Debit -)</option>
                                <option value="bonus">Bonus / Incentive (Credit +)</option>
                                <option value="deduction">Deduction / Fine (Debit -)</option>
                            </select>
                            <div class="form-text text-muted" style="font-size: 0.72rem;">Advance &amp; Salary payouts automatically create an Expense.</div>
                        </div>

                        <!-- Amount (PKR) without decimals -->
                        <div class="col-md-6">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Amount in PKR <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted fw-bold">Rs.</span>
                                <input type="number" step="1" min="1" name="amount" class="form-control rounded-end-3 fw-bold" placeholder="e.g. 5000" required>
                            </div>
                        </div>

                        <!-- Payment Date -->
                        <div class="col-md-4">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="trans_date" class="form-control rounded-3" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <!-- Payment Method -->
                        <div class="col-md-4">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Payment Method <span class="text-danger">*</span>
                            </label>
                            <select name="payment_method" class="form-select rounded-3">
                                <option value="Cash" selected>Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="EasyPaisa">EasyPaisa</option>
                                <option value="JazzCash">JazzCash</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <!-- Reference / Receipt # -->
                        <div class="col-md-4">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Reference # (Optional)
                            </label>
                            <input type="text" name="reference_no" class="form-control rounded-3" placeholder="Slip # / Trx ID">
                        </div>

                        <!-- Notes / Description -->
                        <div class="col-12">
                            <label class="form-label text-dark fw-bold small mb-1">
                                Remarks / Reason / Note (Optional)
                            </label>
                            <input type="text" name="notes" class="form-control rounded-3" placeholder="e.g. Advance requested for home maintenance / emergency">
                        </div>
                    </div>

                    <!-- Informational Notice regarding auto-expenses -->
                    <div class="alert alert-info rounded-3 mt-3 mb-0 py-2 px-3 small d-flex align-items-center gap-2">
                        <i class="fa-solid fa-circle-info text-info fs-5"></i>
                        <div><strong>Daily Expenses Integration:</strong> Recorded advances and salary payouts will automatically reflect in <strong>Expenses &gt; Staff Salary &amp; Advance</strong>.</div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top py-3 px-4 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary rounded-pill btn-sm px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success rounded-pill btn-sm px-4 fw-bold shadow-sm d-inline-flex align-items-center gap-1">
                        <i class="fa-solid fa-check me-1"></i> Save &amp; Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DataTables Buttons & Export Dependencies -->
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    if ($.fn.DataTable.isDataTable('#staffSalaryLedgerTable')) {
        $('#staffSalaryLedgerTable').DataTable().destroy();
    }
    $('#staffSalaryLedgerTable').DataTable({
        dom: '<"row align-items-center mb-3"<"col-md-3"l><"col-md-6 dt-buttons"B><"col-md-3"f>>rtip',
        buttons: [
            {
                extend: 'excelHtml5',
                className: 'btn btn-outline-success btn-sm rounded-pill px-3 shadow-sm',
                text: '<i class="fa-solid fa-file-excel me-1"></i> Excel',
                title: 'Staff_Salary_Ledger_<?= preg_replace("/[^a-zA-Z0-9_-]/", "_", $staff_name) ?>_<?= date("Ymd") ?>',
                exportOptions: { columns: ':visible' }
            },
            {
                extend: 'pdfHtml5',
                className: 'btn btn-outline-danger btn-sm rounded-pill px-3 shadow-sm',
                text: '<i class="fa-solid fa-file-pdf me-1"></i> PDF (A4)',
                title: 'Staff Salary & Advance Ledger - <?= htmlspecialchars($staff_name, ENT_QUOTES) ?>',
                orientation: 'portrait',
                pageSize: 'A4',
                exportOptions: { columns: ':visible' },
                customize: function (doc) {
                    doc.defaultStyle.fontSize = 8.5;
                    doc.styles.tableHeader.fontSize = 9.5;
                    doc.styles.tableHeader.fillColor = '#1e293b';
                    if (doc.content[1] && doc.content[1].table) {
                        doc.content[1].table.widths = ['6%', '14%', '28%', '14%', '12%', '9%', '9%', '8%'];
                    }
                }
            },
            {
                extend: 'print',
                className: 'btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-sm',
                text: '<i class="fa-solid fa-print me-1"></i> Print',
                title: 'Staff Salary & Advance Ledger - <?= htmlspecialchars($staff_name, ENT_QUOTES) ?>'
            }
        ],
        pageLength: 25,
        order: [[1, 'desc']],
        language: {
            search: "Search Ledger:",
            searchPlaceholder: "Search date, note, type...",
            emptyTable: '<div class="text-center py-4 text-muted"><i class="fa-solid fa-receipt fa-2x mb-2 text-muted"></i><div>No salary or advance records found for this team member.</div></div>'
        }
    });
});
</script>
