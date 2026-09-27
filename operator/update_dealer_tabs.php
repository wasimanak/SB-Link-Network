<?php
$file = 'C:/xampp/htdocs/SB Link Network/operator/dealer_view.php';
$content = file_get_contents($file);

// 1. Add Data Fetching Logic at the top
$fetchLogic = <<<'PHP'
// Fetch assigned dealer packages
$assignedPkgStmt = $pdo->prepare("
    SELECT dp.dealer_price, dp.dealer_profit, p.name 
    FROM dealer_packages dp
    JOIN packages p ON dp.package_id = p.id
    WHERE dp.dealer_id = ?
    ORDER BY p.name ASC
");
$assignedPkgStmt->execute([$dealer_id]);
$dealer_assigned_packages = $assignedPkgStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dealer Ledger / Notes
$ledgerStmt = $pdo->prepare("SELECT note, created_at FROM dealer_notes WHERE dealer_id = ? ORDER BY id DESC");
$ledgerStmt->execute([$dealer_id]);
$dealer_ledger = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Dealer Users
$dUsersStmt = $pdo->prepare("
    SELECT s.id, s.full_name, s.username, s.expiry_date, s.status,
           (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip,
           (SELECT SUM(acctinputoctets + acctoutputoctets) FROM radacct r WHERE r.username = s.username) as total_usage
    FROM subscribers s
    WHERE s.dealer_id = ?
    ORDER BY s.id DESC
");
$dUsersStmt->execute([$dealer_id]);
$dealer_users = $dUsersStmt->fetchAll(PDO::FETCH_ASSOC);

function formatBytes($bytes) {
    if ($bytes <= 0) return "0 B";
    $s = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    $e = floor(log($bytes, 1024));
    return round($bytes/pow(1024, $e), 2) . ' ' . $s[$e];
}
PHP;

$content = preg_replace('/\/\/ Fetch assigned dealer packages.*?\$dealer_assigned_packages = \$assignedPkgStmt->fetchAll\(PDO::FETCH_ASSOC\);/s', $fetchLogic, $content);

// 2. Replace the HTML for Ledger & All Users
$oldCardsHtml = <<<'HTML'
            <!-- Ledger -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="fa-solid fa-chart-simple"></i> Ledger</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-right chevron-icon"></i>
                    </div>
                </div>
            </div>

            <!-- All Users -->
            <div class="card">
                <div class="card-header">
                    <h6><i class="fa-solid fa-users"></i> All Users</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-right chevron-icon"></i>
                    </div>
                </div>
            </div>
HTML;

$newCardsHtml = <<<'HTML'
            <!-- Ledger -->
            <div class="card overflow-hidden">
                <div class="card-header border-bottom-0 collapsed" data-bs-toggle="collapse" data-bs-target="#collapseLedger" style="background-color: #1e293b;">
                    <h6 style="color: #fff;"><i class="fa-solid fa-chart-simple me-2" style="color: #fff;"></i> Ledger / Notes</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-down text-white chevron-icon"></i>
                    </div>
                </div>
                <div id="collapseLedger" class="collapse">
                    <div class="card-body p-4 bg-white border-top">
                        <table class="table table-hover table-bordered w-100" id="dealerLedgerTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Transaction / Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($dealer_ledger as $note): ?>
                                <tr>
                                    <td class="text-nowrap text-secondary"><?= date('d M Y, h:i A', strtotime($note['created_at'])) ?></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($note['note']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- All Users -->
            <div class="card overflow-hidden">
                <div class="card-header border-bottom-0 collapsed" data-bs-toggle="collapse" data-bs-target="#collapseAllUsers" style="background-color: #1e293b;">
                    <h6 style="color: #fff;"><i class="fa-solid fa-users me-2" style="color: #fff;"></i> All Users</h6>
                    <div class="header-controls">
                        <i class="fa-solid fa-chevron-down text-white chevron-icon"></i>
                    </div>
                </div>
                <div id="collapseAllUsers" class="collapse">
                    <div class="card-body p-4 bg-white border-top">
                        <div class="table-responsive">
                            <table class="table table-hover table-bordered w-100" id="dealerUsersTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>IP Address</th>
                                        <th>Total Usage</th>
                                        <th>Expiry Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($dealer_users as $u): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($u['full_name']) ?></td>
                                        <td><span class="badge bg-primary"><?= htmlspecialchars($u['username']) ?></span></td>
                                        <td class="font-monospace text-muted"><?= htmlspecialchars($u['live_ip'] ?: 'Offline/None') ?></td>
                                        <td class="fw-bold text-info"><?= formatBytes($u['total_usage']) ?></td>
                                        <td>
                                            <?php if($u['expiry_date']): ?>
                                                <?php if(strtotime($u['expiry_date']) < time()): ?>
                                                    <span class="badge bg-danger">Expired<br><small><?= date('d M Y', strtotime($u['expiry_date'])) ?></small></span>
                                                <?php else: ?>
                                                    <span class="badge bg-success"><?= date('d M Y H:i', strtotime($u['expiry_date'])) ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
HTML;

$content = str_replace($oldCardsHtml, $newCardsHtml, $content);

// 3. Add JS Initialization for DataTables
$oldJs = <<<'JS'
$(document).ready(function() {
    $('#dealerPackagesTable').DataTable({
JS;

$newJs = <<<'JS'
$(document).ready(function() {
    var dtOpts = {
        dom: '<"row align-items-center mb-3"<"col-sm-12 col-md-3"l><"col-sm-12 col-md-6 text-center"B><"col-sm-12 col-md-3"f>>rt<"row align-items-center mt-3"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            { extend: 'print', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-print"></i> Print' },
            { extend: 'copy', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-copy"></i> Copy' },
            { extend: 'pdf', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-pdf"></i> PDF' },
            { extend: 'excel', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-excel"></i> Excel' },
            { extend: 'csv', className: 'btn btn-secondary btn-sm', text: '<i class="fa-solid fa-file-csv"></i> CSV' }
        ],
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        language: { lengthMenu: "Show _MENU_ entries" }
    };
    $('#dealerPackagesTable').DataTable(dtOpts);
    $('#dealerLedgerTable').DataTable(Object.assign({}, dtOpts, { order: [[0, 'desc']] }));
    $('#dealerUsersTable').DataTable(dtOpts);
JS;

$content = preg_replace('/\$\(document\)\.ready\(function\(\) \{\s*\$\(\'#dealerPackagesTable\'\)\.DataTable\(\{/', $newJs . "\n    /*", $content);
$content = preg_replace('/language: \{\s*lengthMenu: "Show _MENU_ entries"\s*\}\s*\}\);\s*\}\);/s', "*/\n});", $content);

file_put_contents($file, $content);
echo "Updated Ledger and All Users tab in dealer_view.php\n";
?>
