    </div> <!-- End Main Content -->
    
    <!-- Add Funds Modal (Global for Dealer) -->
    <div class="modal fade" id="addFundsModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border border-secondary shadow-lg text-light" style="border-radius: 16px;">
          <div class="modal-header border-bottom border-secondary p-4">
            <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-wallet me-2"></i> Recharge Wallet</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <form action="fund_action.php" method="POST">
            <div class="modal-body p-3">
                <div class="text-center mb-3">
                    <h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                    <div class="text-secondary small mb-2">Bank Name: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>

                    <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #10b981;">
                        <img id="fund_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=SB-LINK-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label text-secondary fw-bold small mb-1">Enter Recharge Amount (Rs)</label>
                    <input type="number" name="amount" id="fund_amount" class="form-control form-control-sm bg-dark border-secondary text-light fs-6" placeholder="e.g. 500" required onkeyup="updateFundQR()">
                </div>
                
                <div class="mb-2">
                    <label class="form-label text-secondary fw-bold small mb-1">Transaction Reference ID</label>
                    <input type="text" name="payment_reference" class="form-control form-control-sm bg-dark border-secondary text-light" placeholder="e.g. TID987654321" required>
                </div>
                <div class="text-secondary" style="font-size: 0.75rem;">Scan the QR Code to pay. After paying, submit the request.</div>
            </div>
            <div class="modal-footer border-top border-secondary p-2 d-flex justify-content-between">
              <button type="button" class="btn btn-sm btn-outline-secondary px-3 rounded-pill" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-sm btn-success px-4 rounded-pill fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Submit Recharge</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function updateFundQR() {
        var amount = document.getElementById('fund_amount').value || "0";
        var qrData = encodeURIComponent("FUNDS_DEALER_<?= $current_dealer['id'] ?>_AMT_" + amount + "_TS_" + Date.now());
        var qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" + qrData;
        document.getElementById('fund_qr_code').src = qrUrl;
    }
    </script>
</body>
</html>