<?php
// =========================================================================
// Automated Daily Financial Backup Cron Job
// Run daily via crontab: 0 2 * * * php /var/www/html/operator/cron_financial_backup.php
// Or via HTTP: https://lasaninetwork.com/operator/cron_financial_backup.php?key=YOUR_CRON_SECRET
// =========================================================================

ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/financial_backup_helper.php';

// Security check if accessed via HTTP
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli) {
    $cron_key = $_GET['key'] ?? '';
    // Basic verification - allow if valid session or matching cron key
    session_start();
    $is_logged_admin = !empty($_SESSION['operator_logged_in']) && empty($_SESSION['is_manager']);
    if (!$is_logged_admin && $cron_key !== 'lasani_financial_backup_cron_2026') {
        http_response_code(403);
        die(json_encode(['status' => 'error', 'message' => 'Unauthorized cron access']));
    }
}

$target_client = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;

if ($target_client) {
    $clients = [['id' => $target_client]];
} else {
    // Fetch all active clients
    $cStmt = $pdo->query("SELECT * FROM clients WHERE status = 'active' OR status IS NULL");
    $clients = $cStmt->fetchAll(PDO::FETCH_ASSOC);
}

$results = [];
foreach ($clients as $c) {
    $cid = (int)$c['id'];
    try {
        $res = create_client_financial_backup($pdo, $cid, 'auto_daily');
        $results[] = [
            'client_id' => $cid,
            'status'    => 'success',
            'backup'    => $res['basename'],
            'records'   => $res['records_count'],
            'size'      => $res['size_fmt']
        ];
    } catch (Exception $e) {
        $results[] = [
            'client_id' => $cid,
            'status'    => 'error',
            'message'   => $e->getMessage()
        ];
    }
}

if ($is_cli) {
    echo "Financial Backups Cron Completed at " . date('Y-m-d H:i:s') . "\n";
    foreach ($results as $r) {
        echo "Client #{$r['client_id']}: {$r['status']} - " . ($r['backup'] ?? $r['message']) . "\n";
    }
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'status'    => 'completed',
        'timestamp' => date('Y-m-d H:i:s'),
        'results'   => $results
    ], JSON_PRETTY_PRINT);
}
