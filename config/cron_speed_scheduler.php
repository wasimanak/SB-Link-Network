<?php
// Path: /config/cron_speed_scheduler.php
// Bandwidth & Night Speed Scheduler Cron Job
require_once __DIR__ . '/security.php';

$is_cli = (php_sapi_name() === 'cli');
$cron_key = $_GET['key'] ?? '';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_local_call = in_array($client_ip, ['127.0.0.1', '::1', 'localhost']);

if (!$is_cli && !$is_local_call) {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $is_auth = (!empty($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true)
            || (!empty($_SESSION['operator_logged_in']) && $_SESSION['operator_logged_in'] === true);
    
    if (!$is_auth && $cron_key !== 'sb_cron_secret_786') {
        http_response_code(403);
        die('403 Forbidden: Cron jobs can only be run via CLI or with authorized key (?key=sb_cron_secret_786).');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/speed_scheduler_helper.php';

$force = (!empty($_GET['force']) && $_GET['force'] === '1');
$client_id = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;

$start = microtime(true);
$results = sync_all_speed_schedulers($pdo, $client_id, $force);
$duration = round(microtime(true) - $start, 3);
$timestamp = date('Y-m-d H:i:s');

$changed_count = 0;
foreach ($results as $r) {
    if (!empty($r['changed'])) $changed_count++;
}

$msg = "[{$timestamp}] Speed Scheduler Sync Done in {$duration}s: Checked " . count($results) . " packages, {$changed_count} speeds updated.\n";

if ($is_cli) {
    echo $msg;
    foreach ($results as $r) {
        $ch = !empty($r['changed']) ? "UPDATED" : "OK";
        echo " - [{$ch}] Pkg: {$r['package_name']} => Mode: {$r['mode']} ({$r['speed']})\n";
    }
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status'            => 'success',
        'timestamp'         => $timestamp,
        'execution_seconds' => $duration,
        'packages_checked'  => count($results),
        'packages_updated'  => $changed_count,
        'details'           => $results
    ], JSON_PRETTY_PRINT);
}
