<?php
require_once 'header.php';

try {
    // Fetch last 50 authentication logs from radacct
    // Real deployments might have radpostauth for failed attempts, falling back to basic radacct usage here.
    $stmt = $pdo->query("SELECT * FROM radacct ORDER BY radacctid DESC LIMIT 50");
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Error loading logs. Make sure radacct table exists.</div>");
}
?>

<div class="d-flex justify-content-between mb-3">
    <h4>System Logs (RADIUS)</h4>
    <button class="btn btn-sm btn-outline-light" onclick="location.reload()"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0" style="font-size: 0.9rem;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>NAS IP</th>
                        <th>Start Time</th>
                        <th>Stop Time</th>
                        <th>Termination Cause</th>
                        <th>Session Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($logs)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No radius logs found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= $log['radacctid'] ?></td>
                            <td><span class="text-info"><?= htmlspecialchars($log['username']) ?></span></td>
                            <td><?= htmlspecialchars($log['nasipaddress']) ?></td>
                            <td><?= htmlspecialchars($log['acctstarttime']) ?></td>
                            <td><?= $log['acctstoptime'] ? htmlspecialchars($log['acctstoptime']) : '<span class="badge bg-success">Online</span>' ?></td>
                            <td><?= htmlspecialchars($log['acctterminatecause']) ?></td>
                            <td><?= $log['acctsessiontime'] ? gmdate("H:i:s", $log['acctsessiontime']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
