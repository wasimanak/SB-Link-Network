<?php
/**
 * SB Link Network - Automated Daily Midnight Database Backup
 * 
 * Scheduled to run every night at 12:00 AM (Midnight) via Crontab:
 *   0 0 * * * /usr/bin/php /var/www/html/superadmin/cron_backup.php > /dev/null 2>&1
 * 
 * Or triggered via Web Cron:
 *   https://lasaninetwork.com/superadmin/cron_backup.php?key=sb_cron_secret_786
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$is_cli = (php_sapi_name() === 'cli');
$cron_key = $_GET['key'] ?? '';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$is_local = in_array($client_ip, ['127.0.0.1', '::1', 'localhost']);

if (!$is_cli && !$is_local) {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $is_auth = (!empty($_SESSION['superadmin_logged_in']) && $_SESSION['superadmin_logged_in'] === true);
    if (!$is_auth && $cron_key !== 'sb_cron_secret_786') {
        http_response_code(403);
        die("403 Forbidden: Authorized Cron Access Only (?key=sb_cron_secret_786).\n");
    }
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/backup_helper.php';

$nl = $is_cli ? "\n" : "<br>\n";
echo "=========================================================$nl";
echo "   SB Link Network - Daily Database Backup Engine        $nl";
echo "=========================================================$nl";

$start_time = microtime(true);
$res = create_system_database_backup(null, 'daily_midnight');

if ($res['success']) {
    $elapsed = round(microtime(true) - $start_time, 2);
    echo "[SUCCESS] Backup Created: {$res['filename']} ({$res['size_str']})$nl";
    echo "[RETENTION] Old backups pruned: {$res['pruned_count']}$nl";
    
    $list = get_system_backups_list();
    echo "[STORAGE] Total active backups retained: " . count($list) . " / 30$nl";
    echo "Completed in {$elapsed} seconds.$nl";
} else {
    echo "[ERROR] Backup Generation Failed: " . htmlspecialchars($res['error'] ?? 'Unknown error') . "$nl";
}

echo "=========================================================$nl";
