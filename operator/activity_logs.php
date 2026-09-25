<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Fetch Logs
$stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE client_id = ? ORDER BY id DESC");
$stmt->execute([$client_id]);
$logs = $stmt->fetchAll();
?>

<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    /* Styling consistent with previous module */
    .dt-buttons .btn {
        background-color: #475569;
        border: none;
        color: #fff;
        border-radius: 20px;
        padding: 4px 14px;
        font-size: 0.85rem;
        margin-right: 4px;
    }
    .dt-buttons .btn:hover { background-color: #64748b; }
    
    .table-custom-ui {
        background-color: #ffffff;
        border-radius: 8px;
        overflow: hidden;
    }
    .table-custom-ui thead th {
        background-color: #f8f9fa;
        color: #4b5563;
        border-bottom: 2px solid #e5e7eb;
        font-weight: 600;
        font-size: 0.9rem;
    }
    .table-custom-ui tbody td {
        vertical-align: middle;
        border-bottom: 1px solid #e5e7eb;
        color: #1f2937;
        font-size: 0.9rem;
    }
    
    /* Green Badges mimicking screenshot */
    .badge-soft-success { 
        background-color: rgba(16, 185, 129, 0.2); 
        color: #10b981; 
        border: 1px solid rgba(16, 185, 129, 0.3); 
        font-weight: 500;
    }
    .badge-soft-secondary {
        background-color: rgba(148, 163, 184, 0.2); 
        color: #4b5563; 
        border: 1px solid rgba(148, 163, 184, 0.3);
    }
    
    .dataTables_wrapper .row { margin-bottom: 15px; }
    .dataTables_filter input { background-color: #ffffff; border: 1px solid #ced4da; color: #333; border-radius: 20px; padding: 4px 15px; }
    .dataTables_length select { background-color: #ffffff; border: 1px solid #ced4da; color: #333; border-radius: 6px; }
</style>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0"><i class="fa-solid fa-chart-line text-primary me-2"></i> Activity Logs</h4>
</div>

<div class="card table-custom-ui p-3 shadow-sm border-0">
    <div class="table-responsive">
        <table id="logsTable" class="table table-borderless table-hover w-100">
            <thead>
                <tr>
                    <th>#ID</th>
                    <th>Datetime</th>
                    <th>By</th>
                    <th>Against To</th>
                    <th>Activity</th>
                    <th>Station IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($logs as $log): ?>
                <tr>
                    <td><?= $log['id'] ?></td>
                    <td><?= $log['created_at'] ?></td>
                    <td>
                        <span class="badge rounded-pill badge-soft-success px-3 py-2">
                            <?= htmlspecialchars($log['by_user']) ?> (<?= htmlspecialchars($log['by_role']) ?>)
                        </span>
                    </td>
                    <td>
                        <?php if($log['against_to'] === 'N/A' || empty($log['against_to'])): ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-2">N/A</span>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-success px-3 py-2">
                                <?= htmlspecialchars($log['against_to']) ?> (<?= htmlspecialchars($log['against_role']) ?>)
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($log['activity']) ?></td>
                    <td><?= htmlspecialchars($log['station_ip']) ?></td>
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
$(document).ready(function() {
    $('#logsTable').DataTable({
        dom: '<"row align-items-center"<"col-md-2"l><"col-md-6 dt-buttons"B><"col-md-4"f>>rtip',
        buttons: [
            { extend: 'print', text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'pdf', text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'excel', text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'csv', text: '<i class="fa-solid fa-file-csv"></i> CSV' }
        ],
        order: [[0, 'desc']],
        pageLength: 25,
        language: {
            search: "Search:",
            searchPlaceholder: "Type & Submit"
        }
    });
});
</script>

<?php require_once 'footer.php'; ?>
