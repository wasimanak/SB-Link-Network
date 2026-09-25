<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $req_id = (int)$_POST['request_id'];
    $status = $_POST['status']; // 'approved' or 'rejected'

    if ($status === 'approved') {
        // Fetch request details
        $stmt = $pdo->prepare("SELECT pr.*, p.rate_limit, p.validity_days, s.username FROM package_requests pr JOIN packages p ON pr.package_id = p.id JOIN subscribers s ON pr.subscriber_id = s.id WHERE pr.id = ? AND pr.client_id = ?");
        $stmt->execute([$req_id, $client_id]);
        $req = $stmt->fetch();

        if ($req) {
            // Update subscriber package & expiry
            $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = DATE_ADD(IFNULL(expiry_date, CURDATE()), INTERVAL ? DAY), status = 'active' WHERE id = ?")->execute([$req['package_id'], $req['validity_days'], $req['subscriber_id']]);
            
            // Update radreply Rate Limit
            $pdo->prepare("UPDATE radreply SET value = ? WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$req['rate_limit'], $req['username']]);
            
            // Remove Reject from radcheck if present
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$req['username']]);

            // Update request status
            $pdo->prepare("UPDATE package_requests SET status = 'approved' WHERE id = ?")->execute([$req_id]);

            $_SESSION['msg'] = "Package approved and applied to user.";
            
            // Optionally drop current session so new rate applies
            // $cmd = "echo 'User-Name={$req['username']}' | radclient ... disconnect ..."; exec($cmd);
        }
    } else {
        $pdo->prepare("UPDATE package_requests SET status = 'rejected' WHERE id = ?")->execute([$req_id]);
    }
    header("Location: requests.php");
    exit;
}

$stmt = $pdo->prepare("SELECT pr.*, s.username, p.name as pkg_name FROM package_requests pr JOIN subscribers s ON pr.subscriber_id = s.id JOIN packages p ON pr.package_id = p.id WHERE pr.client_id = ? ORDER BY pr.id DESC");
$stmt->execute([$client_id]);
$requests = $stmt->fetchAll();
?>

<div class="card shadow-sm">
    <div class="card-header">Package / Renewal Requests</div>
    <div class="card-body p-0">
        <table class="table table-hover table-hover table-bordered mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Subscriber</th>
                    <th>Requested Package</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(!$requests): ?><tr><td colspan="5" class="text-center py-4">No pending requests.</td></tr><?php endif; ?>
                <?php foreach($requests as $r): ?>
                <tr>
                    <td><?= $r['created_at'] ?></td>
                    <td><?= htmlspecialchars($r['username']) ?></td>
                    <td><span class="badge bg-info text-dark"><?= htmlspecialchars($r['pkg_name']) ?></span></td>
                    <td>
                        <?php if($r['status']==='pending'): ?><span class="badge bg-warning text-dark">Pending</span>
                        <?php elseif($r['status']==='approved'): ?><span class="badge bg-success">Approved</span>
                        <?php else: ?><span class="badge bg-danger">Rejected</span><?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if($r['status']==='pending'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="status" value="approved">
                            <button class="btn btn-sm btn-success"><i class="fa-solid fa-check"></i> Approve</button>
                        </form>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <input type="hidden" name="status" value="rejected">
                            <button class="btn btn-sm btn-danger"><i class="fa-solid fa-xmark"></i> Reject</button>
                        </form>
                        <?php else: ?>
                            <span class="text-muted">Processed</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'footer.php'; ?>
