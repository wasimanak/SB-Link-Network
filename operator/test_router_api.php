<?php
session_start();
require_once '../config/db.php';
require_once '../config/routeros_api.class.php';

header('Content-Type: text/html; charset=utf-8');

$client_id = $_SESSION['operator_id'] ?? $_SESSION['client_id'] ?? 1;
$sub_id = (int)($_GET['id'] ?? $_GET['sub_id'] ?? 3308);

$uStmt = $pdo->prepare("SELECT id, username, full_name, client_id, status FROM subscribers WHERE id = ?");
$uStmt->execute([$sub_id]);
$sub = $uStmt->fetch(PDO::FETCH_ASSOC);

if (!$sub) {
    die("<h3>Subscriber with ID $sub_id not found in database.</h3>");
}

$username = $sub['username'];
$sub_client_id = $sub['client_id'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>MikroTik Live API Diagnostic Test - <?= htmlspecialchars($username) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f8fafc; font-family: system-ui, -apple-system, sans-serif; padding: 30px; }
        .card { border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: none; }
        pre { background: #0f172a; color: #38bdf8; padding: 12px; border-radius: 8px; font-size: 0.8rem; max-height: 250px; overflow-y: auto; }
    </style>
</head>
<body>
<div class="container" style="max-width: 950px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="fa-solid fa-stethoscope text-primary me-2"></i>MikroTik API Diagnostic Tool</h3>
            <p class="text-muted mb-0">Deep inspection of active sessions, queues, and interface counters</p>
        </div>
        <a href="subscriber_view.php?id=<?= $sub_id ?>" class="btn btn-outline-secondary rounded-pill px-3">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Subscriber
        </a>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="fw-bold border-bottom pb-2 mb-3">1. Subscriber &amp; FreeRADIUS Session</h5>
        <table class="table table-bordered table-sm mb-3">
            <tr>
                <th class="w-25 bg-light">Subscriber ID:</th>
                <td><?= $sub['id'] ?> (<?= htmlspecialchars($sub['full_name']) ?>)</td>
            </tr>
            <tr>
                <th class="bg-light">Database Username:</th>
                <td><strong class="text-primary font-monospace"><?= htmlspecialchars($username) ?></strong></td>
            </tr>
            <tr>
                <th class="bg-light">Operator Client ID:</th>
                <td><?= $sub_client_id ?></td>
            </tr>
        </table>

        <?php
        $radStmt = $pdo->prepare("SELECT radacctid, nasipaddress, acctsessiontime, acctinputoctets, acctoutputoctets, callingstationid, framedipaddress, framedprotocol, nasporttype 
            FROM radacct 
            WHERE username = ? AND acctstoptime IS NULL 
            ORDER BY radacctid DESC LIMIT 1");
        $radStmt->execute([$username]);
        $rad = $radStmt->fetch(PDO::FETCH_ASSOC);

        $framedIp = !empty($rad['framedipaddress']) ? trim($rad['framedipaddress']) : null;

        if ($rad):
            $inMb = round($rad['acctinputoctets'] / 1048576, 2);
            $outMb = round($rad['acctoutputoctets'] / 1048576, 2);
            $secs = (int)$rad['acctsessiontime'];
            $uptime = floor($secs/3600) . 'h ' . floor(($secs%3600)/60) . 'm';
        ?>
            <div class="alert alert-success d-flex align-items-center gap-2 mb-0">
                <i class="fa-solid fa-circle-check fs-4 text-success flex-shrink-0"></i>
                <div>
                    <strong>Active FreeRADIUS Session Found!</strong><br>
                    Connected Router (NAS IP): <strong class="font-monospace text-primary"><?= $rad['nasipaddress'] ?></strong> | 
                    Framed IP: <strong class="font-monospace text-danger"><?= $framedIp ?: 'None' ?></strong> | 
                    MAC: <code><?= htmlspecialchars($rad['callingstationid'] ?: 'N/A') ?></code><br>
                    Session Uptime: <?= $uptime ?> | 
                    Data Consumed: Upload <?= $inMb ?> MB, Download <?= $outMb ?> MB
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-0">
                <i class="fa-solid fa-triangle-exclamation text-warning me-1"></i>
                <strong>No active FreeRADIUS session found</strong> in <code>radacct</code> for username <code><?= htmlspecialchars($username) ?></code>.
            </div>
        <?php endif; ?>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="fw-bold border-bottom pb-2 mb-3">2. Deep Router Inspection</h5>
        <?php
        $nasStmt = $pdo->prepare("SELECT id, client_id, nasname, shortname, api_port, api_user, api_password FROM nas WHERE (client_id = ? OR client_id = 0 OR client_id IS NULL)");
        $nasStmt->execute([$sub_client_id]);
        $routers = $nasStmt->fetchAll(PDO::FETCH_ASSOC);

        $test_targets = [];
        if (!empty($rad['nasipaddress'])) {
            $defUser = !empty($routers[0]['api_user']) ? $routers[0]['api_user'] : 'admin';
            $defPass = !empty($routers[0]['api_password']) ? $routers[0]['api_password'] : '';
            $defPort = !empty($routers[0]['api_port']) ? (int)$routers[0]['api_port'] : 8728;
            $test_targets[] = [
                'name' => 'Active RADIUS Router IP (' . $rad['nasipaddress'] . ')',
                'ip' => $rad['nasipaddress'],
                'port' => $defPort,
                'user' => $defUser,
                'pass' => $defPass
            ];
        }
        foreach ($routers as $r) {
            $already = false;
            foreach ($test_targets as $tt) {
                if ($tt['ip'] === $r['nasname']) { $already = true; break; }
            }
            if (!$already && !empty($r['nasname']) && strpos($r['nasname'], '/') === false && $r['nasname'] !== '0.0.0.0') {
                $test_targets[] = [
                    'name' => htmlspecialchars($r['shortname'] ?: 'Configured Router') . ' (' . $r['nasname'] . ')',
                    'ip' => trim($r['nasname']),
                    'port' => !empty($r['api_port']) ? (int)$r['api_port'] : 8728,
                    'user' => !empty($r['api_user']) ? trim($r['api_user']) : 'admin',
                    'pass' => (string)($r['api_password'] ?? '')
                ];
            }
        }

        foreach ($test_targets as $idx => $t):
            echo "<h6 class='fw-bold text-dark mt-3'><span class='badge bg-primary me-2'>" . ($idx + 1) . "</span> Router: {$t['name']}</h6>";
            echo "<div class='p-3 bg-light rounded-3 mb-3 border font-monospace' style='font-size: 0.85rem;'>";

            $api = new RouterosAPI();
            $api->timeout = 3;
            $api->attempts = 1;

            if (!$api->connect($t['ip'], $t['user'], $t['pass'], $t['port'])) {
                echo "<div class='text-danger fw-bold'>Could not connect to {$t['ip']}:{$t['port']}</div></div>";
                continue;
            }

            $ident = $api->comm('/system/identity/print');
            $ident_name = $ident[0]['name'] ?? 'MikroTik';
            $res = $api->comm('/system/resource/print');
            $ros_ver = $res[0]['version'] ?? 'Unknown';
            $cpu_load = ($res[0]['cpu-load'] ?? 0) . '%';
            $board = $res[0]['board-name'] ?? ($res[0]['platform'] ?? '');

            echo "<div class='text-success fw-bold mb-1'><i class='fa-solid fa-check-circle me-1'></i> Connected &amp; Authenticated Successfully!</div>";
            echo "<div class='text-muted small mb-3'>Identity: <strong>{$ident_name}</strong> | Model: <strong>{$board}</strong> | RouterOS: <strong>{$ros_ver}</strong> | CPU: <strong>{$cpu_load}</strong></div>";

            // A. Check PPPoE Active Sessions
            echo "<div class='fw-bold border-bottom pb-1 mt-2 text-dark'>A. Checking /ppp/active</div>";
            $all_ppp = $api->comm('/ppp/active/print');
            $ppp_count = is_array($all_ppp) ? count($all_ppp) : 0;
            echo "Total Active PPPoE Sessions: <strong>{$ppp_count}</strong><br>";

            $found_ppp_item = null;
            $match_reason = '';
            $ppp_sample_names = [];
            if (is_array($all_ppp)) {
                foreach ($all_ppp as $idx_p => $conn) {
                    $cname = $conn['name'] ?? '';
                    $caddr = $conn['address'] ?? '';
                    if ($idx_p < 8) {
                        $ppp_sample_names[] = $cname . ($caddr ? " ($caddr)" : "");
                    }
                    if (strcasecmp($cname, $username) === 0) {
                        $found_ppp_item = $conn;
                        $match_reason = "Exact username match ('{$cname}')";
                    } elseif (stripos($cname, $username) !== false) {
                        $found_ppp_item = $conn;
                        $match_reason = "Partial username match ('{$cname}')";
                    } elseif (!empty($framedIp) && $caddr === $framedIp) {
                        $found_ppp_item = $conn;
                        $match_reason = "Matched by Framed IP ('{$caddr}')";
                    }
                }
            }

            if ($found_ppp_item) {
                echo "<div class='text-success fw-bold'>&bull; MATCH FOUND in /ppp/active ({$match_reason})!</div>";
                echo "<pre>" . print_r($found_ppp_item, true) . "</pre>";
            } else {
                echo "<div class='text-muted'>&bull; Not found by username '{$username}' or IP '{$framedIp}'.</div>";
                if (!empty($ppp_sample_names)) {
                    echo "<div class='small text-muted'>Sample active PPPoE names: " . htmlspecialchars(implode(', ', $ppp_sample_names)) . "...</div>";
                }
            }

            // B. Check Hotspot Active Sessions
            echo "<div class='fw-bold border-bottom pb-1 mt-3 text-dark'>B. Checking /ip/hotspot/active</div>";
            $all_hs = $api->comm('/ip/hotspot/active/print');
            $hs_count = is_array($all_hs) ? count($all_hs) : 0;
            echo "Total Active Hotspot Sessions: <strong>{$hs_count}</strong><br>";

            $found_hs_item = null;
            $hs_samples = [];
            if (is_array($all_hs)) {
                foreach ($all_hs as $ihs => $hs) {
                    $huser = $hs['user'] ?? '';
                    $haddr = $hs['address'] ?? '';
                    if ($ihs < 8) $hs_samples[] = $huser . ($haddr ? " ($haddr)" : "");
                    if (strcasecmp($huser, $username) === 0 || stripos($huser, $username) !== false || (!empty($framedIp) && $haddr === $framedIp)) {
                        $found_hs_item = $hs;
                    }
                }
            }

            if ($found_hs_item) {
                echo "<div class='text-success fw-bold'>&bull; MATCH FOUND in /ip/hotspot/active!</div>";
                echo "<pre>" . print_r($found_hs_item, true) . "</pre>";
            } else {
                echo "<div class='text-muted'>&bull; Not in Hotspot active list.</div>";
                if (!empty($hs_samples)) {
                    echo "<div class='small text-muted'>Sample active Hotspot: " . htmlspecialchars(implode(', ', $hs_samples)) . "...</div>";
                }
            }

            // C. Check Simple Queues (by target IP & by name)
            echo "<div class='fw-bold border-bottom pb-1 mt-3 text-dark'>C. Checking /queue/simple</div>";
            $all_queues = $api->comm('/queue/simple/print');
            $q_count = is_array($all_queues) ? count($all_queues) : 0;
            echo "Total Simple Queues: <strong>{$q_count}</strong><br>";

            $found_queue = null;
            $q_reason = '';
            $queue_samples = [];
            if (is_array($all_queues)) {
                foreach ($all_queues as $iq => $q) {
                    $qname = $q['name'] ?? '';
                    $qtarget = $q['target'] ?? '';
                    if ($iq < 8) {
                        $queue_samples[] = $qname . ($qtarget ? " [target: {$qtarget}]" : "");
                    }
                    if (stripos($qname, $username) !== false) {
                        $found_queue = $q;
                        $q_reason = "Matched by queue name '{$qname}'";
                    } elseif (!empty($framedIp) && strpos($qtarget, $framedIp) !== false) {
                        $found_queue = $q;
                        $q_reason = "Matched by target IP '{$qtarget}'";
                    }
                }
            }

            if ($found_queue) {
                echo "<div class='text-success fw-bold'>&bull; MATCH FOUND in /queue/simple ({$q_reason})!</div>";
                echo "<pre>" . print_r($found_queue, true) . "</pre>";
                $rparts = explode('/', (string)($found_queue['rate'] ?? '0/0'));
                $upMbps = round(((float)($rparts[0] ?? 0)) / 1048576, 2);
                $downMbps = round(((float)($rparts[1] ?? 0)) / 1048576, 2);
                echo "<div class='alert alert-success py-1.5 px-3 mb-1 small font-sans-serif'>";
                echo "<strong>Live Simple Queue Rate:</strong> Upload: <strong>{$upMbps} Mbps</strong> (" . ($rparts[0] ?? 0) . " bps) | Download: <strong>{$downMbps} Mbps</strong> (" . ($rparts[1] ?? 0) . " bps)";
                echo "</div>";
            } else {
                echo "<div class='text-muted'>&bull; No queue matching name '{$username}' or target IP '{$framedIp}'.</div>";
                if (!empty($queue_samples)) {
                    echo "<div class='small text-muted'>Sample queues: " . htmlspecialchars(implode(', ', $queue_samples)) . "...</div>";
                }
            }

            // D. Check Interfaces (by name)
            echo "<div class='fw-bold border-bottom pb-1 mt-3 text-dark'>D. Checking /interface for dynamic link</div>";
            $all_ifaces = $api->comm('/interface/print');
            $found_if = null;
            $if_samples = [];
            if (is_array($all_ifaces)) {
                foreach ($all_ifaces as $ii => $if) {
                    $iname = $if['name'] ?? '';
                    if (strpos($iname, 'pppoe') !== false || $ii < 10) {
                        if (count($if_samples) < 8) $if_samples[] = $iname;
                    }
                    if (stripos($iname, $username) !== false) {
                        $found_if = $if;
                    }
                }
            }

            if ($found_if) {
                echo "<div class='text-success fw-bold'>&bull; MATCH FOUND in /interface: {$found_if['name']}</div>";
                $tf = $api->comm('/interface/monitor-traffic', ['interface' => $found_if['name'], 'once' => '']);
                echo "<pre>" . print_r($tf, true) . "</pre>";
                if (!empty($tf[0]['rx-bits-per-second'])) {
                    $rxM = round(((float)$tf[0]['rx-bits-per-second']) / 1048576, 2);
                    $txM = round(((float)$tf[0]['tx-bits-per-second']) / 1048576, 2);
                    echo "<div class='alert alert-success py-1.5 px-3 mb-1 small font-sans-serif'>";
                    echo "<strong>Live Interface Monitor:</strong> Upload: <strong>{$rxM} Mbps</strong> | Download: <strong>{$txM} Mbps</strong>";
                    echo "</div>";
                }
            } else {
                echo "<div class='text-muted'>&bull; No interface matching '{$username}'.</div>";
                if (!empty($if_samples)) {
                    echo "<div class='small text-muted'>Sample interfaces: " . htmlspecialchars(implode(', ', $if_samples)) . "...</div>";
                }
            }

            $api->disconnect();
            echo "</div>";
        endforeach;
        ?>
    </div>
</div>
</body>
</html>
