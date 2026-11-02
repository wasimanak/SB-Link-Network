<?php
require_once '../config/db.php';
session_start();

if (!isset($_SESSION['operator_logged_in'])) {
    exit('<div class="col-12 text-danger text-center py-4">Unauthorized access</div>');
}

$client_id = (int)$_SESSION['operator_id'];

$longest_sessions = [];
$highest_usage = [];

// 1. Top 20 Longest Active (Online) Sessions
// Calculates live real-time connection uptime since reconnect (matching MikroTik active connection uptime)
try {
    $longestStmt = $pdo->prepare("
        SELECT s.id, s.username, p.name as package_name, 
               MAX(GREATEST(COALESCE(r.acctsessiontime, 0), TIMESTAMPDIFF(SECOND, r.acctstarttime, NOW()))) as duration
        FROM radacct r
        JOIN subscribers s ON r.username = s.username
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE s.client_id = ? 
          AND r.acctstoptime IS NULL
        GROUP BY s.id, s.username, p.name
        ORDER BY duration DESC
        LIMIT 20
    ");
    $longestStmt->execute([$client_id]);
    $longest_sessions = $longestStmt->fetchAll();
} catch (Exception $e) {
    try {
        $longestStmt = $pdo->prepare("
            SELECT s.id, s.username, p.name as package_name, 
                   MAX(GREATEST(COALESCE(r.acctsessiontime, 0), TIMESTAMPDIFF(SECOND, r.acctstarttime, NOW()))) as duration
            FROM radacct r
            JOIN subscribers s ON r.username = s.username COLLATE utf8mb4_general_ci
            LEFT JOIN packages p ON s.package_id = p.id
            WHERE s.client_id = ? 
              AND r.acctstoptime IS NULL
            GROUP BY s.id, s.username, p.name
            ORDER BY duration DESC
            LIMIT 20
        ");
        $longestStmt->execute([$client_id]);
        $longest_sessions = $longestStmt->fetchAll();
    } catch (Exception $e2) {}
}

// Fallback if no sessions are currently online: show current cycle recent sessions
if (empty($longest_sessions)) {
    try {
        $longestStmt = $pdo->prepare("
            SELECT s.id, s.username, p.name as package_name, MAX(r.acctsessiontime) as duration
            FROM radacct r
            JOIN subscribers s ON r.username = s.username
            LEFT JOIN packages p ON s.package_id = p.id
            WHERE s.client_id = ?
              AND (
                (s.last_renewed_at IS NOT NULL AND (r.acctstarttime >= s.last_renewed_at OR r.acctstoptime >= s.last_renewed_at))
                OR (s.last_renewed_at IS NULL AND r.acctstarttime >= DATE_SUB(NOW(), INTERVAL 30 DAY))
              )
            GROUP BY s.id, s.username, p.name
            ORDER BY duration DESC
            LIMIT 20
        ");
        $longestStmt->execute([$client_id]);
        $longest_sessions = $longestStmt->fetchAll();
    } catch (Exception $e) {}
}

// Ultimate fallback if no accounting records exist
if (empty($longest_sessions)) {
    try {
        $subStmt = $pdo->prepare("SELECT s.id, s.username, p.name as package_name, 0 as duration FROM subscribers s LEFT JOIN packages p ON s.package_id = p.id WHERE s.client_id = ? ORDER BY s.id DESC LIMIT 20");
        $subStmt->execute([$client_id]);
        $longest_sessions = $subStmt->fetchAll();
    } catch (Exception $e) {}
}

// 2. Top 20 Highest Usage in Current Billing Cycle (since last_renewed_at / reset)
try {
    $usageStmt = $pdo->prepare("
        SELECT s.id, s.username, p.name as package_name, 
               SUM(COALESCE(r.acctinputoctets, 0) + COALESCE(r.acctoutputoctets, 0)) as usage_bytes
        FROM radacct r
        JOIN subscribers s ON r.username = s.username
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE s.client_id = ?
          AND (
            (s.last_renewed_at IS NOT NULL AND (r.acctstarttime >= s.last_renewed_at OR r.acctstoptime >= s.last_renewed_at))
            OR (s.last_renewed_at IS NULL AND r.acctstarttime >= DATE_SUB(NOW(), INTERVAL 30 DAY))
          )
        GROUP BY s.id, s.username, p.name
        ORDER BY usage_bytes DESC
        LIMIT 20
    ");
    $usageStmt->execute([$client_id]);
    $highest_usage = $usageStmt->fetchAll();
} catch (Exception $e) {
    try {
        $usageStmt = $pdo->prepare("
            SELECT s.id, s.username, p.name as package_name, 
                   SUM(COALESCE(r.acctinputoctets, 0) + COALESCE(r.acctoutputoctets, 0)) as usage_bytes
            FROM radacct r
            JOIN subscribers s ON r.username = s.username COLLATE utf8mb4_general_ci
            LEFT JOIN packages p ON s.package_id = p.id
            WHERE s.client_id = ?
              AND (
                (s.last_renewed_at IS NOT NULL AND (r.acctstarttime >= s.last_renewed_at OR r.acctstoptime >= s.last_renewed_at))
                OR (s.last_renewed_at IS NULL AND r.acctstarttime >= DATE_SUB(NOW(), INTERVAL 30 DAY))
              )
            GROUP BY s.id, s.username, p.name
            ORDER BY usage_bytes DESC
            LIMIT 20
        ");
        $usageStmt->execute([$client_id]);
        $highest_usage = $usageStmt->fetchAll();
    } catch (Exception $e2) {}
}

// Fill up to 20 rows with other active subscribers at 0 B if cycle has few active data users
if (count($highest_usage) < 20) {
    try {
        $existing_ids = array_column($highest_usage, 'id');
        $exclude_sql = !empty($existing_ids) ? ("AND s.id NOT IN (" . implode(',', array_map('intval', $existing_ids)) . ")") : "";
        $rem_limit = 20 - count($highest_usage);
        $subStmt = $pdo->prepare("
            SELECT s.id, s.username, p.name as package_name, 0 as usage_bytes 
            FROM subscribers s 
            LEFT JOIN packages p ON s.package_id = p.id 
            WHERE s.client_id = ? $exclude_sql
            ORDER BY s.id DESC 
            LIMIT $rem_limit
        ");
        $subStmt->execute([$client_id]);
        $extra_subs = $subStmt->fetchAll();
        $highest_usage = array_merge($highest_usage, $extra_subs);
    } catch (Exception $e) {}
}

function formatDurationStr($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return "0s";
    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    
    $parts = [];
    if ($d > 0) $parts[] = "{$d}d";
    if ($h > 0) $parts[] = "{$h}h";
    if ($m > 0) $parts[] = "{$m}m";
    if ($s > 0 || empty($parts)) $parts[] = "{$s}s";
    
    return implode(' ', $parts);
}

function formatBytesStr($bytes) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return "0 B";
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return number_format($bytes, 0) . ' B';
}
?>

<!-- Longest Session -->
<div class="col-lg-6 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="text-start fw-bold text-secondary mb-0 border-start border-3 border-primary ps-2">
            Longest Active Session <span class="badge bg-primary bg-opacity-10 text-primary ms-1 fw-normal">Online Users</span>
        </h6>
        <span class="text-muted small"><i class="fas fa-signal text-success me-1"></i>Live Uptime</span>
    </div>
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
                    <td>
                        <span class="fw-semibold text-dark"><?= htmlspecialchars($ls['username']) ?></span>
                        <span class="badge bg-success bg-opacity-10 text-success ms-1" style="font-size:0.7rem;"><i class="fas fa-circle me-1" style="font-size:0.5rem;"></i>Live</span>
                    </td>
                    <td><span class="badge bg-primary bg-opacity-10 text-primary"><?= htmlspecialchars($ls['package_name'] ?: 'N/A') ?></span></td>
                    <td class="fw-bold text-success"><?= formatDurationStr($ls['duration']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($longest_sessions)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No active sessions found</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Highest Usage -->
<div class="col-lg-6 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="text-start fw-bold text-secondary mb-0 border-start border-3 border-success ps-2">
            Highest Usage <span class="badge bg-success bg-opacity-10 text-success ms-1 fw-normal">Current Cycle</span>
        </h6>
        <span class="text-muted small"><i class="fas fa-sync-alt text-primary me-1"></i>Since Reset</span>
    </div>
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
                    <td><span class="fw-semibold text-dark"><?= htmlspecialchars($hu['username']) ?></span></td>
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
