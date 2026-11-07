<?php
// =========================================================================
// Automated Daily SMS Expiry Reminders Cron Runner
// Scans expiring / expired subscribers and enqueues automated SMS reminders
// Usage via Linux Crontab:
//   0 10 * * * php /var/www/html/operator/cron_sms_reminders.php > /dev/null 2>&1
// =========================================================================

if (php_sapi_name() !== 'cli') {
    // If accessed via web, verify session or secure key
    session_start();
    $auth = false;
    if (isset($_SESSION['operator_logged_in']) && isset($_SESSION['operator_id'])) {
        $auth = true;
    } elseif (isset($_GET['key']) && $_GET['key'] === 'lasani_cron_sms_secret_2026') {
        $auth = true;
    }
    if (!$auth) {
        header('HTTP/1.0 403 Forbidden');
        exit('Access Denied');
    }
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sms_helper.php';

ensure_sms_tables_exist($pdo);

// Target specific client or all active clients
$targetClient = isset($_SESSION['operator_id']) ? (int)$_SESSION['operator_id'] : (isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0);

if ($targetClient > 0) {
    $clients = [['id' => $targetClient]];
} else {
    $clients = $pdo->query("SELECT id FROM clients WHERE status = 'active'")->fetchAll();
}

$totalQueued = 0;
$results = [];

foreach ($clients as $c) {
    $cid = (int)$c['id'];
    $res = scan_and_queue_expiry_reminders($pdo, $cid);
    $totalQueued += $res['queued'];
    $results[$cid] = $res;
}

if (php_sapi_name() === 'cli') {
    echo "[" . date('Y-m-d H:i:s') . "] SMS Reminders Cron Completed. Total Queued: {$totalQueued}\n";
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'success'      => true,
        'total_queued' => $totalQueued,
        'timestamp'    => date('Y-m-d H:i:s'),
        'details'      => $results
    ]);
}
