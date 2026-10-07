<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// 1. Change action buttons
$oldBtns = '<a href="subscriber_view.php?id=<?= $s[\'id\'] ?>" class="badge rounded-pill badge-soft-primary text-decoration-none px-3 py-2"><i class="fa-brands fa-paypal"></i> Payment</a>
                            <a href="subscriber_view.php?id=<?= $s[\'id\'] ?>" class="badge rounded-pill badge-soft-success text-decoration-none px-3 py-2"><i class="fa-solid fa-rotate"></i> Renew</a>';
$newBtns = '<a href="#" onclick="openPaymentModal(<?= $s[\'id\'] ?>, \'<?= addslashes(htmlspecialchars($s[\'username\'])) ?>\'); return false;" class="badge rounded-pill badge-soft-primary text-decoration-none px-3 py-2"><i class="fa-brands fa-paypal"></i> Payment</a>
                            <a href="#" onclick="openRenewModal(<?= $s[\'id\'] ?>); return false;" class="badge rounded-pill badge-soft-success text-decoration-none px-3 py-2"><i class="fa-solid fa-rotate"></i> Renew</a>';

if (strpos($c, 'openPaymentModal') === false) {
    $c = str_replace($oldBtns, $newBtns, $c);
}

// 2. Add singlePaymentModal HTML
$modalHtml = '
<!-- Single Payment Modal -->
<div class="modal fade" id="singlePaymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content light-modal">
      <div class="modal-header bg-white border-bottom-0">
        <h5 class="modal-title fw-bold" id="singlePaymentModalTitle"><i class="fa-brands fa-paypal text-primary me-2"></i> Add Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body p-4 pt-2">
            <input type="hidden" name="action" value="add_balance_multi">
            <input type="hidden" name="sub_id" id="single_payment_sub_id">
            
            <div class="alert alert-info border-0 shadow-sm mb-4">
                <i class="fa-solid fa-circle-info me-2"></i> This amount will be added to the user\'s current balance.
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold text-secondary">Amount to Add <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text bg-light fw-bold text-dark">Rs.</span>
                    <input type="number" step="0.01" name="amount" id="single_payment_amount" class="form-control" placeholder="Enter amount..." required>
                </div>
            </div>
        </div>
        <div class="modal-footer bg-white border-top-0">
          <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Submit Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>
';

if (strpos($c, 'id="singlePaymentModal"') === false) {
    // Insert right after addBalanceModal ends
    $c = preg_replace('/(<!-- Add Balance Modal -->.*?<\/div>\s*<\/div>\s*<\/div>\s*<\/div>)/is', "$1\n$modalHtml", $c);
}

// 3. Add JS Functions
$jsFunctions = '
<script>
function openPaymentModal(userId, username) {
    $(\'#single_payment_sub_id\').val(userId);
    $(\'#singlePaymentModalTitle\').html(\'<i class="fa-brands fa-paypal text-primary me-2"></i> Add Payment for \' + username);
    $(\'#single_payment_amount\').val(\'\');
    $(\'#singlePaymentModal\').modal(\'show\');
}

function openRenewModal(userId) {
    // The Select2 dropdown for users in the Renew modal
    $(\'#renew_user_id\').val(userId).trigger(\'change\');
    $(\'#renewUserModal\').modal(\'show\');
}
</script>
';

if (strpos($c, 'function openPaymentModal') === false) {
    $c = str_replace('</body>', $jsFunctions . "\n</body>", $c);
}

file_put_contents($f, $c);
echo "operator/dashboard.php patched with action modals.\n";
?>
