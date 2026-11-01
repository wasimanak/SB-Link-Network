<?php
// sync_all_expired_fix.php
// Centralized 1-Click Expired Users Speed Limiter & FreeRADIUS Lock
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/routeros_api.class.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expired Users 10k Speed Lock Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #0f172a; color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; }
        .card-custom { background: #1e293b; border: 1px solid #334155; border-radius: 16px; }
        .table-dark-custom { background: #1e293b; color: #e2e8f0; }
        .badge-10k { background: #ef4444; color: #fff; font-weight: 700; font-size: 0.85rem; padding: 6px 12px; border-radius: 8px; }
    </style>
</head>
<body class="p-4">
<div class="container" style="max-width: 900px;">
    <div class="card card-custom shadow-lg p-4 mb-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="bg-danger text-white rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 54px; height: 54px;">
                <i class="fa-solid fa-gauge-high fs-3"></i>
            </div>
            <div>
                <h3 class="fw-bold mb-0 text-white">Expired Users 10k Speed Lock Tool (Deep Fix)</h3>
                <p class="text-secondary mb-0 small">Automatic radusergroup purge + radreply := 10k/10k lock</p>
            </div>
        </div>

        <?php
        try {
            // Step 1: Find all expired users across all Admins
            $sqlFind = "
                SELECT s.id, s.username, s.client_id, s.full_name, s.expiry_date, s.status, c.company_name
                FROM subscribers s
                LEFT JOIN clients c ON s.client_id = c.id
                WHERE (s.expiry_date IS NOT NULL AND s.expiry_date <= NOW())
                   OR s.status = 'expired'
                ORDER BY s.client_id ASC, s.expiry_date DESC
            ";
            $expired_users = $pdo->query($sqlFind)->fetchAll(PDO::FETCH_ASSOC);
            $total_found = count($expired_users);

            if ($total_found === 0) {
                echo "<div class='alert alert-warning border-0 rounded-4'>No expired users found in the database.</div>";
            } else {
                $pdo->beginTransaction();

                // Step 2: Delete old rate-limits from radreply
                $delStmt = $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Rate-Limit', 'Mikrotik-Address-List', 'Mikrotik-Group')");

                // Step 3: Insert 10k/10k with := operator
                $insRateStmt = $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', ':=', '10k/10k')");
                $insAddrStmt = $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Address-List', '=', 'EXPIRED_USERS')");
                $insGrpStmt  = $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', 'expired')");

                // Step 4: CRITICAL FIX - Remove expired users from radusergroup!
                // If a user stays in radusergroup, radgroupreply (package group) overrides 10k with 4M/5M!
                $delUserGroupStmt = $pdo->prepare("DELETE FROM radusergroup WHERE username = ?");

                // Step 5: Mark expired in subscribers table
                $updSubStmt  = $pdo->prepare("UPDATE subscribers SET status = 'expired' WHERE id = ? AND status != 'disabled'");

                $processed_count = 0;
                foreach ($expired_users as $u) {
                    $un = trim($u['username']);
                    if (empty($un)) continue;

                    $delStmt->execute([$un]);
                    $insRateStmt->execute([$un]);
                    $insAddrStmt->execute([$un]);
                    $insGrpStmt->execute([$un]);
                    $delUserGroupStmt->execute([$un]);
                    $updSubStmt->execute([$u['id']]);

                    $processed_count++;
                }

                // Step 6: Also ensure radgroupreply does NOT use ':=' so it never overrides user reply
                $pdo->exec("UPDATE radgroupreply SET op = '=' WHERE attribute = 'Mikrotik-Rate-Limit' AND op = ':='");

                $pdo->commit();

                // Step 7: Auto-Kick expired active sessions from MikroTik
                $kicked_api_count = 0;
                $nasList = $pdo->query("SELECT DISTINCT nasname, api_user, api_password, api_port FROM nas WHERE api_user IS NOT NULL AND api_user != ''")->fetchAll(PDO::FETCH_ASSOC);
                
                $expiredUserMap = array_flip(array_column($expired_users, 'username'));

                foreach ($nasList as $nas) {
                    $ip = trim($nas['nasname']);
                    $u_api = trim($nas['api_user']);
                    $p_api = trim($nas['api_password']);
                    $port = !empty($nas['api_port']) ? (int)$nas['api_port'] : 8728;

                    if (!empty($ip) && !empty($u_api)) {
                        try {
                            $api = new RouterosAPI();
                            $api->timeout = 2;
                            $api->attempts = 1;
                            if ($api->connect($ip, $u_api, $p_api, $port)) {
                                $active_sessions = $api->comm('/ppp/active/print');
                                if (is_array($active_sessions)) {
                                    foreach ($active_sessions as $sess) {
                                        $sname = trim($sess['name'] ?? '');
                                        if (isset($expiredUserMap[$sname])) {
                                            $api->comm('/ppp/active/remove', ['.id' => $sess['.id']]);
                                            $kicked_api_count++;
                                        }
                                    }
                                }
                                $api->disconnect();
                            }
                        } catch (Exception $e) {}
                    }
                }
                ?>
                <div class="alert alert-success border-0 rounded-4 p-3 mb-4 d-flex align-items-center gap-3">
                    <i class="fa-solid fa-circle-check fs-2 text-success"></i>
                    <div>
                        <h5 class="fw-bold mb-1 text-white">Zabardast! Deep Fix Complete!</h5>
                        <div class="small text-white-50">
                            Total <strong><?= $processed_count ?></strong> expired users ko <code>radusergroup</code> (Package Group) se nikal kar FreeRADIUS me <code>radreply := 10k/10k</code> par pakka lock kar diya gaya hai!
                            <?php if ($kicked_api_count > 0): ?>
                                <br>Aur <strong><?= $kicked_api_count ?></strong> active sessions ko MikroTik se kick kar diya gaya hai.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card card-custom p-3 mb-4 bg-dark border-secondary">
                    <h6 class="text-warning fw-bold mb-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> Last Action (MikroTik New Terminal):</h6>
                    <p class="small text-white-50 mb-2">Ab Chandni Chok (Sajid Rodi) router k <strong>New Terminal</strong> me ye command enter karein:</p>
                    <code class="d-block p-2 bg-black text-success rounded font-monospace fs-6">/ppp active remove [find]</code>
                    <small class="text-white-50 mt-1 d-block">Ab <code>adil219ml</code> samet tamam expired users reconnect hone par <strong>100% 10k speed</strong> uthayenge kyun k unka package group FreeRADIUS se hata diya gaya hai!</small>
                </div>
                <?php
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo "<div class='alert alert-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        ?>
    </div>
</div>
</body>
</html>
