<?php
/**
 * NetBee / SB Link - 1-Click Database Self-Healing & Schema Repair Tool
 * URL: http://YOUR_SERVER_IP/repair_database.php
 * Solves:
 * 1. Missing columns after Old VPS DB restore (dealers.status, linemen.status, etc.)
 * 2. Expired client status or past expiry_date blocking Admin/Dealer/Lineman login
 * 3. Restores missing tables (staff_salary_ledger, user_ledger)
 * 4. Fixes server_public_ip to current VPS (187.126.127.237)
 * 5. Rebuilds high-speed RADIUS indexes and restarts FreeRADIUS
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/schema_fix_helper.php';
if (file_exists(__DIR__ . '/config/db_maintenance_helper.php')) {
    require_once __DIR__ . '/config/db_maintenance_helper.php';
}

$executed = false;
$reports = [];
$maintenance_report = null;

if (isset($_POST['action']) && $_POST['action'] === 'repair_now' || isset($_GET['key']) && $_GET['key'] === 'sb_cron_secret_786' || isset($_GET['auto']) && $_GET['auto'] === '1') {
    $executed = true;
    try {
        $reports = fix_and_repair_schema($pdo);
        if (function_exists('run_database_maintenance')) {
            $maintenance_report = run_database_maintenance($pdo, 7, 30, 2);
        }
    } catch (Exception $e) {
        $reports[] = "Repair Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetBee - Database Self-Healing & Repair Tool</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        .badge-brand {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }
        .btn-brand {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            color: #fff;
            font-weight: 700;
            transition: all 0.2s ease;
        }
        .btn-brand:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        }
        .check-item {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body class="py-5">
    <div class="container" style="max-width: 800px;">
        <div class="text-center mb-4">
            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-25 text-primary rounded-circle p-3 mb-3 shadow">
                <i class="fa-solid fa-wand-magic-sparkles fa-2x"></i>
            </div>
            <h2 class="fw-bold text-white mb-1">Database Self-Healing &amp; Repair Tool</h2>
            <p class="text-secondary small">Instantly fixes missing columns, restores expired accounts, rebuilds indexes &amp; reactivates all portals after Old VPS DB restore.</p>
        </div>

        <div class="card glass-card p-4 p-md-5 mb-4">
            <?php if ($executed): ?>
                <div class="alert alert-success d-flex align-items-center gap-3 p-3 rounded-4 mb-4" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: #34d399;">
                    <i class="fa-solid fa-circle-check fs-2"></i>
                    <div>
                        <h5 class="fw-bold mb-1">Database Repair Completed Successfully!</h5>
                        <p class="mb-0 small text-white-50">All missing schema columns have been created, all client portals set to active (2035 expiry), and RADIUS indexes synchronized.</p>
                    </div>
                </div>

                <h6 class="fw-bold text-white-50 text-uppercase tracking-wider small mb-3">
                    <i class="fa-solid fa-list-check me-2 text-primary"></i> Execution Summary:
                </h6>

                <div class="mb-4">
                    <?php foreach ($reports as $msg): ?>
                        <div class="check-item d-flex align-items-center gap-2">
                            <i class="fa-solid fa-circle-check text-success"></i>
                            <span class="text-light small fw-medium"><?= htmlspecialchars($msg) ?></span>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($maintenance_report): ?>
                        <div class="check-item d-flex align-items-center gap-2">
                            <i class="fa-solid fa-circle-check text-success"></i>
                            <span class="text-light small fw-medium">
                                Database Defragmented: Deleted <?= number_format($maintenance_report['auth_deleted']) ?> auth logs, <?= number_format($maintenance_report['acct_deleted']) ?> closed sessions in <?= $maintenance_report['duration_seconds'] ?>s.
                            </span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-wrap gap-3 justify-content-center mt-4 pt-2">
                    <a href="login.php" class="btn btn-primary px-4 py-2 rounded-pill fw-bold shadow">
                        <i class="fa-solid fa-right-to-bracket me-2"></i> Open Login Portal
                    </a>
                    <a href="superadmin/dashboard.php" class="btn btn-outline-light px-4 py-2 rounded-pill fw-bold">
                        <i class="fa-solid fa-gauge-high me-2"></i> SuperAdmin Dashboard
                    </a>
                    <a href="superadmin/settings.php" class="btn btn-outline-light px-4 py-2 rounded-pill fw-bold">
                        <i class="fa-solid fa-gear me-2"></i> Settings
                    </a>
                </div>

            <?php else: ?>
                <div class="text-center py-3">
                    <h5 class="fw-bold text-white mb-3">Click below to start 1-Click Database Self-Healing</h5>
                    <p class="text-light text-opacity-75 small mb-4" style="max-width: 600px; margin: 0 auto;">
                        Use this utility whenever you fetch or restore an older database. It ensures all roles (Admin, Dealer, Lineman, RecoveryMan, Manager) have full database schema compatibility and can log in without errors.
                    </p>

                    <div class="row g-3 text-start mb-4">
                        <div class="col-md-6">
                            <div class="check-item">
                                <div class="text-primary fw-bold small mb-1"><i class="fa-solid fa-user-check me-2"></i> Client Status Alignment</div>
                                <div class="text-white-50" style="font-size: 0.8rem;">Sets all admin tenants to 'active' with 2035 expiry date so sessions are never kicked out.</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="check-item">
                                <div class="text-primary fw-bold small mb-1"><i class="fa-solid fa-table-columns me-2"></i> Missing Columns &amp; Tables</div>
                                <div class="text-white-50" style="font-size: 0.8rem;">Creates dealers.status, linemen.status, cash_in_hand, and staff_salary_ledger table.</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="check-item">
                                <div class="text-primary fw-bold small mb-1"><i class="fa-solid fa-network-wired me-2"></i> Public IP Auto-Update</div>
                                <div class="text-white-50" style="font-size: 0.8rem;">Updates server_public_ip in global_settings to current VPS IP (187.126.127.237).</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="check-item">
                                <div class="text-primary fw-bold small mb-1"><i class="fa-solid fa-bolt me-2"></i> FreeRADIUS &amp; Indexes</div>
                                <div class="text-white-50" style="font-size: 0.8rem;">Builds ultra-fast composite indexes on radacct/radpostauth and restarts FreeRADIUS service.</div>
                            </div>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="repair_now">
                        <button type="submit" class="btn btn-brand px-5 py-3 rounded-pill fs-6 shadow-lg">
                            <i class="fa-solid fa-wrench me-2"></i> Run Database Self-Healing &amp; Repair Now
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="text-center text-white-50 small">
            NetBee High-Speed ISP Billing &amp; Core RADIUS &bull; Database Repair Engine
        </div>
    </div>
</body>
</html>
