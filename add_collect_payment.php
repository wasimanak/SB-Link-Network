<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Add Backend Logic for 'collect_only'
$backend_logic = <<<'PHP'
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_only') {
    $sub_id = (int)$_POST['subscriber_id'];
    $sub_username = trim($_POST['subscriber_username']);
    $amount = (float)$_POST['amount'];
    $note = trim($_POST['note']);
    $payment_date = trim($_POST['payment_date']);
    
    if (empty($payment_date)) {
        $payment_date = date('Y-m-d H:i:s');
    } else {
        $payment_date = date('Y-m-d H:i:s', strtotime($payment_date));
    }

    try {
        $pdo->beginTransaction();

        if ($amount > 0) {
            // Add to User Balance (Adding money increases their balance/wallet)
            $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);
            
            // Fetch updated balance
            $tmpBalStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $tmpBalStmt->execute([$sub_id]);
            $balAfterPayment = $tmpBalStmt->fetchColumn();
            
            // Ledger Entry with custom date
            $desc = "Payment collected by {$rm_name} RM" . ($note ? " - Note: $note" : "");
            
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description, created_at) VALUES (?, ?, 'credit', ?, ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $balAfterPayment, $desc, $payment_date]);
                
            // Update RM Cash in Hand
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?")->execute([$amount, $rm_id]);
            
            $pdo->commit();
            echo "<script>alert('Payment Collected Successfully!'); window.location='dashboard.php';</script>";
            exit;
        } else {
            $pdo->rollBack();
            echo "<script>alert('Error: Amount must be greater than 0.'); window.history.back();</script>";
            exit;
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    }
}
PHP;

$c = str_replace("if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['action']) && \$_POST['action'] === 'receive_payment') {", $backend_logic . "\n\nif (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['action']) && \$_POST['action'] === 'receive_payment') {", $c);


// 2. Add Button in UI
$renew_button_html = '<button class="btn btn-sm btn-accent w-100 fw-bold shadow-sm" onclick="openRenewModal(<?= $u[\'id\'] ?>)">';
// Note: It uses onclick="openRenewModal(...)". 
// Let's find exactly how the Renew button is written.
$find_renew_btn = '<button class="btn btn-sm btn-accent w-100 fw-bold shadow-sm" onclick="openRenewModal(<?= $u[\'id\'] ?>)">
                                            <i class="fa-solid fa-rotate me-1"></i> Renew & Collect
                                        </button>';
$collect_button_html = <<<'HTML'
<button class="btn btn-sm btn-success w-100 fw-bold shadow-sm mb-2" data-bs-toggle="modal" data-bs-target="#collectModal<?= $u['id'] ?>">
                                            <i class="fa-solid fa-hand-holding-dollar me-1"></i> Collect Payment
                                        </button>
HTML;

$c = str_replace($find_renew_btn, $collect_button_html . "\n                                        " . $find_renew_btn, $c);

// 3. Inject the Collect Modal inside the User Cards loop
// The loop ends around here:
// </div>
// </div>
// </div>
// <?php endforeach;
// We can inject the modal just before the end of the col-md-4 div.
$modal_html = <<<'HTML'
<!-- Collect Payment Modal -->
                                <div class="modal fade" id="collectModal<?= $u['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                                            <div class="modal-header border-bottom p-4">
                                                <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Payment</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="collect_only">
                                                <input type="hidden" name="subscriber_id" value="<?= $u['id'] ?>">
                                                <input type="hidden" name="subscriber_username" value="<?= htmlspecialchars($u['username']) ?>">
                                                
                                                <div class="modal-body p-4 text-start">
                                                    <div class="alert alert-success bg-opacity-10 text-success border-success border-opacity-25 py-2 mb-3">
                                                        <strong>User:</strong> <?= htmlspecialchars($u['username']) ?><br>
                                                        <strong>Current Balance:</strong> Rs. <?= number_format($u['balance'], 2) ?>
                                                    </div>
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold small text-muted text-uppercase">Payment Date & Time</label>
                                                        <input type="datetime-local" name="payment_date" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold small text-muted text-uppercase">Collected Amount (Rs) <span class="text-danger">*</span></label>
                                                        <input type="number" step="0.01" name="amount" class="form-control form-control-lg fw-bold text-success" placeholder="e.g. 1500" required>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold small text-muted text-uppercase">Note (Optional)</label>
                                                        <input type="text" name="note" class="form-control" placeholder="e.g. Previous month pending">
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-top p-3">
                                                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">Save Payment</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
HTML;

// Find where to place the modal. Placing it right after the Collect button is safe, or after the Renew button.
$c = str_replace($find_renew_btn, $find_renew_btn . "\n" . $modal_html, $c);

file_put_contents($f, $c);
echo "Added Collect Payment feature.\n";
?>
