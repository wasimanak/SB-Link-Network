<?php
// Path: /config/cron_db_maintenance.php
// Centralized Database Maintenance & Auto-Prune Nightly Cron Job
require_once __DIR__ . '/security.php';

// Allow execution via CLI, localhost/127.0.0.1, SuperAdmin session, or authorized cron secret key
$is_cli = (php_sapi_name() === 'cli');
$cron_key = $_GET['key'] ?? '';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_local_call = in_array($client_ip, ['127.0.0.1', '::1', 'localhost']);

if (!$is_cli && !$is_local_call) {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $is_auth = (!empty($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true);
    
    if (!$is_auth && $cron_key !== 'sb_cron_secret_786') {
        http_response_code(403);
        die('403 Forbidden: Cron jobs can only be run via CLI or with authorized key (?key=sb_cron_secret_786).');
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db_maintenance_helper.php';

// Fetch configured retention days from global_settings or default fallbacks
$setStmt = $pdo->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_key IN ('db_retention_auth_days', 'db_retention_acct_days', 'db_ghost_session_days')");
$settings = $setStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$auth_days  = intval($settings['db_retention_auth_days'] ?? 7) ?: 7;
$acct_days  = intval($settings['db_retention_acct_days'] ?? 30) ?: 30;
$ghost_days = intval($settings['db_ghost_session_days'] ?? 2) ?: 2;

// Run maintenance engine
if ($is_cli) {
    echo "[" . date('Y-m-d H:i:s') . "] Starting Database Maintenance...\n";
}
$res = run_database_maintenance($pdo, $auth_days, $acct_days, $ghost_days, $is_cli);

$msg = "[{$res['timestamp']}] Database Nightly Maintenance Completed in {$res['duration_seconds']}s: "
     . "{$res['auth_deleted']} auth logs purged (> {$auth_days} days), "
     . "{$res['acct_deleted']} closed sessions purged (> {$acct_days} days), "
     . "{$res['ghost_closed']} ghost sessions closed, "
     . count($res['optimized_tables']) . " tables optimized.\n";

if ($is_cli) {
    echo $msg;
} else {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'success',
        'message' => trim($msg),
        'data' => $res
    ], JSON_PRETTY_PRINT);
}
