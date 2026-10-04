<?php
$f = 'customer/dashboard.php';
$c = file_get_contents($f);

// We want to replace everything from the start of buyModal to the start of <script>

$pattern = '/<div class="modal fade" id="buyModal".*?(?=<script>)/s';

$replacement = '<div class="modal fade" id="buyModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark border border-secondary shadow-lg text-light" style="border-radius: 16px;">
      <div class="modal-header border-bottom border-secondary p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-cart-shopping me-2 text-primary"></i> Confirm Purchase</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <form action="fund_action.php" method="POST">
            <input type="hidden" name="action" value="buy_package">
            <input type="hidden" name="package_id" id="modal_pkg_id">
            
            <div class="text-center mb-4">
                <h4 id="modal_pkg_name" class="fw-bold mb-0 text-light">Package Name</h4>
                <h1 class="text-accent fw-bold mt-1 mb-0">Rs <span id="modal_pkg_price">0</span></h1>
            </div>

            <!-- Online Payment Details Box -->
            <div id="onlinePaymentBox" class="text-center mb-3">
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
                        <img id="qr_code_img" src="" alt="Dynamic QR" class="img-fluid rounded" style="width: 140px; height: 140px;">
                    </div>
                <?php endif; ?>

                <div class="text-secondary" style="font-size: 0.75rem;">
                    Scan the QR Code to pay. After paying, click <b>Submit Request</b>.
                </div>
                <input type="hidden" name="payment_reference" id="payment_reference" value="AUTO_<?= time() ?>_<?= $current_user[\'id\'] ?>">
            </div>

            <!-- Balance Payment Info -->
            <div id="balancePaymentBox" class="d-none text-center mb-3 py-4">
                <i class="fa-solid fa-wallet fa-3x text-success mb-2"></i>
                <h5 class="text-light">Pay via Balance</h5>
                <div class="text-secondary small">Your request will be submitted and amount will be deducted upon approval.</div>
            </div>

            <hr class="border-secondary opacity-25 my-3">
            
            <div class="text-secondary small fw-bold mb-2">Payment Method:</div>
            
            <!-- Payment Options -->
            <div class="d-flex flex-column gap-2">
                <!-- Online Transfer -->
                <label class="form-check-label w-100 m-0" style="cursor: pointer;">
                    <div class="d-flex align-items-center p-2 rounded border border-primary bg-primary bg-opacity-10 payment-option" id="opt_online_box" style="transition: 0.2s;">
                        <input class="form-check-input mt-0 me-2" type="radio" name="payment_method" id="pay_online" value="bank_transfer" checked onchange="togglePaymentUI()">
                        <div>
                            <div class="fw-bold text-light" style="font-size: 0.9rem;"><i class="fa-solid fa-qrcode text-accent me-1"></i> Scan & Pay</div>
                        </div>
                    </div>
                </label>

                <!-- Account Balance -->
                <label class="form-check-label w-100 m-0" style="cursor: pointer;">
                    <div class="d-flex align-items-center p-2 rounded border border-secondary payment-option" id="opt_balance_box" style="background: rgba(255,255,255,0.02); transition: 0.2s;">
                        <input class="form-check-input mt-0 me-2" type="radio" name="payment_method" id="pay_balance" value="balance" onchange="togglePaymentUI()">
                        <div>
                            <div class="fw-bold text-light" style="font-size: 0.9rem;"><i class="fa-solid fa-wallet text-success me-1"></i> Account Balance</div>
                            <div class="text-secondary" style="font-size: 0.75rem;">Available: <strong class="text-success">Rs <?= number_format($current_user[\'balance\'], 2) ?></strong></div>
                        </div>
                    </div>
                </label>
            </div>
            
      </div>
      <div class="modal-footer border-top border-secondary p-3">
        <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" id="submitBtn" class="btn btn-primary px-4 rounded-pill fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Submit Request</button>
      </div>
      </form>
    </div>
  </div>
</div>

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
                <?php if($active_method === \'bank\'): ?>
                    <div class="bg-dark border border-secondary rounded p-3 text-start mx-auto shadow mb-2" style="max-width: 320px;">
                        <div class="text-center mb-2 pb-2 border-bottom border-secondary border-opacity-50">
                            <i class="fa-solid fa-building-columns fa-2x text-success mb-1"></i>
                            <h5 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_display_name) ?></h5>
                        </div>
                        <div class="mb-2">
                            <div class="text-secondary small text-uppercase" style="font-size: 0.7rem;">Account Title</div>
                            <div class="fw-bold text-light fs-6"><?= htmlspecialchars($gateway_account_name) ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-secondary small text-uppercase" style="font-size: 0.7rem;">Account Number</div>
                            <div class="fw-bold text-success fs-5" style="letter-spacing: 1px;"><?= htmlspecialchars($account_number_str) ?></div>
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
                    <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #10b981;">
                        <img id="fund_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=SB-LINK-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
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
          <button type="submit" class="btn btn-sm btn-success px-4 rounded-pill fw-bold"><i class="fa-solid fa-paper-plane me-1"></i> Submit Recharge</button>
        </div>
      </form>
    </div>
  </div>
</div>

';

$c = preg_replace($pattern, $replacement, $c);
file_put_contents($f, $c);
echo "Replaced successfully!\n";
?>
