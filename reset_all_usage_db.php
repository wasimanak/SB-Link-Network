<?php
/**
 * reset_all_usage_db.php
 * Mass Usage Reset & 1970 Date Cleaner
 * 
 * Resets all customer usage counters to zero across the database (subscribers & radacct),
 * cleanses invalid 1970/0000 dates, and reconnects active router sessions so all future usage
 * is 100% authentic, real, and started from 0.
 * 
 * Usage:
 * CLI: php reset_all_usage_db.php
 * Web: https://lasaninetwork.com/reset_all_usage_db.php?key=sb_cron_secret_786
 */

require_once __DIR__ . '/config/security.php';

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
        die('403 Forbidden: Authorized access only (?key=sb_cron_secret_786).');
    }
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/routeros_api.class.php';

$nl = $is_cli ? "\n" : "<br>\n";
echo "=========================================================$nl";
echo "   SB Link Network - Mass Usage Reset & Date Normalizer  $nl";
echo "=========================================================$nl";
$start_time = microtime(true);
$now_dt = date('Y-m-d H:i:s');

// 1. Clean up invalid 1970 & 0000 dates in subscribers
echo "1. Normalizing invalid 1970 & 0000 dates in database...$nl";

// Fix expiry_date
$fix_exp = $pdo->prepare("UPDATE subscribers 
    SET expiry_date = NULL 
    WHERE expiry_date = '0000-00-00 00:00:00' 
       OR expiry_date = '0000-00-00' 
       OR expiry_date LIKE '1970%' 
       OR expiry_date = ''");
$fix_exp->execute();
$exp_cleaned = $fix_exp->rowCount();
echo "   - Cleaned invalid expiry_date records: $exp_cleaned$nl";

// Fix created_at
$fix_crt = $pdo->prepare("UPDATE subscribers 
    SET created_at = ? 
    WHERE created_at = '0000-00-00 00:00:00' 
       OR created_at = '0000-00-00' 
       OR created_at LIKE '1970%' 
       OR created_at IS NULL");
$fix_crt->execute([$now_dt]);
$crt_cleaned = $fix_crt->rowCount();
echo "   - Cleaned invalid created_at records: $crt_cleaned$nl";

// Clean radcheck junk 1970
$fix_rc = $pdo->prepare("DELETE FROM radcheck WHERE attribute = 'Expiration' AND (value LIKE '%1970%' OR username = '' OR username IS NULL)");
$fix_rc->execute();
$rc_cleaned = $fix_rc->rowCount();
echo "   - Cleaned junk 1970 records in radcheck: $rc_cleaned$nl";

// 2. Reset All Subscribers Billing Cycle Reset Marker (last_renewed_at = NOW())
echo "2. Resetting active billing cycle to zero for all subscribers...$nl";
try {
    $colChk = $pdo->query("SHOW COLUMNS FROM `subscribers` LIKE 'last_renewed_at'")->fetch();
    if (!$colChk) {
        $pdo->exec("ALTER TABLE `subscribers` ADD COLUMN `last_renewed_at` DATETIME DEFAULT NULL");
    }
} catch (Exception $e) {}

$stmtSub = $pdo->prepare("UPDATE subscribers SET last_renewed_at = ?");
$stmtSub->execute([$now_dt]);
$subs_updated = $stmtSub->rowCount();
echo "   - Updated subscribers with fresh cycle start: $subs_updated$nl";

// 3. Reset all octets in radacct to 0
echo "3. Zeroing out past data octets in FreeRADIUS radacct table...$nl";
$stmtRad = $pdo->prepare("UPDATE radacct SET acctinputoctets = 0, acctoutputoctets = 0");
$stmtRad->execute();
$rad_updated = $stmtRad->rowCount();
echo "   - Cleared data octets on accounting records: $rad_updated$nl";

// Close stale ghost sessions so radacct has clean state
$close_ghosts = $pdo->prepare("UPDATE radacct SET acctstoptime = ?, acctterminatecause = 'Admin-Reset' WHERE acctstoptime IS NULL");
$close_ghosts->execute([$now_dt]);
$ghosts_closed = $close_ghosts->rowCount();
echo "   - Closed stale/ghost sessions: $ghosts_closed$nl";

// 4. Reconnect Active MikroTik Sessions so hardware interface counters reset to 0
echo "4. Refreshing active sessions on configured MikroTik routers...$nl";
$routers_contacted = 0;
$sessions_reconnected = 0;

try {
    $nasList = $pdo->query("SELECT * FROM nas WHERE nasname NOT LIKE '%/%' AND nasname != '0.0.0.0' AND nasname != '127.0.0.1'")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($nasList as $nas) {
        $nas_ip   = trim($nas['nasname'] ?? '');
        $api_user = trim($nas['api_user'] ?? '');
        $api_pass = trim($nas['api_password'] ?? '');
        $api_port = !empty($nas['api_port']) ? (int)$nas['api_port'] : 8728;

        if (empty($nas_ip) || empty($api_user)) continue;

        // Quick socket check (1.2s timeout)
        $sock = @fsockopen($nas_ip, $api_port, $errn, $errs, 1.2);
        if (!$sock) {
            echo "   - Router {$nas_ip}:{$api_port} unreachable (Skipping socket refresh)$nl";
            continue;
        }
        fclose($sock);

        try {
            $API = new RouterosAPI();
            $API->timeout = 2;
            $API->attempts = 1;
            if ($API->connect($nas_ip, $api_user, $api_pass, $api_port)) {
                $active_ppp = $API->comm('/ppp/active/print');
                if (is_array($active_ppp) && !empty($active_ppp)) {
                    $ids = [];
                    foreach ($active_ppp as $sess) {
                        if (!empty($sess['.id'])) {
                            $ids[] = $sess['.id'];
                        }
                    }
                    if (!empty($ids)) {
                        $chunks = array_chunk($ids, 50);
                        foreach ($chunks as $chunk) {
                            $API->comm('/ppp/active/remove', ['.id' => implode(',', $chunk)]);
                        }
                        $sessions_reconnected += count($ids);
                    }
                }
                $API->disconnect();
                $routers_contacted++;
                echo "   - Router {$nas_ip}: Connected and refreshed active sessions.$nl";
            }
        } catch (Exception $eNas) {
            echo "   - Router {$nas_ip} error: " . $eNas->getMessage() . "$nl";
        }
    }
} catch (Exception $eAllNas) {
    echo "   - Router query skipped: " . $eAllNas->getMessage() . "$nl";
}

$elapsed = round(microtime(true) - $start_time, 2);

echo "=========================================================$nl";
echo "SUCCESS: Mass usage reset completed in {$elapsed} seconds!$nl";
echo "Subscribers reset to 0 GB: $subs_updated$nl";
echo "Radacct session octets cleared: $rad_updated$nl";
echo "Routers contacted: $routers_contacted | Router sessions refreshed: $sessions_reconnected$nl";
echo "=========================================================$nl";
