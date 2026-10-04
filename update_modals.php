<?php
$files = [
    'customer/dashboard.php',
    'dealer/dashboard.php'
];

$new_php_logic = <<<EOD
// Fetch Active Payment Gateway or Bank Account
\$active_method = null;
\$gateway_display_name = '';
\$gateway_account_name = '';
\$account_number_str = '';
\$iban_str = '';

// Check API Gateway first
\$gwStmt = \$pdo->prepare("SELECT gateway_name, account_name FROM payment_gateways WHERE client_id = ? AND status = 'active' LIMIT 1");
\$gwStmt->execute([\$client_id]);
if (\$gw = \$gwStmt->fetch()) {
    \$active_method = 'api';
    \$gateway_display_name = \$gw['gateway_name'];
    \$gateway_account_name = \$gw['account_name'] ?: (\$operator_info['company_name'] ?? 'SB-Link Network');
} else {
    // Check Manual Bank
    // For safety if table doesn't exist yet, we check
    try {
        \$bkStmt = \$pdo->prepare("SELECT bank_name, account_title, account_number, iban FROM operator_bank_accounts WHERE client_id = ? AND status = 'active' LIMIT 1");
        \$bkStmt->execute([\$client_id]);
        if (\$bk = \$bkStmt->fetch()) {
            \$active_method = 'bank';
            \$gateway_display_name = \$bk['bank_name'];
            \$gateway_account_name = \$bk['account_title'];
            \$account_number_str = \$bk['account_number'];
            \$iban_str = \$bk['iban'];
        }
    } catch(PDOException \$e) {}
}

if (!\$active_method) {
    \$gateway_display_name = 'No Active Payment Method';
    \$gateway_account_name = \$operator_info['company_name'] ?? 'SB-Link Network';
}
EOD;

foreach($files as $f) {
    if (!file_exists($f)) continue;
    $c = file_get_contents($f);
    
    // Replace old fetching logic
    $old_php_logic = "// Fetch Active Payment Gateway
\$gwStmt = \$pdo->prepare(\"SELECT gateway_name, account_name FROM payment_gateways WHERE client_id = ? AND status = 'active' LIMIT 1\");
\$gwStmt->execute([\$client_id]);
\$active_gateway = \$gwStmt->fetch();
\$gateway_display_name = \$active_gateway ? \$active_gateway['gateway_name'] : 'Meezan Bank';
\$gateway_account_name = \$active_gateway && \$active_gateway['account_name'] ? \$active_gateway['account_name'] : (\$operator_info['company_name'] ?: 'SB-Link Network');";

    if (strpos($c, "SELECT gateway_name, account_name FROM payment_gateways") !== false) {
        // Regex replace
        $c = preg_replace('/\/\/ Fetch Active Payment Gateway.*?(?=\?>)/s', $new_php_logic . "\n", $c);
    }
    
    // For rendering in modals, we need to show Bank Info if it's a bank.
    // Replace the QR code section in the HTML (which contains h6 and div for Bank Name)
    
    $old_html_search_1 = '<div class="text-center mb-3">
                  <h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                  <div class="text-secondary small mb-2">Bank Name: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>

                  <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #10b981;">
                      <img id="fund_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=SB-LINK-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
                  </div>
              </div>';
              
    $new_html_1 = '<div class="text-center mb-3">
                  <h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                  <div class="text-secondary small mb-2"><?= $active_method === \'bank\' ? \'Bank Name\' : \'Provider\' ?>: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>
                  
                  <?php if($active_method === \'bank\'): ?>
                      <div class="bg-dark border border-secondary rounded p-3 text-start d-inline-block mb-2" style="min-width: 250px;">
                          <div class="small text-secondary mb-1">Account Number:</div>
                          <div class="fw-bold text-success fs-5 mb-2" style="letter-spacing: 1px;"><?= htmlspecialchars($account_number_str) ?></div>
                          <?php if(!empty($iban_str)): ?>
                          <div class="small text-secondary mb-1">IBAN:</div>
                          <div class="fw-bold text-light"><?= htmlspecialchars($iban_str) ?></div>
                          <?php endif; ?>
                      </div>
                  <?php else: ?>
                      <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #10b981;">
                          <img id="fund_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=SB-LINK-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
                      </div>
                  <?php endif; ?>
              </div>';
              
    $c = str_replace($old_html_search_1, $new_html_1, $c);
    
    // For Buy Package modal (onlinePaymentBox)
    $old_html_search_2 = '<h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                  <div class="text-secondary small mb-2">Bank Name: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>

                  <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #3b82f6;">
                      <img id="qr_code_img" src="" alt="Dynamic QR" class="img-fluid rounded" style="width: 140px; height: 140px;">
                  </div>';
                  
    $new_html_2 = '<h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                  <div class="text-secondary small mb-2"><?= $active_method === \'bank\' ? \'Bank Name\' : \'Provider\' ?>: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>
                  
                  <?php if($active_method === \'bank\'): ?>
                      <div class="bg-dark border border-secondary rounded p-3 text-start d-inline-block mb-2" style="min-width: 250px;">
                          <div class="small text-secondary mb-1">Account Number:</div>
                          <div class="fw-bold text-primary fs-5 mb-2" style="letter-spacing: 1px;"><?= htmlspecialchars($account_number_str) ?></div>
                          <?php if(!empty($iban_str)): ?>
                          <div class="small text-secondary mb-1">IBAN:</div>
                          <div class="fw-bold text-light"><?= htmlspecialchars($iban_str) ?></div>
                          <?php endif; ?>
                      </div>
                  <?php else: ?>
                      <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #3b82f6;">
                          <img id="qr_code_img" src="" alt="Dynamic QR" class="img-fluid rounded" style="width: 140px; height: 140px;">
                      </div>
                  <?php endif; ?>';
                  
    $c = str_replace($old_html_search_2, $new_html_2, $c);
    
    // Dealer dashboard "Online Recharge"
    // Find in dealer/dashboard.php
    $old_dealer_search = '<h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                  <div class="text-secondary small mb-2">Bank Name: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>

                  <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #3b82f6;">
                      <img id="dealer_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=DEALER-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
                  </div>';
    
    $new_dealer_html = '<h6 class="text-light fw-bold mb-0"><?= htmlspecialchars($gateway_account_name) ?></h6>
                  <div class="text-secondary small mb-2"><?= $active_method === \'bank\' ? \'Bank Name\' : \'Provider\' ?>: <span class="text-light fw-bold"><?= htmlspecialchars($gateway_display_name) ?></span></div>
                  
                  <?php if($active_method === \'bank\'): ?>
                      <div class="bg-dark border border-secondary rounded p-3 text-start d-inline-block mb-2" style="min-width: 250px;">
                          <div class="small text-secondary mb-1">Account Number:</div>
                          <div class="fw-bold text-primary fs-5 mb-2" style="letter-spacing: 1px;"><?= htmlspecialchars($account_number_str) ?></div>
                          <?php if(!empty($iban_str)): ?>
                          <div class="small text-secondary mb-1">IBAN:</div>
                          <div class="fw-bold text-light"><?= htmlspecialchars($iban_str) ?></div>
                          <?php endif; ?>
                      </div>
                  <?php else: ?>
                      <div class="d-inline-block bg-white p-2 rounded shadow mb-2" style="border: 2px solid #3b82f6;">
                          <img id="dealer_qr_code" src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=DEALER-FUNDS" alt="QR Code" class="img-fluid rounded" style="width: 140px; height: 140px;">
                      </div>
                  <?php endif; ?>';
                  
    $c = str_replace($old_dealer_search, $new_dealer_html, $c);
    
    file_put_contents($f, $c);
    echo "Updated $f\n";
}
echo "Done.\n";
?>
