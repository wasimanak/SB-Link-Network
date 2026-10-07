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
</style>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-file-invoice-dollar text-primary me-2"></i> Operator Recharge Statement</h4>
            <p class="text-secondary mb-0">Lifetime advance / recharge history of all users (unaffected by package renewals).</p>
        </div>
        <a href="profile.php" class="btn btn-light border fw-bold text-secondary shadow-sm"><i class="fa-solid fa-arrow-left me-2"></i> Back to Profile</a>
    </div>

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
                            <div class="fw-bold text-primary" style="font-size: 1.05rem;"><?= htmlspecialchars($u['username']) ?></div>
                            <div class="text-secondary" style="font-size: 0.85rem;"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($u['full_name']) ?></div>
                        </td>
                        <td>
                            <?php if($u['dealer_username']): ?>
                                <span class="badge bg-secondary"><i class="fa-solid fa-store me-1"></i> <?= htmlspecialchars($u['dealer_username']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-primary"><i class="fa-solid fa-user-shield me-1"></i> Operator</span>
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
});
</script>

<?php require_once 'footer.php'; ?>
