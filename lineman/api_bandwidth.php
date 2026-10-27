<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$client_id = $_SESSION['lineman_id'] ?? $_SESSION['operator_id'] ?? $_SESSION['client_id'] ?? null;
$username = trim($_GET['username'] ?? '');
$sub_id = (int)($_GET['sub_id'] ?? 0);

if (!$client_id) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'status' => 'offline', 'error' => 'Unauthorized']);
    exit;
}

if (empty($username) && $sub_id > 0) {
    $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ?");
    $uStmt->execute([$sub_id]);
    $username = $uStmt->fetchColumn() ?: '';
}

if (empty($username)) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'status' => 'offline', 'error' => 'Missing username']);
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
    'attempts' => []
];

try {
    // 1. Check if user currently has an active accounting session in radacct
    $rad_active = null;
    $radStmt = $pdo->prepare("SELECT radacctid, nasipaddress, acctsessiontime, acctinputoctets, acctoutputoctets 
        FROM radacct 
        WHERE username = ? AND acctstoptime IS NULL 
        ORDER BY radacctid DESC LIMIT 1");
    $radStmt->execute([$username]);
    $rad_active = $radStmt->fetch(PDO::FETCH_ASSOC);

    // 2. Build candidate router list
    $target_routers = [];
    $default_api_user = 'admin';
    $default_api_pass = '';
    $default_api_port = 8728;

    $allNasStmt = $pdo->prepare("SELECT * FROM nas 
        WHERE nasname NOT LIKE '%/%' 
        AND nasname != '0.0.0.0' 
        AND nasname != '127.0.0.1' 
        ORDER BY id ASC");
    $allNasStmt->execute();
    $all_nas = $allNasStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($all_nas[0])) {
        $default_api_user = !empty($all_nas[0]['api_user']) ? trim($all_nas[0]['api_user']) : 'admin';
        $default_api_pass = (string)($all_nas[0]['api_password'] ?? '');
        $default_api_port = !empty($all_nas[0]['api_port']) ? (int)$all_nas[0]['api_port'] : 8728;
    }

    if (!empty($rad_active['nasipaddress'])) {
        $diag['rad_nasip'] = $rad_active['nasipaddress'];
        $rStmt = $pdo->prepare("SELECT * FROM nas WHERE nasname = ? LIMIT 1");
        $rStmt->execute([$rad_active['nasipaddress']]);
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

    // 3. Connect to routers and look for live session
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

        $exact_mikrotik_username = null;

        // A. Targeted check in PPPoE active
        $pRes = $api->comm('/ppp/active/print', ['?name' => $username]);
        if (empty($pRes) && $username_lower !== $username) {
            $pRes = $api->comm('/ppp/active/print', ['?name' => $username_lower]);
        }
        if (!empty($pRes) && isset($pRes[0]['name'])) {
            $exact_mikrotik_username = $pRes[0]['name'];
            $uptime_str = $pRes[0]['uptime'] ?? null;
            $attempt_info['user_found'] = true;
            $found = true;
        }

        if (!$exact_mikrotik_username) {
            $all_ppp = $api->comm('/ppp/active/print');
            if (is_array($all_ppp)) {
                foreach ($all_ppp as $conn) {
                    $cname = strtolower(trim($conn['name'] ?? ''));
                    if ($cname === $username_lower || strpos($cname, $username_lower) !== false) {
                        $exact_mikrotik_username = $conn['name'];
                        $uptime_str = $conn['uptime'] ?? null;
                        $attempt_info['user_found'] = true;
                        $found = true;
                        break;
                    }
                }
            }
        }

        $u_target = $exact_mikrotik_username ?: $username;

        // B. Check Simple Queues (Most reliable source for real-time rate on PPPoE in MikroTik)
        $cand_queues = [
            '<pppoe-' . $u_target . '>',
            $u_target,
            '<' . $u_target . '>'
        ];
        foreach ($cand_queues as $cq) {
            $qres = $api->comm('/queue/simple/print', ['?name' => $cq]);
            if (!empty($qres) && is_array($qres) && isset($qres[0])) {
                $q = $qres[0];
                if (!empty($q['rate'])) {
                    $rparts = explode('/', (string)$q['rate']);
                    $rx_bps = (float)($rparts[0] ?? 0);
                    $tx_bps = (float)($rparts[1] ?? 0);
                    if ($rx_bps > 0 || $tx_bps > 0) {
                        $attempt_info['speed_found'] = true;
                    }
                }
                if ($bytes_in == 0 && $bytes_out == 0 && !empty($q['bytes'])) {
                    $bparts = explode('/', (string)$q['bytes']);
                    $bytes_in = (float)($bparts[0] ?? 0);
                    $bytes_out = (float)($bparts[1] ?? 0);
                }
                $found = true;
                break;
            }
        }

        // C. Check interface monitor-traffic if rates still 0
        if ($rx_bps == 0 && $tx_bps == 0) {
            $cand_ifaces = [
                '<pppoe-' . $u_target . '>',
                'pppoe-' . $u_target,
                '<sstp-' . $u_target . '>',
                '<l2tp-' . $u_target . '>',
                $u_target
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
                    $found = true;
                    break;
                }
            }
        }

        // D. Check Hotspot active if not found in PPPoE
        if (!$found) {
            $hs = $api->comm('/ip/hotspot/active/print', ['?user' => $username]);
            if (empty($hs) && $username_lower !== $username) {
                $hs = $api->comm('/ip/hotspot/active/print', ['?user' => $username_lower]);
            }
            if (!empty($hs) && isset($hs[0]['user'])) {
                $bytes_in = (float)($hs[0]['bytes-in'] ?? 0);
                $bytes_out = (float)($hs[0]['bytes-out'] ?? 0);
                $uptime_str = $hs[0]['uptime'] ?? null;
                $found = true;
                $attempt_info['user_found'] = true;
            }
        }

        $api->disconnect();
        $diag['attempts'][] = $attempt_info;

        if ($found) {
            $source = 'mikrotik';
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
        $source = 'radius';
    } elseif ($found && $bytes_in == 0 && $bytes_out == 0 && !empty($rad_active)) {
        $bytes_in = (float)($rad_active['acctinputoctets'] ?? 0);
        $bytes_out = (float)($rad_active['acctoutputoctets'] ?? 0);
    }

    echo json_encode([
        'status'    => $found ? 'online' : 'offline',
        'source'    => $source,
        'bytes_in'  => $bytes_in, 
        'bytes_out' => $bytes_out, 
        'rx_bps'    => $rx_bps, 
        'tx_bps'    => $tx_bps, 
        'uptime'    => $uptime_str,
        'diag'      => $diag,
        'ts'        => microtime(true)
    ]);
} catch (Exception $e) {
    if (!empty($rad_active)) {
        $bytes_in = (float)($rad_active['acctinputoctets'] ?? 0);
        $bytes_out = (float)($rad_active['acctoutputoctets'] ?? 0);
        $secs = (int)($rad_active['acctsessiontime'] ?? 0);
        $hours = floor($secs / 3600);
        $mins = floor(($secs % 3600) / 60);
        $uptime_str = "{$hours}h {$mins}m";
        echo json_encode([
            'status'    => 'online',
            'source'    => 'radius',
            'bytes_in'  => $bytes_in, 
            'bytes_out' => $bytes_out, 
            'rx_bps'    => 0, 
            'tx_bps'    => 0, 
            'uptime'    => $uptime_str,
            'diag'      => $diag,
            'error'     => $e->getMessage(),
            'ts'        => microtime(true)
        ]);
    } else {
        echo json_encode([
            'status'    => 'offline', 
            'source'    => 'none', 
            'bytes_in'  => 0, 
            'bytes_out' => 0, 
            'rx_bps'    => 0, 
            'tx_bps'    => 0, 
            'diag'      => $diag,
            'error'     => $e->getMessage(), 
            'ts'        => microtime(true)
        ]);
    }
}
?>
