<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$dealer_id = (int)($_SESSION['dealer_id'] ?? 0);
$client_id = (int)($_SESSION['client_id'] ?? 0);
$username = trim($_GET['username'] ?? '');
$sub_id = (int)($_GET['sub_id'] ?? 0);

if (!$dealer_id || !$client_id) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'status' => 'offline', 'error' => 'Unauthorized']);
    exit;
}

if ($sub_id > 0) {
    $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND dealer_id = ? AND client_id = ?");
    $uStmt->execute([$sub_id, $dealer_id, $client_id]);
    $username = $uStmt->fetchColumn() ?: '';
} else {
    $uStmt = $pdo->prepare("SELECT id FROM subscribers WHERE username = ? AND dealer_id = ? AND client_id = ?");
    $uStmt->execute([$username, $dealer_id, $client_id]);
    if (!$uStmt->fetchColumn()) {
        $username = '';
    }
}

if (empty($username)) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'status' => 'offline', 'error' => 'Subscriber not found or unauthorized']);
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
$source = 'none';
$diag = [
    'username' => $username,
    'rad_nasip' => null,
    'framed_ip' => null,
    'attempts' => []
];

try {
    // 1. Fetch subscriber cycle reset timestamp & status
    $subStmt = $pdo->prepare("SELECT id, username, status, expiry_date, last_renewed_at, created_at FROM subscribers WHERE (username = ? OR id = ?) AND dealer_id = ? AND client_id = ? LIMIT 1");
    $subStmt->execute([$username, $sub_id, $dealer_id, $client_id]);
    $sub_row = $subStmt->fetch(PDO::FETCH_ASSOC);

    $is_expired_sub = false;
    if ($sub_row) {
        $has_exp = (!empty($sub_row['expiry_date']) && $sub_row['expiry_date'] !== '0000-00-00 00:00:00');
        $exp_ts = $has_exp ? strtotime($sub_row['expiry_date']) : 0;
        $is_expired_sub = (strtolower($sub_row['status'] ?? '') === 'expired' || ($has_exp && $exp_ts <= time()));
    }

    $cycle_start_ts = 0;
    if (!empty($sub_row['last_renewed_at']) && $sub_row['last_renewed_at'] !== '0000-00-00 00:00:00') {
        $cycle_start_ts = strtotime($sub_row['last_renewed_at']);
    } elseif (!empty($sub_row['created_at'])) {
        $cycle_start_ts = strtotime($sub_row['created_at']);
    }

    // Check if user currently has an active accounting session in radacct
    $rad_active = null;
    $radStmt = $pdo->prepare("SELECT radacctid, nasipaddress, acctsessiontime, acctinputoctets, acctoutputoctets, framedipaddress, acctstarttime 
        FROM radacct 
        WHERE username = ? AND acctstoptime IS NULL 
        ORDER BY radacctid DESC LIMIT 1");
    $radStmt->execute([$username]);
    $rad_active = $radStmt->fetch(PDO::FETCH_ASSOC);

    $framed_ip = !empty($rad_active['framedipaddress']) ? trim($rad_active['framedipaddress']) : null;
    $diag['framed_ip'] = $framed_ip;

    // 2. Build candidate router list
    $target_routers = [];
    $default_api_user = 'admin';
    $default_api_pass = '';
    $default_api_port = 8728;

    // Fetch operator's configured NAS routers from DB
    $allNasStmt = $pdo->prepare("SELECT * FROM nas 
        WHERE (client_id = ? OR client_id = 0 OR client_id IS NULL) 
        AND nasname NOT LIKE '%/%' 
        AND nasname != '0.0.0.0' 
        AND nasname != '127.0.0.1' 
        ORDER BY id ASC");
    $allNasStmt->execute([$client_id]);
    $all_nas = $allNasStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($all_nas[0])) {
        $default_api_user = !empty($all_nas[0]['api_user']) ? trim($all_nas[0]['api_user']) : 'admin';
        $default_api_pass = (string)($all_nas[0]['api_password'] ?? '');
        $default_api_port = !empty($all_nas[0]['api_port']) ? (int)$all_nas[0]['api_port'] : 8728;
    }

    // A. If radacct has active nasipaddress, prioritize it
    if (!empty($rad_active['nasipaddress'])) {
        $diag['rad_nasip'] = $rad_active['nasipaddress'];
        $rStmt = $pdo->prepare("SELECT * FROM nas WHERE (client_id = ? OR client_id = 0 OR client_id IS NULL) AND nasname = ? LIMIT 1");
        $rStmt->execute([$client_id, $rad_active['nasipaddress']]);
        $matched_nas = $rStmt->fetch(PDO::FETCH_ASSOC);

        if ($matched_nas) {
            $target_routers[] = $matched_nas;
        } else {
            $target_routers[] = [
                'id' => 0,
                'nasname' => $rad_active['nasipaddress'],
                'shortname' => 'RADIUS Router (' . $rad_active['nasipaddress'] . ')',
                'api_user' => $default_api_user,
                'api_password' => $default_api_pass,
                'api_port' => $default_api_port
            ];
        }
    }

    // B. Append other configured NAS routers
    foreach ($all_nas as $r) {
        $already = false;
        foreach ($target_routers as $tr) {
            if (trim($tr['nasname'] ?? '') === trim($r['nasname'] ?? '')) {
                $already = true;
                break;
            }
        }
        if (!$already) {
            $target_routers[] = $r;
        }
    }

    // 3. Connect to candidate routers
    $any_router_logged_in = false;

    foreach ($target_routers as $nas) {
        $nas_ip = trim($nas['nasname'] ?? '');
        $api_user = !empty($nas['api_user']) ? trim($nas['api_user']) : $default_api_user;
        $api_pass = isset($nas['api_password']) ? (string)$nas['api_password'] : $default_api_pass;
        $api_port = !empty($nas['api_port']) ? (int)$nas['api_port'] : $default_api_port;

        if (empty($nas_ip) || strpos($nas_ip, '/') !== false) {
            continue;
        }

        $attempt_info = [
            'ip' => $nas_ip,
            'port' => $api_port,
            'user' => $api_user,
            'sock_ok' => false,
            'sock_err' => null,
            'login_ok' => false,
            'user_found' => false,
            'speed_found' => false
        ];

        // 1.2s timeout socket check
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($nas_ip, $api_port, $errno, $errstr, 1.2);
        if (!$sock) {
            $attempt_info['sock_err'] = $errstr ?: "Connection timed out (Port $api_port unreachable)";
            $diag['attempts'][] = $attempt_info;
            continue;
        }
        $attempt_info['sock_ok'] = true;
        fclose($sock);

        $api = new RouterosAPI();
        $api->timeout = 2;
        $api->attempts = 1;

        if (!$api->connect($nas_ip, $api_user, $api_pass, $api_port)) {
            $attempt_info['sock_err'] = 'Login failed (Check API username/password on MikroTik)';
            $diag['attempts'][] = $attempt_info;
            continue;
        }
        $attempt_info['login_ok'] = true;
        $any_router_logged_in = true;

        $exact_mikrotik_username = null;
        $matched_ip = null;

        // A. Check PPPoE Active - STRICT EXACT USERNAME MATCH ONLY
        // 1. Direct query by username
        $pRes = $api->comm('/ppp/active/print', ['?name' => $username]);
        if (empty($pRes) && $username_lower !== $username) {
            $pRes = $api->comm('/ppp/active/print', ['?name' => $username_lower]);
        }
        if (!empty($pRes) && isset($pRes[0]['name']) && strcasecmp(trim($pRes[0]['name']), $username) === 0) {
            $exact_mikrotik_username = trim($pRes[0]['name']);
            $matched_ip = trim($pRes[0]['address'] ?? '');
            $uptime_str = $pRes[0]['uptime'] ?? null;
            $attempt_info['user_found'] = true;
            $found = true;
        }

        // 2. Full scan of PPPoE active if direct query didn't match (STRICT case-insensitive exact match ONLY - NEVER loose stripos)
        if (!$exact_mikrotik_username) {
            $all_ppp = $api->comm('/ppp/active/print');
            if (is_array($all_ppp)) {
                foreach ($all_ppp as $conn) {
                    $cname = trim($conn['name'] ?? '');
                    if (strcasecmp($cname, $username) === 0) {
                        $exact_mikrotik_username = $cname;
                        $matched_ip = trim($conn['address'] ?? '');
                        $uptime_str = $conn['uptime'] ?? null;
                        $attempt_info['user_found'] = true;
                        $found = true;
                        break;
                    }
                }
            }
        }

        // B. Check Hotspot active if not found in PPPoE - STRICT EXACT USERNAME MATCH ONLY
        if (!$found) {
            $hs = $api->comm('/ip/hotspot/active/print', ['?user' => $username]);
            if (empty($hs) && $username_lower !== $username) {
                $hs = $api->comm('/ip/hotspot/active/print', ['?user' => $username_lower]);
            }
            if (!empty($hs) && isset($hs[0]['user']) && strcasecmp(trim($hs[0]['user']), $username) === 0) {
                $bytes_in = (float)($hs[0]['bytes-in'] ?? 0);
                $bytes_out = (float)($hs[0]['bytes-out'] ?? 0);
                $uptime_str = $hs[0]['uptime'] ?? null;
                $matched_ip = trim($hs[0]['address'] ?? '');
                $exact_mikrotik_username = trim($hs[0]['user']);
                $found = true;
                $attempt_info['user_found'] = true;
            }
        }

        // C. ONLY IF the subscriber is confirmed active on this router, query Simple Queue or Interface Monitor
        if ($found && !empty($exact_mikrotik_username)) {
            $u_target = $exact_mikrotik_username;

            // Simple Queues for this exact user
            $cand_queues = [
                '<pppoe-' . $u_target . '>',
                $u_target,
                '<' . $u_target . '>'
            ];
            $found_queue = null;
            foreach ($cand_queues as $cq) {
                $qres = $api->comm('/queue/simple/print', ['?name' => $cq]);
                if (!empty($qres) && is_array($qres) && isset($qres[0]['name'])) {
                    $found_queue = $qres[0];
                    break;
                }
            }

            if ($found_queue) {
                if (!empty($found_queue['rate'])) {
                    $rparts = explode('/', (string)$found_queue['rate']);
                    $rx_bps = (float)($rparts[0] ?? 0); // Upload
                    $tx_bps = (float)($rparts[1] ?? 0); // Download
                    if ($rx_bps > 0 || $tx_bps > 0) {
                        $attempt_info['speed_found'] = true;
                    }
                }
                if ($bytes_in == 0 && $bytes_out == 0 && !empty($found_queue['bytes'])) {
                    $bparts = explode('/', (string)$found_queue['bytes']);
                    $bytes_in = (float)($bparts[0] ?? 0);
                    $bytes_out = (float)($bparts[1] ?? 0);
                }
            }

            // Interface monitor-traffic if rates still 0 (PPPoE interface only, NEVER generic physical ports)
            if ($rx_bps == 0 && $tx_bps == 0) {
                $cand_ifaces = [
                    '<pppoe-' . $u_target . '>',
                    'pppoe-' . $u_target,
                    '<sstp-' . $u_target . '>',
                    '<l2tp-' . $u_target . '>'
                ];
                foreach ($cand_ifaces as $cif) {
                    $traffic = $api->comm('/interface/monitor-traffic', [
                        'interface' => $cif,
                        'once'      => ''
                    ]);
                    if (!empty($traffic) && is_array($traffic) && isset($traffic[0]['rx-bits-per-second'])) {
                        $rx_bps = (float)$traffic[0]['rx-bits-per-second'];
                        $tx_bps = (float)$traffic[0]['tx-bits-per-second'];
                        $attempt_info['speed_found'] = true;
                        break;
                    }
                }
            }
        }

        $api->disconnect();
        $diag['attempts'][] = $attempt_info;

        if ($found) {
            $source = 'mikrotik';
            break;
        }
    }

    // 4. Authoritative check: If routers were reached and confirmed subscriber is NOT active on any router:
    if (!$found && $any_router_logged_in) {
        // Router confirmed user is OFFLINE.
        // Auto-close any stale/ghost session in radacct so DB stays clean
        if (!empty($rad_active)) {
            try {
                $pdo->prepare("UPDATE radacct SET acctstoptime = NOW(), acctterminatecause = 'Lost-Carrier' WHERE username = ? AND acctstoptime IS NULL")->execute([$username]);
            } catch (Exception $e) {}
            $rad_active = null;
        }

        echo json_encode([
            'status'        => 'offline',
            'source'        => 'none',
            'bytes_in'      => 0,
            'bytes_out'     => 0,
            'rx_bps'        => 0,
            'tx_bps'        => 0,
            'uptime'        => null,
            'is_prev_cycle' => false,
            'diag'          => $diag,
            'ts'            => microtime(true)
        ]);
        exit;
    }

    // 5. Fallback to FreeRADIUS radacct ONLY if NO router API could be reached AND user is not expired
    if (!$found && !empty($rad_active) && !$is_expired_sub && !$any_router_logged_in) {
        $bytes_in = (float)($rad_active['acctinputoctets'] ?? 0);
        $bytes_out = (float)($rad_active['acctoutputoctets'] ?? 0);
        $secs = (int)($rad_active['acctsessiontime'] ?? 0);
        $hours = floor($secs / 3600);
        $mins = floor(($secs % 3600) / 60);
        $uptime_str = "{$hours}h {$mins}m";
        $found = true;
        $source = 'radius';
        $rx_bps = 0;
        $tx_bps = 0;
    } elseif ($found && $bytes_in == 0 && $bytes_out == 0 && !empty($rad_active)) {
        $bytes_in = (float)($rad_active['acctinputoctets'] ?? 0);
        $bytes_out = (float)($rad_active['acctoutputoctets'] ?? 0);
    }

    // If still not found or user is expired/offline: strictly clamp all traffic stats to 0
    if (!$found) {
        $bytes_in = 0;
        $bytes_out = 0;
        $rx_bps = 0;
        $tx_bps = 0;
        $uptime_str = null;
        $source = 'none';
    }

    // If current live session started BEFORE the cycle start date, clamp cumulative session bytes to 0
    $is_prev_cycle = false;
    if ($cycle_start_ts > 0 && $found) {
        $active_start_ts = 0;
        if (!empty($rad_active['acctstarttime']) && $rad_active['acctstarttime'] !== '0000-00-00 00:00:00') {
            $active_start_ts = is_numeric($rad_active['acctstarttime']) ? (int)$rad_active['acctstarttime'] : strtotime($rad_active['acctstarttime']);
        }
        if ($active_start_ts > 0 && $active_start_ts < $cycle_start_ts) {
            $is_prev_cycle = true;
            $bytes_in = 0;
            $bytes_out = 0;
        }
    }

    echo json_encode([
        'status'        => $found ? 'online' : 'offline',
        'source'        => $source,
        'bytes_in'      => $bytes_in, 
        'bytes_out'     => $bytes_out, 
        'rx_bps'        => $rx_bps, 
        'tx_bps'        => $tx_bps, 
        'uptime'        => $uptime_str,
        'is_prev_cycle' => $is_prev_cycle,
        'diag'          => $diag,
        'ts'            => microtime(true)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status'    => 'offline', 
        'source'    => 'none', 
        'bytes_in'  => 0, 
        'bytes_out' => 0, 
        'rx_bps'    => 0, 
        'tx_bps'    => 0, 
        'uptime'    => null,
        'diag'      => $diag,
        'error'     => $e->getMessage(), 
        'ts'        => microtime(true)
    ]);
}