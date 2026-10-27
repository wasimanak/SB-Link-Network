<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');

$client_id = $_SESSION['operator_id'] ?? $_SESSION['client_id'] ?? null;
$username = trim($_GET['username'] ?? '');

if (!$client_id || empty($username)) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'error' => 'Unauthorized or missing params']);
    exit;
}

session_write_close();
$username_lower = strtolower($username);

$bytes_in = 0;
$bytes_out = 0;
$rx_bps = 0;
$tx_bps = 0;
$uptime_str = null;
$found = false;

try {
    // 1. Check if user currently has an active accounting session in radacct
    $rad_active = null;
    $radStmt = $pdo->prepare("SELECT radacctid, nasipaddress, acctsessiontime, acctinputoctets, acctoutputoctets 
        FROM radacct 
        WHERE username = ? AND acctstoptime IS NULL 
        ORDER BY radacctid DESC LIMIT 1");
    $radStmt->execute([$username]);
    $rad_active = $radStmt->fetch(PDO::FETCH_ASSOC);

    // 2. Build list of candidate NAS routers
    $target_routers = [];
    if (!empty($rad_active['nasipaddress'])) {
        $rStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? AND nasname = ? LIMIT 1");
        $rStmt->execute([$client_id, $rad_active['nasipaddress']]);
        $matched_nas = $rStmt->fetch(PDO::FETCH_ASSOC);
        if ($matched_nas) {
            $target_routers[] = $matched_nas;
        }
    }

    // Include all other routers configured for this operator
    $allNasStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? ORDER BY id ASC");
    $allNasStmt->execute([$client_id]);
    $all_nas = $allNasStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_nas as $r) {
        $already = false;
        foreach ($target_routers as $tr) {
            if ((int)$tr['id'] === (int)$r['id']) {
                $already = true;
                break;
            }
        }
        if (!$already) {
            $target_routers[] = $r;
        }
    }

    // 3. Connect to routers and look for live session
    foreach ($target_routers as $nas) {
        if (empty($nas['nasname']) || empty($nas['api_user'])) {
            continue;
        }

        $api = new RouterosAPI();
        $api->timeout = 3;
        $api_port = !empty($nas['api_port']) ? (int)$nas['api_port'] : 8728;

        if (!$api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'] ?? '', $api_port)) {
            continue;
        }

        // A. Check PPPoE active sessions
        $exact_mikrotik_username = null;
        $ppp = $api->comm('/ppp/active/print', ['?name' => $username]);
        if (empty($ppp)) {
            $ppp = $api->comm('/ppp/active/print', ['?name' => $username_lower]);
        }
        if (!empty($ppp) && isset($ppp[0]['name'])) {
            $exact_mikrotik_username = $ppp[0]['name'];
            $uptime_str = $ppp[0]['uptime'] ?? null;
        }

        // Fallback scan if case differed or filter was unsupported
        if (!$exact_mikrotik_username) {
            $all_ppp = $api->comm('/ppp/active/print');
            if (is_array($all_ppp)) {
                foreach ($all_ppp as $conn) {
                    if (isset($conn['name']) && strtolower($conn['name']) === $username_lower) {
                        $exact_mikrotik_username = $conn['name'];
                        $uptime_str = $conn['uptime'] ?? null;
                        break;
                    }
                }
            }
        }

        // B. If found in PPPoE, fetch interface stats
        if ($exact_mikrotik_username) {
            $target_iface_name = null;

            $candidates = [
                '<pppoe-' . $exact_mikrotik_username . '>',
                'pppoe-' . $exact_mikrotik_username,
                '<sstp-' . $exact_mikrotik_username . '>',
                '<l2tp-' . $exact_mikrotik_username . '>',
                '<ovpn-' . $exact_mikrotik_username . '>'
            ];

            foreach ($candidates as $cand) {
                $iface_res = $api->comm('/interface/print', ['?name' => $cand]);
                if (!empty($iface_res) && isset($iface_res[0]['name'])) {
                    $target_iface_name = $iface_res[0]['name'];
                    $bytes_in = (float)($iface_res[0]['rx-byte'] ?? 0);
                    $bytes_out = (float)($iface_res[0]['tx-byte'] ?? 0);
                    $found = true;
                    break;
                }
            }

            // If not found by candidate name, search all PPPoE dynamic interfaces for partial match
            if (!$target_iface_name) {
                $all_pppoe = $api->comm('/interface/print', ['?type' => 'pppoe-in']);
                if (is_array($all_pppoe)) {
                    foreach ($all_pppoe as $pif) {
                        if (isset($pif['name']) && stripos($pif['name'], $exact_mikrotik_username) !== false) {
                            $target_iface_name = $pif['name'];
                            $bytes_in = (float)($pif['rx-byte'] ?? 0);
                            $bytes_out = (float)($pif['tx-byte'] ?? 0);
                            $found = true;
                            break;
                        }
                    }
                }
            }

            // Query monitor-traffic for live instantaneous bitrate
            if ($target_iface_name) {
                $traffic = $api->comm('/interface/monitor-traffic', [
                    'interface' => $target_iface_name,
                    'once'      => ''
                ]);
                if (!empty($traffic) && isset($traffic[0]['rx-bits-per-second'])) {
                    $rx_bps = (float)$traffic[0]['rx-bits-per-second'];
                    $tx_bps = (float)$traffic[0]['tx-bits-per-second'];
                }
            } else {
                $found = true;
            }
        }

        // C. Check Hotspot active if not found in PPPoE
        if (!$found) {
            $hs = $api->comm('/ip/hotspot/active/print', ['?user' => $username]);
            if (empty($hs)) {
                $hs = $api->comm('/ip/hotspot/active/print', ['?user' => $username_lower]);
            }
            if (!empty($hs) && isset($hs[0]['user'])) {
                $bytes_in = (float)($hs[0]['bytes-in'] ?? 0);
                $bytes_out = (float)($hs[0]['bytes-out'] ?? 0);
                $uptime_str = $hs[0]['uptime'] ?? null;
                $found = true;
            }
        }

        $api->disconnect();

        if ($found) {
            break;
        }
    }

    // 4. Fallback to FreeRADIUS radacct if router API was unreachable or returned 0 bytes
    if (!$found && !empty($rad_active)) {
        $bytes_in = (float)($rad_active['acctinputoctets'] ?? 0);
        $bytes_out = (float)($rad_active['acctoutputoctets'] ?? 0);
        $secs = (int)($rad_active['acctsessiontime'] ?? 0);
        $hours = floor($secs / 3600);
        $mins = floor(($secs % 3600) / 60);
        $uptime_str = "{$hours}h {$mins}m";
        $found = true;
    }

    if ($found) {
        echo json_encode([
            'status'    => 'online',
            'bytes_in'  => $bytes_in, 
            'bytes_out' => $bytes_out, 
            'rx_bps'    => $rx_bps, 
            'tx_bps'    => $tx_bps, 
            'uptime'    => $uptime_str
        ]);
    } else {
        echo json_encode([
            'status'    => 'offline',
            'bytes_in'  => 0, 
            'bytes_out' => 0, 
            'rx_bps'    => 0,
            'tx_bps'    => 0,
            'msg'       => 'User not active in MikroTik'
        ]);
    }
} catch (Exception $e) {
    // If any error occurred, try fallback to radacct
    if (!empty($rad_active)) {
        $bytes_in = (float)($rad_active['acctinputoctets'] ?? 0);
        $bytes_out = (float)($rad_active['acctoutputoctets'] ?? 0);
        $secs = (int)($rad_active['acctsessiontime'] ?? 0);
        $hours = floor($secs / 3600);
        $mins = floor(($secs % 3600) / 60);
        $uptime_str = "{$hours}h {$mins}m";
        echo json_encode([
            'status'    => 'online',
            'bytes_in'  => $bytes_in, 
            'bytes_out' => $bytes_out, 
            'rx_bps'    => 0, 
            'tx_bps'    => 0, 
            'uptime'    => $uptime_str
        ]);
    } else {
        echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'error' => $e->getMessage()]);
    }
}
?>