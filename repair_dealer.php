<?php
$f = 'dealer/dashboard.php';
$c = file_get_contents($f);

$pattern = '/<div class="modal fade" id="addFundsModal".*?(?=<script>)/s';

$replacement = '<div class="modal fade" id="addFundsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark border border-secondary shadow-lg text-light" style="border-radius: 16px;">
      <div class="modal-header border-bottom border-secondary p-4">
        <h5 class="modal-title fw-bold text-primary"><i class="fa-solid fa-wallet me-2"></i> Online Recharge</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form action="fund_action.php" method="POST">
        <div class="modal-body p-3">
            
            <div class="text-center mb-3">
                <?php if($active_method === \'bank\'): ?>
                    <div class="bg-dark border border-secondary rounded p-3 text-start mx-auto shadow mb-2" style="max-width: 320px;">
                        <div class="text-center mb-2 pb-2 border-bottom border-secondary border-opacity-50">
                            <i class="fa-solid fa-building-columns fa-2x text-primary mb-1"></i>
                            <h5 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_display_name) ?></h5>
                        </div>
                        <div class="mb-2">
                            <div class="text-secondary small text-uppercase" style="font-size: 0.7rem;">Account Title</div>
                            <div class="fw-bold text-light fs-6"><?= htmlspecialchars($gateway_account_name) ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-secondary small text-uppercase" style="font-size: 0.7rem;">Account Number</div>
                            <div class="fw-bold text-primary fs-5" style="letter-spacing: 1px;"><?= htmlspecialchars($account_number_str) ?></div>
                        </div>
                        <?php if(!empty($iban_str)): ?>
                        <div class="mb-0">
                            <div class="text-secondary small text-uppercase" style="font-size: 0.7rem;">IBAN</div>
                            <div class="fw-bold text-info" style="font-family: monospace; font-size: 0.95rem;"><?= htmlspecialchars($iban_str) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                    <div class="text-secondary small mb-2">Provider: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>
                    <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #3b82f6;">
                        <img id="dealer_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=DEALER-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
                    </div>
                <?php endif; ?>
            </div>

            <div class="mb-2">
                <label class="form-label text-secondary fw-bold small mb-1">Enter Recharge Amount (Rs)</label>
                <input type="number" name="amount" id="fund_amount" class="form-control form-control-sm bg-dark border-secondary text-light fs-6" placeholder="e.g. 500" required onkeyup="updateFundQR()">
            </div>
            
            <div class="mb-2">
                <label class="form-label text-secondary fw-bold small mb-1">Transaction Reference ID</label>
                <input type="text" name="payment_reference" class="form-control form-control-sm bg-dark border-secondary text-light" placeholder="e.g. TID987654321" required>
            </div>

            <div class="text-secondary" style="font-size: 0.75rem;">
                Scan the QR Code to pay. After paying, submit the request.
            </div>

        </div>
        <div class="modal-footer border-top border-secondary p-2 d-flex justify-content-between">
          <button type="button" class="btn btn-sm btn-outline-secondary px-3 rounded-pill" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary px-4 rounded-pill fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Submit Recharge</button>
        </div>
      </form>
    </div>
  </div>
</div>

';

$c = preg_replace($pattern, $replacement, $c);
file_put_contents($f, $c);
echo "Replaced dealer successfully!\n";
?>
