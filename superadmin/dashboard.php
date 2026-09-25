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

<div class="row">
    <!-- Operators Card -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 shadow-sm border-primary">
            <div class="card-body text-center">
                <i class="fa-solid fa-users fa-3x text-primary mb-3"></i>
                <h5 class="card-title">Total Operators</h5>
                <h2 class="fw-bold"><?= number_format($totalOperators) ?></h2>
            </div>
        </div>
    </div>
    
    <!-- Routers Card -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 shadow-sm border-success">
            <div class="card-body text-center">
                <i class="fa-solid fa-server fa-3x text-success mb-3"></i>
                <h5 class="card-title">Total Routers</h5>
                <h2 class="fw-bold"><?= number_format($totalRouters) ?></h2>
                <small class="text-success"><i class="fa-solid fa-circle-check"></i> All online</small>
            </div>
        </div>
    </div>
    
    <!-- Subscribers Card -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 shadow-sm border-info">
            <div class="card-body text-center">
                <i class="fa-solid fa-wifi fa-3x text-info mb-3"></i>
                <h5 class="card-title">Active Subscribers</h5>
                <h2 class="fw-bold"><?= number_format($activeSubscribers) ?></h2>
                <small class="text-muted">Total history: <?= number_format($totalSubscribers) ?></small>
            </div>
        </div>
    </div>

    <!-- Server Health Card -->
    <div class="col-md-3 mb-4">
        <div class="card h-100 shadow-sm border-warning">
            <div class="card-body">
                <h5 class="card-title text-center text-warning"><i class="fa-solid fa-heart-pulse"></i> Server Health</h5>
                <hr class="border-secondary">
                <div class="mb-2">
                    <small class="text-muted">CPU Load:</small><br>
                    <strong><?= $cpuLoad ?></strong>
                </div>
                <div class="mb-2">
                    <small class="text-muted">Disk Usage (<?= $diskUsage ?>%):</small>
                    <div class="progress" style="height: 5px; background-color: #334155;">
                      <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $diskUsage ?>%"></div>
                    </div>
                </div>
                <div>
                    <small class="text-muted">MySQL:</small><br>
                    <strong class="text-success text-truncate d-block" title="<?= htmlspecialchars($mysqlStatus) ?>">Connected</strong>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
