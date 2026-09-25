<?php require_once 'header.php'; 

// Fetch past requests
$reqStmt = $pdo->prepare("SELECT pr.*, p.name FROM package_requests pr JOIN packages p ON pr.package_id = p.id WHERE pr.subscriber_id = ? ORDER BY pr.id DESC LIMIT 10");
$reqStmt->execute([$current_user['id']]);
$requests = $reqStmt->fetchAll();

// Fetch past sessions
$radStmt = $pdo->prepare("SELECT * FROM radacct WHERE username = ? AND acctstoptime IS NOT NULL ORDER BY radacctid DESC LIMIT 10");
$radStmt->execute([$current_user['username']]);
$sessions = $radStmt->fetchAll();

function formatBytes($bytes) {
    if ($bytes == 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>

<div class="row">
    <div class="col-lg-6 mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-code-pull-request text-accent"></i> Recent Package Requests</h5>
        <div class="card-ui overflow-hidden">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th class="border-secondary text-secondary">Date</th>
                        <th class="border-secondary text-secondary">Package</th>
                        <th class="border-secondary text-secondary">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="3" class="text-center text-secondary py-3">No requests found.</td></tr>
                    <?php else: ?>
                        <?php foreach($requests as $r): 
                            $badge = 'bg-warning';
                            if ($r['status'] == 'approved') $badge = 'bg-success';
                            if ($r['status'] == 'rejected') $badge = 'bg-danger';
                        ?>
                        <tr>
                            <td class="border-secondary"><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></td>
                            <td class="border-secondary"><?= htmlspecialchars($r['name']) ?></td>
                            <td class="border-secondary"><span class="badge <?= $badge ?> text-uppercase"><?= $r['status'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <h5 class="mb-3"><i class="fa-solid fa-clock-rotate-left text-accent"></i> Recent Data Sessions</h5>
        <div class="card-ui overflow-hidden">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0" style="font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th class="border-secondary text-secondary">Start Time</th>
                            <th class="border-secondary text-secondary">Duration</th>
                            <th class="border-secondary text-secondary text-end">Data Usage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sessions)): ?>
                            <tr><td colspan="3" class="text-center text-secondary py-3">No past sessions found.</td></tr>
                        <?php else: ?>
                            <?php foreach($sessions as $s): 
                                $dur = $s['acctsessiontime'];
                                $h = floor($dur / 3600);
                                $m = floor(($dur % 3600) / 60);
                                $total = $s['acctinputoctets'] + $s['acctoutputoctets'];
                            ?>
                            <tr>
                                <td class="border-secondary"><?= date('d M Y, H:i', strtotime($s['acctstarttime'])) ?></td>
                                <td class="border-secondary"><?= $h ?>h <?= $m ?>m</td>
                                <td class="border-secondary text-end font-monospace text-accent"><?= formatBytes($total) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
