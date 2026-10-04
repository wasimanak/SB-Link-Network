<?php
$files = [
    'customer/dashboard.php',
    'dealer/dashboard.php'
];

foreach($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    
    // Find the current block we injected (for addFundsModal / Buy Package / Dealer Recharge)
    // We will use a regex to match the entire <div class="text-center mb-3"> ... </div> block
    // Because we used it in multiple places, we'll replace all occurrences that match our previous structure.

    $pattern = '/<div class="text-center mb-3">\s*<h6 class="text-light fw-bold mb-0"><\?= htmlspecialchars\(\$gateway_account_name\) \?><\/h6>\s*<div class="text-secondary small mb-2"><\?= \$active_method === \'bank\' \? \'Bank Name\' : \'Provider\' \?>: <span class="text-light fw-bold"><\?= htmlspecialchars\(\$gateway_display_name\) \?><\/span><\/div>.*?<\/div>/s';

    // The new block with the highly professional Bank details layout
    $new_html = '<div class="text-center mb-3">
                  <?php if($active_method === \'bank\'): ?>
                      <div class="bg-dark border border-secondary rounded p-4 text-start mx-auto shadow" style="max-width: 320px;">
                          <div class="text-center mb-3 pb-3 border-bottom border-secondary border-opacity-50">
                              <i class="fa-solid fa-building-columns fa-2x text-success mb-2"></i>
                              <h5 class="text-light fw-bold mb-0" style="letter-spacing: 0.5px;"><?= htmlspecialchars($gateway_display_name) ?></h5>
                          </div>
                          
                          <div class="mb-3">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">Account Title</div>
                              <div class="fw-bold text-light fs-5"><?= htmlspecialchars($gateway_account_name) ?></div>
                          </div>
                          
                          <div class="mb-3">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">Account Number</div>
                              <div class="fw-bold text-success fs-4" style="letter-spacing: 1px;"><?= htmlspecialchars($account_number_str) ?></div>
                          </div>
                          
                          <?php if(!empty($iban_str)): ?>
                          <div class="mb-0">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">IBAN</div>
                              <div class="fw-bold text-info" style="font-family: monospace; font-size: 1.1rem; letter-spacing: 1px;"><?= htmlspecialchars($iban_str) ?></div>
                          </div>
                          <?php endif; ?>
                      </div>
                  <?php else: ?>
                      <h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                      <div class="text-secondary small mb-2">Provider: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>
                      
                      <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #3b82f6;">
                          <img id="qr_code_img" src="" alt="Dynamic QR" class="img-fluid rounded fund-qr-target" style="width: 140px; height: 140px;">
                      </div>
                  <?php endif; ?>
              </div>';
              
    // Wait, in our previous script we had `id="fund_qr_code"` for the Add Funds modal, `id="qr_code_img"` for the Buy Package modal, and `id="dealer_qr_code"` for the Dealer modal.
    // If I replace blindly, the JS QR updater will break. I must preserve the IDs!
    // I will write 3 separate specific replaces.
    
    // 1. Customer - Buy Package (qr_code_img)
    $pattern_buy = '/<h6 class="text-light fw-bold mb-0"><\?= htmlspecialchars\(\$gateway_account_name\) \?><\/h6>\s*<div class="text-secondary small mb-2"><\?= \$active_method === \'bank\' \? \'Bank Name\' : \'Provider\' \?>: <span class="text-light fw-bold"><\?= htmlspecialchars\(\$gateway_display_name\) \?><\/span><\/div>\s*<\?php if\(\$active_method === \'bank\'\): \?>.*?<\?php else: \?>\s*<div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #3b82f6;">\s*<img id="qr_code_img" src="" alt="Dynamic QR" class="img-fluid rounded" style="width: 140px; height: 140px;">\s*<\/div>\s*<\?php endif; \?>/s';
    
    $new_buy = '<?php if($active_method === \'bank\'): ?>
                      <div class="bg-dark border border-secondary rounded p-4 text-start mx-auto shadow" style="max-width: 320px;">
                          <div class="text-center mb-3 pb-3 border-bottom border-secondary border-opacity-50">
                              <i class="fa-solid fa-building-columns fa-2x text-success mb-2"></i>
                              <h5 class="text-light fw-bold mb-0" style="letter-spacing: 0.5px;"><?= htmlspecialchars($gateway_display_name) ?></h5>
                          </div>
                          <div class="mb-3">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">Account Title</div>
                              <div class="fw-bold text-light fs-5"><?= htmlspecialchars($gateway_account_name) ?></div>
                          </div>
                          <div class="mb-3">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">Account Number</div>
                              <div class="fw-bold text-success fs-4" style="letter-spacing: 1px;"><?= htmlspecialchars($account_number_str) ?></div>
                          </div>
                          <?php if(!empty($iban_str)): ?>
                          <div class="mb-0">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">IBAN</div>
                              <div class="fw-bold text-info" style="font-family: monospace; font-size: 1.1rem; letter-spacing: 1px;"><?= htmlspecialchars($iban_str) ?></div>
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
                  
    $c = preg_replace($pattern_buy, $new_buy, $c);

    // 2. Customer - Add Funds (fund_qr_code)
    $pattern_fund = '/<div class="text-center mb-3">\s*<h6 class="text-light fw-bold mb-0"><\?= htmlspecialchars\(\$gateway_account_name\) \?><\/h6>\s*<div class="text-secondary small mb-2"><\?= \$active_method === \'bank\' \? \'Bank Name\' : \'Provider\' \?>: <span class="text-light fw-bold"><\?= htmlspecialchars\(\$gateway_display_name\) \?><\/span><\/div>\s*<\?php if\(\$active_method === \'bank\'\): \?>.*?<\?php else: \?>\s*<div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #10b981;">\s*<img id="fund_qr_code" src="https:\/\/api\.qrserver\.com\/v1\/create-qr-code\/\?size=140x140&data=SB-LINK-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">\s*<\/div>\s*<\?php endif; \?>\s*<\/div>/s';
    
    $new_fund = '<div class="text-center mb-3">
                  <?php if($active_method === \'bank\'): ?>
                      <div class="bg-dark border border-secondary rounded p-4 text-start mx-auto shadow" style="max-width: 320px;">
                          <div class="text-center mb-3 pb-3 border-bottom border-secondary border-opacity-50">
                              <i class="fa-solid fa-building-columns fa-2x text-success mb-2"></i>
                              <h5 class="text-light fw-bold mb-0" style="letter-spacing: 0.5px;"><?= htmlspecialchars($gateway_display_name) ?></h5>
                          </div>
                          <div class="mb-3">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">Account Title</div>
                              <div class="fw-bold text-light fs-5"><?= htmlspecialchars($gateway_account_name) ?></div>
                          </div>
                          <div class="mb-3">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">Account Number</div>
                              <div class="fw-bold text-success fs-4" style="letter-spacing: 1px;"><?= htmlspecialchars($account_number_str) ?></div>
                          </div>
                          <?php if(!empty($iban_str)): ?>
                          <div class="mb-0">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">IBAN</div>
                              <div class="fw-bold text-info" style="font-family: monospace; font-size: 1.1rem; letter-spacing: 1px;"><?= htmlspecialchars($iban_str) ?></div>
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
              </div>';
              
    $c = preg_replace($pattern_fund, $new_fund, $c);

    // 3. Dealer - Recharge (dealer_qr_code)
    $pattern_dealer = '/<h6 class="text-light fw-bold mb-0"><\?= htmlspecialchars\(\$gateway_account_name\) \?><\/h6>\s*<div class="text-secondary small mb-2"><\?= \$active_method === \'bank\' \? \'Bank Name\' : \'Provider\' \?>: <span class="text-light fw-bold"><\?= htmlspecialchars\(\$gateway_display_name\) \?><\/span><\/div>\s*<\?php if\(\$active_method === \'bank\'\): \?>.*?<\?php else: \?>\s*<div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #3b82f6;">\s*<img id="dealer_qr_code" src="https:\/\/api\.qrserver\.com\/v1\/create-qr-code\/\?size=140x140&data=DEALER-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">\s*<\/div>\s*<\?php endif; \?>/s';
    
    $new_dealer = '<?php if($active_method === \'bank\'): ?>
                      <div class="bg-dark border border-secondary rounded p-4 text-start mx-auto shadow" style="max-width: 320px;">
                          <div class="text-center mb-3 pb-3 border-bottom border-secondary border-opacity-50">
                              <i class="fa-solid fa-building-columns fa-2x text-success mb-2"></i>
                              <h5 class="text-light fw-bold mb-0" style="letter-spacing: 0.5px;"><?= htmlspecialchars($gateway_display_name) ?></h5>
                          </div>
                          <div class="mb-3">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">Account Title</div>
                              <div class="fw-bold text-light fs-5"><?= htmlspecialchars($gateway_account_name) ?></div>
                          </div>
                          <div class="mb-3">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">Account Number</div>
                              <div class="fw-bold text-success fs-4" style="letter-spacing: 1px;"><?= htmlspecialchars($account_number_str) ?></div>
                          </div>
                          <?php if(!empty($iban_str)): ?>
                          <div class="mb-0">
                              <div class="text-secondary small text-uppercase" style="font-size: 0.7rem; letter-spacing: 1px;">IBAN</div>
                              <div class="fw-bold text-info" style="font-family: monospace; font-size: 1.1rem; letter-spacing: 1px;"><?= htmlspecialchars($iban_str) ?></div>
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
                  
    $c = preg_replace($pattern_dealer, $new_dealer, $c);

    file_put_contents($f, $c);
    echo "Redesigned $f\n";
}
echo "Done.\n";
?>
