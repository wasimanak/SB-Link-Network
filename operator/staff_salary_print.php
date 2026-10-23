<?php
// =========================================================================
// Official Staff Salary & Lifetime Advance Ledger - A4 Printable / PDF View
// =========================================================================

session_start();
require_once '../config/db.php';
require_once 'staff_salary_helper.php';

// Auth Check
if (!isset($_SESSION['operator_logged_in']) || !isset($_SESSION['operator_id'])) {
    header("Location: ../login.php");
    exit;
}

$client_id = (int)$_SESSION['operator_id'];
$staff_type = $_GET['type'] ?? 'recoveryman';
$staff_id = (int)($_GET['id'] ?? 0);

if (!in_array($staff_type, ['recoveryman', 'lineman', 'manager']) || $staff_id <= 0) {
    die("Invalid staff parameters.");
}

// Fetch Client / Operator Details
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
$companyName = !empty($client['company_name']) ? $client['company_name'] : 'SB Link Network';
$clientPhone = $client['phone'] ?? '';
$clientEmail = $client['email'] ?? '';
$clientAddress = $client['address'] ?? '';

// Fetch Staff Details
$staff = null;
$roleTitle = 'Staff Member';

if ($staff_type === 'recoveryman') {
    $roleTitle = 'Operator';
    $sStmt = $pdo->prepare("SELECT * FROM recovery_men WHERE id = ? AND client_id = ?");
    $sStmt->execute([$staff_id, $client_id]);
    $staff = $sStmt->fetch(PDO::FETCH_ASSOC);
} elseif ($staff_type === 'lineman') {
    $roleTitle = 'Line Man / Technician';
    $sStmt = $pdo->prepare("SELECT * FROM linemen WHERE id = ? AND client_id = ?");
    $sStmt->execute([$staff_id, $client_id]);
    $staff = $sStmt->fetch(PDO::FETCH_ASSOC);
} elseif ($staff_type === 'manager') {
    $roleTitle = 'Team Manager';
    $sStmt = $pdo->prepare("SELECT * FROM managers WHERE id = ? AND client_id = ?");
    $sStmt->execute([$staff_id, $client_id]);
    $staff = $sStmt->fetch(PDO::FETCH_ASSOC);
}

if (!$staff) {
    die("Staff member not found or access denied.");
}

$staff_name = $staff['full_name'];
$monthly_sal = (int)($staff['monthly_salary'] ?? 0);
$joined_date = $staff['created_at'] ?? date('Y-m-d');

// Sync auto-accrual if 1st of month
sync_staff_monthly_salary($pdo, $client_id, $staff_type, $staff_id, $staff_name, $monthly_sal, $joined_date);

// Summary Stats
$stats = get_staff_salary_summary($pdo, $client_id, $staff_type, $staff_id, $monthly_sal);
$curBal = (int)$stats['balance'];

// Fetch All Ledger Entries in chronological order for official statement
$orderDir = (isset($_GET['order']) && strtolower($_GET['order']) === 'desc') ? 'DESC' : 'ASC';
$lStmt = $pdo->prepare("SELECT * FROM staff_salary_ledger 
    WHERE client_id = ? AND staff_type = ? AND staff_id = ? 
    ORDER BY trans_date {$orderDir}, id {$orderDir}");
$lStmt->execute([$client_id, $staff_type, $staff_id]);
$ledgerEntries = $lStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCredit = 0;
$totalDebit = 0;
foreach ($ledgerEntries as $e) {
    if ($e['direction'] === 'credit') $totalCredit += (int)$e['amount'];
    if ($e['direction'] === 'debit') $totalDebit += (int)$e['amount'];
}

$refNo = "STF-" . strtoupper(substr($staff_type, 0, 3)) . "-{$staff_id}-" . date('Ymd');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary &amp; Advance Ledger - <?= htmlspecialchars($staff_name) ?> (<?= htmlspecialchars($companyName) ?>)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 12mm 12mm 12mm;
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif;
            background: #e2e8f0;
            color: #1e293b;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
            padding: 20px 0;
        }

        .a4-sheet {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 14mm 16mm;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            border-radius: 4px;
            box-sizing: border-box;
            position: relative;
        }

        /* Top Action Bar (Hidden in Print) */
        .top-action-bar {
            width: 210mm;
            margin: 0 auto 15px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Printable typography & components */
        .company-title {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            text-transform: uppercase;
        }

        .document-title {
            font-size: 15px;
            font-weight: 800;
            color: #1e40af;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
            height: 100%;
        }

        .info-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
        }

        .info-value {
            font-size: 12px;
            font-weight: 600;
            color: #0f172a;
        }

        .metric-box {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            text-align: center;
            background: #f8fafc;
        }

        .metric-box .metric-title {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 2px;
        }

        .metric-box .metric-val {
            font-size: 14px;
            font-weight: 800;
        }

        .metric-box.box-danger {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .metric-box.box-success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .metric-box.box-primary {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1e40af;
        }

        .metric-box.box-warning {
            background: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }

        /* Statement Ledger Table */
        .ledger-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 10px;
        }

        .ledger-table th {
            background: #1e293b;
            color: #ffffff;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 9.5px;
            letter-spacing: 0.3px;
            padding: 6px 8px;
            border: 1px solid #0f172a;
            text-align: left;
        }

        .ledger-table td {
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
            color: #334155;
        }

        .ledger-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .ledger-table tfoot td {
            background: #f1f5f9;
            font-weight: 800;
            border-top: 2px solid #0f172a;
            padding: 8px;
        }

        .type-badge-accrual { color: #0284c7; font-weight: 700; }
        .type-badge-advance { color: #b45309; font-weight: 700; }
        .type-badge-salary { color: #15803d; font-weight: 700; }
        .type-badge-bonus { color: #7e22ce; font-weight: 700; }
        .type-badge-deduction { color: #b91c1c; font-weight: 700; }

        .signature-section {
            margin-top: 35px;
            page-break-inside: avoid;
        }

        .signature-line {
            border-top: 1px dashed #64748b;
            margin-top: 45px;
            padding-top: 5px;
            font-weight: 700;
            font-size: 11px;
            color: #334155;
            text-align: center;
        }

        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .no-print {
                display: none !important;
            }

            .a4-sheet {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                width: 100% !important;
                margin: 0 !important;
            }

            .ledger-table th {
                background: #1e293b !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .metric-box, .info-card {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            tr {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <!-- Top Action Bar (Hidden on Print) -->
    <div class="top-action-bar no-print">
        <div>
            <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-bold">
                <i class="fa-solid fa-arrow-left me-1"></i> Back
            </a>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="staff_salary_export.php?type=<?= urlencode($staff_type) ?>&id=<?= (int)$staff_id ?>" class="btn btn-success btn-sm rounded-pill px-3 fw-bold shadow-sm">
                <i class="fa-solid fa-file-excel me-1"></i> Export to Excel
            </a>
            <button onclick="window.print()" class="btn btn-danger btn-sm rounded-pill px-4 fw-bold shadow-sm">
                <i class="fa-solid fa-print me-1"></i> Print / Save as PDF (A4)
            </button>
        </div>
    </div>

    <!-- Official A4 Sheet Document -->
    <div class="a4-sheet">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
            <div>
                <div class="company-title d-flex align-items-center gap-2">
                    <i class="fa-solid fa-tower-broadcast text-primary"></i> <?= htmlspecialchars($companyName) ?>
                </div>
                <div class="text-muted small fw-semibold">Internet Service Provider &amp; Enterprise Network Solutions</div>
                <?php if (!empty($clientAddress)): ?>
                    <div class="text-secondary small mt-1"><i class="fa-solid fa-location-dot me-1 text-muted"></i> <?= htmlspecialchars($clientAddress) ?></div>
                <?php endif; ?>
                <div class="text-secondary small">
                    <?php if (!empty($clientPhone)): ?><span class="me-3"><i class="fa-solid fa-phone me-1 text-muted"></i> <?= htmlspecialchars($clientPhone) ?></span><?php endif; ?>
                    <?php if (!empty($clientEmail)): ?><span><i class="fa-solid fa-envelope me-1 text-muted"></i> <?= htmlspecialchars($clientEmail) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="text-end">
                <div class="document-title"><i class="fa-solid fa-receipt me-1"></i> Staff Salary &amp; Advance Ledger</div>
                <div class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 mt-1 font-monospace fw-bold">
                    Ref: <?= $refNo ?>
                </div>
                <div class="text-muted small mt-1">Generated: <?= date('d M Y, h:i A') ?></div>
                <div class="text-muted small">Period: <strong>Lifetime Statement</strong></div>
            </div>
        </div>

        <!-- Staff Particulars & Financial Snapshot Grid -->
        <div class="row g-2 mb-3">
            <!-- Staff Particulars -->
            <div class="col-6">
                <div class="info-card">
                    <div class="info-label mb-1">Staff Member Particulars</div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Full Name:</span>
                        <span class="info-value text-primary"><?= htmlspecialchars($staff_name) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Designation:</span>
                        <span class="info-value"><?= $roleTitle ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Username:</span>
                        <span class="info-value font-monospace">@<?= htmlspecialchars($staff['username']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="text-muted">Contact Phone:</span>
                        <span class="info-value"><?= htmlspecialchars($staff['phone'] ?: 'N/A') ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Station / Joined:</span>
                        <span class="info-value"><?= htmlspecialchars($staff['city'] ?: 'N/A') ?> (<?= date('d M Y', strtotime($joined_date)) ?>)</span>
                    </div>
                </div>
            </div>

            <!-- Financial Metrics Box -->
            <div class="col-6">
                <div class="row g-2 h-100">
                    <div class="col-6">
                        <div class="metric-box box-primary h-100 d-flex flex-column justify-content-center">
                            <div class="metric-title">Monthly Fixed Salary</div>
                            <div class="metric-val text-primary">Rs. <?= number_format($monthly_sal, 0) ?></div>
                            <span class="text-muted" style="font-size: 8.5px;">Accrued 1st of every month</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-box box-warning h-100 d-flex flex-column justify-content-center">
                            <div class="metric-title">Total Advance Taken</div>
                            <div class="metric-val text-warning">Rs. <?= number_format($stats['total_advances'], 0) ?></div>
                            <span class="text-muted" style="font-size: 8.5px;">Lifetime advance payments</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="metric-box box-success h-100 d-flex flex-column justify-content-center">
                            <div class="metric-title">Total Salary Accrued</div>
                            <div class="metric-val text-success">Rs. <?= number_format($stats['total_accrued'], 0) ?></div>
                            <span class="text-muted" style="font-size: 8.5px;">Total earnings recorded</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <?php if ($curBal < 0): ?>
                            <div class="metric-box box-danger h-100 d-flex flex-column justify-content-center">
                                <div class="metric-title">Advance Overdrawn</div>
                                <div class="metric-val text-danger">- Rs. <?= number_format(abs($curBal), 0) ?></div>
                                <span class="fw-bold text-danger" style="font-size: 8.5px;">Overdrawn (Deductible)</span>
                            </div>
                        <?php elseif ($curBal > 0): ?>
                            <div class="metric-box box-success h-100 d-flex flex-column justify-content-center">
                                <div class="metric-title">Net Salary Due</div>
                                <div class="metric-val text-success">Rs. <?= number_format($curBal, 0) ?></div>
                                <span class="fw-bold text-success" style="font-size: 8.5px;">Payable Balance</span>
                            </div>
                        <?php else: ?>
                            <div class="metric-box h-100 d-flex flex-column justify-content-center">
                                <div class="metric-title">Net Balance</div>
                                <div class="metric-val text-secondary">Rs. 0</div>
                                <span class="text-muted" style="font-size: 8.5px;">Accounts Cleared</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ledger Statement Table -->
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 32px; text-align: center;">#</th>
                    <th style="width: 75px;">Date</th>
                    <th>Particulars / Description</th>
                    <th style="width: 100px;">Type</th>
                    <th style="width: 95px;">Method / Ref</th>
                    <th style="width: 80px; text-align: right;">Credit (+)</th>
                    <th style="width: 80px; text-align: right;">Debit (-)</th>
                    <th style="width: 90px; text-align: right;">Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledgerEntries)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            <i class="fa-solid fa-receipt fa-2x mb-2 d-block"></i>
                            No salary accrual or advance records found in the lifetime ledger.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $sr = 1;
                    foreach ($ledgerEntries as $row): 
                        $bAfter = (int)$row['balance_after'];
                        $typeCls = 'type-badge-accrual';
                        $typeLbl = 'Salary Accrual';
                        if ($row['trans_type'] === 'advance_payment') {
                            $typeCls = 'type-badge-advance';
                            $typeLbl = 'Advance Paid';
                        } elseif ($row['trans_type'] === 'salary_payment') {
                            $typeCls = 'type-badge-salary';
                            $typeLbl = 'Salary Payout';
                        } elseif ($row['trans_type'] === 'bonus') {
                            $typeCls = 'type-badge-bonus';
                            $typeLbl = 'Bonus Credit';
                        } elseif ($row['trans_type'] === 'deduction') {
                            $typeCls = 'type-badge-deduction';
                            $typeLbl = 'Deduction';
                        }
                    ?>
                    <tr>
                        <td style="text-align: center;" class="text-muted fw-bold"><?= $sr++ ?></td>
                        <td>
                            <div class="fw-bold"><?= date('d M Y', strtotime($row['trans_date'])) ?></div>
                            <div class="text-muted" style="font-size: 9px;"><?= date('h:i A', strtotime($row['created_at'])) ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark"><?= htmlspecialchars($row['notes'] ?: $typeLbl) ?></div>
                            <?php if (!empty($row['expense_id'])): ?>
                                <span class="text-muted" style="font-size: 8.5px;">(Expense #<?= $row['expense_id'] ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?= $typeCls ?>"><?= $typeLbl ?></span>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($row['payment_method'] ?: 'Cash') ?></div>
                            <?php if (!empty($row['reference_no'])): ?>
                                <div class="text-muted font-monospace" style="font-size: 8.5px;">Ref: <?= htmlspecialchars($row['reference_no']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <?php if ($row['direction'] === 'credit'): ?>
                                <span class="fw-bold text-success">+ Rs. <?= number_format($row['amount'], 0) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <?php if ($row['direction'] === 'debit'): ?>
                                <span class="fw-bold text-danger">- Rs. <?= number_format($row['amount'], 0) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <?php if ($bAfter < 0): ?>
                                <span class="fw-bold text-danger">- Rs. <?= number_format(abs($bAfter), 0) ?></span>
                            <?php elseif ($bAfter > 0): ?>
                                <span class="fw-bold text-success">Rs. <?= number_format($bAfter, 0) ?></span>
                            <?php else: ?>
                                <span class="fw-bold text-muted">Rs. 0</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align: right;">TOTAL LIFETIME MOVEMENT &amp; CLOSING BALANCE:</td>
                    <td style="text-align: right;" class="text-success">+ Rs. <?= number_format($totalCredit, 0) ?></td>
                    <td style="text-align: right;" class="text-danger">- Rs. <?= number_format($totalDebit, 0) ?></td>
                    <td style="text-align: right;">
                        <?php if ($curBal < 0): ?>
                            <span class="text-danger fw-bold">- Rs. <?= number_format(abs($curBal), 0) ?></span>
                        <?php elseif ($curBal > 0): ?>
                            <span class="text-success fw-bold">Rs. <?= number_format($curBal, 0) ?></span>
                        <?php else: ?>
                            <span class="text-muted fw-bold">Rs. 0</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- Signatures & Official Stamp Area -->
        <div class="signature-section">
            <div class="row pt-4">
                <div class="col-4 text-center">
                    <div class="signature-line">
                        Prepared &amp; Verified By<br>
                        <span class="text-muted small fw-normal">(Accounts / Operator)</span>
                    </div>
                </div>
                <div class="col-4 text-center">
                    <div class="signature-line">
                        Authorized Management Signature<br>
                        <span class="text-muted small fw-normal">(<?= htmlspecialchars($companyName) ?>)</span>
                    </div>
                </div>
                <div class="col-4 text-center">
                    <div class="signature-line">
                        Staff Member Signature<br>
                        <span class="text-muted small fw-normal">(<?= htmlspecialchars($staff_name) ?>)</span>
                    </div>
                </div>
            </div>

            <!-- Footer Notice -->
            <div class="text-center text-muted border-top mt-4 pt-2" style="font-size: 9.5px;">
                <i class="fa-solid fa-shield-halved me-1 text-primary"></i> This document is an official enterprise payroll &amp; advance ledger record issued by <strong><?= htmlspecialchars($companyName) ?></strong>.
                All salary payments and advance disbursements recorded herein are reconciled with daily business expense accounting.
            </div>
        </div>

    </div>

    <?php if (isset($_GET['autoprint']) && $_GET['autoprint'] == '1'): ?>
    <script>
        window.onload = function() {
            window.print();
        }
    </script>
    <?php endif; ?>

</body>
</html>
