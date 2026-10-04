<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];
$operator_name = $_SESSION['operator_username'] ?? 'Operator';

if (!isset($_GET['id'])) {
    echo "<script>window.location='recovery_man.php';</script>";
    exit;
}
$rm_id = (int)$_GET['id'];

// Fetch RM details
$stmt = $pdo->prepare("SELECT * FROM recovery_men WHERE id = ? AND client_id = ?");
$stmt->execute([$rm_id, $client_id]);
$rm = $stmt->fetch();

if (!$rm) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Recovery Man not found.</div></div>";
    exit;
}

// Handle Cash Collection from RM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_cash') {
    $amount = (float)$_POST['amount'];
    
    if ($amount > 0 && $amount <= $rm['cash_in_hand']) {
        try {
            $pdo->beginTransaction();
            
            // Deduct cash
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand - ? WHERE id = ?")->execute([$amount, $rm_id]);
            
            // Log the activity
            $log_msg = "Collected Cash Rs. " . number_format($amount, 2) . " from Recovery Man";
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'Operator', ?, 'RecoveryMan', ?)")
                ->execute([$client_id, $operator_name, $rm['full_name'], $log_msg]);
                
            $pdo->commit();
            echo "<script>alert('Cash collected successfully!'); window.location='recoveryman_profile.php?id=$rm_id';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error collecting cash.');</script>";
        }
    } else {
        echo "<script>alert('Invalid amount. Must be greater than 0 and not exceed Cash in Hand.');</script>";
    }
}

// Fetch User Collections by this RM
$userLedgerStmt = $pdo->prepare("
    SELECT * FROM user_ledger 
    WHERE client_id = ? AND description LIKE ? 
    ORDER BY created_at DESC 
    LIMIT 100
");
// We use the format: "Cash collected by {Name} RM%"
$userLedgerStmt->execute([$client_id, "Cash collected by {$rm['full_name']} RM%"]);
$user_collections = $userLedgerStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Cash Submitted to Operator
$submissionStmt = $pdo->prepare("
    SELECT * FROM activity_logs 
    WHERE client_id = ? AND against_role = 'RecoveryMan' AND against_to = ? AND activity LIKE 'Collected Cash Rs.%'
    ORDER BY created_at DESC 
    LIMIT 100
");
$submissionStmt->execute([$client_id, $rm['full_name']]);
$submissions = $submissionStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<style>
    .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
    .nav-tabs .nav-link { font-weight: 600; color: #64748b; border: none; border-bottom: 3px solid transparent; padding: 12px 20px; }
    .nav-tabs .nav-link.active { color: #3b82f6; border-bottom-color: #3b82f6; background: transparent; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="recovery_man.php" class="text-decoration-none text-muted small"><i class="fa-solid fa-arrow-left me-1"></i> Back to List</a>
        <h4 class="mb-0 fw-bold mt-1"><i class="fa-solid fa-user-circle text-primary me-2"></i> <?= htmlspecialchars($rm['full_name']) ?>'s Profile</h4>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Profile Info -->
    <div class="col-md-4">
        <div class="card card-custom h-100 bg-white">
            <div class="card-body p-4 text-center">
                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                    <i class="fa-solid fa-hard-hat fs-1"></i>
                </div>
                <h5 class="fw-bold mb-1"><?= htmlspecialchars($rm['full_name']) ?></h5>
                <span class="badge bg-secondary mb-3">@<?= htmlspecialchars($rm['username']) ?></span>
                
                <div class="text-start mt-4 border-top pt-3">
                    <div class="mb-2"><i class="fa-solid fa-phone text-muted me-2 w-15px"></i> <?= htmlspecialchars($rm['phone'] ?: 'N/A') ?></div>
                    <div class="mb-2"><i class="fa-solid fa-city text-muted me-2 w-15px"></i> <?= htmlspecialchars($rm['city'] ?: 'N/A') ?></div>
                    <div class="mb-2"><i class="fa-solid fa-location-dot text-muted me-2 w-15px"></i> <?= htmlspecialchars($rm['address'] ?: 'N/A') ?></div>
                    <div class="mb-2"><i class="fa-solid fa-calendar text-muted me-2 w-15px"></i> Joined <?= date('d M Y', strtotime($rm['created_at'])) ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Balance & Collect -->
    <div class="col-md-8">
        <div class="card card-custom h-100 <?= ($rm['cash_in_hand'] > 0) ? 'bg-success bg-opacity-10 border border-success border-opacity-25' : 'bg-light' ?>">
            <div class="card-body p-4 d-flex flex-column justify-content-center align-items-center text-center">
                <h6 class="fw-bold text-uppercase text-muted tracking-wider mb-2">Pending Cash in Hand</h6>
                <h1 class="display-3 fw-bold <?= ($rm['cash_in_hand'] > 0) ? 'text-success' : 'text-dark' ?> mb-4">
                    Rs. <?= number_format($rm['cash_in_hand'], 2) ?>
                </h1>
                
                <?php if($rm['cash_in_hand'] > 0): ?>
                    <button class="btn btn-success btn-lg rounded-pill px-5 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#collectModal">
                        <i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Cash
                    </button>
                <?php else: ?>
                    <div class="badge bg-secondary p-2 px-3 rounded-pill"><i class="fa-solid fa-check-circle me-1"></i> Accounts Cleared</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Statement Tabs -->
<div class="card card-custom bg-white">
    <div class="card-header bg-transparent border-bottom-0 p-0 pt-2 px-3">
        <ul class="nav nav-tabs border-bottom" id="statementTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">Collections from Users</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="operator-tab" data-bs-toggle="tab" data-bs-target="#operator" type="button" role="tab">Submissions to Operator</button>
            </li>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="tab-content p-4" id="statementTabsContent">
            
            <!-- Tab 1: User Collections -->
            <div class="tab-pane fade show active" id="users" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Subscriber Username</th>
                                <th>Amount Collected</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($user_collections as $uc): ?>
                            <tr>
                                <td class="text-secondary small fw-bold"><?= date('d M Y, h:i A', strtotime($uc['created_at'])) ?></td>
                                <td><span class="badge bg-primary"><?= htmlspecialchars($uc['username']) ?></span></td>
                                <td class="text-success fw-bold">Rs. <?= number_format($uc['amount'], 2) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($uc['description']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($user_collections)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No collections found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Tab 2: Submissions -->
            <div class="tab-pane fade" id="operator" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Collected By</th>
                                <th>Amount Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($submissions as $sub): ?>
                            <?php 
                                $amount_text = "N/A";
                                if (preg_match('/Rs\.\s*([\d,.]+)/', $sub['activity'], $m)) {
                                    $amount_text = $m[1];
                                }
                            ?>
                            <tr>
                                <td class="text-secondary small fw-bold"><?= date('d M Y, h:i A', strtotime($sub['created_at'])) ?></td>
                                <td><span class="badge bg-dark"><?= htmlspecialchars($sub['by_user']) ?> (Operator)</span></td>
                                <td class="text-danger fw-bold fs-6">Rs. <?= $amount_text ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($submissions)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No cash submissions yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </div>
</div>

<!-- Collect Cash Modal -->
<div class="modal fade" id="collectModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-white border-0 shadow-lg" style="border-radius: 16px;">
      <div class="modal-header border-bottom p-4 bg-light">
        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-hand-holding-dollar text-success me-2"></i> Collect Cash from RM</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" onsubmit="return confirm('Confirm cash collection?');">
        <input type="hidden" name="action" value="collect_cash">
        <div class="modal-body p-4 text-center">
            <h6 class="text-muted mb-1">Max Available to Collect</h6>
            <h3 class="fw-bold text-success mb-4">Rs. <?= number_format($rm['cash_in_hand'], 2) ?></h3>
            
            <div class="mb-3 text-start">
                <label class="form-label fw-bold small text-muted">Amount Received (Rs)</label>
                <input type="number" step="0.01" max="<?= $rm['cash_in_hand'] ?>" name="amount" class="form-control form-control-lg bg-light fw-bold" placeholder="e.g. 5000" required>
            </div>
        </div>
        <div class="modal-footer border-top p-3 bg-light">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm"><i class="fa-solid fa-check me-1"></i> Confirm Received</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php require_once 'footer.php'; ?>
