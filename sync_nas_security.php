<?php
/**
 * NetBee / SB Link - Multi-Tenant RADIUS Router Lock & NAS Isolation Utility
 * URL: http://YOUR_DOMAIN/sync_nas_security.php
 * 
 * Purpose:
 * Enforces that FreeRADIUS ONLY authenticates subscribers from their own Operator's
 * assigned MikroTik router(s). Prevents cross-operator bleeding and rogue router access.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/schema_fix_helper.php';

$message = '';
$synced_stats = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_now') {
    try {
        $synced_stats = sync_all_subscribers_nas($pdo);
        $message = "<div class='alert alert-success d-flex align-items-center gap-2 rounded-4 shadow-sm mb-4'>
            <i class='fa-solid fa-circle-check fs-4'></i>
            <div>
                <strong>Multi-Tenant Router Lock Successful!</strong><br>
                Locked/verified <strong>{$synced_stats['locked']}</strong> subscribers across <strong>{$synced_stats['operators']}</strong> operator networks into FreeRADIUS radcheck with <code>NAS-IP-Address == [Router IP]</code>.
            </div>
        </div>";
    } catch (Exception $e) {
        $message = "<div class='alert alert-danger rounded-4 shadow-sm mb-4'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Ensure schema is updated
add_column_if_missing($pdo, 'subscribers', 'nasname', 'VARCHAR(128) DEFAULT NULL');
add_column_if_missing($pdo, 'subscribers', 'router_id', 'INT DEFAULT NULL');

// Fetch Operators & Routers
$operators = $pdo->query("SELECT id, company_name, phone FROM clients ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Routers
$routersStmt = $pdo->query("SELECT n.*, c.company_name FROM nas n LEFT JOIN clients c ON n.client_id = c.id ORDER BY n.client_id ASC, n.id ASC");
$all_routers = $routersStmt->fetchAll(PDO::FETCH_ASSOC);
$routers_by_client = [];
foreach ($all_routers as $r) {
    $routers_by_client[$r['client_id']][] = $r;
}

// Fetch Subscribers Summary
$subStatsStmt = $pdo->query("
    SELECT client_id, nasname, COUNT(*) as total_users
    FROM subscribers 
    GROUP BY client_id, nasname
");
$sub_stats = $subStatsStmt->fetchAll(PDO::FETCH_ASSOC);

$total_subs = $pdo->query("SELECT COUNT(*) FROM subscribers")->fetchColumn();
$locked_radcheck = $pdo->query("SELECT COUNT(DISTINCT username) FROM radcheck WHERE attribute = 'NAS-IP-Address'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetBee - Multi-Tenant Router Lock &amp; NAS Isolation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #0b1120 0%, #0f172a 50%, #1e293b 100%);
            min-height: 100vh;
            color: #f1f5f9;
        }
        .glass-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1.25rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
        .stat-badge {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 1rem;
            padding: 1.25rem;
        }
    </style>
</head>
<body class="py-5">
<div class="container" style="max-width: 950px;">
    
    <div class="text-center mb-4">
        <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-25 text-primary rounded-circle p-3 mb-3 shadow">
            <i class="fa-solid fa-shield-halved fa-2x"></i>
        </div>
        <h2 class="fw-bold text-white mb-1">Multi-Tenant Router Lock &amp; NAS Isolation</h2>
        <p class="text-secondary small">Har user sirf apnay Operator k MikroTik Router sy connect ho sakta hai. Doosray operator k router sy request direct <strong>Access-Reject</strong> hogi.</p>
    </div>

    <?= $message ?>

    <!-- Summary Stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-badge text-center">
                <div class="text-secondary small mb-1">Total Subscribers</div>
                <div class="h2 fw-bold text-white mb-0"><?= number_format($total_subs) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-badge text-center">
                <div class="text-secondary small mb-1">Locked in FreeRADIUS (radcheck)</div>
                <div class="h2 fw-bold text-success mb-0"><?= number_format($locked_radcheck) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-badge text-center">
                <div class="text-secondary small mb-1">Lock Protection Status</div>
                <div class="h2 fw-bold <?= ($total_subs > 0 && $locked_radcheck >= $total_subs) ? 'text-success' : 'text-warning' ?> mb-0">
                    <?= ($total_subs > 0 && $locked_radcheck >= $total_subs) ? '<i class="fa-solid fa-lock text-success me-1"></i> 100% Locked' : '<i class="fa-solid fa-triangle-exclamation me-1"></i> Sync Needed' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Form -->
    <div class="card glass-card p-4 mb-4 text-center">
        <h5 class="fw-bold text-white mb-2"><i class="fa-solid fa-arrows-rotate text-primary me-2"></i> Synchronize All Subscribers to Operator Routers</h5>
        <p class="text-white-50 small mb-3">Ye action tamam subscribers ko unky apny operator k MikroTik router k sath bind kray ga aur FreeRADIUS <code>radcheck</code> me <code>NAS-IP-Address == [Router IP]</code> check add kray ga.</p>
        <form method="POST">
            <input type="hidden" name="action" value="sync_now">
            <button type="submit" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow">
                <i class="fa-solid fa-lock me-2"></i> Sync &amp; Enforce Router Lock Now
            </button>
        </form>
    </div>

    <!-- Operator & Router Breakdown -->
    <div class="card glass-card p-4">
        <h5 class="fw-bold text-white mb-3"><i class="fa-solid fa-network-wired text-info me-2"></i> Operator &amp; Router Mappings</h5>
        <div class="table-responsive">
            <table class="table table-dark table-borderless align-middle mb-0">
                <thead>
                    <tr class="border-bottom border-secondary text-secondary small">
                        <th>Operator Name</th>
                        <th>MikroTik Routers (NAS)</th>
                        <th>Subscribers Mapped</th>
                        <th>Security Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($operators as $op): 
                        $cid = $op['id'];
                        $routers = $routers_by_client[$cid] ?? [];
                        
                        // Count subs
                        $opSubs = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ?");
                        $opSubs->execute([$cid]);
                        $sub_count = $opSubs->fetchColumn();

                        $opLocked = $pdo->prepare("
                            SELECT COUNT(DISTINCT s.username) 
                            FROM subscribers s 
                            JOIN radcheck rc ON s.username = rc.username 
                            WHERE s.client_id = ? AND rc.attribute = 'NAS-IP-Address'
                        ");
                        $opLocked->execute([$cid]);
                        $locked_count = $opLocked->fetchColumn();
                    ?>
                        <tr class="border-bottom border-secondary border-opacity-25">
                            <td>
                                <strong class="text-white"><?= htmlspecialchars($op['company_name']) ?></strong>
                                <div class="text-secondary small">Client ID: <?= $cid ?></div>
                            </td>
                            <td>
                                <?php if (empty($routers)): ?>
                                    <span class="badge bg-danger">No Routers Configured!</span>
                                <?php else: ?>
                                    <?php foreach ($routers as $r): ?>
                                        <div class="mb-1">
                                            <span class="badge bg-primary bg-opacity-25 text-info border border-info border-opacity-25 font-mono">
                                                <i class="fa-solid fa-server me-1"></i> <?= htmlspecialchars($r['shortname'] ?: 'Router') ?> (<?= htmlspecialchars($r['nasname']) ?>)
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="fw-bold"><?= number_format($sub_count) ?></span> Users
                                <div class="text-secondary small font-mono"><?= number_format($locked_count) ?> locked</div>
                            </td>
                            <td>
                                <?php if ($sub_count == 0): ?>
                                    <span class="badge bg-secondary">No Users</span>
                                <?php elseif ($locked_count >= $sub_count): ?>
                                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-50">
                                        <i class="fa-solid fa-check-double me-1"></i> Fully Protected
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-50">
                                        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= ($sub_count - $locked_count) ?> Unlocked
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="text-center mt-4">
        <a href="/superadmin/routers" class="text-secondary text-decoration-none small me-3"><i class="fa-solid fa-arrow-left me-1"></i> Back to Routers List</a>
        <a href="/repair_database" class="text-secondary text-decoration-none small"><i class="fa-solid fa-wrench me-1"></i> Database Repair Tool</a>
    </div>

</div>
</body>
</html>
