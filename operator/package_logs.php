<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Fetch approved package requests (Package Logs)
$stmt = $pdo->prepare("
    SELECT pr.*, 
           s.username, 
           s.full_name, 
           p.name AS package_name, 
           p.price, 
           pr.created_at
    FROM package_requests pr 
    JOIN subscribers s ON pr.subscriber_id = s.id 
    JOIN packages p ON pr.package_id = p.id 
    WHERE pr.client_id = ? AND pr.status = 'approved'
    ORDER BY pr.id DESC
");
$stmt->execute([$client_id]);
$logs = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-secondary fw-bold"><i class="fa-solid fa-box-open text-primary me-2"></i> Package Purchase Logs</h4>
    <button class="btn btn-primary rounded-pill px-4" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>
</div>

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
        <h6 class="fw-bold text-secondary mb-0">Record of Purchased Packages</h6>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="pkgLogsTable">
                <thead class="table-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>User Details</th>
                        <th>Package</th>
                        <th>Price</th>
                        <th>Payment Method</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    
                    <?php foreach($logs as $log): ?>
                    <tr>
                        <td class="text-secondary small">
                            <i class="fa-regular fa-calendar me-1"></i> <?= date('d M Y', strtotime($log['created_at'])) ?><br>
                            <i class="fa-regular fa-clock me-1"></i> <?= date('h:i A', strtotime($log['created_at'])) ?>
                        </td>
                        <td>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-user text-muted me-1"></i> <?= htmlspecialchars($log['username']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($log['full_name']) ?></div>
                        </td>
                        <td>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-2 rounded-pill">
                                <?= htmlspecialchars($log['package_name']) ?>
                            </span>
                        </td>
                        <td>
                            <strong class="text-success">Rs <?= number_format($log['price'], 2) ?></strong>
                        </td>
                        <td>
                            <?php if ($log['payment_method'] === 'bank_transfer'): ?>
                                <span class="text-info small fw-bold"><i class="fa-solid fa-building-columns me-1"></i> Bank Transfer</span>
                                <div class="text-muted" style="font-size: 0.7rem;">Ref: <?= htmlspecialchars($log['payment_reference']) ?></div>
                            <?php else: ?>
                                <span class="text-success small fw-bold"><i class="fa-solid fa-wallet me-1"></i> Account Balance</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-success"><i class="fa-solid fa-check-circle me-1"></i> Approved/Active</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Include DataTables for easy searching/pagination -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#pkgLogsTable').DataTable({
            "order": [[ 0, "desc" ]],
            "pageLength": 25,
            "language": {
                "search": "_INPUT_",
                "searchPlaceholder": "Search logs..."
            }
        });
    });
</script>

<?php require_once 'footer.php'; ?>
