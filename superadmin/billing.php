<?php
require_once 'header.php';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_balance') {
        $client_id = (int)$_POST['client_id'];
        $amount = (float)$_POST['amount'];
        $notes = trim($_POST['notes']);
        
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE clients SET balance = balance + ? WHERE id = ?")->execute([$amount, $client_id]);
            // Ideally, insert into a transactions log table here.
            $pdo->commit();
            $success = "Balance added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error adding balance: " . $e->getMessage();
        }
    }
}

// Fetch all operators
$operators = $pdo->query("SELECT id, company_name, phone, status, balance, expiry_date FROM clients ORDER BY company_name")->fetchAll();
?>

<div class="row mb-4">
    <div class="col-12">
        <h3 class="fw-bold mb-1">Billing & Subscriptions</h3>
        <p class="text-secondary">Manage ISP tenant balances and subscriptions.</p>
    </div>
</div>

<?php if($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">ISP Company</th>
                        <th>Status</th>
                        <th>Wallet Balance</th>
                        <th>Expiry Date</th>
                        <th class="pe-4 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($operators as $op): ?>
                    <tr>
                        <td class="ps-4 fw-bold text-dark"><?= htmlspecialchars($op['company_name']) ?></td>
                        <td>
                            <?php if($op['status'] === 'active'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success px-2 py-1">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1">Suspended</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="fw-bold text-primary">Rs <?= number_format($op['balance'], 2) ?></span></td>
                        <td class="text-secondary"><?= $op['expiry_date'] ? date('d M Y', strtotime($op['expiry_date'])) : 'Lifetime' ?></td>
                        <td class="pe-4 text-end">
                            <button type="button" class="btn btn-sm btn-outline-success fw-semibold" data-bs-toggle="modal" data-bs-target="#fundModal<?= $op['id'] ?>">
                                <i class="fa-solid fa-plus"></i> Add Funds
                            </button>
                        </td>
                    </tr>
                    
                    <!-- Modal -->
                    <div class="modal fade" id="fundModal<?= $op['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Add Funds: <?= htmlspecialchars($op['company_name']) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="action" value="add_balance">
                                        <input type="hidden" name="client_id" value="<?= $op['id'] ?>">
                                        <div class="mb-3">
                                            <label class="form-label">Amount (Rs)</label>
                                            <input type="number" step="0.01" name="amount" class="form-control" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Notes / Transaction ID</label>
                                            <input type="text" name="notes" class="form-control">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-success">Add Balance</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
