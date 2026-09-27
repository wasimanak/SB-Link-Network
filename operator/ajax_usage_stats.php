<?php
require_once '../config/db.php';
session_start();

if (!isset($_SESSION['operator_logged_in'])) {
    exit('<div class="col-12 text-danger text-center py-4">Unauthorized access</div>');
}

$client_id = $_SESSION['operator_id'];

// Top 20 Longest Sessions
$longest_sessions = $pdo->query("
    SELECT s.id, s.username, p.name as package_name, MAX(r.acctsessiontime) as duration
    FROM radacct r
    JOIN subscribers s ON r.username = s.username
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE s.client_id = $client_id
    GROUP BY s.id, s.username, p.name
    ORDER BY duration DESC
    LIMIT 20
")->fetchAll();

// Top 20 Highest Usage
$highest_usage = $pdo->query("
    SELECT s.id, s.username, p.name as package_name, MAX(r.acctinputoctets + r.acctoutputoctets) as usage_bytes
    FROM radacct r
    JOIN subscribers s ON r.username = s.username
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE s.client_id = $client_id
    GROUP BY s.id, s.username, p.name
    ORDER BY usage_bytes DESC
    LIMIT 20
")->fetchAll();

function formatDurationStr($seconds) {
    if (!$seconds) return "0s";
    $dtF = new \DateTime('@0');
    $dtT = new \DateTime("@$seconds");
    $d = $dtF->diff($dtT);
    $str = '';
    if($d->d > 0) $str .= $d->d . 'd ';
    if($d->h > 0) $str .= $d->h . 'h ';
    if($d->i > 0) $str .= $d->i . 'm ';
    $str .= $d->s . 's';
    return trim($str);
}

function formatBytesStr($bytes) {
    if (!$bytes) return "0 B";
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>

<!-- Longest Session -->
<div class="col-lg-6 mb-4">
    <h6 class="text-start fw-bold text-secondary mb-3 border-start border-3 border-primary ps-2">Longest Session (Top 20 Users)</h6>
    <div class="table-responsive bg-white border rounded shadow-sm">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th class="text-secondary ps-3">ID</th>
                    <th class="text-secondary">Username</th>
                    <th class="text-secondary">Package</th>
                    <th class="text-secondary">Duration</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($longest_sessions as $ls): ?>
                <tr>
                    <td class="ps-3 fw-bold text-secondary">#<?= $ls['id'] ?></td>
                    <td><?= htmlspecialchars($ls['username']) ?></td>
                    <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= htmlspecialchars($ls['package_name'] ?: 'N/A') ?></span></td>
                    <td class="fw-bold text-success"><?= formatDurationStr($ls['duration']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($longest_sessions)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No sessions found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Highest Usage -->
<div class="col-lg-6 mb-4">
    <h6 class="text-start fw-bold text-secondary mb-3 border-start border-3 border-success ps-2">Highest Session Usage (Top 20)</h6>
    <div class="table-responsive bg-white border rounded shadow-sm">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th class="text-secondary ps-3">ID</th>
                    <th class="text-secondary">Username</th>
                    <th class="text-secondary">Package</th>
                    <th class="text-secondary">Usage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($highest_usage as $hu): ?>
                <tr>
                    <td class="ps-3 fw-bold text-secondary">#<?= $hu['id'] ?></td>
                    <td><?= htmlspecialchars($hu['username']) ?></td>
                    <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= htmlspecialchars($hu['package_name'] ?: 'N/A') ?></span></td>
                    <td class="fw-bold text-primary"><?= formatBytesStr($hu['usage_bytes']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($highest_usage)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No usage data found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
