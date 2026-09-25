<?php
require_once 'header.php';
require_once '../config/routeros_api.class.php';

$client_id = $_SESSION['operator_id'];
$log = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Fetch NAS info for the operator
        $nasStmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
        $nasStmt->execute([$client_id]);
        $nas = $nasStmt->fetch();

        if (!$nas || empty($nas['nasname'])) {
            $log[] = "<span class='text-danger fw-bold'>Error: MikroTik settings not configured. Please go to MikroTik Settings first.</span>";
        } else {
            $log[] = "<span class='text-info'>Attempting connection to {$nas['nasname']} on port {$nas['api_port']}...</span>";
            
            $API = new RouterosAPI();
            // Increase timeout if necessary
            $API->timeout = 5;

            if ($API->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
                $log[] = "<span class='text-success fw-bold'>Connected successfully to MikroTik!</span>";
                $pdo->beginTransaction();

                // 1. Operator Quota Limits
                $clientStmt = $pdo->prepare("SELECT max_subscribers FROM clients WHERE id = ?");
                $clientStmt->execute([$client_id]);
                $max_sub = $clientStmt->fetchColumn();

                $curStmt = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ?");
                $curStmt->execute([$client_id]);
                $current_count = $curStmt->fetchColumn();

                // 2. Fetch and Sync Profiles (Packages)
                $log[] = "Fetching PPP Profiles...";
                $profiles = $API->comm('/ppp/profile/print');
                
                foreach ($profiles as $prof) {
                    $name = $prof['name'] ?? null;
                    $rate_limit = $prof['rate-limit'] ?? 'No Limit';
                    
                    // Skip default profiles
                    if ($name && $name !== 'default' && $name !== 'default-encryption') {
                        $check = $pdo->prepare("SELECT id FROM packages WHERE client_id = ? AND name = ?");
                        $check->execute([$client_id, $name]);
                        if (!$check->fetch()) {
                            // Automatically insert profile as a package with default validity 30 days and price 0
                            $ins = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price) VALUES (?, ?, ?, 30, 0)");
                            $ins->execute([$client_id, $name, $rate_limit]);
                            $log[] = "Imported Package Profile: " . htmlspecialchars($name) . " ($rate_limit)";
                        }
                    }
                }

                // 3. Fetch and Sync Secrets (Subscribers)
                $log[] = "Fetching PPP Secrets (Users)...";
                $secrets = $API->comm('/ppp/secret/print');
                
                foreach ($secrets as $sec) {
                    $name = $sec['name'] ?? null;
                    $password = $sec['password'] ?? '';
                    $service = $sec['service'] ?? 'pppoe';
                    $profile = $sec['profile'] ?? 'default';
                    $is_disabled = (isset($sec['disabled']) && $sec['disabled'] === 'true') ? 'disabled' : 'active';

                    if (!$name) continue;

                    // Check if subscriber limit reached
                    if ($current_count >= $max_sub) {
                        $log[] = "<span class='text-warning'>Quota limit reached ($max_sub). Skipped user: " . htmlspecialchars($name) . "</span>";
                        break; // Stop adding more
                    }

                    // Find corresponding package ID in database
                    $pkgStmt = $pdo->prepare("SELECT id, rate_limit FROM packages WHERE client_id = ? AND name = ?");
                    $pkgStmt->execute([$client_id, $profile]);
                    $pkg = $pkgStmt->fetch();

                    $pkg_id = $pkg ? $pkg['id'] : null;
                    $limit = $pkg ? $pkg['rate_limit'] : '';

                    try {
                        // Check if subscriber already exists
                        $checkSub = $pdo->prepare("SELECT id FROM subscribers WHERE username = ?");
                        $checkSub->execute([$name]);
                        
                        if (!$checkSub->fetch()) {
                            $insSub = $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, service_type, status) VALUES (?, ?, ?, ?, ?, ?)");
                            $insSub->execute([$client_id, $pkg_id, $name, $password, $service, $is_disabled]);
                            
                            // Sync with FreeRADIUS radcheck
                            $radC = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
                            $radC->execute([$name, $password]);

                            // If disabled, also add Reject rule to radcheck
                            if ($is_disabled === 'disabled') {
                                $radDis = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')");
                                $radDis->execute([$name]);
                            }

                            // Sync with FreeRADIUS radreply (Rate Limit)
                            if ($limit && $limit !== 'No Limit') {
                                $radR = $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)");
                                $radR->execute([$name, $limit]);
                            }

                            $log[] = "Imported User: " . htmlspecialchars($name) . " (Status: $is_disabled)";
                            $current_count++;
                        }
                    } catch (Exception $e) {
                        $log[] = "<span class='text-danger'>Error importing {$name}: " . htmlspecialchars($e->getMessage()) . "</span>";
                    }
                }

                // --- 4. Fetch and Sync Hotspot Profiles ---
                $log[] = "Fetching Hotspot Profiles...";
                $hs_profiles = $API->comm('/ip/hotspot/user/profile/print');
                
                foreach ($hs_profiles as $prof) {
                    $name = $prof['name'] ?? null;
                    $rate_limit = $prof['rate-limit'] ?? 'No Limit';
                    
                    if ($name && $name !== 'default') {
                        $check = $pdo->prepare("SELECT id FROM packages WHERE client_id = ? AND name = ?");
                        $check->execute([$client_id, $name]);
                        if (!$check->fetch()) {
                            $ins = $pdo->prepare("INSERT INTO packages (client_id, name, rate_limit, validity_days, price) VALUES (?, ?, ?, 30, 0)");
                            $ins->execute([$client_id, $name, $rate_limit]);
                            $log[] = "Imported Hotspot Profile: " . htmlspecialchars($name) . " ($rate_limit)";
                        }
                    }
                }

                // --- 5. Fetch and Sync Hotspot Users ---
                $log[] = "Fetching Hotspot Users...";
                $hs_users = $API->comm('/ip/hotspot/user/print');

                foreach ($hs_users as $sec) {
                    $name = $sec['name'] ?? null;
                    $password = $sec['password'] ?? '';
                    $service = 'hotspot';
                    $profile = $sec['profile'] ?? 'default';
                    $is_disabled = (isset($sec['disabled']) && $sec['disabled'] === 'true') ? 'disabled' : 'active';

                    if (!$name) continue;

                    if ($current_count >= $max_sub) {
                        $log[] = "<span class='text-warning'>Quota limit reached ($max_sub). Skipped hotspot user: " . htmlspecialchars($name) . "</span>";
                        break;
                    }

                    $pkgStmt = $pdo->prepare("SELECT id, rate_limit FROM packages WHERE client_id = ? AND name = ?");
                    $pkgStmt->execute([$client_id, $profile]);
                    $pkg = $pkgStmt->fetch();

                    $pkg_id = $pkg ? $pkg['id'] : null;
                    $limit = $pkg ? $pkg['rate_limit'] : '';

                    try {
                        $checkSub = $pdo->prepare("SELECT id FROM subscribers WHERE username = ?");
                        $checkSub->execute([$name]);
                        
                        if (!$checkSub->fetch()) {
                            $insSub = $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, service_type, status) VALUES (?, ?, ?, ?, ?, ?)");
                            $insSub->execute([$client_id, $pkg_id, $name, $password, $service, $is_disabled]);
                            
                            $radC = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
                            $radC->execute([$name, $password]);

                            if ($is_disabled === 'disabled') {
                                $radDis = $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')");
                                $radDis->execute([$name]);
                            }

                            if ($limit && $limit !== 'No Limit') {
                                $radR = $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)");
                                $radR->execute([$name, $limit]);
                            }

                            $log[] = "Imported Hotspot User: " . htmlspecialchars($name) . " (Status: $is_disabled)";
                            $current_count++;
                        }
                    } catch (Exception $e) {
                        $log[] = "<span class='text-danger'>Error importing {$name}: " . htmlspecialchars($e->getMessage()) . "</span>";
                    }
                }

                $pdo->commit();
                $log[] = "<span class='text-success fw-bold'>Sync Completed Successfully. Total Users: $current_count</span>";
                $API->disconnect();
            } else {
                $log[] = "<span class='text-danger fw-bold'>Connection Failed. Check your IP, Port, Username, and Password in MikroTik Settings. Verify API service is enabled on Router (`/ip services`).</span>";
            }
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $log[] = "<span class='text-danger'>System Error: " . htmlspecialchars($e->getMessage()) . "</span>";
    }
}
?>

<div class="mb-3">
    <a href="mikrotik_connect.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>

<div class="card w-75 shadow-sm">
    <div class="card-header"><i class="fa-solid fa-cloud-arrow-down"></i> Live Sync with MikroTik Router</div>
    <div class="card-body">
        <p>This will connect to your live MikroTik router using the API credentials provided in MikroTik Settings.</p>
        <ul class="text-muted">
            <li>It will pull all <strong>PPP Profiles</strong> and create them as Packages in your Portal.</li>
            <li>It will pull all <strong>PPP Secrets</strong> and add them as Subscribers in your Portal and FreeRADIUS database.</li>
            <li>Your Operator quota (`max_subscribers`) will be strictly enforced.</li>
        </ul>
        
        <form method="POST">
            <button type="submit" class="btn btn-info text-dark"><i class="fa-solid fa-plug"></i> Connect & Run Sync Now</button>
        </form>

        <?php if (!empty($log)): ?>
            <div class="mt-4 p-3 bg-white text-dark border border-light rounded" style="max-height: 400px; overflow-y: auto; font-family: monospace;">
                <h6 class="text-white border-bottom border-light pb-2">Sync Log:</h6>
                <?php foreach($log as $msg): ?>
                    <div class="mb-1">> <?= $msg ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
