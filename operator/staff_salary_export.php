<?php
// =========================================================================
// Official Staff Salary & Lifetime Advance Ledger - Microsoft Excel Export
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

// Fetch Staff Details
$staff = null;
$roleTitle = 'Staff Member';

if ($staff_type === 'recoveryman') {
    $roleTitle = 'Recovery Officer';
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

// Fetch All Ledger Entries
$lStmt = $pdo->prepare("SELECT * FROM staff_salary_ledger 
    WHERE client_id = ? AND staff_type = ? AND staff_id = ? 
    ORDER BY trans_date ASC, id ASC");
$lStmt->execute([$client_id, $staff_type, $staff_id]);
$ledgerEntries = $lStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCredit = 0;
$totalDebit = 0;
foreach ($ledgerEntries as $e) {
    if ($e['direction'] === 'credit') $totalCredit += (int)$e['amount'];
    if ($e['direction'] === 'debit') $totalDebit += (int)$e['amount'];
}

// Prepare Excel Download Headers
$cleanStaff = preg_replace('/[^a-zA-Z0-9_-]/', '_', $staff_name);
$filename = "Staff_Ledger_{$cleanStaff}_" . date('Ymd_His') . ".xls";

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: max-age=0');
header('Pragma: public');

// Output UTF-8 BOM so Excel opens Urdu / English characters properly
echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Calibri, Arial, sans-serif; }
        .hdr { background-color: #1e3a8a; color: #ffffff; font-size: 16pt; font-weight: bold; text-align: center; }
        .subhdr { background-color: #3b82f6; color: #ffffff; font-size: 11pt; font-weight: bold; text-align: center; }
        .meta-label { font-weight: bold; background-color: #f1f5f9; }
        .meta-val { background-color: #ffffff; }
        .th-col { background-color: #0f172a; color: #ffffff; font-weight: bold; text-align: center; border: 1px solid #334155; }
        .td-cell { border: 1px solid #cbd5e1; vertical-align: middle; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .credit { color: #15803d; font-weight: bold; }
        .debit { color: #b91c1c; font-weight: bold; }
        .bal-neg { color: #b91c1c; font-weight: bold; background-color: #fee2e2; }
        .bal-pos { color: #15803d; font-weight: bold; background-color: #dcfce7; }
        .tfoot-row { background-color: #e2e8f0; font-weight: bold; border-top: 2px solid #000000; }
    </style>
</head>
<body>
    <table border="1" cellpadding="5" cellspacing="0">
        <!-- Title Header -->
        <tr>
            <td colspan="8" class="hdr"><?= htmlspecialchars($companyName) ?></td>
        </tr>
        <tr>
            <td colspan="8" class="subhdr">STAFF SALARY &amp; LIFETIME ADVANCE LEDGER</td>
        </tr>
        <tr>
            <td colspan="8" style="background-color: #ffffff;">&nbsp;</td>
        </tr>

        <!-- Staff Details -->
        <tr>
            <td class="meta-label">Staff Member:</td>
            <td class="meta-val" colspan="3"><strong><?= htmlspecialchars($staff_name) ?></strong> (<?= $roleTitle ?>)</td>
            <td class="meta-label">Username:</td>
            <td class="meta-val" colspan="3">@<?= htmlspecialchars($staff['username']) ?></td>
        </tr>
        <tr>
            <td class="meta-label">Contact Mobile:</td>
            <td class="meta-val" colspan="3"><?= htmlspecialchars($staff['phone'] ?: 'N/A') ?></td>
            <td class="meta-label">Station / City:</td>
            <td class="meta-val" colspan="3"><?= htmlspecialchars($staff['city'] ?: 'N/A') ?></td>
        </tr>
        <tr>
            <td class="meta-label">Monthly Fixed Salary:</td>
            <td class="meta-val" colspan="3"><strong>Rs. <?= number_format($monthly_sal, 0) ?></strong></td>
            <td class="meta-label">Joined Date:</td>
            <td class="meta-val" colspan="3"><?= date('d M Y', strtotime($joined_date)) ?></td>
        </tr>
        <tr>
            <td class="meta-label">Total Advance Drawn:</td>
            <td class="meta-val" colspan="3" style="color: #b45309; font-weight: bold;">Rs. <?= number_format($stats['total_advances'], 0) ?></td>
            <td class="meta-label">Net Closing Balance:</td>
            <td class="meta-val" colspan="3" style="font-weight: bold; <?= $curBal < 0 ? 'color: #b91c1c;' : 'color: #15803d;' ?>">
                <?= $curBal < 0 ? '- Rs. ' . number_format(abs($curBal), 0) . ' (Advance Overdrawn)' : 'Rs. ' . number_format($curBal, 0) . ' (Due Payable)' ?>
            </td>
        </tr>
        <tr>
            <td colspan="8" style="background-color: #ffffff;">&nbsp;</td>
        </tr>

        <!-- Table Columns -->
        <tr>
            <th class="th-col" style="width: 50px;">Sr #</th>
            <th class="th-col" style="width: 110px;">Date &amp; Time</th>
            <th class="th-col" style="width: 250px;">Particulars / Description</th>
            <th class="th-col" style="width: 130px;">Transaction Type</th>
            <th class="th-col" style="width: 140px;">Payment Method / Ref</th>
            <th class="th-col" style="width: 120px;">Credit (+)</th>
            <th class="th-col" style="width: 120px;">Debit (-)</th>
            <th class="th-col" style="width: 140px;">Balance (Rs.)</th>
        </tr>

        <!-- Table Rows -->
        <?php 
        $sr = 1;
        if (empty($ledgerEntries)): 
        ?>
            <tr>
                <td colspan="8" class="td-cell text-center" style="padding: 20px; color: #64748b;">No salary accrual or advance records found.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($ledgerEntries as $row): 
                $bAfter = (int)$row['balance_after'];
                $typeLbl = 'Salary Accrual';
                if ($row['trans_type'] === 'advance_payment') $typeLbl = 'Advance Paid';
                elseif ($row['trans_type'] === 'salary_payment') $typeLbl = 'Salary Payout';
                elseif ($row['trans_type'] === 'bonus') $typeLbl = 'Bonus Credit';
                elseif ($row['trans_type'] === 'deduction') $typeLbl = 'Deduction';
            ?>
            <tr>
                <td class="td-cell text-center"><?= $sr++ ?></td>
                <td class="td-cell text-center"><?= date('d-m-Y h:i A', strtotime($row['created_at'])) ?></td>
                <td class="td-cell text-left">
                    <?= htmlspecialchars($row['notes'] ?: $typeLbl) ?>
                    <?php if (!empty($row['expense_id'])): ?> (Expense #<?= $row['expense_id'] ?>)<?php endif; ?>
                </td>
                <td class="td-cell text-center"><?= $typeLbl ?></td>
                <td class="td-cell text-center">
                    <?= htmlspecialchars($row['payment_method'] ?: 'Cash') ?>
                    <?php if (!empty($row['reference_no'])): ?> [Ref: <?= htmlspecialchars($row['reference_no']) ?>]<?php endif; ?>
                </td>
                <td class="td-cell text-right credit">
                    <?= $row['direction'] === 'credit' ? number_format($row['amount'], 0) : '0' ?>
                </td>
                <td class="td-cell text-right debit">
                    <?= $row['direction'] === 'debit' ? number_format($row['amount'], 0) : '0' ?>
                </td>
                <td class="td-cell text-right <?= $bAfter < 0 ? 'bal-neg' : ($bAfter > 0 ? 'bal-pos' : '') ?>">
                    <?= number_format($bAfter, 0) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Totals Row -->
        <tr class="tfoot-row">
            <td colspan="5" class="td-cell text-right"><strong>TOTAL MOVEMENT &amp; CLOSING BALANCE:</strong></td>
            <td class="td-cell text-right credit"><?= number_format($totalCredit, 0) ?></td>
            <td class="td-cell text-right debit"><?= number_format($totalDebit, 0) ?></td>
            <td class="td-cell text-right <?= $curBal < 0 ? 'bal-neg' : ($curBal > 0 ? 'bal-pos' : '') ?>">
                <strong><?= number_format($curBal, 0) ?></strong>
            </td>
        </tr>
    </table>
</body>
</html>
