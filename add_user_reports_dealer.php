<?php
// Enhances Dealer Dashboard with User Reports section
$f = 'dealer/dashboard.php';
$c = file_get_contents($f);

// 1. Add Report PHP Logic at the top (after require 'header.php')
$phpLogic = '
// User Reports Filter Logic
$report_filter = $_GET[\'report\'] ?? \'all\';
$filter_sql = "";
if ($report_filter == \'expired\') {
    $filter_sql = " AND expiry_date < NOW() AND status=\'active\'";
} elseif ($report_filter == \'expiring_1\') {
    $filter_sql = " AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY) AND status=\'active\'";
} elseif ($report_filter == \'expiring_3\') {
    $filter_sql = " AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) AND status=\'active\'";
} elseif ($report_filter == \'expiring_1w\') {
    $filter_sql = " AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) AND status=\'active\'";
} elseif ($report_filter == \'expiring_2w\') {
    $filter_sql = " AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY) AND status=\'active\'";
} elseif ($report_filter == \'disabled\') {
    $filter_sql = " AND status != \'active\'";
}

$subsStmt = $pdo->prepare("
    SELECT s.*, p.name as package_name,
    (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
    (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s 
    LEFT JOIN packages p ON s.package_id = p.id 
    WHERE s.dealer_id = ? $filter_sql 
    ORDER BY s.id DESC
");
$subsStmt->execute([$dealer_id]);
$subs = $subsStmt->fetchAll(PDO::FETCH_ASSOC);
';
$c = str_replace('// Dealer Stats Scope', $phpLogic . "\n// Dealer Stats Scope", $c);

// 2. Add DataTables CSS to the <style> block
$cssLogic = '
    /* Reports Tabs */
    .report-tabs { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
    .report-tab { padding: 8px 15px; border-radius: 30px; font-size: 0.85rem; font-weight: 600; text-decoration: none; color: #475569; background: #f8fafc; border: 1px solid #e2e8f0; transition: 0.2s; }
    .report-tab:hover { background: #e2e8f0; color: #0f172a; }
    .report-tab.active { background: #0f172a; color: #fff; border-color: #0f172a; }
    .table-custom-ui th { background: #f8fafc; font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0; padding: 15px; }
    .table-custom-ui td { padding: 15px; font-size: 0.9rem; color: #334155; vertical-align: middle; border-bottom: 1px solid #f1f5f9; }
    .avatar-circle { width: 35px; height: 35px; background: #e2e8f0; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 14px; }
';
$c = str_replace('</style>', $cssLogic . "\n</style>\n<link rel=\"stylesheet\" href=\"https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css\">\n<link rel=\"stylesheet\" href=\"https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css\">", $c);

// 3. Add the HTML for User Reports before Recent Activity
$htmlLogic = '
<!-- User Reports Table -->
<div class="card-ui p-4 mt-4" id="reportsSection">
    <h6 class="fw-bold text-secondary mb-4"><i class="fa-solid fa-list-ul me-2 text-primary"></i> User Reports</h6>
    
    <div class="report-tabs">
        <a href="?report=all#reportsSection" class="report-tab <?= $report_filter==\'all\'?\'active\':\'\' ?>"><i class="fa-solid fa-users"></i> All Users</a>
        <a href="?report=expired#reportsSection" class="report-tab <?= $report_filter==\'expired\'?\'active\':\'\' ?>"><i class="fa-solid fa-user-xmark"></i> Expired Users</a>
        <a href="?report=expiring_1#reportsSection" class="report-tab <?= $report_filter==\'expiring_1\'?\'active\':\'\' ?>"><i class="fa-solid fa-hourglass-end"></i> Expiring (1 Days)</a>
        <a href="?report=expiring_3#reportsSection" class="report-tab <?= $report_filter==\'expiring_3\'?\'active\':\'\' ?>"><i class="fa-solid fa-hourglass-half"></i> Expiring (3 Days)</a>
        <a href="?report=expiring_1w#reportsSection" class="report-tab <?= $report_filter==\'expiring_1w\'?\'active\':\'\' ?>"><i class="fa-solid fa-calendar-week"></i> Expiring (1 week)</a>
        <a href="?report=expiring_2w#reportsSection" class="report-tab <?= $report_filter==\'expiring_2w\'?\'active\':\'\' ?>"><i class="fa-solid fa-calendar-days"></i> Expiring (2 weeks)</a>
        <a href="?report=disabled#reportsSection" class="report-tab <?= $report_filter==\'disabled\'?\'active\':\'\' ?>"><i class="fa-solid fa-user-slash"></i> Disabled Users</a>
    </div>

    <div class="table-responsive">
        <table id="reportsTable" class="table table-hover table-custom-ui table-borderless w-100">
            <thead>
                <tr>
                    <th>#ID</th>
                    <th>Photo</th>
                    <th>Username</th>
                    <th>Phone</th>
                    <th>Package</th>
                    <th>Balance</th>
                    <th>Service</th>
                    <th>On/Off</th>
                    <th>Expiry</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($subs as $s): ?>
                <tr>
                    <td><?= $s[\'id\'] ?></td>
                    <td><div class="avatar-circle"><i class="fa-solid fa-user"></i></div></td>
                    <td>
                        <span class="badge rounded-pill badge-soft-success px-3 py-2"><?= htmlspecialchars($s[\'username\']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($s[\'mobile\'] ?: ($s[\'phone\'] ?: \'N/A\')) ?></td>
                    <td><?= htmlspecialchars($s[\'package_name\'] ?? \'N/A\') ?></td>
                    <td><span class="badge rounded-pill badge-soft-warning px-3 py-2"><?= number_format($s[\'balance\']) ?></span></td>
                    <td><span class="badge rounded-pill badge-soft-primary px-3 py-2 fw-bold"><?= strtoupper(htmlspecialchars($s[\'service_type\'])) ?></span></td>
                    <td>
                        <?php if($s[\'is_online\'] > 0): ?>
                            <div class="d-flex flex-column align-items-center gap-1">
                                <span class="badge rounded-pill badge-soft-success px-3 py-1">Online</span>
                                <small class="text-muted font-monospace" style="font-size: 0.75rem;"><?= htmlspecialchars($s[\'live_ip\']) ?></small>
                            </div>
                        <?php else: ?>
                            <span class="badge rounded-pill badge-soft-secondary px-3 py-1">Offline</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($s[\'expiry_date\']): ?>
                            <?php 
                                $is_expired = strtotime($s[\'expiry_date\']) < time(); 
                                $badge_class = $is_expired ? \'badge-soft-danger\' : \'badge-soft-success\';
                            ?>
                            <span class="badge <?= $badge_class ?> px-3 py-2"><?= date(\'d M Y, h:i A\', strtotime($s[\'expiry_date\'])) ?></span>
                        <?php else: ?>
                            <span class="badge badge-soft-secondary px-3 py-2">N/A</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
';

$c = str_replace('<div class="card border-0 shadow-sm rounded-4">', $htmlLogic . "\n" . '<div class="card border-0 shadow-sm rounded-4 mt-4">', $c);

// 4. Add DataTables JS at the end
$jsLogic = '
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
    $(\'#reportsTable\').DataTable({
        dom: \'<"row align-items-center"<"col-md-2"l><"col-md-6 dt-buttons"B><"col-md-4"f>>rtip\',
        buttons: [
            { extend: \'print\', text: \'<i class="fa-solid fa-print"></i> Print\' },
            { extend: \'copy\', text: \'<i class="fa-solid fa-copy"></i> Copy\' },
            { extend: \'pdf\', text: \'<i class="fa-solid fa-file-pdf"></i> PDF\' },
            { extend: \'excel\', text: \'<i class="fa-solid fa-file-excel"></i> Excel\' },
            { extend: \'csv\', text: \'<i class="fa-solid fa-file-csv"></i> CSV\' }
        ],
        order: [[0, \'desc\']],
        pageLength: 10,
        scrollX: true,
        language: { search: "Search:", searchPlaceholder: "Type & Submit" }
    });
});
</script>
';
$c = str_replace('<?php require_once \'footer.php\'; ?>', $jsLogic . "\n<?php require_once 'footer.php'; ?>", $c);

file_put_contents($f, $c);
echo "dealer/dashboard.php upgraded with User Reports section.\n";
?>
