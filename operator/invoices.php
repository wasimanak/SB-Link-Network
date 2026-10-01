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
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-secondary fw-bold"><i class="fa-solid fa-file-invoice-dollar text-primary me-2"></i> Invoices & Receipts</h4>
</div>

<div class="card shadow-sm border-0 rounded-4 mb-5">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
        <h6 class="fw-bold text-secondary mb-0">Transaction History</h6>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="invoicesTable">
                <thead class="table-light">
                    <tr>
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Subscriber</th>
                        <th>Description</th>
                        <th>Type</th>
                        <th>Amount (Rs)</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($invoices as $inv): ?>
                    <tr>
                        <td class="text-secondary fw-bold">INV-<?= str_pad($inv['id'], 6, '0', STR_PAD_LEFT) ?></td>
                        <td class="small text-secondary"><?= date('d M Y, h:i A', strtotime($inv['created_at'])) ?></td>
                        <td>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($inv['username']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($inv['subscriber_name']) ?></div>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($inv['description']) ?></td>
                        <td>
                            <?php if($inv['type'] == 'debit'): ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1"><i class="fa-solid fa-arrow-up"></i> Deduction / Bill</span>
                            <?php else: ?>
                                <span class="badge bg-success bg-opacity-10 text-success px-2 py-1"><i class="fa-solid fa-arrow-down"></i> Payment / Recharge</span>
                            <?php endif; ?>
                        </td>
                        <td><strong class="<?= $inv['type'] == 'credit' ? 'text-success' : 'text-danger' ?>">Rs <?= number_format($inv['amount'], 2) ?></strong></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick='printInvoice(<?= json_encode($inv) ?>, <?= json_encode($operator) ?>)'>
                                <i class="fa-solid fa-print"></i> Print
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Hidden Printable Invoice Layout -->
<div id="printArea" style="display:none;">
    <div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 30px; border: 1px solid #ddd; background: #fff;">
        <div style="display: flex; justify-content: space-between; border-bottom: 2px solid #3b82f6; padding-bottom: 20px; margin-bottom: 30px;">
            <div>
                <h2 style="color: #3b82f6; margin: 0; font-size: 28px;" id="p_company"><?= htmlspecialchars($operator['company_name'] ?? 'ISP Provider') ?></h2>
                <p style="margin: 5px 0; color: #555;">Email: <span id="p_email"><?= htmlspecialchars($operator['email'] ?? 'N/A') ?></span></p>
                <p style="margin: 5px 0; color: #555;">Phone: <span id="p_phone"><?= htmlspecialchars($operator['phone'] ?? 'N/A') ?></span></p>
            </div>
            <div style="text-align: right;">
                <h1 style="color: #333; margin: 0; font-size: 36px; text-transform: uppercase;" id="p_title">INVOICE</h1>
                <p style="margin: 5px 0; font-weight: bold; color: #555;">Invoice #: <span id="p_inv_num"></span></p>
                <p style="margin: 5px 0; color: #555;">Date: <span id="p_date"></span></p>
            </div>
        </div>

        <div style="margin-bottom: 40px;">
            <h4 style="color: #777; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px;">Billed To:</h4>
            <p style="margin: 5px 0; font-weight: bold; font-size: 18px;" id="p_sub_name"></p>
            <p style="margin: 5px 0; color: #555;">Username: <span id="p_sub_user"></span></p>
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

        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 50px;">
            <div>
                <p style="color: #777; font-size: 12px;">This is a computer-generated invoice and requires no signature.</p>
                <p style="color: #777; font-size: 12px;">Thank you for your business!</p>
            </div>
            <div style="text-align: right; background-color: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <p style="margin: 0; font-size: 16px; color: #555;">Total Amount:</p>
                <h2 style="margin: 5px 0 0 0; color: #3b82f6; font-size: 28px;" id="p_total"></h2>
            </div>
        </div>
    </div>
</div>

<!-- DataTables setup -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#invoicesTable').DataTable({
        pageLength: 20,
        order: [[0, 'desc']]
    });
});

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
