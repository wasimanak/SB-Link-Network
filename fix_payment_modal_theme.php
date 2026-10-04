<?php
$f = 'operator/subscribers.php';
$c = file_get_contents($f);

// We need to replace the singlePaymentModal block with a premium light theme version
$oldModalPattern = '/<!-- Single Payment Modal -->.*?<!-- Mass Payment Modal -->/s';

$newModal = '<!-- Single Payment Modal -->
<div class="modal fade" id="singlePaymentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-white border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
      <div class="modal-header border-bottom p-4" style="background-color: #f8fafc; border-color: #e2e8f0 !important;">
        <h5 class="modal-title text-dark fw-bold"><i class="fa-brands fa-paypal text-primary me-2"></i> Add Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="single_payment">
        <input type="hidden" name="id" id="pay_user_id" value="">
        <div class="modal-body p-4">
            <div class="mb-4 text-center p-3 rounded" style="background-color: #f1f5f9; border: 1px dashed #cbd5e1;">
                <h5 class="text-primary fw-bold mb-1" id="pay_username_display">Username</h5>
                <div class="text-muted small">Current Balance: <span class="text-success fw-bold fs-6">Rs <span id="pay_current_balance">0.00</span></span></div>
            </div>
            <div class="mb-2">
                <label class="form-label text-dark fw-bold small text-uppercase" style="letter-spacing: 0.5px;">Amount to Add (Rs) <span class="text-danger">*</span></label>
                <div class="input-group shadow-sm" style="border-radius: 10px; overflow: hidden;">
                    <span class="input-group-text bg-light border-0 text-muted px-3 fw-bold">Rs</span>
                    <input type="number" step="1" name="amount" class="form-control bg-light border-0 px-3 py-2 text-dark fs-5 fw-bold" placeholder="e.g. 500" required>
                </div>
                <small class="text-muted mt-2 d-block"><i class="fa-solid fa-circle-info me-1"></i>Use negative amount to deduct balance.</small>
            </div>
        </div>
        <div class="modal-footer border-top p-3" style="background-color: #f8fafc; border-color: #e2e8f0 !important;">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"><i class="fa-solid fa-check me-1"></i> Apply Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- Mass Payment Modal -->';

if (preg_match($oldModalPattern, $c)) {
    $c = preg_replace($oldModalPattern, $newModal, $c);
    file_put_contents($f, $c);
    echo "Premium Light Theme applied to Payment Modal!\n";
} else {
    echo "Could not find Single Payment Modal in operator/subscribers.php\n";
}
?>
