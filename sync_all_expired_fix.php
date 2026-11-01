<?php
// sync_all_expired_fix.php
// Centralized 1-Click Expired Users Speed Limiter & FreeRADIUS Lock
require_once __DIR__ . '/config/db.php';

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
                ?>
                <div class="alert alert-success border-0 rounded-4 p-3 mb-4 d-flex align-items-center gap-3">
                    <i class="fa-solid fa-circle-check fs-2 text-success"></i>
                    <div>
                        <h5 class="fw-bold mb-1 text-white">Zabardast! Deep Fix Complete!</h5>
                        <div class="small text-white-50">
                            Total <strong><?= $processed_count ?></strong> expired users ko <code>radusergroup</code> (Package Group 4M/5M) se nikal diya gaya hai aur FreeRADIUS me <code>radreply := 10k/10k</code> par pakka lock kar diya gaya hai!
                        </div>
                    </div>
                </div>

                <div class="card card-custom p-4 mb-4 bg-dark border-danger border-2">
                    <h5 class="text-danger fw-bold mb-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> AB SIRF YE 1 STEP KAREIN (ZAROORI):</h5>
                    <p class="text-white mb-3">Database bilkul theek ho gaya hai! Lekin router jab tak purani session drop na kare, wo purani 4M speed chalata rehta hai. Is liye Chandni Chok (Sajid Rodi) router k <strong>New Terminal</strong> me foran ye command paste kar k <strong>Enter</strong> karein:</p>
                    <code class="d-block p-3 bg-black text-warning rounded font-monospace fs-5 border border-warning">/ppp active remove [find]</code>
                    <small class="text-white-50 mt-2 d-block">Ye chalate hi <code>adil219ml</code> samet tamam users 2 second me reconnect honge aur unki Queues me <strong>100% 10k speed</strong> set ho jayegi!</small>
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
