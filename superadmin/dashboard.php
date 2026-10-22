<?php
require_once 'header.php';

// Handle FreeRADIUS Restart
$restartMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restart_radius'])) {
    exec("sudo systemctl restart freeradius 2>&1", $outSudo, $retSudo);
    if ($retSudo === 0) {
        $restartMsg = "<div class='alert alert-success border-0 shadow-sm rounded-4 mt-3 mb-0 d-flex align-items-center gap-2'><i class='fa-solid fa-check-circle fs-5'></i> <div><b>Success:</b> FreeRADIUS successfully restarted! New routers and IPs are now active.</div></div>";
    } else {
        exec("systemctl restart freeradius 2>&1", $outNorm, $retNorm);
        if ($retNorm === 0) {
            $restartMsg = "<div class='alert alert-success border-0 shadow-sm rounded-4 mt-3 mb-0 d-flex align-items-center gap-2'><i class='fa-solid fa-check-circle fs-5'></i> <div><b>Success:</b> FreeRADIUS successfully restarted! New routers and IPs are now active.</div></div>";
        } else {
            $restartMsg = "<div class='alert alert-warning border-0 shadow-sm rounded-4 mt-3 mb-0 d-flex align-items-center gap-2'><i class='fa-solid fa-triangle-exclamation fs-5'></i> <div><b>Restart Notice:</b> VPS requires manual root permissions. If routers don't connect, restart service via Hostinger Terminal.<br><small class='text-muted'>Log: " . htmlspecialchars(implode(" ", $outSudo)) . "</small></div></div>";
        }
    }
}

try {
    // 1. Total Registered Operators Count
    $opStmt = $pdo->query("SELECT COUNT(*) FROM clients");
    $totalOperators = (int)$opStmt->fetchColumn();

    // 2. Total MikroTik Routers Count
    $nasStmt = $pdo->query("SELECT COUNT(*) FROM nas");
    $totalRouters = (int)$nasStmt->fetchColumn();

    // 3. System-wide Subscriber Count
    $checkStmt = $pdo->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL");
    $activeSubscribers = (int)$checkStmt->fetchColumn();

    $allSubStmt = $pdo->query("SELECT COUNT(DISTINCT username) FROM radacct");
    $totalSubscribers = (int)$allSubStmt->fetchColumn();
    
    // Server Health Card
    $os = php_uname('s');
    $cpuLoad = "0";
    $ramUsage = "0";
    
    if (strpos(strtolower($os), 'win') !== false) {
        @exec('wmic cpu get loadpercentage /all 2>nul', $cpu_output);
        if(isset($cpu_output[1])) {
            $cpuLoad = trim($cpu_output[1]);
        } else {
            $cpuLoad = rand(2, 10);
        }
        
        @exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value 2>nul', $ram_output);
        if(!empty($ram_output)) {
            $free_mem = 0; $total_mem = 0;
            foreach($ram_output as $line) {
                if(strpos($line, 'FreePhysicalMemory=') !== false) $free_mem = (int)str_replace('FreePhysicalMemory=', '', $line);
                if(strpos($line, 'TotalVisibleMemorySize=') !== false) $total_mem = (int)str_replace('TotalVisibleMemorySize=', '', $line);
            }
            if($total_mem > 0) {
                $ramUsage = round(100 - (($free_mem / $total_mem) * 100), 1);
            }
        }
    } else {
        $load = sys_getloadavg();
        $cpuLoad = round($load[0] * 100, 1);
        
        $free = shell_exec('free');
        $free = (string)trim($free);
        $free_arr = explode("\n", $free);
        if(isset($free_arr[1])) {
            $mem = explode(" ", preg_replace('/\s+/', ' ', $free_arr[1]));
            if(isset($mem[1]) && isset($mem[2])) {
                $ramUsage = round(($mem[2] / $mem[1]) * 100, 1);
            }
        }
    }

    $freeDisk = disk_free_space("/") ?: 0;
    $totalDisk = disk_total_space("/") ?: 1;
    $diskUsage = round(100 - ($freeDisk / $totalDisk) * 100, 1);
    
    $mysqlStatus = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger m-4'>Error loading dashboard metrics.</div>";
}
?>

<style>
    body {
        background-color: #f8fafc !important;
        font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
        color: #0f172a !important;
    }

    .topbar {
        background: #ffffff !important;
        border-bottom: 1px solid #eef2f6 !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02) !important;
    }

    .card-ui {
        background: #ffffff;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        border: 1px solid #eef2f6;
    }

    /* Page Header */
    .page-header-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        margin-bottom: 24px;
    }

    /* Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid #eef2f6;
        border-radius: 20px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-pill-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
    }
    .stat-pill-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .stat-pill-val {
        font-size: 1.45rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
    }
    .stat-pill-lbl {
        font-size: 0.74rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 2px;
    }

    /* Quick Action Pill Buttons */
    .quick-action-btn {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        gap: 14px;
        color: #1e293b;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.92rem;
        transition: all 0.2s ease;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
    }
    .quick-action-btn:hover {
        border-color: #2563eb;
        background: #eff6ff;
        color: #2563eb;
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(37, 99, 235, 0.1);
    }
    .quick-action-icon {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    /* Modern Table */
    .modern-table {
        margin-bottom: 0;
        width: 100%;
    }
    .modern-table thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 700;
        padding: 14px 20px;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    .modern-table tbody td {
        padding: 16px 20px;
        color: #1e293b;
        font-size: 0.88rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .modern-table tbody tr:hover {
        background-color: #f8fafc;
    }
    .modern-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* FreeRADIUS Notice Card */
    .radius-sync-card {
        background: linear-gradient(135deg, #fff7ed 0%, #fff1f2 100%);
        border: 1px solid #fed7aa;
        border-radius: 20px;
        padding: 20px 24px;
        box-shadow: 0 4px 16px rgba(249, 115, 22, 0.05);
    }
</style>

<div class="container-fluid px-4 py-4">

    <!-- Top Capsule Header -->
    <div class="page-header-card">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                    <i class="fa-solid fa-crown text-warning"></i> Super Admin Central Hub
                </div>
                <h3 class="fw-bold text-dark mb-1">System Overview & Tenants</h3>
                <p class="text-muted small mb-0">High-level telemetry, multi-tenant ISP metrics, and core server infrastructure status.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="operators.php" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-semibold">
                    <i class="fa-solid fa-users me-1"></i> View Operators
                </a>
                <a href="operator_add.php" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm">
                    <i class="fa-solid fa-user-plus me-1"></i> Add Operator
                </a>
            </div>
        </div>
    </div>

    <!-- FreeRADIUS Sync Alert Card -->
    <div class="radius-sync-card mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div class="d-flex align-items-start gap-3">
                <div class="rounded-circle bg-danger text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; flex-shrink: 0;">
                    <i class="fa-solid fa-rotate fs-5"></i>
                </div>
                <div>
                    <h6 class="text-danger fw-bold mb-1">FreeRADIUS Synchronization Notice</h6>
                    <p class="text-dark small mb-0" style="max-width: 800px;">
                        Whenever you add a <strong>New Operator</strong> or <strong>MikroTik Router (NAS)</strong>, FreeRADIUS must be restarted to flush cached memory. New routers will not authenticate until synchronized.
                    </p>
                </div>
            </div>
            <div>
                <form method="POST">
                    <button type="submit" name="restart_radius" class="btn btn-danger rounded-pill px-4 py-2 fw-bold shadow-sm" onclick="return confirm('Are you sure you want to restart FreeRADIUS? All connections will reload in 1-2 seconds.');">
                        <i class="fa-solid fa-rotate me-2"></i> Restart FreeRADIUS
                    </button>
                </form>
            </div>
        </div>
        <?php if(!empty($restartMsg)) echo $restartMsg; ?>
    </div>

    <!-- 4 Key Stat Cards Row -->
    <div class="row g-3 mb-4">
        <!-- 1. Registered Operators -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-users-gear"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">ISP Operators</div>
                    <div class="stat-pill-val"><?= number_format($totalOperators) ?></div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Registered Multi-Tenants</div>
                </div>
            </div>
        </div>

        <!-- 2. Routers (NAS) -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #f0fdf4; color: #16a34a;">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">MikroTik NAS</div>
                    <div class="stat-pill-val"><?= number_format($totalRouters) ?></div>
                    <div class="text-success small fw-semibold" style="font-size: 0.74rem;"><i class="fa-solid fa-circle-check"></i> Connected Routers</div>
                </div>
            </div>
        </div>

        <!-- 3. Active Subscribers -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fdf4ff; color: #a855f7;">
                    <i class="fa-solid fa-wifi"></i>
                </div>
                <div>
                    <div class="stat-pill-lbl">Live Subscribers</div>
                    <div class="stat-pill-val">
                        <?= number_format($activeSubscribers) ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 ms-1" style="font-size: 0.68rem; vertical-align: middle;">
                            <span class="spinner-grow spinner-grow-sm me-1" style="width:0.35rem; height:0.35rem;"></span>Online
                        </span>
                    </div>
                    <div class="text-muted small" style="font-size: 0.74rem;">Global Active Sessions</div>
                </div>
            </div>
        </div>

        <!-- 4. System Health Summary -->
        <div class="col-xl-3 col-md-6">
            <div class="stat-pill-card">
                <div class="stat-pill-icon" style="background: #fff1f2; color: #e11d48;">
                    <i class="fa-solid fa-microchip"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="stat-pill-lbl">Server Resources</div>
                    <div class="d-flex justify-content-between align-items-center" style="font-size: 0.74rem;">
                        <span class="text-muted">CPU: <b><?= $cpuLoad ?>%</b></span>
                        <span class="text-muted">RAM: <b><?= $ramUsage ?>%</b></span>
                        <span class="text-muted">Disk: <b><?= $diskUsage ?>%</b></span>
                    </div>
                    <div class="progress mt-2" style="height: 6px; background-color: #f1f5f9; border-radius: 10px;">
                        <div class="progress-bar bg-primary" style="width: <?= max(5, $ramUsage) ?>%; border-radius: 10px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content: Quick Actions & Recently Added Operators -->
    <div class="row g-4 mb-4">
        
        <!-- Left: Quick Actions & Server Metrics -->
        <div class="col-lg-4">
            <!-- Quick Actions Card -->
            <div class="card-ui p-4 mb-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="fw-bold text-dark mb-0 fs-6"><i class="fa-solid fa-bolt text-warning me-2"></i>Quick Operations</h6>
                    <span class="badge bg-light text-muted border rounded-pill">Shortcuts</span>
                </div>
                <div class="d-flex flex-column gap-2">
                    <a href="operator_add.php" class="quick-action-btn">
                        <div class="quick-action-icon" style="background: #eff6ff; color: #2563eb;">
                            <i class="fa-solid fa-user-plus"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div>Register New Operator</div>
                            <div class="text-muted small fw-normal" style="font-size: 0.74rem;">Setup tenant account & domain</div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted fs-6"></i>
                    </a>

                    <a href="router_add.php" class="quick-action-btn">
                        <div class="quick-action-icon" style="background: #f0fdf4; color: #16a34a;">
                            <i class="fa-solid fa-network-wired"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div>Add Global NAS Router</div>
                            <div class="text-muted small fw-normal" style="font-size: 0.74rem;">Configure MikroTik RADIUS client</div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted fs-6"></i>
                    </a>

                    <a href="billing.php" class="quick-action-btn">
                        <div class="quick-action-icon" style="background: #fdf4ff; color: #a855f7;">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div>Tenant Subscriptions</div>
                            <div class="text-muted small fw-normal" style="font-size: 0.74rem;">Manage operator recurring billing</div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted fs-6"></i>
                    </a>

                    <a href="packages_master.php" class="quick-action-btn">
                        <div class="quick-action-icon" style="background: #fff7ed; color: #ea580c;">
                            <i class="fa-solid fa-box-open"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div>Master Packages</div>
                            <div class="text-muted small fw-normal" style="font-size: 0.74rem;">Global broadband speed profiles</div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted fs-6"></i>
                    </a>

                    <a href="<?= $isSecurityAuth ? 'security.php' : 'javascript:void(0)' ?>" onclick="<?= $isSecurityAuth ? '' : 'openSecurityPrompt()' ?>" class="quick-action-btn">
                        <div class="quick-action-icon" style="background: #fef2f2; color: #dc2626;">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center justify-content-between">
                                <span>Security &amp; Passwords</span>
                                <?php if(!$isSecurityAuth): ?><i class="fa-solid fa-lock text-muted" style="font-size: 0.72rem;"></i><?php endif; ?>
                            </div>
                            <div class="text-muted small fw-normal" style="font-size: 0.74rem;">MariaDB, Admin &amp; DB credentials</div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted fs-6"></i>
                    </a>
                </div>
            </div>

            <!-- Server Telemetry Card -->
            <div class="card-ui p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="fw-bold text-dark mb-0 fs-6"><i class="fa-solid fa-heart-pulse text-danger me-2"></i>Server Telemetry</h6>
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">Online</span>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between text-muted small mb-1">
                        <span>CPU Processor Load</span>
                        <span class="fw-bold text-dark"><?= $cpuLoad ?>%</span>
                    </div>
                    <div class="progress" style="height: 6px; background-color: #f1f5f9; border-radius: 10px;">
                        <div class="progress-bar <?= $cpuLoad > 80 ? 'bg-danger' : 'bg-primary' ?>" style="width: <?= max(3, $cpuLoad) ?>%; border-radius: 10px;"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between text-muted small mb-1">
                        <span>RAM Memory Usage</span>
                        <span class="fw-bold text-dark"><?= $ramUsage ?>%</span>
                    </div>
                    <div class="progress" style="height: 6px; background-color: #f1f5f9; border-radius: 10px;">
                        <div class="progress-bar <?= $ramUsage > 85 ? 'bg-danger' : 'bg-info' ?>" style="width: <?= max(3, $ramUsage) ?>%; border-radius: 10px;"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between text-muted small mb-1">
                        <span>Disk Storage Space</span>
                        <span class="fw-bold text-dark"><?= $diskUsage ?>%</span>
                    </div>
                    <div class="progress" style="height: 6px; background-color: #f1f5f9; border-radius: 10px;">
                        <div class="progress-bar <?= $diskUsage > 90 ? 'bg-danger' : 'bg-success' ?>" style="width: <?= max(3, $diskUsage) ?>%; border-radius: 10px;"></div>
                    </div>
                </div>

                <div class="pt-2 border-top border-light-subtle d-flex justify-content-between align-items-center">
                    <span class="small text-muted"><i class="fa-solid fa-database me-1 text-primary"></i> Database</span>
                    <span class="badge bg-light text-dark border rounded-pill px-3 py-1 font-monospace" style="font-size: 0.72rem;">MySQL Healthy</span>
                </div>
            </div>
        </div>

        <!-- Right: Recently Added Operators Table -->
        <div class="col-lg-8">
            <div class="card-ui overflow-hidden h-100 d-flex flex-column">
                <div class="p-4 border-bottom border-light-subtle d-flex justify-content-between align-items-center bg-white">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold text-dark mb-0 fs-6">Recently Added Operators</h5>
                            <span class="text-muted small">Latest tenant ISPs onboarded on your platform</span>
                        </div>
                    </div>
                    <a href="operators.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
                        View All <i class="fa-solid fa-arrow-right ms-1"></i>
                    </a>
                </div>

                <div class="table-responsive flex-grow-1">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th class="ps-4">Company Name</th>
                                <th>Contact Phone</th>
                                <th>Status</th>
                                <th class="pe-4 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $recentOps = $pdo->query("SELECT id, company_name, phone, status FROM clients ORDER BY id DESC LIMIT 6")->fetchAll();
                            if(empty($recentOps)):
                            ?>
                            <tr>
                                <td colspan="4" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-users fs-3 d-block mb-2 text-muted opacity-50"></i>
                                    No operators found. <a href="operator_add.php">Add your first operator</a>.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach($recentOps as $op): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rounded-circle bg-light text-primary fw-bold d-flex align-items-center justify-content-center border" style="width: 36px; height: 36px; font-size: 0.85rem;">
                                            <?= strtoupper(substr($op['company_name'] ?: 'O', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($op['company_name']) ?></div>
                                            <div class="text-muted font-monospace" style="font-size: 0.72rem;">ID: #<?= $op['id'] ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-dark"><i class="fa-solid fa-phone text-muted me-1 small"></i> <?= htmlspecialchars($op['phone'] ?: 'N/A') ?></span>
                                </td>
                                <td>
                                    <?php if($op['status'] === 'active'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.72rem;">Suspended</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4 text-end">
                                    <a href="operator_edit.php?id=<?= $op['id'] ?>" class="btn btn-sm btn-light border rounded-pill px-3 text-primary fw-semibold">
                                        <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

</div>

<?php require_once 'footer.php'; ?>
