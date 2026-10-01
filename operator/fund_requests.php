<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'])) {
    $req_id = (int)$_POST['request_id'];
    $status = $_POST['status']; // 'approved' or 'rejected'

    if ($status === 'approved') {
        // Fetch request details
        $stmt = $pdo->prepare("SELECT fr.*, s.username as s_username, s.balance as s_balance, d.username as d_username, d.balance as d_balance 
                               FROM fund_requests fr 
                               LEFT JOIN subscribers s ON fr.subscriber_id = s.id 
                               LEFT JOIN dealers d ON fr.dealer_id = d.id 
                               WHERE fr.id = ? AND fr.client_id = ? AND fr.status = 'pending'");
        $stmt->execute([$req_id, $client_id]);
        $req = $stmt->fetch();

        if ($req) {
            $amount = (float)$req['amount'];
            
            $pdo->beginTransaction();
            try {
                if (!empty($req['subscriber_id'])) {
                    $new_balance = $req['s_balance'] + $amount;
                    $pdo->prepare("UPDATE subscribers SET balance = ? WHERE id = ?")->execute([$new_balance, $req['subscriber_id']]);
                    $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                        ->execute([$client_id, $req['s_username'], $amount, $new_balance, "Wallet Recharge (Ref: {$req['payment_reference']})"]);
                } elseif (!empty($req['dealer_id'])) {
                    $new_balance = $req['d_balance'] + $amount;
                    $pdo->prepare("UPDATE dealers SET balance = ? WHERE id = ?")->execute([$new_balance, $req['dealer_id']]);
                    // If dealer has a ledger, we'd add it here. For now just update balance.
                }
                
                // Update request status
                $pdo->prepare("UPDATE fund_requests SET status = 'approved' WHERE id = ?")->execute([$req_id]);
                
                $pdo->commit();
                $_SESSION['msg'] = "Fund request approved. Balance added to wallet.";
            } catch(Exception $e) {
                $pdo->rollBack();
                $_SESSION['msg'] = "Error approving fund request.";
            }
        }
    } else {
        $pdo->prepare("UPDATE fund_requests SET status = 'rejected' WHERE id = ? AND client_id = ?")->execute([$req_id, $client_id]);
        $_SESSION['msg'] = "Fund request rejected.";
    }
    echo "<script>window.location='fund_requests.php';</script>";
    exit;
}

// Fetch BOTH subscriber and dealer requests
$stmt = $pdo->prepare("
    SELECT fr.*, 
           s.username as s_username, s.full_name as s_full_name,
           d.username as d_username, d.full_name as d_full_name
    FROM fund_requests fr 
    LEFT JOIN subscribers s ON fr.subscriber_id = s.id 
    LEFT JOIN dealers d ON fr.dealer_id = d.id 
    WHERE fr.client_id = ? 
    ORDER BY fr.id DESC
");
$stmt->execute([$client_id]);
$requests = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 text-secondary fw-bold"><i class="fa-solid fa-wallet text-success me-2"></i> Fund Requests (Payments)</h4>
</div>

<?php if(isset($_SESSION['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= $_SESSION['msg']; unset($_SESSION['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-4">
    <div class="card-header bg-white border-bottom-0 pt-4 pb-0">
        <h6 class="fw-bold text-secondary mb-0">Pending & History</h6>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>User Type</th>
                        <th>Name / Username</th>
                        <th>Amount (Rs)</th>
                        <th>Reference ID</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!$requests): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fa-solid fa-inbox fa-3x mb-3 text-light"></i><br>No fund requests found.</td></tr>
                    <?php endif; ?>
                    
                    <?php foreach($requests as $r): ?>
                    <tr>
                        <td class="text-secondary small"><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></td>
                        <td>
                            <?php if(!empty($r['dealer_id'])): ?>
                                <span class="badge bg-success text-white px-2 py-1"><i class="fa-solid fa-handshake"></i> Dealer</span>
                            <?php else: ?>
                                <span class="badge bg-primary text-white px-2 py-1"><i class="fa-solid fa-user"></i> Subscriber</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if(!empty($r['dealer_id'])): ?>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($r['d_username']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($r['d_full_name']) ?></div>
                            <?php else: ?>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($r['s_username']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($r['s_full_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><strong class="text-success">Rs <?= number_format($r['amount'], 2) ?></strong></td>
                        <td class="font-monospace text-secondary small"><?= htmlspecialchars($r['payment_reference']) ?></td>
                        <td>
                            <?php if($r['status'] == 'pending'): ?>
                                <span class="badge bg-warning text-dark rounded-pill px-3 py-2 border border-warning">Pending</span>
                            <?php elseif($r['status'] == 'approved'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2 border border-success">Approved</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2 border border-danger">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if($r['status'] == 'pending'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                    <button type="submit" name="status" value="approved" class="btn btn-sm btn-success rounded-pill px-3" onclick="return confirm('Approve request and add funds?')">Approve</button>
                                    <button type="submit" name="status" value="rejected" class="btn btn-sm btn-outline-danger rounded-pill px-3 ms-1" onclick="return confirm('Reject this request?')">Reject</button>
                                </form>
                            <?php else: ?>
                                <span class="text-muted small"><i class="fa-solid fa-check text-success"></i> Action Taken</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
