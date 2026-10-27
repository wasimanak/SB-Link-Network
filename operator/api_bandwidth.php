<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$client_id = $_SESSION['operator_id'] ?? $_SESSION['client_id'] ?? null;
$username = trim($_GET['username'] ?? '');
$sub_id = (int)($_GET['sub_id'] ?? 0);

if (!$client_id) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'status' => 'offline', 'error' => 'Unauthorized']);
    exit;
}

if (empty($username) && $sub_id > 0) {
    $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
    $uStmt->execute([$sub_id, $client_id]);
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
        $rStmt = $pdo->prepare("SELECT * FROM nas WHERE (client_id = ? OR client_id = 0 OR client_id IS NULL) AND nasname = ? LIMIT 1");
        $rStmt->execute([$client_id, $rad_active['nasipaddress']]);
        $matched_nas = $rStmt->fetch(PDO::FETCH_ASSOC);
        if ($matched_nas) {
            $target_routers[] = $matched_nas;
        }
    }

    // Include other real routers configured for this operator
    $allNasStmt = $pdo->prepare("SELECT * FROM nas 
        WHERE (client_id = ? OR client_id = 0 OR client_id IS NULL) 
        AND nasname NOT LIKE '%/%' 
        AND nasname != '0.0.0.0' 
        AND nasname != '127.0.0.1' 
        ORDER BY id ASC");
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
        $nas_ip = trim($nas['nasname'] ?? '');
        $api_user = !empty($nas['api_user']) ? trim($nas['api_user']) : 'admin';
        $api_pass = (string)($nas['api_password'] ?? '');
        $api_port = !empty($nas['api_port']) ? (int)$nas['api_port'] : 8728;

        if (empty($nas_ip) || strpos($nas_ip, '/') !== false) {
            continue;
        }

        // Fast socket check (0.6s)
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($nas_ip, $api_port, $errno, $errstr, 0.6);
        if (!$sock) {
            continue;
        }
        fclose($sock);

        $api = new RouterosAPI();
        $api->timeout = 2;
        $api->attempts = 1;

        if (!$api->connect($nas_ip, $api_user, $api_pass, $api_port)) {
            continue;
        }

        $exact_mikrotik_username = null;

        // A. Targeted check in PPPoE active
        $pRes = $api->comm('/ppp/active/print', ['?name' => $username]);
        if (empty($pRes) && $username_lower !== $username) {
            $pRes = $api->comm('/ppp/active/print', ['?name' => $username_lower]);
        }
        if (!empty($pRes) && isset($pRes[0]['name'])) {
            $exact_mikrotik_username = $pRes[0]['name'];
            $uptime_str = $pRes[0]['uptime'] ?? null;
            $found = true;
        }

        // Fallback scan if case differed or filter didn't match
        if (!$exact_mikrotik_username) {
            $all_ppp = $api->comm('/ppp/active/print');
            if (is_array($all_ppp)) {
                foreach ($all_ppp as $conn) {
                    $cname = strtolower(trim($conn['name'] ?? ''));
                    if ($cname === $username_lower || strpos($cname, $username_lower) !== false) {
                        $exact_mikrotik_username = $conn['name'];
                        $uptime_str = $conn['uptime'] ?? null;
                        $found = true;
                        break;
                    }
                }
            }
        }

        $u_target = $exact_mikrotik_username ?: $username;
        $target_iface_name = null;

        // B. Query monitor-traffic on candidate interfaces
        $cand_ifaces = [
            '<pppoe-' . $u_target . '>',
            'pppoe-' . $u_target,
            '<sstp-' . $u_target . '>',
            '<l2tp-' . $u_target . '>',
            '<ovpn-' . $u_target . '>',
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
                $target_iface_name = $cif;
                $found = true;
                break;
            }
        }

        // B2. If interface found, fetch byte counters
        if ($target_iface_name) {
            $if_stat = $api->comm('/interface/print', [
                '?name' => $target_iface_name,
                'stats' => ''
            ]);
            if (!empty($if_stat) && is_array($if_stat) && isset($if_stat[0])) {
                $bytes_in = (float)($if_stat[0]['rx-byte'] ?? $if_stat[0]['rx-bytes'] ?? 0);
                $bytes_out = (float)($if_stat[0]['tx-byte'] ?? $if_stat[0]['tx-bytes'] ?? 0);
            }
        }

        // C. Simple Queue check for instantaneous rates and byte counters
        if ($rx_bps == 0 && $tx_bps == 0) {
            $cand_queues = [
                '<pppoe-' . $u_target . '>',
                $u_target
            ];
            foreach ($cand_queues as $cq) {
                $qres = $api->comm('/queue/simple/print', ['?name' => $cq]);
                if (!empty($qres) && is_array($qres) && isset($qres[0])) {
                    $q = $qres[0];
                    if (!empty($q['rate'])) {
                        $rparts = explode('/', (string)$q['rate']);
                        $rx_bps = (float)($rparts[0] ?? 0);
                        $tx_bps = (float)($rparts[1] ?? 0);
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
            }
        }

        $api->disconnect();

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

    if ($found) {
        echo json_encode([
            'status'    => 'online',
            'source'    => $source,
            'bytes_in'  => $bytes_in, 
            'bytes_out' => $bytes_out, 
            'rx_bps'    => $rx_bps, 
            'tx_bps'    => $tx_bps, 
            'uptime'    => $uptime_str,
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
            'msg'       => 'User not active in MikroTik',
            'ts'        => microtime(true)
        ]);
    }
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
            'ts'        => microtime(true)
        ]);
    } else {
        echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'status' => 'offline', 'source' => 'none', 'error' => $e->getMessage(), 'ts' => microtime(true)]);
    }
}
?>
