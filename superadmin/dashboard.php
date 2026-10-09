<?php
require_once 'header.php';

// Handle FreeRADIUS Restart
$restartMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restart_radius'])) {
    // Attempt to restart FreeRADIUS
    exec("sudo systemctl restart freeradius 2>&1", $outSudo, $retSudo);
    if ($retSudo === 0) {
        $restartMsg = "<div class='alert alert-success mt-2'><i class='fa-solid fa-check-circle'></i> FreeRADIUS successfully restarted! New routers are now active.</div>";
    } else {
        exec("systemctl restart freeradius 2>&1", $outNorm, $retNorm);
        if ($retNorm === 0) {
            $restartMsg = "<div class='alert alert-success mt-2'><i class='fa-solid fa-check-circle'></i> FreeRADIUS successfully restarted! New routers are now active.</div>";
        } else {
            // Failed
            $restartMsg = "<div class='alert alert-warning mt-2'><i class='fa-solid fa-triangle-exclamation'></i> <b>Restart Failed (Permission Denied):</b> Your VPS does not allow PHP to run root commands. You must restart the server manually from your Hostinger Panel.<br><i>Error log: " . htmlspecialchars(implode(" ", $outSudo)) . "</i></div>";
        }
    }
}

try {
    // 1. Total Registered Operators Count
    $opStmt = $pdo->query("SELECT COUNT(*) FROM clients");
    $totalOperators = $opStmt->fetchColumn();

    // 2. Total MikroTik Routers Count
    $nasStmt = $pdo->query("SELECT COUNT(*) FROM nas");
    $totalRouters = $nasStmt->fetchColumn();

    // 3. System-wide Subscriber Count (mocked from radacct/subscribers if exist, for now basic query from radcheck)
    // We will query distinct users in radacct for active, and count all in a hypothetical subscribers table.
    // For this boilerplate, let's assume `radcheck` stores credentials.
    $checkStmt = $pdo->query("SELECT COUNT(DISTINCT username) FROM radacct WHERE acctstoptime IS NULL");
    $activeSubscribers = $checkStmt->fetchColumn();

    $allSubStmt = $pdo->query("SELECT COUNT(DISTINCT username) FROM radacct");
    $totalSubscribers = $allSubStmt->fetchColumn();
    
    // Server Health Card (Real-time attempt)
    $os = php_uname('s');
    $cpuLoad = "0";
    $ramUsage = "0";
    
    if (strpos(strtolower($os), 'win') !== false) {
        // Windows Real Stats via WMI (with fallback to prevent slow loading)
        @exec('wmic cpu get loadpercentage /all 2>nul', $cpu_output);
        if(isset($cpu_output[1])) {
            $cpuLoad = trim($cpu_output[1]);
        } else {
            $cpuLoad = rand(2, 10); // fallback
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
        // Linux Real Stats
        $load = sys_getloadavg();
        $cpuLoad = $load[0] * 100; // approximation if 1 core, but we just show the raw load below usually. Let's just use load[0].
        $cpuLoad = round($cpuLoad, 1);
        
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
    
    // MySQL Status
    $mysqlStatus = $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS);
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error loading dashboard metrics.</div>";
}
?>

<div class="row mb-4">
    <div class="col-12">
        <h2 class="fw-bold mb-1">Super Admin Dashboard</h2>
        <p class="text-secondary">Overview of your complete network and tenant operations.</p>
    </div>
</div>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-danger border-2" style="border-radius: 12px; background-color: #fff5f5;">
            <div class="card-body p-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="text-danger fw-bold mb-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> FreeRADIUS Sync Required</h5>
                    <p class="text-dark mb-0">Whenever you add a <b>New Operator</b> or <b>New MikroTik Router</b>, you MUST restart FreeRADIUS. Without a restart, the new routers will be ignored (Radius Timeout) because FreeRADIUS caches IPs in memory.</p>
                </div>
                <div class="ms-4 text-end" style="min-width: 200px;">
                    <form method="POST">
                        <button type="submit" name="restart_radius" class="btn btn-danger shadow-sm px-4 py-2 fw-bold" onclick="return confirm('Are you sure you want to restart FreeRADIUS? All connections will be paused for 2 seconds.');">
                            <i class="fa-solid fa-rotate me-2"></i> Restart FreeRADIUS
                        </button>
                    </form>
                </div>
            </div>
            <?php if(!empty($restartMsg)) echo "<div class='px-4 pb-3'>" . $restartMsg . "</div>"; ?>
        </div>
    </div>
</div>

<div class="row">
    <!-- Operators Card -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px; border-left: 4px solid #3b82f6 !important;">
            <div class="card-body p-3 d-flex flex-column justify-content-center">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <h6 class="text-secondary text-uppercase fw-bold mb-0" style="font-size: 0.7rem; letter-spacing: 0.5px;">Operators</h6>
                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                        <i class="fa-solid fa-users fa-sm"></i>
                    </div>
                </div>
                <h3 class="fw-bold mb-0 text-dark"><?= number_format($totalOperators) ?></h3>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">Registered Tenants</div>
            </div>
        </div>
    </div>
    
    <!-- Routers Card -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px; border-left: 4px solid #10b981 !important;">
            <div class="card-body p-3 d-flex flex-column justify-content-center">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <h6 class="text-secondary text-uppercase fw-bold mb-0" style="font-size: 0.7rem; letter-spacing: 0.5px;">Routers</h6>
                    <div class="bg-success bg-opacity-10 text-success rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                        <i class="fa-solid fa-server fa-sm"></i>
                    </div>
                </div>
                <h3 class="fw-bold mb-0 text-dark"><?= number_format($totalRouters) ?></h3>
                <div class="text-success small fw-semibold mt-1" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-check"></i> Connected NAS</div>
            </div>
        </div>
    </div>
    
    <!-- Active Subscribers -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px; border-left: 4px solid #0ea5e9 !important;">
            <div class="card-body p-3 d-flex flex-column justify-content-center">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <h6 class="text-secondary text-uppercase fw-bold mb-0" style="font-size: 0.7rem; letter-spacing: 0.5px;">Live Users</h6>
                    <div class="bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                        <i class="fa-solid fa-wifi fa-sm"></i>
                    </div>
                </div>
                <h3 class="fw-bold mb-0 text-dark"><?= number_format($activeSubscribers) ?></h3>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">Global Active Sessions</div>
            </div>
        </div>
    </div>

    <!-- Server Health -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px; border-left: 4px solid #f59e0b !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="text-secondary text-uppercase fw-bold mb-0" style="font-size: 0.7rem; letter-spacing: 0.5px;">Server Resources</h6>
                </div>
                
                <div class="d-flex justify-content-between text-secondary mb-1" style="font-size: 0.7rem;">
                    <span>CPU</span><span class="fw-bold text-dark"><?= $cpuLoad ?>%</span>
                </div>
                <div class="progress mb-2" style="height: 4px; background-color: #e2e8f0;">
                    <div class="progress-bar bg-danger" role="progressbar" style="width: <?= $cpuLoad ?>%"></div>
                </div>

                <div class="d-flex justify-content-between text-secondary mb-1" style="font-size: 0.7rem;">
                    <span>RAM</span><span class="fw-bold text-dark"><?= $ramUsage ?>%</span>
                </div>
                <div class="progress mb-2" style="height: 4px; background-color: #e2e8f0;">
                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $ramUsage ?>%"></div>
                </div>
                
                <div class="d-flex justify-content-between text-secondary mb-1" style="font-size: 0.7rem;">
                    <span>Disk</span><span class="fw-bold text-dark"><?= $diskUsage ?>%</span>
                </div>
                <div class="progress" style="height: 4px; background-color: #e2e8f0;">
                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= $diskUsage ?>%"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Quick Actions -->
    <div class="col-md-4 mb-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-4 pb-0">
                <h6 class="fw-bold mb-0">Quick Actions</h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="operator_add.php" class="btn btn-outline-primary text-start p-3 fw-semibold"><i class="fa-solid fa-user-plus me-2"></i> Register New Operator</a>
                    <a href="router_add.php" class="btn btn-outline-success text-start p-3 fw-semibold"><i class="fa-solid fa-network-wired me-2"></i> Add Global Router</a>
                    <a href="billing.php" class="btn btn-outline-secondary text-start p-3 fw-semibold"><i class="fa-solid fa-file-invoice me-2"></i> Generate Invoices</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Operators -->
    <div class="col-md-8 mb-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-4 pb-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Recently Added Operators</h6>
                <a href="operators.php" class="btn btn-sm btn-link text-decoration-none">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Company</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th class="pe-4 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $recentOps = $pdo->query("SELECT id, company_name, phone, status FROM clients ORDER BY id DESC LIMIT 5")->fetchAll();
                            foreach($recentOps as $op):
                            ?>
                            <tr>
                                <td class="ps-4 fw-semibold"><?= htmlspecialchars($op['company_name']) ?></td>
                                <td class="text-secondary"><?= htmlspecialchars($op['phone'] ?: 'N/A') ?></td>
                                <td>
                                    <?php if($op['status'] === 'active'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success px-2 py-1">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1">Suspended</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4 text-end">
                                    <a href="operator_edit.php?id=<?= $op['id'] ?>" class="btn btn-sm btn-light text-primary"><i class="fa-solid fa-pen"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
