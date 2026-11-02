<?php
/**
 * reset_all_usage_db.php
 * Mass Usage Reset & Date Normalizer
 * 
 * Resets all customer usage counters to zero across the database (subscribers & radacct),
 * cleanses invalid 1970/0000 dates, and reconnects active router sessions so all future usage
 * is 100% authentic, real, and started from 0.
 * 
 * Usage options:
 *   1. Full reset: ?key=sb_cron_secret_786
 *   2. Instant DB zero only (no router disconnect): ?key=sb_cron_secret_786&action=zero_radacct
 */

// Enable detailed error display so web responses never silently blank out
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/security.php';

$is_cli = (php_sapi_name() === 'cli');
$cron_key = $_GET['key'] ?? '';
$action = $_GET['action'] ?? '';
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

// Disable strict SQL mode for this maintenance session so 0000-00-00 queries never throw 1292 errors on MySQL 8.0
try {
    $pdo->exec("SET sql_mode = ''");
} catch (Throwable $e) {}

$nl = $is_cli ? "\n" : "<br>\n";
echo "=========================================================$nl";
echo "   SB Link Network - Mass Usage Reset & Date Normalizer  $nl";
echo "=========================================================$nl";
$start_time = microtime(true);
$now_dt = date('Y-m-d H:i:s');

// QUICK ACTION: Instant radacct zero & cycle synchronization (No router kick)
if ($action === 'zero_radacct') {
    echo "Mode: Instant Radacct Zero (Router sessions not interrupted)$nl";
    try {
        $st1 = $pdo->prepare("UPDATE radacct SET acctinputoctets = 0, acctoutputoctets = 0");
        $st1->execute();
        $rad_cnt = $st1->rowCount();
        echo "   [OK] Cleared data octets on radacct records: $rad_cnt$nl";

        $colChk = $pdo->query("SHOW COLUMNS FROM `subscribers` LIKE 'last_renewed_at'")->fetch();
        if (!$colChk) {
            $pdo->exec("ALTER TABLE `subscribers` ADD COLUMN `last_renewed_at` DATETIME DEFAULT NULL");
        }
        $st2 = $pdo->prepare("UPDATE subscribers SET last_renewed_at = ?");
        $st2->execute([$now_dt]);
        $sub_cnt = $st2->rowCount();
        echo "   [OK] Synchronized last_renewed_at for subscribers: $sub_cnt$nl";
    } catch (Throwable $e) {
        echo "   [ERROR] Zeroing failed: " . htmlspecialchars($e->getMessage()) . "$nl";
    }
    $elapsed = round(microtime(true) - $start_time, 2);
    echo "=========================================================$nl";
    echo "SUCCESS: Radacct zeroed out in {$elapsed} seconds!$nl";
    echo "=========================================================$nl";
    exit;
}

// STEP 1: Normalize invalid 1970 & 0000 dates in database
echo "1. Normalizing invalid 1970 & 0000 dates in database...$nl";

$exp_cleaned = 0;
$crt_cleaned = 0;
$rc_cleaned = 0;

try {
    // Ensure expiry_date allows NULL
    try {
        $pdo->exec("ALTER TABLE `subscribers` MODIFY COLUMN `expiry_date` DATETIME DEFAULT NULL");
    } catch (Throwable $eAlt) {}

    // Clean invalid expiry_date
    $fix_exp = $pdo->prepare("UPDATE subscribers 
        SET expiry_date = NULL 
        WHERE expiry_date IS NULL 
           OR CAST(expiry_date AS CHAR) = '' 
           OR CAST(expiry_date AS CHAR) LIKE '0000%' 
           OR CAST(expiry_date AS CHAR) LIKE '1970%'");
    $fix_exp->execute();
    $exp_cleaned = $fix_exp->rowCount();
    echo "   [OK] Cleaned invalid expiry_date records: $exp_cleaned$nl";
} catch (Throwable $e) {
    echo "   [NOTE] Expiry date cleanup notice: " . htmlspecialchars($e->getMessage()) . "$nl";
}

try {
    // Clean invalid created_at
    $fix_crt = $pdo->prepare("UPDATE subscribers 
        SET created_at = ? 
        WHERE created_at IS NULL 
           OR CAST(created_at AS CHAR) = '' 
           OR CAST(created_at AS CHAR) LIKE '0000%' 
           OR CAST(created_at AS CHAR) LIKE '1970%'");
    $fix_crt->execute([$now_dt]);
    $crt_cleaned = $fix_crt->rowCount();
    echo "   [OK] Cleaned invalid created_at records: $crt_cleaned$nl";
} catch (Throwable $e) {
    echo "   [NOTE] Created_at cleanup notice: " . htmlspecialchars($e->getMessage()) . "$nl";
}

try {
    // Clean radcheck junk 1970
    $fix_rc = $pdo->prepare("DELETE FROM radcheck WHERE attribute = 'Expiration' AND (value LIKE '%1970%' OR username = '' OR username IS NULL)");
    $fix_rc->execute();
    $rc_cleaned = $fix_rc->rowCount();
    echo "   [OK] Cleaned junk 1970 records in radcheck: $rc_cleaned$nl";
} catch (Throwable $e) {
    echo "   [NOTE] Radcheck cleanup notice: " . htmlspecialchars($e->getMessage()) . "$nl";
}

// STEP 2: Reconnect Active MikroTik Sessions FIRST so hardware interface counters reset to 0
echo "2. Refreshing active sessions on configured MikroTik routers...$nl";
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
                echo "   [OK] Router {$nas_ip}: Connected and refreshed active sessions.$nl";
            }
        } catch (Throwable $eNas) {
            echo "   [NOTE] Router {$nas_ip} response: " . htmlspecialchars($eNas->getMessage()) . "$nl";
        }
    }
} catch (Throwable $eAllNas) {
    echo "   [NOTE] Router query skipped: " . htmlspecialchars($eAllNas->getMessage()) . "$nl";
}

// STEP 3: Wait briefly for MikroTik Accounting-Stop packets to be processed by FreeRADIUS
echo "3. Waiting for router disconnect accounting stop packets to commit...$nl";
sleep(2);

// STEP 4: Zero out ALL octets in radacct and close past sessions
echo "4. Zeroing out past data octets in FreeRADIUS radacct table...$nl";
$rad_updated = 0;
$ghosts_closed = 0;
try {
    $stmtRad = $pdo->prepare("UPDATE radacct SET acctinputoctets = 0, acctoutputoctets = 0");
    $stmtRad->execute();
    $rad_updated = $stmtRad->rowCount();
    echo "   [OK] Cleared data octets on accounting records: $rad_updated$nl";

    $close_ghosts = $pdo->prepare("UPDATE radacct SET acctstoptime = ?, acctterminatecause = 'Admin-Reset' WHERE acctstoptime IS NULL");
    $close_ghosts->execute([$now_dt]);
    $ghosts_closed = $close_ghosts->rowCount();
    echo "   [OK] Closed stale/ghost sessions: $ghosts_closed$nl";
} catch (Throwable $e) {
    echo "   [ERROR] Radacct reset notice: " . htmlspecialchars($e->getMessage()) . "$nl";
}

// STEP 5: Reset All Subscribers Billing Cycle Reset Marker (last_renewed_at = NOW())
echo "5. Resetting active billing cycle to zero for all subscribers...$nl";
$subs_updated = 0;
try {
    try {
        $colChk = $pdo->query("SHOW COLUMNS FROM `subscribers` LIKE 'last_renewed_at'")->fetch();
        if (!$colChk) {
            $pdo->exec("ALTER TABLE `subscribers` ADD COLUMN `last_renewed_at` DATETIME DEFAULT NULL");
        }
    } catch (Throwable $eCol) {}

    $stmtSub = $pdo->prepare("UPDATE subscribers SET last_renewed_at = ?");
    $stmtSub->execute([$now_dt]);
    $subs_updated = $stmtSub->rowCount();
    echo "   [OK] Updated subscribers with fresh cycle start: $subs_updated$nl";
} catch (Throwable $e) {
    echo "   [ERROR] Subscriber cycle reset failed: " . htmlspecialchars($e->getMessage()) . "$nl";
}

// Final safety clear of radacct octets
try {
    $pdo->exec("UPDATE radacct SET acctinputoctets = 0, acctoutputoctets = 0");
} catch (Throwable $e) {}

$elapsed = round(microtime(true) - $start_time, 2);

echo "=========================================================$nl";
echo "SUCCESS: Mass usage reset completed in {$elapsed} seconds!$nl";
echo "Subscribers reset to 0 GB: $subs_updated$nl";
echo "Radacct session octets cleared: $rad_updated$nl";
echo "Routers contacted: $routers_contacted | Router sessions refreshed: $sessions_reconnected$nl";
echo "=========================================================$nl";
