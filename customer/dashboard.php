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
$total_gb = $total_bytes > 0 ? round($total_bytes / 1073741824, 2) : 0.00;

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

// Fetch available packages for this operator
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ?");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll();

// Fetch Operator Bank Details
$opStmt = $pdo->prepare("SELECT bank_details, qr_code FROM clients WHERE id = ?");
$opStmt->execute([$client_id]);
$operator_info = $opStmt->fetch();
$bank_details = $operator_info['bank_details'] ?: "Bank details not provided. Please contact operator.";
?>

<?php if ($pending_request): ?>
<div class="alert alert-info border-info bg-transparent text-info d-flex align-items-center mb-4">
    <i class="fa-solid fa-circle-info fs-4 me-3"></i>
    <div>
        <strong>Request Pending!</strong> Your package renewal/upgrade request is currently pending operator approval.
    </div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Welcome, <?= htmlspecialchars($current_user['full_name'] ?: $current_user['username']) ?>!</h4>
    <h5 class="mb-0 text-accent"><i class="fa-solid fa-wallet"></i> Balance: Rs <?= number_format($current_user['balance'], 2) ?></h5>
</div>

<div class="row">
    <!-- Account Status -->
    <div class="col-md-4 mb-4">
        <div class="card-ui p-4 h-100">
            <h6 class="text-secondary mb-3"><i class="fa-solid fa-id-card text-accent"></i> Account Status</h6>
            <h3 class="fw-bold mb-1"><?= htmlspecialchars($current_user['package_name'] ?? 'No Package') ?></h3>
            <p class="text-secondary mb-4"><?= htmlspecialchars($current_user['rate_limit'] ?? 'Unlimited Speed') ?></p>
            
            <div class="d-flex justify-content-between align-items-center border-top border-secondary pt-3 mt-auto">
                <div class="text-secondary small">Status</div>
                <div>
                    <?php if($current_user['status'] == 'active'): ?>
                        <span class="badge bg-success">Active</span>
                    <?php elseif($current_user['status'] == 'expired'): ?>
                        <span class="badge bg-danger">Expired</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Disabled</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="d-flex justify-content-between align-items-center border-top border-secondary pt-3 mt-3">
                <div class="text-secondary small">Expires In<br><strong class="text-light"><?= $expiry_text ?></strong></div>
                <div><?= $expiry_badge ?></div>
            </div>
        </div>
    </div>

    <!-- Live Connection -->
    <div class="col-md-4 mb-4">
        <div class="card-ui p-4 h-100 d-flex flex-column">
            <h6 class="text-secondary mb-3"><i class="fa-solid fa-network-wired text-accent"></i> Live Connection</h6>
            <?php if ($live_session): 
                $uptime_sec = time() - strtotime($live_session['acctstarttime']);
                $h = floor($uptime_sec / 3600);
                $m = floor(($uptime_sec % 3600) / 60);
            ?>
                <div class="text-center mb-4 mt-auto">
                    <div class="d-inline-block badge-online px-4 py-2 rounded-pill fw-bold mb-2">
                        ONLINE
                    </div>
                    <h5 class="mt-3 mb-0 fw-bold"><?= $h ?>h <?= $m ?>m</h5>
                    <small class="text-secondary">Current Session Uptime</small>
                </div>
                
                <div class="border-top border-secondary pt-3 mt-auto">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-secondary small">IP Address</span>
                        <span class="small font-monospace"><?= htmlspecialchars($live_session['framedipaddress']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary small">MAC Address</span>
                        <span class="small font-monospace"><?= htmlspecialchars($live_session['callingstationid']) ?></span>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center my-auto py-4">
                    <div class="d-inline-block badge-offline px-4 py-2 rounded-pill fw-bold mb-3">
                        <i class="fa-solid fa-circle text-secondary small me-1"></i> OFFLINE
                    </div>
                    <p class="text-secondary mb-0">Router is not connected.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Data Consumption -->
    <div class="col-md-4 mb-4">
        <div class="card-ui p-4 h-100 text-center d-flex flex-column justify-content-center">
            <h6 class="text-secondary text-start mb-4"><i class="fa-solid fa-chart-pie text-accent"></i> Data Consumption</h6>
            
            <div class="position-relative mx-auto my-3" style="width: 150px; height: 150px;">
                <svg viewBox="0 0 36 36" class="w-100 h-100">
                    <path class="text-secondary" stroke-width="3" stroke="currentColor" fill="none"
                        d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" style="opacity: 0.2;" />
                    <path class="text-accent" stroke-dasharray="100, 100" stroke-width="3" stroke-linecap="round" stroke="currentColor" fill="none"
                        d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                </svg>
                <div class="position-absolute top-50 start-50 translate-middle text-center w-100">
                    <h3 class="mb-0 fw-bold"><?= $total_gb ?></h3>
                    <small class="text-secondary">GB Used</small>
                </div>
            </div>
            <p class="text-secondary small mt-3 mb-0">Total volume consumed across all sessions.</p>
        </div>
    </div>
</div>

<!-- ================= PACKAGES SECTION ================= -->
<div class="d-flex justify-content-between align-items-center mt-4 mb-3 border-top border-secondary pt-4">
    <h4><i class="fa-solid fa-box-open text-accent"></i> Purchase / Renew Packages</h4>
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
  <div class="modal-dialog">
    <div class="modal-content bg-dark border-secondary text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title text-accent"><i class="fa-solid fa-cart-shopping"></i> Purchase Package</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form action="request_action.php" method="POST">
        <input type="hidden" name="package_id" id="modal_pkg_id">
        <div class="modal-body p-4">
            
            <div class="text-center mb-4">
                <h5 id="modal_pkg_name" class="fw-bold">Package Name</h5>
                <h3 class="text-accent">Rs <span id="modal_pkg_price">0.00</span></h3>
            </div>

            <div class="mb-4">
                <label class="form-label text-secondary fw-bold">Select Payment Method</label>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="payment_method" id="pay_balance" value="balance" checked onchange="togglePaymentUI()">
                    <label class="form-check-label" for="pay_balance">
                        Use Account Balance (Current: Rs <?= number_format($current_user['balance'], 2) ?>)
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="payment_method" id="pay_online" value="bank_transfer" onchange="togglePaymentUI()">
                    <label class="form-check-label" for="pay_online">
                        Online Transfer (Bank / EasyPaisa / JazzCash)
                    </label>
                </div>
            </div>

            <!-- Online Payment Details Box -->
            <div id="onlinePaymentBox" class="d-none border border-secondary p-3 rounded bg-transparent">
                <h6 class="text-accent mb-3"><i class="fa-solid fa-building-columns"></i> Operator Payment Details</h6>
                <div class="mb-3 text-secondary" style="white-space: pre-wrap; font-size: 0.9rem;"><?= htmlspecialchars($bank_details) ?></div>
                
                <?php if(!empty($operator_info['qr_code'])): ?>
                    <!-- Optional QR Code Display (if URL/Path provided) -->
                    <div class="text-center mb-3">
                        <img src="<?= htmlspecialchars($operator_info['qr_code']) ?>" alt="QR Code" style="max-width: 150px; border-radius: 8px;">
                    </div>
                <?php endif; ?>

                <div class="mb-2">
                    <label class="form-label text-light">Enter Transaction ID / Reference No.</label>
                    <input type="text" name="payment_reference" id="payment_reference" class="form-control bg-dark text-light border-secondary" placeholder="e.g. TID123456789">
                    <small class="text-secondary mt-1 d-block">Operator will verify this transaction before activating the package.</small>
                </div>
            </div>

            <!-- Balance Payment Info -->
            <div id="balancePaymentBox" class="alert alert-info border-info bg-transparent text-info mt-3">
                <i class="fa-solid fa-bolt"></i> Auto-Verification Enabled! Amount will be deducted from your wallet and package activated instantly.
            </div>

        </div>
        <div class="modal-footer border-secondary">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-accent">Confirm Purchase</button>
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
    
    var modal = new bootstrap.Modal(document.getElementById('buyModal'));
    modal.show();
}

function togglePaymentUI() {
    var isOnline = document.getElementById('pay_online').checked;
    
    if (isOnline) {
        document.getElementById('onlinePaymentBox').classList.remove('d-none');
        document.getElementById('payment_reference').setAttribute('required', 'required');
        document.getElementById('balancePaymentBox').classList.add('d-none');
    } else {
        document.getElementById('onlinePaymentBox').classList.add('d-none');
        document.getElementById('payment_reference').removeAttribute('required');
        document.getElementById('balancePaymentBox').classList.remove('d-none');
    }
}
</script>

<?php require_once 'footer.php'; ?>
