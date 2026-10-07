<?php
// Generates the new premium Dealer Dashboard
$f = 'dealer/dashboard.php';
$c = '<?php
require_once \'header.php\';

// Dealer Stats Scope
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN status = \'active\' AND (expiry_date IS NULL OR expiry_date > NOW()) THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN status = \'active\' AND expiry_date < NOW() THEN 1 ELSE 0 END) as expired_users,
        SUM(CASE WHEN status != \'active\' THEN 1 ELSE 0 END) as disabled_users,
        SUM(CASE WHEN service_type = \'pppoe\' THEN 1 ELSE 0 END) as pppoe_users,
        SUM(CASE WHEN service_type = \'hotspot\' THEN 1 ELSE 0 END) as hotspot_users,
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as expiring_1d,
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) as expiring_3d,
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as expiring_1w,
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as expiring_2w
    FROM subscribers 
    WHERE dealer_id = ?
");
$statsStmt->execute([$dealer_id]);
$s = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Online Users
$onStmt = $pdo->prepare("SELECT COUNT(DISTINCT r.username) FROM radacct r JOIN subscribers sub ON r.username = sub.username WHERE sub.dealer_id = ? AND r.acctstoptime IS NULL");
$onStmt->execute([$dealer_id]);
$online_users = $onStmt->fetchColumn();

// Variables for ease
$total_users = $s[\'total_users\'] ?: 0;
$active_users = $s[\'active_users\'] ?: 0;
$expired = $s[\'expired_users\'] ?: 0;
$disabled_users = $s[\'disabled_users\'] ?: 0;
$pppoe_users = $s[\'pppoe_users\'] ?: 0;
$hotspot_users = $s[\'hotspot_users\'] ?: 0;
$expiring_1d = $s[\'expiring_1d\'] ?: 0;
$expiring_3d = $s[\'expiring_3d\'] ?: 0;
$expiring_1w = $s[\'expiring_1w\'] ?: 0;
$expiring_2w = $s[\'expiring_2w\'] ?: 0;
$offline_users = max(0, $total_users - $online_users);
$balance = $current_dealer[\'balance\'] ?? 0;

// Chart Data
$chart_online = $online_users;
$chart_active = max(0, $active_users - $online_users);
$chart_expired = $expired;
$chart_disabled = $disabled_users;
$chart_others = max(0, $total_users - ($chart_online + $chart_active + $chart_expired + $chart_disabled));

$pct = function($val) use ($total_users) {
    if ($total_users == 0) return "0.00%";
    return number_format(($val / $total_users) * 100, 2) . "%";
};

// Recent Activity
$alogsStmt = $pdo->prepare("SELECT activity, against_to, created_at FROM activity_logs WHERE dealer_id = ? ORDER BY id DESC LIMIT 10");
$alogsStmt->execute([$dealer_id]);
$dealer_activities = $alogsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    .card-ui { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; margin-bottom: 20px; }
    
    /* Top Quick Actions */
    .quick-btn { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px 25px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; color: #475569; min-width: 110px; transition: 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
    .quick-btn i { font-size: 1.5rem; color: #3b82f6; margin-bottom: 8px; }
    .quick-btn span { font-size: 0.8rem; font-weight: 500; }
    .quick-btn:hover { border-color: #cbd5e1; transform: translateY(-2px); color: #1e293b; }

    /* Stats Grid */
    .stat-tabs { display: flex; gap: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px; margin-bottom: 20px; }
    .stat-tab { padding: 8px 16px; border-radius: 8px; text-decoration: none; color: #64748b; font-weight: 600; font-size: 0.9rem; transition: 0.2s; border: 1px solid transparent; }
    .stat-tab:hover { background: #f8fafc; color: #0f172a; }
    .stat-tab.active { background: #f0f9ff; color: #0284c7; border-color: #bae6fd; }
    
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
    .stat-box { padding: 15px; border-radius: 10px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    .stat-box .title { font-size: 0.85rem; font-weight: 600; margin-bottom: 5px; opacity: 0.9; }
    .stat-box .value { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; justify-content: space-between; }
    .stat-box .pct { font-size: 0.75rem; background: rgba(0,0,0,0.2); padding: 2px 6px; border-radius: 4px; font-weight: 600; }
    
    .bg-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
    .bg-green { background: linear-gradient(135deg, #22c55e, #16a34a); }
    .bg-yellow { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .bg-red { background: linear-gradient(135deg, #ef4444, #dc2626); }
    .bg-grey { background: linear-gradient(135deg, #64748b, #475569); }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0 text-dark">Dealer Dashboard</h4>
    <div class="d-flex align-items-center gap-3">
        <div class="bg-white px-4 py-2 rounded-pill shadow-sm border border-success border-opacity-25">
            <span class="text-secondary fw-bold small me-2">Wallet Balance:</span>
            <span class="text-success fw-bold fs-5">Rs. <?= number_format($balance) ?></span>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="d-flex gap-3 mb-4 overflow-auto pb-2">
    <?php if($current_dealer[\'perm_create_user\']): ?>
    <a href="users.php" class="quick-btn">
        <i class="fa-solid fa-user-plus"></i>
        <span>Add User</span>
    </a>
    <?php endif; ?>
    <a href="users.php" class="quick-btn">
        <i class="fa-solid fa-users"></i>
        <span>My Users</span>
    </a>
    <a href="support.php" class="quick-btn">
        <i class="fa-solid fa-headset"></i>
        <span>Support</span>
    </a>
</div>

<!-- Main Stats Card -->
<div class="card-ui p-4">
    <h6 class="fw-bold text-secondary mb-4"><i class="fa-solid fa-chart-pie me-2 text-primary"></i> Reports & Statistics</h6>
    
    <div class="stat-tabs">
        <a href="javascript:void(0)" class="stat-tab active"><i class="fa-solid fa-user me-1"></i> User Statistics</a>
    </div>

    <!-- User Tab Content -->
    <div id="stat-content-user" class="row">
        <!-- Donut Chart -->
        <div class="col-lg-3 col-md-4 mb-4 text-center">
            <h6 class="text-start fw-bold text-secondary mb-3 border-start border-4 border-primary ps-2">User Distribution</h6>
            <div style="position: relative; height: 180px; width: 100%; display: flex; justify-content: center;">
                <canvas id="usersDonut"></canvas>
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                    <h4 class="mb-0 fw-bold text-dark"><?= number_format($total_users) ?></h4>
                    <span class="small text-muted fw-bold">Total</span>
                </div>
            </div>
            <div class="mt-3 text-start small fw-bold d-flex flex-column gap-2 px-2">
                <span class="d-flex justify-content-between"><span><i class="fa-solid fa-circle text-info" style="font-size: 0.6rem;"></i> Online</span> <?= $chart_online ?></span>
                <span class="d-flex justify-content-between"><span><i class="fa-solid fa-circle text-success" style="font-size: 0.6rem;"></i> Active</span> <?= $chart_active ?></span>
                <span class="d-flex justify-content-between"><span><i class="fa-solid fa-circle text-danger" style="font-size: 0.6rem;"></i> Expired</span> <?= $chart_expired ?></span>
                <span class="d-flex justify-content-between"><span><i class="fa-solid fa-circle text-warning" style="font-size: 0.6rem;"></i> Disabled</span> <?= $chart_disabled ?></span>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="col-lg-9 col-md-8">
            <div class="stats-grid">
                <div class="stat-box bg-blue">
                    <div class="title"><i class="fa-solid fa-users"></i> Users</div>
                    <div class="value"><?= number_format($total_users) ?> <span class="pct">100.00%</span></div>
                </div>
                <div class="stat-box bg-green">
                    <div class="title"><i class="fa-solid fa-circle-check"></i> Active</div>
                    <div class="value"><?= number_format($active_users) ?> <span class="pct"><?= $pct($active_users) ?></span></div>
                </div>
                <div class="stat-box bg-green" style="opacity: 0.8;">
                    <div class="title"><i class="fa-solid fa-wifi"></i> Online</div>
                    <div class="value"><?= number_format($online_users) ?> <span class="pct"><?= $pct($online_users) ?></span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-user-large-slash"></i> Offline</div>
                    <div class="value"><?= number_format($offline_users) ?> <span class="pct"><?= $pct($offline_users) ?></span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-user-slash"></i> Disabled</div>
                    <div class="value"><?= number_format($disabled_users) ?> <span class="pct"><?= $pct($disabled_users) ?></span></div>
                </div>
                <div class="stat-box bg-red">
                    <div class="title"><i class="fa-solid fa-user-xmark"></i> Expired</div>
                    <div class="value"><?= number_format($expired) ?> <span class="pct"><?= $pct($expired) ?></span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-network-wired"></i> PPPoE</div>
                    <div class="value"><?= number_format($pppoe_users) ?> <span class="pct"><?= $pct($pppoe_users) ?></span></div>
                </div>
                <div class="stat-box bg-grey">
                    <div class="title"><i class="fa-solid fa-wifi"></i> Hotspot</div>
                    <div class="value"><?= number_format($hotspot_users) ?> <span class="pct"><?= $pct($hotspot_users) ?></span></div>
                </div>
                <div class="stat-box bg-yellow">
                    <div class="title"><i class="fa-solid fa-hourglass-end"></i> Expiring (1 Day)</div>
                    <div class="value"><?= number_format($expiring_1d) ?> <span class="pct"><?= $pct($expiring_1d) ?></span></div>
                </div>
                <div class="stat-box bg-yellow">
                    <div class="title"><i class="fa-solid fa-calendar-week"></i> Expiring (1 Week)</div>
                    <div class="value"><?= number_format($expiring_1w) ?> <span class="pct"><?= $pct($expiring_1w) ?></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
        <h6 class="fw-bold text-secondary mb-0"><i class="fa-solid fa-clock-rotate-left me-2"></i> Recent Activity</h6>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover table-borderless align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($dealer_activities as $act): ?>
                    <tr class="border-bottom">
                        <td class="text-secondary small"><?= date(\'d M Y, h:i A\', strtotime($act[\'created_at\'])) ?></td>
                        <td class="fw-bold text-primary"><?= htmlspecialchars($act[\'against_to\'] ?: \'N/A\') ?></td>
                        <td><?= htmlspecialchars($act[\'activity\']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($dealer_activities)): ?>
                    <tr><td colspan="3" class="text-center text-muted">No recent activity.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById(\'usersDonut\').getContext(\'2d\');
    new Chart(ctx, {
        type: \'doughnut\',
        data: {
            labels: [\'Online\', \'Active (Offline)\', \'Expired\', \'Disabled\', \'Others\'],
            datasets: [{
                data: [<?= $chart_online ?>, <?= $chart_active ?>, <?= $chart_expired ?>, <?= $chart_disabled ?>, <?= $chart_others ?>],
                backgroundColor: [\'#0ea5e9\', \'#22c55e\', \'#ef4444\', \'#f59e0b\', \'#94a3b8\'],
                borderWidth: 0,
                cutout: \'75%\'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: true }
            }
        }
    });
});
</script>

<?php require_once \'footer.php\'; ?>
';
file_put_contents($f, $c);
echo "dealer/dashboard.php completely upgraded to premium UI.\n";
?>
