<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: application/json');

$client_id = $_SESSION['client_id'] ?? null;
$username = trim($_GET['username'] ?? '');
$sub_id = (int)($_GET['sub_id'] ?? 0);

if (!isset($_SESSION['lineman_id']) || !$client_id) {
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

    // Include all other real routers configured for this operator (exclude subnets / wildcards)
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

        // Fast socket check (0.8s) so unreachable router IPs don't stall the request
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($nas_ip, $api_port, $errno, $errstr, 0.8);
        if (!$sock) {
            continue;
        }
        fclose($sock);

        $api = new RouterosAPI();
        $api->timeout = 3;
        $api->attempts = 1;

        if (!$api->connect($nas_ip, $api_user, $api_pass, $api_port)) {
            continue;
        }

        $exact_mikrotik_username = null;

        // A. Check PPPoE active sessions
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

        // B. If found in PPPoE, fetch interface stats directly
        if ($exact_mikrotik_username) {
            $target_iface_name = null;
            $target_iface_id = null;

            // Fetch interfaces with cumulative byte stats
            $all_ifaces = $api->comm('/interface/print', ['stats' => '']);
            if (empty($all_ifaces) || !is_array($all_ifaces)) {
                $all_ifaces = $api->comm('/interface/print');
            }

            if (is_array($all_ifaces)) {
                foreach ($all_ifaces as $if) {
                    $iname = strtolower(trim($if['name'] ?? ''));
                    if (strpos($iname, $username_lower) !== false) {
                        $target_iface_name = $if['name'];
                        $target_iface_id = $if['.id'] ?? null;
                        $bytes_in = (float)($if['rx-byte'] ?? $if['rx-bytes'] ?? 0);
                        $bytes_out = (float)($if['tx-byte'] ?? $if['tx-bytes'] ?? 0);
                        break;
                    }
                }
            }

            // Query monitor-traffic for live instantaneous bitrate
            if ($target_iface_name) {
                $traffic = $api->comm('/interface/monitor-traffic', [
                    'interface' => $target_iface_name,
                    'once'      => ''
                ]);
                if (!empty($traffic) && is_array($traffic) && isset($traffic[0]['rx-bits-per-second'])) {
                    $rx_bps = (float)$traffic[0]['rx-bits-per-second'];
                    $tx_bps = (float)$traffic[0]['tx-bits-per-second'];
                } elseif (!empty($target_iface_id)) {
                    // Try with interface .id if bracketed name had issues
                    $traffic = $api->comm('/interface/monitor-traffic', [
                        'interface' => $target_iface_id,
                        'once'      => ''
                    ]);
                    if (!empty($traffic) && is_array($traffic) && isset($traffic[0]['rx-bits-per-second'])) {
                        $rx_bps = (float)$traffic[0]['rx-bits-per-second'];
                        $tx_bps = (float)$traffic[0]['tx-bits-per-second'];
                    }
                }
            }
        }

        // C. Simple Queue check for instantaneous rates and byte counters
        if ($rx_bps == 0 && $tx_bps == 0) {
            $all_queues = $api->comm('/queue/simple/print');
            if (is_array($all_queues)) {
                foreach ($all_queues as $q) {
                    $qname = strtolower(trim($q['name'] ?? ''));
                    if (strpos($qname, $username_lower) !== false) {
                        if (!empty($q['rate'])) {
                            $rparts = explode('/', (string)$q['rate']);
                            $rx_bps = (float)($rparts[0] ?? 0);
                            $tx_bps = (float)($rparts[1] ?? 0);
                        } elseif (isset($q['rate.upload']) || isset($q['rate.download'])) {
                            $rx_bps = (float)($q['rate.upload'] ?? 0);
                            $tx_bps = (float)($q['rate.download'] ?? 0);
                        }
                        if ($bytes_in == 0 && $bytes_out == 0) {
                            if (!empty($q['bytes'])) {
                                $bparts = explode('/', (string)$q['bytes']);
                                $bytes_in = (float)($bparts[0] ?? 0);
                                $bytes_out = (float)($bparts[1] ?? 0);
                            } elseif (isset($q['bytes.upload']) || isset($q['bytes.download'])) {
                                $bytes_in = (float)($q['bytes.upload'] ?? 0);
                                $bytes_out = (float)($q['bytes.download'] ?? 0);
                            }
                        }
                        $found = true;
                        break;
                    }
                }
            }
        }

        // D. Check Hotspot active if not found in PPPoE
        if (!$found) {
            $all_hs = $api->comm('/ip/hotspot/active/print');
            if (is_array($all_hs)) {
                foreach ($all_hs as $hs) {
                    $huser = strtolower(trim($hs['user'] ?? ''));
                    if ($huser === $username_lower || strpos($huser, $username_lower) !== false) {
                        $bytes_in = (float)($hs['bytes-in'] ?? 0);
                        $bytes_out = (float)($hs['bytes-out'] ?? 0);
                        $uptime_str = $hs['uptime'] ?? null;
                        $found = true;
                        break;
                    }
                }
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
    } elseif ($found && $bytes_in == 0 && $bytes_out == 0 && !empty($rad_active)) {
        $bytes_in = (float)($rad_active['acctinputoctets'] ?? 0);
        $bytes_out = (float)($rad_active['acctoutputoctets'] ?? 0);
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
        echo json_encode(['bytes_in' => 0, 'bytes_out' => 0, 'status' => 'offline', 'error' => $e->getMessage()]);
    }
}
?>