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
        .step-badge { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.85rem; }
        pre { background: #0f172a; color: #38bdf8; padding: 15px; border-radius: 8px; font-size: 0.82rem; }
    </style>
</head>
<body>
<div class="container" style="max-width: 900px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-1"><i class="fa-solid fa-stethoscope text-primary me-2"></i>MikroTik API Diagnostic Tool</h3>
            <p class="text-muted mb-0">Testing live bandwidth communication between Linux VPS and MikroTik Router</p>
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
                <th class="bg-light">Username:</th>
                <td><strong class="text-primary font-monospace"><?= htmlspecialchars($username) ?></strong></td>
            </tr>
            <tr>
                <th class="bg-light">Operator Client ID:</th>
                <td><?= $sub_client_id ?></td>
            </tr>
        </table>

        <?php
        // Query FreeRADIUS radacct
        $radStmt = $pdo->prepare("SELECT radacctid, nasipaddress, acctsessiontime, acctinputoctets, acctoutputoctets, callingstationid, framedipaddress 
            FROM radacct 
            WHERE username = ? AND acctstoptime IS NULL 
            ORDER BY radacctid DESC LIMIT 1");
        $radStmt->execute([$username]);
        $rad = $radStmt->fetch(PDO::FETCH_ASSOC);

        if ($rad):
            $inMb = round($rad['acctinputoctets'] / 1048576, 2);
            $outMb = round($rad['acctoutputoctets'] / 1048576, 2);
            $secs = (int)$rad['acctsessiontime'];
            $uptime = floor($secs/3600) . 'h ' . floor(($secs%3600)/60) . 'm';
        ?>
            <div class="alert alert-success d-flex align-items-center gap-2 mb-0">
                <i class="fa-solid fa-circle-check fs-4 text-success"></i>
                <div>
                    <strong>Active FreeRADIUS Session Found!</strong><br>
                    Connected Router (NAS IP): <strong class="font-monospace text-primary"><?= $rad['nasipaddress'] ?></strong> | 
                    Framed IP: <span class="font-monospace"><?= $rad['framedipaddress'] ?></span> | 
                    Session Uptime: <?= $uptime ?> | 
                    Data Consumed: Upload <?= $inMb ?> MB, Download <?= $outMb ?> MB
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-0">
                <i class="fa-solid fa-triangle-exclamation text-warning me-1"></i>
                <strong>No active FreeRADIUS session found</strong> in <code>radacct</code> for username <code><?= htmlspecialchars($username) ?></code>. Subscriber might be offline in RADIUS.
            </div>
        <?php endif; ?>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="fw-bold border-bottom pb-2 mb-3">2. Configured NAS Routers in NetBee Database</h5>
        <?php
        $nasStmt = $pdo->prepare("SELECT id, client_id, nasname, shortname, api_port, api_user, api_password FROM nas WHERE (client_id = ? OR client_id = 0 OR client_id IS NULL)");
        $nasStmt->execute([$sub_client_id]);
        $routers = $nasStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($routers)):
        ?>
            <div class="alert alert-danger mb-0">
                <i class="fa-solid fa-circle-xmark me-1"></i>
                <strong>No routers found in NAS table!</strong> Go to <strong>Admin > Profile > Add Router</strong> and configure your MikroTik router IP, API user, and password.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered font-monospace" style="font-size: 0.85rem;">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Router Name</th>
                            <th>NAS IP (nasname)</th>
                            <th>API Port</th>
                            <th>API Username</th>
                            <th>Password Set?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($routers as $r): ?>
                        <tr>
                            <td><?= $r['id'] ?></td>
                            <td><?= htmlspecialchars($r['shortname'] ?: 'Router') ?></td>
                            <td><strong><?= htmlspecialchars($r['nasname']) ?></strong></td>
                            <td><?= $r['api_port'] ?: 8728 ?></td>
                            <td><?= htmlspecialchars($r['api_user'] ?: 'admin') ?></td>
                            <td><?= !empty($r['api_password']) ? '<span class="text-success fw-bold">YES</span>' : '<span class="text-danger fw-bold">NO (Empty)</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card p-4 mb-4">
        <h5 class="fw-bold border-bottom pb-2 mb-3">3. Live Connection &amp; Bandwidth Diagnostics</h5>
        <?php
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

        if (empty($test_targets)) {
            echo '<div class="alert alert-danger">No valid target router IP available to test.</div>';
        }

        foreach ($test_targets as $idx => $t):
            echo "<h6 class='fw-bold text-dark mt-3'><span class='badge bg-primary me-2'>" . ($idx + 1) . "</span> Testing Router: {$t['name']}</h6>";
            echo "<div class='p-3 bg-light rounded-3 mb-3 border font-monospace' style='font-size: 0.85rem;'>";
            
            // Step A: TCP Socket Check
            echo "<div>[1/4] Checking TCP connection to <strong>{$t['ip']}:{$t['port']}</strong>... ";
            flush();
            $errno = 0;
            $errstr = '';
            $sockStart = microtime(true);
            $sock = @fsockopen($t['ip'], $t['port'], $errno, $errstr, 2.0);
            $sockDuration = round((microtime(true) - $sockStart) * 1000, 1);

            if (!$sock) {
                echo "<span class='text-danger fw-bold'>FAILED</span> ({$sockDuration}ms)</div>";
                echo "<div class='text-danger ps-3 mt-1'>&bull; Error {$errno}: " . htmlspecialchars($errstr ?: 'Connection timed out') . "</div>";
                echo "<div class='alert alert-danger mt-2 py-2 px-3 small font-sans-serif'>";
                echo "<strong>Why did this fail?</strong><br>";
                echo "1. The VPS cannot reach IP <code>{$t['ip']}</code> on port <code>{$t['port']}</code>.<br>";
                echo "2. If <code>{$t['ip']}</code> is a local private IP (e.g. 192.168.x.x or 10.x.x.x), change it in <strong>Admin > Profile > Routers</strong> to the MikroTik's public IP.<br>";
                echo "3. If <code>{$t['ip']}</code> is a public IP, ensure MikroTik's Firewall Filter (<code>/ip firewall filter</code>) has an accept rule for TCP port 8728:<br>";
                echo "<code>/ip firewall filter add chain=input protocol=tcp dst-port=8728 action=accept place-before=1</code>";
                echo "</div>";
                echo "</div>";
                continue;
            }

            echo "<span class='text-success fw-bold'>SUCCESS</span> ({$sockDuration}ms)</div>";
            fclose($sock);

            // Step B: MikroTik API Login
            echo "<div class='mt-1'>[2/4] Authenticating with MikroTik API (User: '{$t['user']}')... ";
            flush();
            $api = new RouterosAPI();
            $api->timeout = 3;
            $api->attempts = 1;

            if (!$api->connect($t['ip'], $t['user'], $t['pass'], $t['port'])) {
                echo "<span class='text-danger fw-bold'>LOGIN FAILED</span></div>";
                echo "<div class='alert alert-warning mt-2 py-2 px-3 small font-sans-serif'>";
                echo "<strong>API connection opened, but login was rejected!</strong><br>";
                echo "Verify that user <code>" . htmlspecialchars($t['user']) . "</code> exists in MikroTik under <strong>System > Users</strong> with full API permissions, and that the password matches the one in <strong>Admin > Profile > Routers</strong>.";
                echo "</div>";
                echo "</div>";
                continue;
            }

            echo "<span class='text-success fw-bold'>LOGGED IN SUCCESSFULLY</span></div>";

            // Step C: Look for Subscriber in PPPoE & Hotspot
            echo "<div class='mt-1'>[3/4] Searching for subscriber '{$username}' in router active sessions... ";
            flush();
            
            $foundName = null;
            $activePpp = $api->comm('/ppp/active/print', ['?name' => $username]);
            if (empty($activePpp)) {
                $activePpp = $api->comm('/ppp/active/print', ['?name' => strtolower($username)]);
            }
            if (!empty($activePpp) && isset($activePpp[0]['name'])) {
                $foundName = $activePpp[0]['name'];
                echo "<span class='text-success fw-bold'>FOUND in PPPoE Active</span> (Uptime: " . ($activePpp[0]['uptime'] ?? 'N/A') . ")</div>";
            } else {
                echo "<span class='text-warning fw-bold'>NOT in PPPoE Active directly</span> (Checking full list)... ";
                $all_ppp = $api->comm('/ppp/active/print');
                if (is_array($all_ppp)) {
                    foreach ($all_ppp as $conn) {
                        if (stripos($conn['name'] ?? '', $username) !== false) {
                            $foundName = $conn['name'];
                            break;
                        }
                    }
                }
                if ($foundName) {
                    echo "<span class='text-success fw-bold'>FOUND as '{$foundName}'</span></div>";
                } else {
                    echo "<span class='text-muted'>Not found in PPPoE</span></div>";
                }
            }

            // Step D: Speed & Rate check
            echo "<div class='mt-1'>[4/4] Fetching Real-Time Bandwidth &amp; Simple Queue Rates... </div>";
            $u_search = $foundName ?: $username;

            // Check Simple Queue
            $qres = $api->comm('/queue/simple/print', ['?name' => '<pppoe-' . $u_search . '>']);
            if (empty($qres)) {
                $qres = $api->comm('/queue/simple/print', ['?name' => $u_search]);
            }
            if (!empty($qres) && is_array($qres) && isset($qres[0])) {
                $q = $qres[0];
                $rparts = explode('/', (string)($q['rate'] ?? '0/0'));
                $rxMbps = round(((float)($rparts[0] ?? 0)) / 1048576, 2);
                $txMbps = round(((float)($rparts[1] ?? 0)) / 1048576, 2);
                echo "<div class='alert alert-success mt-2 py-2 px-3 small font-sans-serif'>";
                echo "<strong>Live Simple Queue Found: <code>" . htmlspecialchars($q['name'] ?? '') . "</code></strong><br>";
                echo "Current Live Upload: <strong class='text-purple'>{$rxMbps} Mbps</strong> (" . ($rparts[0] ?? 0) . " bps)<br>";
                echo "Current Live Download: <strong class='text-info'>{$txMbps} Mbps</strong> (" . ($rparts[1] ?? 0) . " bps)<br>";
                echo "Total Bytes: <code>" . ($q['bytes'] ?? '0/0') . "</code>";
                echo "</div>";
            } else {
                echo "<div class='text-muted ps-3'>&bull; Simple Queue for &lt;pppoe-{$u_search}&gt; not returned by query.</div>";
            }

            // Check monitor-traffic
            $cand_if = '<pppoe-' . $u_search . '>';
            $traffic = $api->comm('/interface/monitor-traffic', [
                'interface' => $cand_if,
                'once'      => ''
            ]);
            if (!empty($traffic) && is_array($traffic) && isset($traffic[0]['rx-bits-per-second'])) {
                $rxM = round(((float)$traffic[0]['rx-bits-per-second']) / 1048576, 2);
                $txM = round(((float)$traffic[0]['tx-bits-per-second']) / 1048576, 2);
                echo "<div class='alert alert-success mt-2 py-2 px-3 small font-sans-serif'>";
                echo "<strong>Live Interface Monitor Succeeded on <code>{$cand_if}</code></strong><br>";
                echo "Upload: <strong>{$rxM} Mbps</strong> | Download: <strong>{$txM} Mbps</strong>";
                echo "</div>";
            } else {
                echo "<div class='text-muted ps-3'>&bull; /interface/monitor-traffic on {$cand_if} returned no data.</div>";
            }

            $api->disconnect();
            echo "</div>";
        endforeach;
        ?>
    </div>
</div>
</body>
</html>
