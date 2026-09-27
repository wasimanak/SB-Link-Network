<?php
require_once 'header.php';

// Fetch stats for this dealer only
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN status = 'active' AND (expiry_date IS NULL OR expiry_date > NOW()) THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN expiry_date < NOW() THEN 1 ELSE 0 END) as expired_users,
        (SELECT COUNT(*) FROM radacct r JOIN subscribers s ON r.username = s.username WHERE s.dealer_id = ? AND r.acctstoptime IS NULL) as online_users
    FROM subscribers 
    WHERE dealer_id = ?
");
$statsStmt->execute([$dealer_id, $dealer_id]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Fetch recent activity
$alogsStmt = $pdo->prepare("SELECT activity, against_to, created_at FROM activity_logs WHERE dealer_id = ? ORDER BY id DESC LIMIT 10");
$alogsStmt->execute([$dealer_id]);
$dealer_activities = $alogsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="row g-4 mb-4">
    <!-- Total Users -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-light-primary"><i class="fa-solid fa-users"></i></div>
            <div class="stat-details">
                <h3><?= number_format($stats['total_users'] ?? 0) ?></h3>
                <p>Total Users</p>
            </div>
        </div>
    </div>
    <!-- Online Users -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-light-success"><i class="fa-solid fa-wifi"></i></div>
            <div class="stat-details">
                <h3><?= number_format($stats['online_users'] ?? 0) ?></h3>
                <p>Online Users</p>
            </div>
        </div>
    </div>
    <!-- Active Users -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-light-warning"><i class="fa-solid fa-user-check"></i></div>
            <div class="stat-details">
                <h3><?= number_format($stats['active_users'] ?? 0) ?></h3>
                <p>Active Users</p>
            </div>
        </div>
    </div>
    <!-- Expired Users -->
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-light-danger"><i class="fa-solid fa-user-xmark"></i></div>
            <div class="stat-details">
                <h3><?= number_format($stats['expired_users'] ?? 0) ?></h3>
                <p>Expired Users</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
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
                                <td class="text-secondary small"><?= date('d M Y, h:i A', strtotime($act['created_at'])) ?></td>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($act['against_to'] ?: 'N/A') ?></td>
                                <td><?= htmlspecialchars($act['activity']) ?></td>
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
    </div>
</div>

<?php require_once 'footer.php'; ?>