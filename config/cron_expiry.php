<?php
// Path: /config/cron_expiry.php
// Centralized Expiry Kicker & FreeRADIUS Blocker Cron Job
require_once __DIR__ . '/security.php';

// Allow execution via CLI, localhost/127.0.0.1, SuperAdmin/Operator session, or authorized cron secret key
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
require_once __DIR__ . '/radius_kick_helper.php';
require_once __DIR__ . '/speed_scheduler_helper.php';

$start = microtime(true);
$res = sync_expired_subscribers($pdo, null, true);
$sched_res = sync_all_speed_schedulers($pdo);
$duration = round(microtime(true) - $start, 3);

$sched_updated = 0;
foreach ($sched_res as $sr) {
    if (!empty($sr['changed'])) $sched_updated++;
}

$timestamp = date('Y-m-d H:i:s');
$msg = "[{$timestamp}] Auto-Expiry & Speed Sync Done in {$duration}s: {$res['walled']} expired walled-garden, {$res['disabled']} hard disabled, {$res['kicked']} sessions kicked from MikroTik, {$res['restored']} renewed restored. Speed Scheduler: " . count($sched_res) . " plans checked, {$sched_updated} speeds transitioned.\n";

if ($is_cli) {
    echo $msg;
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'success',
        'timestamp' => $timestamp,
        'walled_in_radius' => $res['walled'],
        'hard_disabled' => $res['disabled'],
        'kicked_from_mikrotik' => $res['kicked'],
        'active_restored' => $res['restored'],
        'speed_schedulers_checked' => count($sched_res),
        'speed_schedulers_updated' => $sched_updated,
        'execution_seconds' => $duration
    ], JSON_PRETTY_PRINT);
}
?>
