<?php
/**
 * cron_sync_mikrotik.php
 * Background MikroTik Live Sessions Synchronizer
 * 
 * Automatically synchronizes active connections from MikroTik into FreeRADIUS (radacct).
 * Can be run every 1 or 2 minutes in crontab:
 * * * * * * php /var/www/html/cron_sync_mikrotik.php > /dev/null 2>&1
 */

if (php_sapi_name() !== 'cli') {
    // If accessed via web browser, ensure logged in as admin or operator
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['operator_logged_in']) && empty($_SESSION['operator_id'])) {
        die("Unauthorized access.");
    }
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/mikrotik_sync_helper.php';

$is_cli = (php_sapi_name() === 'cli');
$nl = $is_cli ? "\n" : "<br>";

echo "[" . date('Y-m-d H:i:s') . "] Starting MikroTik Live Sessions Sync...$nl";

// Get all active clients / operators
try {
    $clients = $pdo->query("SELECT id, company_name FROM clients WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage() . $nl);
}

$total_synced_all = 0;
$total_ghosts_all = 0;

foreach ($clients as $c) {
    $cid = (int)$c['id'];
    echo "  -> Syncing Operator #{$cid} ({$c['company_name']})... ";
    $res = sync_operator_mikrotik_active_sessions($pdo, $cid);
    if (!empty($res['success'])) {
        echo "OK: {$res['active_count']} active, +{$res['synced_new']} restored, -{$res['ghosts_cleared']} cleared.$nl";
        $total_synced_all += $res['synced_new'];
        $total_ghosts_all += $res['ghosts_cleared'];
    } else {
        echo "Note: " . ($res['error'] ?? 'No router') . "$nl";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Complete: +{$total_synced_all} missing restored, -{$total_ghosts_all} stale cleared.$nl";
