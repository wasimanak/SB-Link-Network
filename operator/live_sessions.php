<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

// Get Live Sessions mapped to this operator
$sql = "SELECT r.*, s.package_id 
        FROM radacct r 
        JOIN subscribers s ON r.username = s.username 
        WHERE s.client_id = ? AND r.acctstoptime IS NULL 
        ORDER BY r.acctstarttime DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$client_id]);
$sessions = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between mb-3">
    <h4>Live Sessions</h4>
    <button class="btn btn-outline-dark" onclick="location.reload()"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-hover table-bordered mb-0" style="font-size: 0.9rem;">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>IP Address</th>
                    <th>MAC Address</th>
                    <th>Uptime</th>
                    <th>Download</th>
                    <th>Upload</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$sessions): ?><tr><td colspan="7" class="text-center py-3 text-muted">No active sessions.</td></tr><?php endif; ?>
                <?php foreach($sessions as $sess): 
                    $upTime = time() - strtotime($sess['acctstarttime']);
                    $hrs = floor($upTime / 3600);
                    $mins = floor(($upTime % 3600) / 60);
                    $dl = round($sess['acctoutputoctets'] / 1048576, 2) . " MB";
                    $ul = round($sess['acctinputoctets'] / 1048576, 2) . " MB";
                ?>
                <tr>
                    <td><strong class="text-success"><?= htmlspecialchars($sess['username']) ?></strong></td>
                    <td><?= htmlspecialchars($sess['framedipaddress']) ?></td>
                    <td><?= htmlspecialchars($sess['callingstationid']) ?></td>
                    <td><?= sprintf("%02d:%02d", $hrs, $mins) ?> hrs</td>
                    <td><i class="fa-solid fa-arrow-down text-info"></i> <?= $dl ?></td>
                    <td><i class="fa-solid fa-arrow-up text-warning"></i> <?= $ul ?></td>
                    <td class="text-end">
                        <form action="subscriber_action.php" method="POST" class="d-inline" onsubmit="return confirm('Disconnect this user now?');">
                            <input type="hidden" name="action" value="kick">
                            <input type="hidden" name="username" value="<?= htmlspecialchars($sess['username']) ?>">
                            <button class="btn btn-sm btn-danger">Kick</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'footer.php'; ?>
