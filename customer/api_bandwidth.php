<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['customer_logged_in']) || $_SESSION['customer_logged_in'] !== true) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'status' => 'offline', 'error' => 'Unauthorized']);
    exit;
}

$sub_id = (int)($_SESSION['customer_id'] ?? 0);
$client_id = (int)($_SESSION['customer_client_id'] ?? 0);

if (!$sub_id || !$client_id) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'status' => 'offline', 'error' => 'Unauthorized']);
    exit;
}

$uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
$uStmt->execute([$sub_id, $client_id]);
$username = $uStmt->fetchColumn() ?: '';

if (empty($username)) {
    echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'status' => 'offline', 'error' => 'Subscriber not found']);
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
    $subStmt = $pdo->prepare("SELECT id, username, status, expiry_date, last_renewed_at, created_at FROM subscribers WHERE id = ? AND client_id = ? LIMIT 1");
    $subStmt->execute([$sub_id, $client_id]);
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

    // IMMEDIATE SHORT-CIRCUIT:
    // If subscriber is expired OR has no active session and status != 'active',
    // DO NOT connect to MikroTik API!
    if ($is_expired_sub || (!$rad_active && strtolower($sub_row['status'] ?? '') !== 'active')) {
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

    // 2. MikroTik Credentials & Router Discovery
    $default_api_user = 'admin';
    $default_api_pass = '';
    $default_api_port = 8728;

    $cStmt = $pdo->prepare("SELECT mikrotik_ip, mikrotik_user, mikrotik_password, mikrotik_port FROM clients WHERE id = ?");
    $cStmt->execute([$client_id]);
    $client_conf = $cStmt->fetch(PDO::FETCH_ASSOC);

    if ($client_conf) {
        if (!empty($client_conf['mikrotik_user'])) $default_api_user = trim($client_conf['mikrotik_user']);
        if (isset($client_conf['mikrotik_password'])) $default_api_pass = (string)$client_conf['mikrotik_password'];
        if (!empty($client_conf['mikrotik_port'])) $default_api_port = (int)$client_conf['mikrotik_port'];
    }

    $routers_to_try = [];
    $seen_ips = [];

    // Prioritize NAS IP from active radacct
    if ($rad_active && !empty($rad_active['nasipaddress'])) {
        $nas_ip_rad = trim($rad_active['nasipaddress']);
        $diag['rad_nasip'] = $nas_ip_rad;
        $nasLookup = $pdo->prepare("SELECT nasname, api_user, api_password, api_port FROM nas WHERE nasname = ? AND client_id = ? LIMIT 1");
        $nasLookup->execute([$nas_ip_rad, $client_id]);
        $matched_nas = $nasLookup->fetch(PDO::FETCH_ASSOC);
        if ($matched_nas) {
            $routers_to_try[] = $matched_nas;
            $seen_ips[$matched_nas['nasname']] = true;
        } else {
            $routers_to_try[] = [
                'nasname'      => $nas_ip_rad,
                'api_user'     => $default_api_user,
                'api_password' => $default_api_pass,
                'api_port'     => $default_api_port
            ];
            $seen_ips[$nas_ip_rad] = true;
        }
    }

    // Add remaining NAS devices
    $nasStmt = $pdo->prepare("SELECT nasname, api_user, api_password, api_port FROM nas WHERE client_id = ? ORDER BY id ASC");
    $nasStmt->execute([$client_id]);
    while ($n = $nasStmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($n['nasname']) && !isset($seen_ips[$n['nasname']])) {
            $routers_to_try[] = $n;
            $seen_ips[$n['nasname']] = true;
        }
    }

    // Fallback: clients table mikrotik_ip
    if (!empty($client_conf['mikrotik_ip']) && !isset($seen_ips[$client_conf['mikrotik_ip']])) {
        $routers_to_try[] = [
            'nasname'      => trim($client_conf['mikrotik_ip']),
            'api_user'     => $default_api_user,
            'api_password' => $default_api_pass,
            'api_port'     => $default_api_port
        ];
    }

    $any_router_logged_in = false;

    foreach ($routers_to_try as $nas) {
        $nas_ip = trim($nas['nasname'] ?? '');
        $api_user = !empty($nas['api_user']) ? trim($nas['api_user']) : $default_api_user;
        $api_pass = isset($nas['api_password']) ? (string)$nas['api_password'] : $default_api_pass;
        $api_port = !empty($nas['api_port']) ? (int)$nas['api_port'] : $default_api_port;

        if (empty($nas_ip) || strpos($nas_ip, '/') !== false) {
            continue;
        }

        $attempt_info = [
            'ip'          => $nas_ip,
            'port'        => $api_port,
            'user'        => $api_user,
            'sock_ok'     => false,
            'sock_err'    => null,
            'login_ok'    => false,
            'user_found'  => false,
            'speed_found' => false
        ];

        // 1.2s socket probe
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

        // Method A: Check /ppp/active
        $active_ppp = $api->comm('/ppp/active/print');
        if (is_array($active_ppp)) {
            foreach ($active_ppp as $session) {
                $sess_name = strtolower($session['name'] ?? '');
                $sess_ip = trim($session['address'] ?? '');

                $is_match = ($sess_name === $username_lower);
                if (!$is_match && $framed_ip && !empty($sess_ip) && $sess_ip === $framed_ip) {
                    $is_match = true;
                }

                if ($is_match) {
                    $found = true;
                    $attempt_info['user_found'] = true;
                    $exact_mikrotik_username = $session['name'] ?? $username;
                    $matched_ip = $sess_ip ?: $framed_ip;
                    $source = 'mikrotik';
                    $uptime_str = $session['uptime'] ?? null;

                    // Fallback bytes if present
                    if (isset($session['bytes-in']) && isset($session['bytes-out'])) {
                        $bytes_in = (float)$session['bytes-in'];
                        $bytes_out = (float)$session['bytes-out'];
                    }

                    // A1. Query Simple Queue
                    $q_names_to_try = array_unique(array_filter([
                        '<pppoe-' . $exact_mikrotik_username . '>',
                        '<pppoe-' . $username . '>',
                        $exact_mikrotik_username,
                        $username
                    ]));

                    $queue_found = false;
                    foreach ($q_names_to_try as $qn) {
                        $queue_res = $api->comm('/queue/simple/print', [
                            '?name' => $qn
                        ]);
                        if (is_array($queue_res) && !empty($queue_res)) {
                            $q_item = $queue_res[0];
                            if (isset($q_item['bytes'])) {
                                $b_parts = explode('/', $q_item['bytes']);
                                if (count($b_parts) >= 2) {
                                    $bytes_in = (float)trim($b_parts[0]);
                                    $bytes_out = (float)trim($b_parts[1]);
                                }
                            }
                            if (isset($q_item['rate'])) {
                                $r_parts = explode('/', $q_item['rate']);
                                if (count($r_parts) >= 2) {
                                    $rx_bps = (float)trim($r_parts[0]);
                                    $tx_bps = (float)trim($r_parts[1]);
                                    $queue_found = true;
                                    $attempt_info['speed_found'] = true;
                                }
                            }
                            break;
                        }
                    }

                    // A2. Fallback to /interface/monitor-traffic
                    if (!$queue_found) {
                        $if_names_to_try = array_unique(array_filter([
                            '<pppoe-' . $exact_mikrotik_username . '>',
                            '<pppoe-' . $username . '>'
                        ]));

                        foreach ($if_names_to_try as $if_name) {
                            $mon = $api->comm('/interface/monitor-traffic', [
                                'interface' => $if_name,
                                'once'      => ''
                            ]);
                            if (is_array($mon) && !empty($mon)) {
                                $mon_item = isset($mon[0]) ? $mon[0] : $mon;
                                if (isset($mon_item['rx-bits-per-second']) || isset($mon_item['tx-bits-per-second'])) {
                                    $rx_bps = (float)($mon_item['rx-bits-per-second'] ?? 0);
                                    $tx_bps = (float)($mon_item['tx-bits-per-second'] ?? 0);
                                    $attempt_info['speed_found'] = true;
                                    break;
                                }
                            }
                        }
                    }
                    break;
                }
            }
        }

        // Method B: Check /ip/hotspot/active if not found
        if (!$found) {
            $active_hotspot = $api->comm('/ip/hotspot/active/print');
            if (is_array($active_hotspot)) {
                foreach ($active_hotspot as $session) {
                    $sess_user = strtolower($session['user'] ?? '');
                    $sess_ip = trim($session['address'] ?? '');

                    $is_match = ($sess_user === $username_lower);
                    if (!$is_match && $framed_ip && !empty($sess_ip) && $sess_ip === $framed_ip) {
                        $is_match = true;
                    }

                    if ($is_match) {
                        $found = true;
                        $attempt_info['user_found'] = true;
                        $exact_mikrotik_username = $session['user'] ?? $username;
                        $source = 'mikrotik';
                        $uptime_str = $session['uptime'] ?? null;
                        if (isset($session['bytes-in'])) $bytes_in = (float)$session['bytes-in'];
                        if (isset($session['bytes-out'])) $bytes_out = (float)$session['bytes-out'];

                        $queue_res = $api->comm('/queue/simple/print', [
                            '?name' => $exact_mikrotik_username
                        ]);
                        if (is_array($queue_res) && !empty($queue_res)) {
                            $q_item = $queue_res[0];
                            if (isset($q_item['rate'])) {
                                $r_parts = explode('/', $q_item['rate']);
                                if (count($r_parts) >= 2) {
                                    $rx_bps = (float)trim($r_parts[0]);
                                    $tx_bps = (float)trim($r_parts[1]);
                                    $attempt_info['speed_found'] = true;
                                }
                            }
                        }
                        break;
                    }
                }
            }
        }

        // Method C: Direct Simple Queue check if still not found
        if (!$found) {
            $q_match = $api->comm('/queue/simple/print', [
                '?name' => $username
            ]);
            if (is_array($q_match) && !empty($q_match)) {
                $found = true;
                $attempt_info['user_found'] = true;
                $source = 'mikrotik';
                $q_item = $q_match[0];
                if (isset($q_item['bytes'])) {
                    $b_parts = explode('/', $q_item['bytes']);
                    if (count($b_parts) >= 2) {
                        $bytes_in = (float)trim($b_parts[0]);
                        $bytes_out = (float)trim($b_parts[1]);
                    }
                }
                if (isset($q_item['rate'])) {
                    $r_parts = explode('/', $q_item['rate']);
                    if (count($r_parts) >= 2) {
                        $rx_bps = (float)trim($r_parts[0]);
                        $tx_bps = (float)trim($r_parts[1]);
                        $attempt_info['speed_found'] = true;
                    }
                }
            }
        }

        $api->disconnect();
        $diag['attempts'][] = $attempt_info;

        if ($found) {
            break;
        }
    }

    // 3. Fallback to RADIUS Accounting if MikroTik API was unavailable or did not report
    if (!$found && !empty($rad_active)) {
        $found = true;
        $source = 'radius';
        $bytes_in = (float)($rad_active['acctinputoctets'] ?? 0);
        $bytes_out = (float)($rad_active['acctoutputoctets'] ?? 0);
        $uptime_sec = (int)($rad_active['acctsessiontime'] ?? 0);
        $u_hours = floor($uptime_sec / 3600);
        $u_mins = floor(($uptime_sec % 3600) / 60);
        $uptime_str = ($u_hours > 0 ? "{$u_hours}h " : "") . "{$u_mins}m";
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
