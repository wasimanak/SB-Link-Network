<?php require_once 'header.php'; 

// Fetch pending requests
$reqStmt = $pdo->prepare("SELECT * FROM package_requests WHERE subscriber_id = ? AND status = 'pending' LIMIT 1");
$reqStmt->execute([$current_user['id']]);
$pending_request = $reqStmt->fetch();
$has_pending = $pending_request ? true : false;

// Fetch live session
$radStmt = $pdo->prepare("SELECT * FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1");
$radStmt->execute([$current_user['username']]);
$live_session = $radStmt->fetch();

// Fetch total usage
$usageStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down FROM radacct WHERE username = ?");
$usageStmt->execute([$current_user['username']]);
$usage = $usageStmt->fetch();

$total_bytes = ($usage['up'] ?? 0) + ($usage['down'] ?? 0);
if ($total_bytes >= 1073741824) {
    $used_volume_str = round($total_bytes / 1073741824, 2) . " GB";
} elseif ($total_bytes >= 1048576) {
    $used_volume_str = round($total_bytes / 1048576, 2) . " MB";
} elseif ($total_bytes > 0) {
    $used_volume_str = round($total_bytes / 1024, 2) . " KB";
} else {
    $used_volume_str = "0 MB";
}

// Expiry logic
$expiry_badge = "<span class='badge bg-secondary'>N/A</span>";
$expiry_text = "No Expiry Set";
if ($current_user['expiry_date']) {
    $exp_time = strtotime($current_user['expiry_date']);
    $now = time();
    $diff = $exp_time - $now;
    
    if ($diff > 0) {
        $days = floor($diff / 86400);
        $hours = floor(($diff % 86400) / 3600);
        $expiry_badge = "<span class='badge bg-success'>$days Days, $hours Hrs</span>";
        $expiry_text = date('d M Y, h:i A', $exp_time);
    } else {
        $expiry_badge = "<span class='badge bg-danger'>Expired</span>";
        $expiry_text = "Expired on " . date('d M Y', $exp_time);
    }
}

// Fetch available packages for this operator and global superadmin packages
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ? OR client_id = 0");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll();

// Fetch Operator Details
$opStmt = $pdo->prepare("SELECT company_name, bank_details, qr_code FROM clients WHERE id = ?");
$opStmt->execute([$client_id]);
$operator_info = $opStmt->fetch();
$bank_details = (isset($operator_info['bank_details']) && $operator_info['bank_details']) ? $operator_info['bank_details'] : "Bank details not provided. Please contact operator.";

// Fetch Active Payment Gateway or Bank Account
$active_method = null;
$gateway_display_name = '';
$gateway_account_name = '';
$account_number_str = '';
$iban_str = '';

// Check API Gateway first
$gwStmt = $pdo->prepare("SELECT gateway_name, account_name FROM payment_gateways WHERE client_id = ? AND status = 'active' LIMIT 1");
$gwStmt->execute([$client_id]);
if ($gw = $gwStmt->fetch()) {
    $active_method = 'api';
    $gateway_display_name = $gw['gateway_name'];
    $opCompany = isset($operator_info['company_name']) ? $operator_info['company_name'] : 'SB-Link Network';
    $gateway_account_name = $gw['account_name'] ?: $opCompany;
} else {
    // Check Manual Bank
    // For safety if table doesn't exist yet, we check
    try {
        $bkStmt = $pdo->prepare("SELECT bank_name, account_title, account_number, iban FROM operator_bank_accounts WHERE client_id = ? AND status = 'active' LIMIT 1");
        $bkStmt->execute([$client_id]);
        if ($bk = $bkStmt->fetch()) {
            $active_method = 'bank';
            $gateway_display_name = $bk['bank_name'];
            $gateway_account_name = $bk['account_title'];
            $account_number_str = $bk['account_number'];
            $iban_str = $bk['iban'];
        }
    } catch(PDOException $e) {}
}

if (!$active_method) {
    $gateway_display_name = 'No Active Payment Method';
    $gateway_account_name = isset($operator_info['company_name']) ? $operator_info['company_name'] : 'SB-Link Network';
}
?>

<?php if ($pending_request): ?>
<div class="alert alert-info border-info bg-transparent text-info d-flex align-items-center mb-4">
    <i class="fa-solid fa-circle-info fs-4 me-3"></i>
    <div>
        <strong>Request Pending!</strong> Your package renewal/upgrade request is currently pending operator approval.
    </div>
</div>
<?php endif; ?>

<!-- ISP Premium Hero Section -->
<div class="card bg-dark text-white border-0 shadow-lg mb-4 rounded-4 position-relative overflow-hidden" style="background: linear-gradient(135deg, #1e3a8a 0%, #1e1b4b 100%) !important;">
    <!-- Abstract Shapes / Overlay -->
    <div class="position-absolute top-0 end-0 opacity-25" style="width: 300px; height: 300px; background: radial-gradient(circle, #3b82f6 0%, transparent 70%); transform: translate(30%, -30%);"></div>
    <div class="position-absolute bottom-0 start-0 opacity-25" style="width: 200px; height: 200px; background: radial-gradient(circle, #8b5cf6 0%, transparent 70%); transform: translate(-30%, 30%);"></div>
    
    <div class="card-body p-4 p-md-5 position-relative z-1">
        <div class="row align-items-center">
            <div class="col-md-8">
                <span class="badge bg-white text-dark mb-3 px-3 py-2 rounded-pill fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-bolt text-warning me-1"></i> <?= htmlspecialchars($operator_info['company_name'] ?? 'SB-Link') ?> Subscriber</span>
                <h2 class="fw-bold mb-2">Welcome back, <?= htmlspecialchars($current_user['full_name'] ?: $current_user['username']) ?>!</h2>
                <p class="text-white-50 mb-4 mb-md-0" style="font-size: 1.1rem;">Manage your internet package, view data usage, and recharge your account seamlessly.</p>
            </div>
            <div class="col-md-4 text-md-end">
                <div class="d-inline-block p-4 rounded-4 bg-white bg-opacity-10 border border-light border-opacity-25 text-start shadow-sm" style="backdrop-filter: blur(10px); min-width: 200px;">
                    <p class="text-white-50 small mb-1 fw-bold text-uppercase tracking-wider">Current Wallet Balance</p>
                    <h3 class="fw-bold text-white mb-2">Rs <?= number_format($current_user['balance'], 2) ?></h3>
                    <a href="#" data-bs-toggle="modal" data-bs-target="#addFundsModal" class="btn btn-sm btn-light rounded-pill px-4 fw-bold shadow-sm"><i class="fa-solid fa-plus me-1"></i> Add Funds</a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .stat-card-premium { border-radius: 16px; transition: 0.3s; background: #1e1e2d; border: 1px solid rgba(255,255,255,0.05); }
    .stat-card-premium:hover { transform: translateY(-5px); border-color: rgba(59, 130, 246, 0.3); box-shadow: 0 10px 25px rgba(0,0,0,0.4); }
    .stat-icon-wrapper { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; }
</style>

<!-- Metrics Row -->
<div class="row mb-5">
    
    <!-- Account Status -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stat-card-premium p-4 h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="stat-icon-wrapper bg-primary bg-opacity-10 text-primary">
                    <i class="fa-solid fa-satellite-dish"></i>
                </div>
                <div>
                    <?php if($current_user['status'] == 'active'): ?>
                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2 border border-success">Active</span>
                    <?php elseif($current_user['status'] == 'expired'): ?>
                        <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2 border border-danger">Expired</span>
                    <?php else: ?>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3 py-2 border border-secondary">Disabled</span>
                    <?php endif; ?>
                </div>
            </div>
            <h6 class="text-secondary small fw-bold text-uppercase mb-1">Current Package</h6>
            <h4 class="fw-bold text-light mb-1"><?= htmlspecialchars($current_user['package_name'] ?? 'No Package') ?></h4>
            <div class="text-muted small mt-auto pt-2">
                <i class="fa-solid fa-gauge-high me-1 text-accent"></i> <?= htmlspecialchars($current_user['rate_limit'] ?? 'Unlimited Speed') ?>
            </div>
        </div>
    </div>

    <!-- Data Usage -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stat-card-premium p-4 h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="stat-icon-wrapper bg-info bg-opacity-10 text-info">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
            </div>
            <h6 class="text-secondary small fw-bold text-uppercase mb-1">Total Data Used</h6>
            <h4 class="fw-bold text-light mb-1"><?= $used_volume_str ?></h4>
            <div class="mt-auto pt-2">
                <div class="progress" style="height: 6px; background-color: rgba(255,255,255,0.05); border-radius: 10px;">
                    <div class="progress-bar bg-info" style="width: 100%; border-radius: 10px;"></div>
                </div>
                <div class="text-muted small mt-2 d-flex justify-content-between">
                    <span><i class="fa-solid fa-arrow-up text-secondary me-1"></i> <?= round((($usage['up'] ?? 0)/1048576),1) ?> MB</span>
                    <span><i class="fa-solid fa-arrow-down text-accent me-1"></i> <?= round((($usage['down'] ?? 0)/1048576),1) ?> MB</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Expiry Details -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stat-card-premium p-4 h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="stat-icon-wrapper bg-warning bg-opacity-10 text-warning">
                    <i class="fa-regular fa-clock"></i>
                </div>
                <?= $expiry_badge ?>
            </div>
            <h6 class="text-secondary small fw-bold text-uppercase mb-1">Expiration Date</h6>
            <h5 class="fw-bold text-light mb-1" style="font-size: 1.1rem;"><?= $expiry_text ?></h5>
            <div class="text-muted small mt-auto pt-2">
                <i class="fa-solid fa-rotate text-secondary me-1"></i> Auto-renew: <strong>Off</strong>
            </div>
        </div>
    </div>

    <!-- Live Session -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="stat-card-premium p-4 h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="stat-icon-wrapper <?= $live_session ? 'bg-success bg-opacity-10 text-success' : 'bg-secondary bg-opacity-10 text-secondary' ?>">
                    <i class="fa-solid fa-network-wired"></i>
                </div>
                <div>
                    <?php if($live_session): ?>
                        <span class="badge bg-success rounded-pill px-3 py-2"><span class="spinner-grow spinner-grow-sm me-1" style="width:0.5rem; height:0.5rem;"></span>Online</span>
                    <?php else: ?>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3 py-2 border border-secondary">Offline</span>
                    <?php endif; ?>
                </div>
            </div>
            <h6 class="text-secondary small fw-bold text-uppercase mb-1">Connection Status</h6>
            
            <?php if($live_session): ?>
                <h5 class="fw-bold text-light mb-1"><?= htmlspecialchars($live_session['framedipaddress']) ?></h5>
                <div class="text-muted small mt-auto pt-2">
                    <i class="fa-solid fa-laptop text-secondary me-1"></i> MAC: <?= htmlspecialchars($live_session['callingstationid']) ?>
                </div>
            <?php else: ?>
                <h5 class="fw-bold text-muted mb-1">Not Connected</h5>
                <div class="text-muted small mt-auto pt-2">
                    Check your router power.
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ================= PACKAGES SECTION ================= -->
<div id="available-packages" class="d-flex justify-content-between align-items-center mt-4 mb-3 pt-2">
    <h4 class="fw-bold"><i class="fa-solid fa-box-open text-accent me-2"></i> Purchase / Renew Packages</h4>
</div>

<div class="row">
    <?php foreach($packages as $p): 
        $isActive = ($current_user['package_id'] == $p['id']);
    ?>
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="card-ui package-card p-3 text-center h-100 d-flex flex-column <?= $isActive ? 'active-plan' : '' ?>">
            <?php if($isActive): ?>
                <div class="current-badge" style="font-size:0.7rem; padding:3px 10px;">Current Plan</div>
            <?php endif; ?>
            
            <h5 class="fw-bold mt-2 text-light"><?= htmlspecialchars($p['name']) ?></h5>
            <h4 class="text-accent my-2 fw-bold">Rs <?= number_format($p['price'], 2) ?></h4>
            
            <ul class="list-unstyled text-secondary my-3" style="font-size: 0.9rem;">
                <li class="mb-2"><i class="fa-solid fa-gauge me-2 text-light"></i> <?= htmlspecialchars($p['rate_limit'] ?: 'Unlimited Speed') ?></li>
                <li class="mb-2"><i class="fa-regular fa-calendar me-2 text-light"></i> <?= htmlspecialchars($p['validity_days']) ?> Days Validity</li>
                <li><i class="fa-solid fa-infinity me-2 text-light"></i> <?= $p['data_limit_gb'] > 0 ? $p['data_limit_gb'] . ' GB Data' : 'Unlimited Data' ?></li>
            </ul>
            
            <div class="mt-auto pt-3 border-top border-secondary">
                <?php if ($has_pending): ?>
                    <button class="btn btn-secondary btn-sm w-100 rounded-pill" disabled>Request Pending</button>
                <?php else: ?>
                    <button type="button" class="btn btn-accent btn-sm w-100 rounded-pill py-2" onclick="openBuyModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name']) ?>', <?= $p['price'] ?>)">
                        <i class="fa-solid fa-cart-shopping me-1"></i> Buy Now
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Buy Now Modal -->
<div class="modal fade" id="buyModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content bg-dark border border-secondary shadow-lg text-light" style="border-radius: 16px;">
      <div class="modal-header border-bottom border-secondary p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-cart-shopping me-2 text-primary"></i> Confirm Purchase</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <form action="request_action.php" method="POST">
            <input type="hidden" name="action" value="buy_package">
            <input type="hidden" name="package_id" id="modal_pkg_id">
            
            <div class="text-center mb-4">
                <h4 id="modal_pkg_name" class="fw-bold mb-0 text-light">Package Name</h4>
                <h1 class="text-accent fw-bold mt-1 mb-0">Rs <span id="modal_pkg_price">0</span></h1>
            </div>

            <!-- Online Payment Details Box -->
            <div id="onlinePaymentBox" class="text-center mb-3">
                <?php if($active_method === 'bank'): ?>
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
                <input type="hidden" name="payment_reference" id="payment_reference" value="AUTO_<?= time() ?>_<?= $current_user['id'] ?>">
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
                            <div class="text-secondary" style="font-size: 0.75rem;">Available: <strong class="text-success">Rs <?= number_format($current_user['balance'], 2) ?></strong></div>
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
                <?php if($active_method === 'bank'): ?>
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

<script>
function openBuyModal(id, name, price) {
    document.getElementById('modal_pkg_id').value = id;
    document.getElementById('modal_pkg_name').innerText = name;
    document.getElementById('modal_pkg_price').innerText = price.toFixed(2);
    
    // Generate unique QR code URL (using goqr.me or qrserver API)
    // Data inside: user_id | amount | timestamp
    var qrData = encodeURIComponent("PAY_<?= $current_user['id'] ?>_AMT_" + price.toFixed(2) + "_TS_" + Date.now());
    var qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" + qrData;
    var qrElement = document.getElementById('qr_code_img');
    if (qrElement) {
        qrElement.src = qrUrl;
    }
    
    var modal = new bootstrap.Modal(document.getElementById('buyModal'));
    modal.show();
}

function togglePaymentUI() {
    var isOnline = document.getElementById('pay_online').checked;
    
    // UI Styling for the blocks
    var optOnline = document.getElementById('opt_online_box');
    var optBalance = document.getElementById('opt_balance_box');
    
    if (isOnline) {
        // Show Online UI
        document.getElementById('onlinePaymentBox').classList.remove('d-none');
        document.getElementById('payment_reference').setAttribute('required', 'required');
        document.getElementById('submitBtn').innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Submit Request';
        
        // Highlight Online Option
        optOnline.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
        optOnline.classList.remove('border-secondary');
        optOnline.style.background = '';
        
        // Unhighlight Balance Option
        optBalance.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
        optBalance.classList.add('border-secondary');
        optBalance.style.background = 'rgba(255,255,255,0.02)';
        
    } else {
        // Hide Online UI
        document.getElementById('onlinePaymentBox').classList.add('d-none');
        document.getElementById('payment_reference').removeAttribute('required');
        document.getElementById('submitBtn').innerHTML = '<i class="fa-solid fa-bolt me-1"></i> Confirm & Activate';
        
        // Highlight Balance Option
        optBalance.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
        optBalance.classList.remove('border-secondary');
        optBalance.style.background = '';
        
        // Unhighlight Online Option
        optOnline.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
        optOnline.classList.add('border-secondary');
        optOnline.style.background = 'rgba(255,255,255,0.02)';
    }
}
function updateFundQR() {
    var amount = document.getElementById('fund_amount').value || "0";
    var qrData = encodeURIComponent("FUNDS_<?= $current_user['id'] ?>_AMT_" + amount + "_TS_" + Date.now());
    var qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" + qrData;
    document.getElementById('fund_qr_code').src = qrUrl;
}
</script>

<?php require_once 'footer.php'; ?>
