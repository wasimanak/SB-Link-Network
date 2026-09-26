<?php
require_once 'header.php';

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
    
    // Server Health Card
    $os = php_uname('s');
    $cpuLoad = "N/A (Windows)";
    if (strpos(strtolower($os), 'win') === false && function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $cpuLoad = $load[0] . ' (1m) / ' . $load[1] . ' (5m)';
    } else {
        $cpuLoad = rand(1, 15) . "% (Mock)"; // Mock for windows localhost
    }

    $freeDisk = disk_free_space("/") ?: 0;
    $totalDisk = disk_total_space("/") ?: 1;
    $diskUsage = round(100 - ($freeDisk / $totalDisk) * 100, 2);
    
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

<div class="row">
    <!-- Operators Card -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-secondary text-uppercase fw-semibold mb-0" style="letter-spacing: 0.5px;">Operators</h6>
                    <div class="bg-primary bg-opacity-10 text-primary rounded p-2 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                        <i class="fa-solid fa-users fa-lg"></i>
                    </div>
                </div>
                <h2 class="fw-bold mb-1 text-dark"><?= number_format($totalOperators) ?></h2>
                <small class="text-secondary mt-auto">Registered Tenants (ISPs)</small>
            </div>
        </div>
    </div>
    
    <!-- Routers Card -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-secondary text-uppercase fw-semibold mb-0" style="letter-spacing: 0.5px;">Routers</h6>
                    <div class="bg-success bg-opacity-10 text-success rounded p-2 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                        <i class="fa-solid fa-server fa-lg"></i>
                    </div>
                </div>
                <h2 class="fw-bold mb-1 text-dark"><?= number_format($totalRouters) ?></h2>
                <small class="text-success fw-semibold mt-auto"><i class="fa-solid fa-circle-check"></i> Connected MikroTiks</small>
            </div>
        </div>
    </div>
    
    <!-- Active Subscribers -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-secondary text-uppercase fw-semibold mb-0" style="letter-spacing: 0.5px;">Live Users</h6>
                    <div class="bg-info bg-opacity-10 text-info rounded p-2 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                        <i class="fa-solid fa-wifi fa-lg"></i>
                    </div>
                </div>
                <h2 class="fw-bold mb-1 text-dark"><?= number_format($activeSubscribers) ?></h2>
                <small class="text-secondary mt-auto">Global Active Sessions</small>
            </div>
        </div>
    </div>

    <!-- Server Health -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="text-secondary text-uppercase fw-semibold mb-0" style="letter-spacing: 0.5px;">System</h6>
                    <div class="bg-warning bg-opacity-10 text-warning rounded p-2 d-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                        <i class="fa-solid fa-heart-pulse fa-lg"></i>
                    </div>
                </div>
                <div class="mt-2 mb-2">
                    <div class="d-flex justify-content-between text-secondary small mb-1">
                        <span>CPU Load</span>
                        <span class="fw-semibold text-dark"><?= $cpuLoad ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary small mb-1">
                        <span>DB Status</span>
                        <span class="fw-semibold text-success">Online</span>
                    </div>
                </div>
                <div class="mt-auto">
                    <div class="d-flex justify-content-between text-secondary small mb-1">
                        <span>Disk Usage</span>
                        <span class="fw-semibold text-dark"><?= $diskUsage ?>%</span>
                    </div>
                    <div class="progress" style="height: 6px; background-color: #e2e8f0;">
                      <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $diskUsage ?>%"></div>
                    </div>
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
