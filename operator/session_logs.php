<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Delete session logs older than 14 days
$pdo->exec("DELETE FROM radacct WHERE acctstoptime IS NOT NULL AND acctstoptime < DATE_SUB(NOW(), INTERVAL 14 DAY)");

// Fetch the last 1500 session logs to avoid crashing the browser for massive databases
// Operator can only see sessions belonging to their subscribers
$stmt = $pdo->prepare("
    SELECT r.*, s.full_name 
    FROM radacct r
    JOIN subscribers s ON r.username = s.username
    WHERE s.client_id = ?
    ORDER BY r.radacctid DESC
    LIMIT 1500
");
$stmt->execute([$client_id]);
$logs = $stmt->fetchAll();

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function formatDuration($seconds) {
    if (!$seconds) return '-';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    return sprintf("%02d:%02d:%02d", $h, $m, $s);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-secondary fw-bold"><i class="fa-solid fa-history text-info me-2"></i> Session Logs</h4>
    <button class="btn btn-info text-white rounded-pill px-4 fw-bold" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print / Export</button>
</div>

<div class="card shadow-sm border-0 rounded-4 mb-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
        <h6 class="fw-bold text-secondary mb-0">Recent User Connections & Usage</h6>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="sessionLogsTable">
                <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>IP / MAC Address</th>
                        <th>Connection Time</th>
                        <th>Duration</th>
                        <th>Data Usage</th>
                        <th>Status / Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($logs as $log): ?>
                    <?php 
                        $is_live = empty($log['acctstoptime']);
                        $up = formatBytes($log['acctinputoctets'] ?? 0);
                        $down = formatBytes($log['acctoutputoctets'] ?? 0);
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold text-dark"><i class="fa-solid fa-user text-muted me-1"></i> <?= htmlspecialchars($log['username']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($log['full_name']) ?></div>
                        </td>
                        <td>
                            <div class="font-monospace text-primary fw-bold" style="font-size: 0.85rem;"><?= htmlspecialchars($log['framedipaddress'] ?? 'N/A') ?></div>
                            <div class="font-monospace text-secondary" style="font-size: 0.75rem;"><i class="fa-solid fa-laptop me-1"></i> <?= htmlspecialchars($log['callingstationid'] ?? 'N/A') ?></div>
                        </td>
                        <td class="text-secondary" style="font-size: 0.85rem;">
                            <div class="text-success"><i class="fa-solid fa-arrow-right-to-bracket me-1"></i> <?= date('d M Y, h:i A', strtotime($log['acctstarttime'])) ?></div>
                            <?php if(!$is_live): ?>
                                <div class="text-danger mt-1"><i class="fa-solid fa-arrow-right-from-bracket me-1"></i> <?= date('d M Y, h:i A', strtotime($log['acctstoptime'])) ?></div>
                            <?php else: ?>
                                <div class="text-muted mt-1"><i class="fa-solid fa-spinner fa-spin me-1"></i> Still connected...</div>
                            <?php endif; ?>
                        </td>
                        <td class="font-monospace" style="font-size: 0.85rem;">
                            <?= $is_live ? '<span class="text-success">Live</span>' : formatDuration($log['acctsessiontime']) ?>
                        </td>
                        <td style="font-size: 0.8rem;">
                            <div class="d-flex justify-content-between mb-1" style="min-width: 90px;">
                                <span class="text-secondary"><i class="fa-solid fa-arrow-up"></i></span>
                                <span class="fw-bold"><?= $up ?></span>
                            </div>
                            <div class="d-flex justify-content-between text-info">
                                <span><i class="fa-solid fa-arrow-down"></i></span>
                                <span class="fw-bold"><?= $down ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if($is_live): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success rounded-pill px-3 py-1">Online</span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary rounded-pill px-2 py-1 mb-1 d-inline-block">Offline</span>
                                <?php if($log['acctterminatecause']): ?>
                                    <div class="small text-muted" style="font-size: 0.7rem;">Reason: <?= htmlspecialchars($log['acctterminatecause']) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Include DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function() {
        $('#sessionLogsTable').DataTable({
            "order": [], // Let SQL handle default ordering (newest first)
            "pageLength": 25,
            "language": {
                "search": "_INPUT_",
                "searchPlaceholder": "Search logs (IP, Mac, Username)..."
            }
        });
    });
</script>

<?php require_once 'footer.php'; ?>
