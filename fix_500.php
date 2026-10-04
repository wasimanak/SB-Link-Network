<?php
$f = 'customer/dashboard.php';
$c = file_get_contents($f);

// 1. Fix onlinePaymentBox
$start = strpos($c, '<div id="onlinePaymentBox" class="text-center mb-3">');
$end = strpos($c, '<div class="text-secondary"', $start);
if ($start !== false && $end !== false) {
    $before = substr($c, 0, $start);
    $after = substr($c, $end);
    
    $new_block1 = '<div id="onlinePaymentBox" class="text-center mb-3">
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
                  ';
                  
    $c = $before . $new_block1 . $after;
}

// 2. Fix Add Funds Modal
$start2 = strpos($c, '<div class="text-center mb-3">', strpos($c, 'Recharge Wallet'));
$end2 = strpos($c, '</div>', strpos($c, 'qr_code_img" src="" alt="Dynamic QR"', $start2) + 20) + 6;
// Wait, the Add Funds modal has `fund_qr_code`.
$start2 = strpos($c, '<div class="text-center mb-3">', strpos($c, '<div class="modal-body p-3">'));
$end2 = strpos($c, '<div class="mb-2">', $start2);

if ($start2 !== false && $end2 !== false) {
    $before2 = substr($c, 0, $start2);
    $after2 = substr($c, $end2);
    
    $new_block2 = '<div class="text-center mb-3">
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
              ';
              
    $c = $before2 . $new_block2 . $after2;
}

file_put_contents($f, $c);
echo "Fixed 500 error in customer/dashboard.php\n";
?>
