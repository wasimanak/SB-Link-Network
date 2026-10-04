<?php
$f1 = 'customer/dashboard.php';
$f2 = 'dealer/dashboard.php';

// -------------------------------------------------------------
// 1. CUSTOMER DASHBOARD - BUY MODAL
// -------------------------------------------------------------
if (file_exists($f1)) {
    $c = file_get_contents($f1);
    
    $buy_old = '<h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                  <div class="text-secondary small mb-2">Bank Name: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>
  
                  <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #3b82f6;">
                      <img id="qr_code_img" src="" alt="Dynamic QR" class="img-fluid rounded" style="width: 140px; height: 140px;">
                  </div>';
                  
    $buy_new = '<?php if($active_method === \'bank\'): ?>
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
                  <?php endif; ?>';
                  
    // Sometimes whitespace mismatches. Let's do a smarter replace.
    // We will find the exact string by extracting it using preg_match if str_replace fails.
    if (strpos($c, 'id="qr_code_img"') !== false && strpos($c, '$active_method === \'bank\'') === false) {
        $c = str_replace($buy_old, $buy_new, $c);
    }
    if (strpos($c, $buy_new) === false) {
        // Fallback robust replace for Buy Modal
        $pattern = '/<h6 class="text-light fw-bold mb-0"><\?= htmlspecialchars\(\$gateway_account_name\) \?><\/h6>.*?<img id="qr_code_img" .*?<\/div>/s';
        $c = preg_replace($pattern, $buy_new, $c);
    }

    // -------------------------------------------------------------
    // CUSTOMER DASHBOARD - FUND MODAL
    // -------------------------------------------------------------
    $fund_old = '<h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                  <div class="text-secondary small mb-2">Bank Name: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>
  
                  <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #10b981;">
                      <img id="fund_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=SB-LINK-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
                  </div>';
                  
    $fund_new = '<?php if($active_method === \'bank\'): ?>
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
                  <?php endif; ?>';
                  
    if (strpos($c, 'id="fund_qr_code"') !== false && strpos($c, $fund_new) === false) {
        $pattern = '/<h6 class="text-light fw-bold mb-0"><\?= htmlspecialchars\(\$gateway_account_name\) \?><\/h6>.*?<img id="fund_qr_code" .*?<\/div>/s';
        $c = preg_replace($pattern, $fund_new, $c);
    }
    
    file_put_contents($f1, $c);
    echo "Fixed customer/dashboard.php\n";
}

// -------------------------------------------------------------
// 2. DEALER DASHBOARD - RECHARGE MODAL
// -------------------------------------------------------------
if (file_exists($f2)) {
    $c = file_get_contents($f2);
    
    $dealer_new = '<?php if($active_method === \'bank\'): ?>
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
                  <?php endif; ?>';
                  
    if (strpos($c, 'id="dealer_qr_code"') !== false && strpos($c, $dealer_new) === false) {
        $pattern = '/<h6 class="text-light fw-bold mb-0"><\?= htmlspecialchars\(\$gateway_account_name\) \?><\/h6>.*?<img id="dealer_qr_code" .*?<\/div>/s';
        $c = preg_replace($pattern, $dealer_new, $c);
    }
    
    file_put_contents($f2, $c);
    echo "Fixed dealer/dashboard.php\n";
}
?>
