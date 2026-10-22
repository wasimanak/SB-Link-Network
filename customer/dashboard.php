<?php 
require_once 'header.php'; 

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
$expiry_badge = "<span class='badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-1'>N/A</span>";
$expiry_text = "No Expiry Set";
$is_expired = false;
$days_left = 0;
if ($current_user['expiry_date']) {
    $exp_time = strtotime($current_user['expiry_date']);
    $now = time();
    $diff = $exp_time - $now;
    
    if ($diff > 0) {
        $days_left = floor($diff / 86400);
        $hours = floor(($diff % 86400) / 3600);
        $expiry_badge = "<span class='badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold'><i class='fa-regular fa-clock me-1'></i>{$days_left}d {$hours}h Left</span>";
        $expiry_text = date('d M Y, h:i A', $exp_time);
    } else {
        $is_expired = true;
        $expiry_badge = "<span class='badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 fw-bold'><i class='fa-solid fa-triangle-exclamation me-1'></i>Expired</span>";
        $expiry_text = "Expired on " . date('d M Y', $exp_time);
    }
}
if (($current_user['status'] ?? '') === 'expired') {
    $is_expired = true;
}

// Fetch available packages for this operator and global superadmin packages
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ? OR client_id = 0");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll();

// Fetch Operator Details
$opStmt = $pdo->prepare("SELECT company_name, phone, email, address, bank_details, qr_code FROM clients WHERE id = ?");
$opStmt->execute([$client_id]);
$operator_info = $opStmt->fetch();
$bank_details = (isset($operator_info['bank_details']) && $operator_info['bank_details']) ? $operator_info['bank_details'] : "Bank details not provided. Please contact operator.";

// Check Safepay Payment Gateway
require_once '../config/safepay_helper.php';
$safepay_cfg = get_safepay_config($pdo, $client_id);
$has_safepay = ($safepay_cfg && !empty($safepay_cfg['api_key']) && !empty($safepay_cfg['api_secret']) && ($safepay_cfg['status'] ?? '') === 'active');

// Auto-Sync pending Safepay transactions if any
$synced_payments = [];
if ($has_safepay && !empty($current_user['id'])) {
    try {
        $synced_payments = sync_pending_safepay_payments($pdo, $client_id, (int)$current_user['id']);
        if (!empty($synced_payments)) {
            // Refresh current user data since balance / expiry might have updated
            $uStmt = $pdo->prepare("SELECT s.*, p.name as package_name, p.price as package_price, p.validity_days, p.rate_limit, pl.pool_name 
                                    FROM subscribers s 
                                    LEFT JOIN packages p ON s.package_id = p.id 
                                    LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
                                    WHERE s.id = ?");
            $uStmt->execute([$current_user['id']]);
            $current_user = $uStmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

// Fetch Active Bank Account or Operator Bank Details
$active_method = 'bank';
$gateway_display_name = '';
$gateway_account_name = '';
$account_number_str = '';
$iban_str = '';

// Check operator_bank_accounts first
try {
    $bkStmt = $pdo->prepare("SELECT bank_name, account_title, account_number, iban FROM operator_bank_accounts WHERE client_id = ? AND status = 'active' LIMIT 1");
    $bkStmt->execute([$client_id]);
    $bk = $bkStmt->fetch();
    if (!$bk) {
        $bkStmt2 = $pdo->prepare("SELECT bank_name, account_title, account_number, iban FROM operator_bank_accounts WHERE client_id = ? ORDER BY id DESC LIMIT 1");
        $bkStmt2->execute([$client_id]);
        $bk = $bkStmt2->fetch();
    }
    if ($bk) {
        $gateway_display_name = $bk['bank_name'];
        $gateway_account_name = $bk['account_title'];
        $account_number_str = $bk['account_number'];
        $iban_str = $bk['iban'];
    }
} catch(PDOException $e) {}

// Fallback to clients table bank_details or company name
if (empty($account_number_str) && !empty($operator_info['bank_details'])) {
    $gateway_display_name = 'Bank Transfer / EasyPaisa / JazzCash';
    $gateway_account_name = $operator_info['company_name'] ?? 'ISP Provider';
    $account_number_str = $operator_info['bank_details'];
}

if (empty($gateway_display_name)) {
    $gateway_display_name = 'Bank Transfer / Online Payment';
    $gateway_account_name = $operator_info['company_name'] ?? 'ISP Provider';
}
?>

<script>
    // Harmonize Bootstrap Theme to clean modern mode
    document.documentElement.setAttribute('data-bs-theme', 'light');
</script>

<style>
    :root {
        --primary-blue: #2563eb;
        --primary-dark: #1e40af;
        --surface-bg: #f8fafc;
        --card-border: #eef2f6;
        --text-dark: #0f172a;
        --text-muted: #64748b;
    }

    body {
        background-color: var(--surface-bg) !important;
        color: var(--text-dark) !important;
        font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
    }

    /* Modern Navbar Override */
    .navbar {
        background: #ffffff !important;
        border-bottom: 1px solid #e2e8f0 !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03) !important;
    }
    .navbar-brand {
        color: #1e3a8a !important;
        font-weight: 800 !important;
        letter-spacing: -0.5px;
    }
    .navbar .nav-link {
        color: #475569 !important;
        font-weight: 500;
        border-radius: 10px;
        padding: 8px 14px !important;
        transition: all 0.2s ease;
    }
    .navbar .nav-link:hover, .navbar .nav-link.active {
        color: #2563eb !important;
        background: #eff6ff !important;
    }

    /* Hero Banner */
    .hero-banner-card {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 60%, #3b82f6 100%);
        border-radius: 24px;
        box-shadow: 0 10px 30px rgba(37, 99, 235, 0.18);
        border: 1px solid rgba(255, 255, 255, 0.15);
        color: #ffffff;
        position: relative;
        overflow: hidden;
    }
    .hero-banner-decor-1 {
        position: absolute;
        top: -60px;
        right: -60px;
        width: 260px;
        height: 260px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
        pointer-events: none;
    }
    .hero-banner-decor-2 {
        position: absolute;
        bottom: -40px;
        left: 20%;
        width: 180px;
        height: 180px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(96, 165, 250, 0.2) 0%, transparent 70%);
        pointer-events: none;
    }
    .hero-balance-box {
        background: rgba(255, 255, 255, 0.12);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid rgba(255, 255, 255, 0.25);
        border-radius: 20px;
        padding: 20px 24px;
        transition: transform 0.2s ease;
    }
    .hero-balance-box:hover {
        transform: translateY(-2px);
    }

    /* Metric Stat Cards */
    .stat-pill-card {
        background: #ffffff;
        border: 1px solid var(--card-border);
        border-radius: 20px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.02);
        height: 100%;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-pill-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
    }
    .stat-pill-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }
    .stat-pill-val {
        font-size: 1.25rem;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.2;
        margin-bottom: 2px;
    }
    .stat-pill-lbl {
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 4px;
    }

    /* Packages Modern Card */
    .modern-pkg-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 22px;
        padding: 24px 20px;
        transition: all 0.25s ease;
        position: relative;
        display: flex;
        flex-direction: column;
        height: 100%;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
    }
    .modern-pkg-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 30px rgba(0,0,0,0.08);
        border-color: #cbd5e1;
    }
    .modern-pkg-card.active-plan {
        border: 2px solid #2563eb !important;
        background: linear-gradient(180deg, #ffffff 0%, #f0f7ff 100%);
        box-shadow: 0 8px 25px rgba(37, 99, 235, 0.12);
    }
    .plan-badge-top {
        position: absolute;
        top: -12px;
        right: 20px;
        background: #2563eb;
        color: #ffffff;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.5px;
        padding: 4px 14px;
        border-radius: 20px;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
    }
    .plan-feature-item {
        padding: 8px 0;
        font-size: 0.88rem;
        color: #475569;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .plan-feature-item i {
        color: #2563eb;
        width: 18px;
        text-align: center;
    }

    /* Modern Modals */
    .modal-content.clean-modal {
        background: #ffffff !important;
        color: #0f172a !important;
        border-radius: 24px !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 20px 60px rgba(0,0,0,0.15) !important;
    }
    .clean-modal .modal-header {
        border-bottom: 1px solid #f1f5f9;
        padding: 20px 24px;
    }
    .clean-modal .modal-footer {
        border-top: 1px solid #f1f5f9;
        padding: 16px 24px;
    }
    .bank-box-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 18px;
    }
    .payment-option-pill {
        border: 1.5px solid #e2e8f0;
        border-radius: 14px;
        padding: 12px 16px;
        cursor: pointer;
        transition: all 0.2s ease;
        background: #ffffff;
    }
    .payment-option-pill:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
    }
    .payment-option-pill.active {
        border-color: #2563eb;
        background: #eff6ff;
    }

    /* Copy Button */
    .btn-copy-chip {
        font-size: 0.72rem;
        padding: 2px 8px;
        border-radius: 6px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #475569;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-copy-chip:hover {
        background: #f1f5f9;
        color: #0f172a;
    }

    /* Footer modern look */
    footer.bg-black {
        background: #0f172a !important;
        border-top: 1px solid #1e293b !important;
    }
</style>

<!-- Pending Request Banner -->
<?php if ($pending_request): ?>
<div class="alert alert-warning border-0 rounded-4 shadow-sm d-flex align-items-center mb-4 p-3 bg-warning-subtle text-warning-emphasis">
    <div class="rounded-circle bg-warning text-white d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px; flex-shrink: 0;">
        <i class="fa-solid fa-clock-rotate-left fs-5"></i>
    </div>
    <div class="flex-grow-1">
        <h6 class="fw-bold mb-0">Package Request Pending Approval</h6>
        <div class="small text-muted">Your package renewal/upgrade request is currently awaiting operator approval. You will receive service as soon as it is processed.</div>
    </div>
    <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold">Under Review</span>
</div>
<?php endif; ?>

<!-- Expired Account Alert Banner -->
<?php if ($is_expired || (isset($_GET['msg']) && $_GET['msg'] === 'expired') || isset($_GET['expired'])): ?>
<div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4 p-3 p-md-4 bg-danger-subtle text-danger-emphasis">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle bg-danger text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px; flex-shrink: 0;">
                <i class="fa-solid fa-triangle-exclamation fs-4"></i>
            </div>
            <div>
                <h5 class="fw-bold mb-1 text-danger" style="letter-spacing: -0.3px;">Aap Ka Internet Package Expire Ho Chuka Hai!</h5>
                <div class="small text-secondary">
                    Barah-e-Karam apna bill ada karein ya wallet balance recharge karein taake aap ka internet foran bahaal (activate) ho sakay.
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <button type="button" data-bs-toggle="modal" data-bs-target="#addFundsModal" class="btn btn-danger rounded-pill px-4 py-2 fw-bold shadow-sm">
                <i class="fa-solid fa-wallet me-1"></i> Pay Bill / Add Funds
            </button>
            <a href="#available-packages" class="btn btn-outline-danger rounded-pill px-3 py-2 fw-semibold">
                <i class="fa-solid fa-bolt me-1"></i> Renew Package
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($synced_payments)): ?>
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-4 mb-4 py-3 px-4" role="alert" style="background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);">
    <div class="d-flex align-items-center gap-3">
        <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 44px; height: 44px; min-width: 44px;">
            <i class="fa-solid fa-check fs-5"></i>
        </div>
        <div>
            <h6 class="fw-bold mb-1 text-success-emphasis">Online Payment Verified & Activated!</h6>
            <div class="small text-success-emphasis">
                <?php foreach ($synced_payments as $sp): ?>
                    Your payment of <strong>Rs. <?= number_format($sp['amount'], 0) ?></strong> (Order #<?= htmlspecialchars($sp['order_id']) ?>) was confirmed with Safepay. 
                    <?= !empty($sp['package_renewed']) ? 'Your internet package (<strong>' . htmlspecialchars($sp['package_name']) . '</strong>) has been renewed and connection re-activated!' : 'Your wallet balance has been credited!' ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Hero Section -->
<div class="hero-banner-card p-4 p-md-5 mb-4">
    <div class="hero-banner-decor-1"></div>
    <div class="hero-banner-decor-2"></div>
    
    <div class="row align-items-center position-relative z-1 g-4">
        <div class="col-lg-8">
            <div class="d-inline-flex align-items-center gap-2 bg-white px-3 py-1 rounded-pill mb-3 shadow-sm" style="font-size: 0.78rem;">
                <i class="fa-solid fa-bolt text-warning"></i>
                <span class="fw-bold text-dark" style="color: #000000 !important;"><?= htmlspecialchars($operator_info['company_name'] ?? BRAND_NAME) ?> Subscriber</span>
            </div>
            <h1 class="fw-bold mb-2 text-white" style="font-size: clamp(1.6rem, 3vw, 2.3rem); letter-spacing: -0.5px;">
                Welcome back, <?= htmlspecialchars($current_user['full_name'] ?: $current_user['username']) ?>!
            </h1>
            <p class="text-white-50 mb-4 mb-lg-0" style="font-size: 1.05rem; max-width: 600px;">
                Monitor your internet connection speed, track monthly data consumption, and recharge your account with just one click.
            </p>
        </div>
        
        <div class="col-lg-4 text-lg-end">
            <div class="hero-balance-box d-inline-block text-start shadow-sm" style="min-width: 240px;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-white-50 small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Wallet Balance</span>
                    <i class="fa-solid fa-wallet text-white-50"></i>
                </div>
                <div class="fw-bold text-white mb-3" style="font-size: 1.9rem; line-height: 1;">
                    Rs <?= number_format($current_user['balance'], 0) ?>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" data-bs-toggle="modal" data-bs-target="#addFundsModal" class="btn btn-light btn-sm rounded-pill px-3 fw-bold shadow-sm flex-grow-1 text-primary">
                        <i class="fa-solid fa-plus me-1"></i> Add Funds
                    </button>
                    <a href="#available-packages" class="btn btn-outline-light btn-sm rounded-pill px-3 fw-semibold">
                        <i class="fa-solid fa-box-open me-1"></i> Plans
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 4 Key Stat Cards Row -->
<div class="row g-3 mb-5">
    
    <!-- 1. Current Package -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #eff6ff; color: #2563eb;">
                <i class="fa-solid fa-satellite-dish"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-pill-lbl">Current Plan</div>
                    <?php if($current_user['status'] == 'active'): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1" style="font-size: 0.68rem;">Active</span>
                    <?php elseif($current_user['status'] == 'expired'): ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1" style="font-size: 0.68rem;">Expired</span>
                    <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1" style="font-size: 0.68rem;">Disabled</span>
                    <?php endif; ?>
                </div>
                <div class="stat-pill-val text-truncate" title="<?= htmlspecialchars($current_user['package_name'] ?? 'No Package') ?>">
                    <?= htmlspecialchars($current_user['package_name'] ?? 'No Package') ?>
                </div>
                <div class="text-muted small mt-1 d-flex align-items-center gap-1" style="font-size: 0.78rem;">
                    <i class="fa-solid fa-gauge-high text-primary"></i>
                    <span><?= htmlspecialchars($current_user['rate_limit'] ?? 'Unlimited Speed') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. Data Usage -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #f0fdf4; color: #16a34a;">
                <i class="fa-solid fa-chart-pie"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="stat-pill-lbl">Data Consumed</div>
                <div class="stat-pill-val">
                    <?= $used_volume_str ?>
                </div>
                <div class="mt-2">
                    <div class="progress" style="height: 5px; background-color: #f1f5f9; border-radius: 10px;">
                        <div class="progress-bar bg-success" style="width: 100%; border-radius: 10px;"></div>
                    </div>
                    <div class="text-muted mt-1 d-flex justify-content-between" style="font-size: 0.72rem;">
                        <span><i class="fa-solid fa-arrow-up text-primary me-1"></i><?= round((($usage['up'] ?? 0)/1048576),1) ?> MB</span>
                        <span><i class="fa-solid fa-arrow-down text-success me-1"></i><?= round((($usage['down'] ?? 0)/1048576),1) ?> MB</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Expiry Details -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: #fff1f2; color: #e11d48;">
                <i class="fa-regular fa-clock"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-pill-lbl">Expiry Date</div>
                </div>
                <div class="stat-pill-val text-truncate" style="font-size: 1.05rem;" title="<?= $expiry_text ?>">
                    <?= $expiry_text ?>
                </div>
                <div class="mt-1">
                    <?= $expiry_badge ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. Live Connection Session -->
    <div class="col-xl-3 col-md-6">
        <div class="stat-pill-card">
            <div class="stat-pill-icon" style="background: <?= $live_session ? '#eff6ff' : '#f8fafc' ?>; color: <?= $live_session ? '#2563eb' : '#94a3b8' ?>;">
                <i class="fa-solid fa-network-wired"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="stat-pill-lbl">Connection</div>
                    <?php if($live_session): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1" style="font-size: 0.68rem;">
                            <span class="spinner-grow spinner-grow-sm me-1" style="width:0.4rem; height:0.4rem;"></span>Online
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-1" style="font-size: 0.68rem;">Offline</span>
                    <?php endif; ?>
                </div>
                
                <?php if($live_session): ?>
                    <div class="stat-pill-val text-truncate" style="font-size: 1.05rem; font-family: monospace;">
                        <?= htmlspecialchars($live_session['framedipaddress']) ?>
                    </div>
                    <div class="text-muted small mt-1 text-truncate" style="font-size: 0.74rem;">
                        <i class="fa-solid fa-laptop text-secondary me-1"></i> MAC: <?= htmlspecialchars($live_session['callingstationid']) ?>
                    </div>
                <?php else: ?>
                    <div class="stat-pill-val text-muted" style="font-size: 1.05rem;">
                        Disconnected
                    </div>
                    <div class="text-muted small mt-1" style="font-size: 0.74rem;">
                        Check router power/cables
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ================= PACKAGES SECTION ================= -->
<div id="available-packages" class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase" style="font-size: 0.78rem; letter-spacing: 0.5px;">
                <i class="fa-solid fa-bolt"></i> High-Speed Connectivity
            </div>
            <h3 class="fw-bold mb-0 text-dark">Available Internet Packages</h3>
        </div>
        <div class="d-none d-md-block text-muted small">
            Choose a package to upgrade or renew your subscription instantly
        </div>
    </div>

    <div class="row g-4">
        <?php foreach($packages as $p): 
            $isActive = ($current_user['package_id'] == $p['id']);
        ?>
        <div class="col-lg-3 col-md-6">
            <div class="modern-pkg-card <?= $isActive ? 'active-plan' : '' ?>">
                <?php if($isActive): ?>
                    <div class="plan-badge-top">
                        <i class="fa-solid fa-check me-1"></i> Current Plan
                    </div>
                <?php endif; ?>
                
                <div class="mb-3">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;">
                        <?= htmlspecialchars($p['validity_days']) ?> Days Plan
                    </span>
                </div>

                <h4 class="fw-bold text-dark mb-1"><?= htmlspecialchars($p['name']) ?></h4>
                <div class="d-flex align-items-baseline gap-1 my-3">
                    <span class="text-muted fw-bold" style="font-size: 1rem;">Rs</span>
                    <span class="fw-bold text-primary" style="font-size: 2rem; line-height: 1;"><?= number_format($p['price'], 0) ?></span>
                    <span class="text-muted small">/ billing</span>
                </div>
                
                <div class="border-top border-bottom border-slate-100 py-3 my-2">
                    <div class="plan-feature-item">
                        <i class="fa-solid fa-gauge-high"></i>
                        <span>Speed: <strong><?= htmlspecialchars($p['rate_limit'] ?: 'Unlimited Speed') ?></strong></span>
                    </div>
                    <div class="plan-feature-item">
                        <i class="fa-regular fa-calendar-check"></i>
                        <span>Validity: <strong><?= htmlspecialchars($p['validity_days']) ?> Days</strong></span>
                    </div>
                    <div class="plan-feature-item">
                        <i class="fa-solid fa-infinity"></i>
                        <span>Data: <strong><?= $p['data_limit_gb'] > 0 ? $p['data_limit_gb'] . ' GB Limit' : 'Unlimited Bandwidth' ?></strong></span>
                    </div>
                </div>
                
                <div class="mt-auto pt-3">
                    <?php if ($has_pending): ?>
                        <button class="btn btn-secondary w-100 rounded-pill py-2 fw-semibold" disabled>
                            <i class="fa-solid fa-clock me-1"></i> Request Pending
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn <?= $isActive ? 'btn-primary' : 'btn-outline-primary' ?> w-100 rounded-pill py-2 fw-bold" onclick="openBuyModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name']) ?>', <?= $p['price'] ?>)">
                            <i class="fa-solid fa-cart-shopping me-1"></i> <?= $isActive ? 'Renew Plan' : 'Choose Plan' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ================= CONSUMER PROTECTION & COMPLIANCE POLICIES ================= -->
<div class="mb-5 mt-4">
    <div class="card border-0 shadow-sm" style="border-radius: 20px; background: #ffffff; border: 1px solid #e2e8f0 !important; overflow: hidden;">
        <div class="p-4 p-lg-5" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom: 1px solid #e2e8f0;">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="d-inline-flex align-items-center gap-2 text-primary fw-bold text-uppercase mb-2" style="font-size: 0.78rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-shield-halved"></i> Consumer Protection &amp; Regulatory Standards
                    </div>
                    <h3 class="fw-bold mb-1 text-dark">Legal, Privacy &amp; Compliance Policies</h3>
                    <p class="text-muted small mb-0">Transparent billing, strict data privacy, hardware delivery, and refund guarantees for every subscriber.</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2 fw-semibold">
                        <i class="fa-solid fa-circle-check me-1"></i> PTA &amp; Payment Gateway Compliant
                    </span>
                    <a href="/policies.php" class="btn btn-outline-primary rounded-pill px-4 py-2 btn-sm fw-bold">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Full Legal Portal
                    </a>
                </div>
            </div>
        </div>

        <div class="p-4 p-lg-5">
            <!-- 4 Policy Highlights Grid -->
            <div class="row g-4 mb-4">
                <div class="col-md-6 col-lg-3">
                    <div class="h-100 p-4 rounded-4" style="background: #f8fafc; border: 1.5px solid #e2e8f0; transition: transform 0.2s, box-shadow 0.2s;">
                        <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center mb-3" style="width: 44px; height: 44px; font-size: 1.2rem;">
                            <i class="fa-solid fa-rotate-left"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-2">Return &amp; Refund Policy</h6>
                        <p class="text-muted small mb-3">100% advance refund for pre-activation cancellations. 7-day service assurance and 5–7 business days refund turnaround in PKR.</p>
                        <a href="/policies.php?tab=refund" class="fw-bold text-primary small text-decoration-none">
                            Read Refund Policy <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="h-100 p-4 rounded-4" style="background: #f8fafc; border: 1.5px solid #e2e8f0; transition: transform 0.2s, box-shadow 0.2s;">
                        <div class="rounded-circle bg-info-subtle text-info d-flex align-items-center justify-content-center mb-3" style="width: 44px; height: 44px; font-size: 1.2rem;">
                            <i class="fa-solid fa-user-shield"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-2">Privacy &amp; Data Policy</h6>
                        <p class="text-muted small mb-3">256-bit SSL encryption. Zero unauthorized disclosure or third-party sharing of your CNIC, phone, or billing details.</p>
                        <a href="/policies.php?tab=privacy" class="fw-bold text-primary small text-decoration-none">
                            Read Privacy Terms <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="h-100 p-4 rounded-4" style="background: #f8fafc; border: 1.5px solid #e2e8f0; transition: transform 0.2s, box-shadow 0.2s;">
                        <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center mb-3" style="width: 44px; height: 44px; font-size: 1.2rem;">
                            <i class="fa-solid fa-file-contract"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-2">Terms &amp; Conditions (SLA)</h6>
                        <p class="text-muted small mb-3">Transparent 30-day billing, 99.5% uptime SLA, PTA/PECA compliance, and fair usage guidelines for residential &amp; enterprise lines.</p>
                        <a href="/policies.php?tab=terms" class="fw-bold text-primary small text-decoration-none">
                            Read Terms of Service <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>

                <div class="col-md-6 col-lg-3">
                    <div class="h-100 p-4 rounded-4" style="background: #f8fafc; border: 1.5px solid #e2e8f0; transition: transform 0.2s, box-shadow 0.2s;">
                        <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center mb-3" style="width: 44px; height: 44px; font-size: 1.2rem;">
                            <i class="fa-solid fa-truck-fast"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-2">Hardware Delivery Policy</h6>
                        <p class="text-muted small mb-3">FTTH optical drop cabling, fusion splicing, and ONU router physical delivery &amp; live signal test within 24–48 hours.</p>
                        <a href="/policies.php?tab=shipping" class="fw-bold text-primary small text-decoration-none">
                            Read Delivery Policy <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Business Information Strip -->
            <div class="p-4 rounded-4" style="background: #ffffff; border: 1.5px dashed #cbd5e1;">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-3 col-md-6">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                                <i class="fa-solid fa-headset"></i>
                            </div>
                            <div>
                                <div class="text-muted small text-uppercase fw-bold" style="font-size: 0.7rem;">Helpline &amp; Inquiries</div>
                                <div class="fw-bold text-dark"><?= htmlspecialchars(!empty($operator_info['phone']) ? $operator_info['phone'] : BRAND_SUPPORT_PHONE) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3 col-md-6">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                                <i class="fa-solid fa-clock"></i>
                            </div>
                            <div>
                                <div class="text-muted small text-uppercase fw-bold" style="font-size: 0.7rem;">Operational Hours</div>
                                <div class="fw-bold text-dark">Mon–Sun 9 AM – 11 PM <span class="text-muted fw-normal" style="font-size: 0.75rem;">(NOC 24/7)</span></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-2 col-md-6">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                                <i class="fa-solid fa-money-bill-wave"></i>
                            </div>
                            <div>
                                <div class="text-muted small text-uppercase fw-bold" style="font-size: 0.7rem;">Billing Currency</div>
                                <div class="fw-bold text-dark">PKR (₨) Only</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4 col-md-6">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center flex-shrink-0" style="width: 40px; height: 40px;">
                                <i class="fa-solid fa-location-dot"></i>
                            </div>
                            <div class="small">
                                <div class="text-muted small text-uppercase fw-bold" style="font-size: 0.7rem;">Permanent Business Address</div>
                                <div class="fw-semibold text-dark text-truncate" title="<?= htmlspecialchars(!empty($operator_info['address']) ? $operator_info['address'] : BRAND_SUPPORT_ADDRESS) ?>">
                                    <?= htmlspecialchars(!empty($operator_info['address']) ? $operator_info['address'] : BRAND_SUPPORT_ADDRESS) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ================= BUY NOW MODAL ================= -->
<div class="modal fade" id="buyModal" tabindex="-1" aria-labelledby="buyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content clean-modal">
      <div class="modal-header">
        <div class="d-flex align-items-center gap-2">
            <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                <i class="fa-solid fa-cart-shopping"></i>
            </div>
            <h5 class="modal-title fw-bold text-dark" id="buyModalLabel">Confirm Package Purchase</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form action="<?= $has_safepay ? 'safepay_checkout.php' : 'request_action.php' ?>" method="POST" id="buyPackageForm">
        <div class="modal-body p-4">
            <input type="hidden" name="action" value="buy_package">
            <input type="hidden" name="package_id" id="modal_pkg_id">
            <input type="hidden" name="amount" id="modal_pkg_amount" value="0">
            <input type="hidden" name="purpose" value="package_renew">
            
            <!-- Summary Header Box -->
            <div class="text-center p-3 rounded-4 mb-4" style="background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%); border: 1px solid #dbeafe;">
                <div class="text-muted small fw-bold text-uppercase mb-1">Selected Package</div>
                <h4 id="modal_pkg_name" class="fw-bold mb-1 text-dark">Package Name</h4>
                <div class="text-primary fw-bold" style="font-size: 2rem; line-height: 1;">
                    Rs <span id="modal_pkg_price">0</span>
                </div>
            </div>

            <!-- 1. Safepay Payment Info Box -->
            <?php if ($has_safepay): ?>
            <div id="safepayPaymentBox" class="text-center mb-3 py-3 px-3 bg-light rounded-4 border">
                <div class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px;">
                    <i class="fa-solid fa-bolt fs-4 text-primary"></i>
                </div>
                <h6 class="text-dark fw-bold mb-1">Instant Online Checkout (Safepay)</h6>
                <p class="text-muted small mb-2 px-2" style="font-size: 0.8rem;">Pay securely using ANY Pakistani Bank Debit/Credit Card, EasyPaisa, JazzCash, or 1Link Raast. Internet activates immediately!</p>
                <div class="d-flex justify-content-center gap-1 flex-wrap" style="font-size: 0.72rem;">
                    <span class="badge bg-white text-dark border"><i class="fa-solid fa-credit-card text-primary me-1"></i> All Bank Cards</span>
                    <span class="badge bg-white text-success border"><i class="fa-solid fa-mobile-screen-button me-1"></i> EasyPaisa</span>
                    <span class="badge bg-white text-danger border"><i class="fa-solid fa-bolt me-1"></i> JazzCash</span>
                    <span class="badge bg-white text-primary border"><i class="fa-solid fa-building-columns me-1"></i> 1Link / Raast</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- 2. Online Payment Details Box (Manual Bank) -->
            <div id="onlinePaymentBox" class="text-center mb-3 d-none">
                <div class="bank-box-card text-start mx-auto shadow-sm mb-3" style="max-width: 400px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 18px; padding: 18px;">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom border-slate-200">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-building-columns text-primary fs-5"></i>
                            <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($gateway_display_name ?: 'Bank Account') ?></span>
                        </div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1">Official Account</span>
                    </div>

                    <div class="mb-2">
                        <div class="text-muted small text-uppercase" style="font-size: 0.68rem; font-weight: 700;">Account Title</div>
                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($gateway_account_name ?: ($operator_info['company_name'] ?? 'ISP Provider')) ?></div>
                    </div>

                    <?php if(!empty($account_number_str)): ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="text-muted small text-uppercase" style="font-size: 0.68rem; font-weight: 700;">Account Number</div>
                            <button type="button" class="btn-copy-chip" onclick="copyText('<?= htmlspecialchars($account_number_str) ?>', this)" title="Copy Account Number">
                                <i class="fa-regular fa-clipboard me-1"></i>Copy
                            </button>
                        </div>
                        <div class="fw-bold text-primary fs-5 font-monospace" style="letter-spacing: 0.5px;"><?= htmlspecialchars($account_number_str) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if(!empty($iban_str)): ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div class="text-muted small text-uppercase" style="font-size: 0.68rem; font-weight: 700;">IBAN</div>
                            <button type="button" class="btn-copy-chip" onclick="copyText('<?= htmlspecialchars($iban_str) ?>', this)" title="Copy IBAN">
                                <i class="fa-regular fa-clipboard me-1"></i>Copy
                            </button>
                        </div>
                        <div class="fw-bold text-dark font-monospace" style="font-size: 0.88rem;"><?= htmlspecialchars($iban_str) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="mb-3 text-start">
                    <label class="form-label text-dark fw-bold small mb-1">Transaction Reference / TID <span class="text-danger">*</span></label>
                    <input type="text" name="payment_reference" id="payment_reference" class="form-control" placeholder="e.g. TRX192837465">
                    <div class="form-text text-muted" style="font-size: 0.72rem;">Enter the Transaction ID received in your bank receipt or SMS after transfer.</div>
                </div>

                <div class="text-muted small bg-light p-2 rounded-3 border">
                    <i class="fa-solid fa-circle-info text-primary me-1"></i> Send payment to the bank account above via Mobile App, ATM or Online Banking, then click <strong>Submit Request</strong> below.
                </div>
            </div>

            <!-- 3. Balance Payment Info -->
            <div id="balancePaymentBox" class="d-none text-center mb-3 py-4 bg-light rounded-4 border">
                <div class="rounded-circle bg-success-subtle text-success d-inline-flex align-items-center justify-content-center mb-2" style="width: 60px; height: 60px;">
                    <i class="fa-solid fa-wallet fs-3"></i>
                </div>
                <h5 class="text-dark fw-bold mb-1">Pay via Account Balance</h5>
                <p class="text-muted small mb-0 px-3">Your request will be submitted instantly and deducted from your available balance upon approval.</p>
            </div>

            <div class="text-dark small fw-bold mb-2">Select Payment Method:</div>
            
            <!-- Payment Options Selector -->
            <div class="d-flex flex-column gap-2">
                <!-- Safepay Instant Payment -->
                <?php if ($has_safepay): ?>
                <label class="payment-option-pill active w-100 m-0" id="opt_safepay_box">
                    <div class="d-flex align-items-center">
                        <input class="form-check-input mt-0 me-3" type="radio" name="payment_method" id="pay_safepay" value="safepay" checked onchange="togglePaymentUI()">
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.92rem;"><i class="fa-solid fa-bolt text-primary me-1"></i> Pay Online Instantly (Safepay)</div>
                            <div class="text-muted small">Cards, EasyPaisa, JazzCash, Any Bank &bull; <span class="badge bg-success-subtle text-success py-0 px-2 fw-bold">Instant Activation</span></div>
                        </div>
                    </div>
                </label>
                <?php endif; ?>

                <!-- Account Balance -->
                <label class="payment-option-pill w-100 m-0 <?= !$has_safepay ? 'active' : '' ?>" id="opt_balance_box">
                    <div class="d-flex align-items-center">
                        <input class="form-check-input mt-0 me-3" type="radio" name="payment_method" id="pay_balance" value="balance" <?= !$has_safepay ? 'checked' : '' ?> onchange="togglePaymentUI()">
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.92rem;"><i class="fa-solid fa-wallet text-success me-1"></i> Available Wallet Balance</div>
                            <div class="text-muted small">Available Funds: <strong class="text-success">Rs <?= number_format($current_user['balance'], 0) ?></strong></div>
                        </div>
                    </div>
                </label>

                <!-- Manual Bank Transfer -->
                <label class="payment-option-pill w-100 m-0" id="opt_online_box">
                    <div class="d-flex align-items-center">
                        <input class="form-check-input mt-0 me-3" type="radio" name="payment_method" id="pay_online" value="bank_transfer" onchange="togglePaymentUI()">
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.92rem;"><i class="fa-solid fa-building-columns text-secondary me-1"></i> Manual Bank Transfer / TID</div>
                            <div class="text-muted small">Pay via mobile bank or ATM (Manual operator approval)</div>
                        </div>
                    </div>
                </label>
            </div>
            
        </div>
        <div class="modal-footer d-flex justify-content-between">
            <button type="button" class="btn btn-light px-4 rounded-pill fw-semibold" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" id="submitBtn" class="btn btn-primary px-4 rounded-pill fw-bold shadow-sm">
                <i class="fa-solid fa-bolt me-1"></i> Proceed to Pay Online
            </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= ADD FUNDS MODAL ================= -->
<div class="modal fade" id="addFundsModal" tabindex="-1" aria-labelledby="addFundsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content clean-modal">
      <div class="modal-header">
        <div class="d-flex align-items-center gap-2">
            <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                <i class="fa-solid fa-wallet"></i>
            </div>
            <h5 class="modal-title fw-bold text-dark" id="addFundsModalLabel">Recharge Your Wallet</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <?php if ($has_safepay): ?>
      <!-- Mode Tabs -->
      <div class="px-4 pt-3 pb-0">
        <ul class="nav nav-pills nav-fill bg-light p-1 rounded-pill border" id="fundTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active rounded-pill fw-bold small py-2" id="tab-safepay" data-bs-toggle="pill" data-bs-target="#pane-safepay" type="button" role="tab">
              <i class="fa-solid fa-bolt text-warning me-1"></i> Pay Online (Safepay)
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill fw-semibold small py-2" id="tab-manual" data-bs-toggle="pill" data-bs-target="#pane-manual" type="button" role="tab">
              <i class="fa-solid fa-building-columns me-1"></i> Manual Bank Transfer
            </button>
          </li>
        </ul>
      </div>
      <?php endif; ?>

      <div class="tab-content" id="fundTabsContent">
        <!-- 1. Safepay Instant Recharge Tab -->
        <?php if ($has_safepay): ?>
        <div class="tab-pane fade show active" id="pane-safepay" role="tabpanel">
          <form action="safepay_checkout.php" method="POST">
            <input type="hidden" name="purpose" value="recharge">
            <div class="modal-body p-4">
              <div class="text-center mb-3 py-3 px-3 bg-light rounded-4 border">
                <i class="fa-solid fa-credit-card text-primary fa-2x mb-2"></i>
                <h6 class="fw-bold mb-1 text-dark">Instant Online Wallet Recharge</h6>
                <p class="text-muted small mb-2">Debit / Credit Cards (Any Bank), EasyPaisa, JazzCash, 1Link Raast</p>
                <div class="d-flex justify-content-center gap-1 flex-wrap" style="font-size: 0.72rem;">
                  <span class="badge bg-white text-dark border">All Bank Cards</span>
                  <span class="badge bg-white text-success border">EasyPaisa</span>
                  <span class="badge bg-white text-danger border">JazzCash</span>
                  <span class="badge bg-white text-primary border">1Link / Raast</span>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label text-dark fw-bold small mb-1">Enter Recharge Amount (Rs) <span class="text-danger">*</span></label>
                <div class="input-group">
                  <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                  <input type="number" name="amount" class="form-control form-control-lg font-monospace fw-bold" placeholder="e.g. 1500" min="10" required>
                </div>
                <div class="form-text text-muted small"><i class="fa-solid fa-shield-halved text-success me-1"></i> 100% Secure Checkout via Safepay. Funds are credited automatically!</div>
              </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
              <button type="button" class="btn btn-light px-4 rounded-pill fw-semibold" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary px-4 rounded-pill fw-bold shadow-sm">
                <i class="fa-solid fa-bolt me-1"></i> Pay Online via Safepay
              </button>
            </div>
          </form>
        </div>
        <?php endif; ?>

        <!-- 2. Manual Bank Transfer Tab -->
        <div class="tab-pane fade <?= !$has_safepay ? 'show active' : '' ?>" id="pane-manual" role="tabpanel">
          <form action="fund_action.php" method="POST">
            <div class="modal-body p-4">
                
                <div class="text-center mb-3">
                    <div class="bank-box-card text-start mx-auto shadow-sm mb-3" style="max-width: 400px; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 18px; padding: 18px;">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom border-slate-200">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-building-columns text-success fs-5"></i>
                                <span class="fw-bold text-dark fs-6"><?= htmlspecialchars($gateway_display_name ?: 'Bank Account') ?></span>
                            </div>
                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">Bank Account</span>
                        </div>

                        <div class="mb-2">
                            <div class="text-muted small text-uppercase" style="font-size: 0.68rem; font-weight: 700;">Account Title</div>
                            <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($gateway_account_name ?: ($operator_info['company_name'] ?? 'ISP Provider')) ?></div>
                        </div>

                        <?php if(!empty($account_number_str)): ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <div class="text-muted small text-uppercase" style="font-size: 0.68rem; font-weight: 700;">Account Number</div>
                                <button type="button" class="btn-copy-chip" onclick="copyText('<?= htmlspecialchars($account_number_str) ?>', this)" title="Copy Account Number">
                                    <i class="fa-regular fa-clipboard me-1"></i>Copy
                                </button>
                            </div>
                            <div class="fw-bold text-success fs-5 font-monospace" style="letter-spacing: 0.5px;"><?= htmlspecialchars($account_number_str) ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if(!empty($iban_str)): ?>
                        <div class="mb-0">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <div class="text-muted small text-uppercase" style="font-size: 0.68rem; font-weight: 700;">IBAN</div>
                                <button type="button" class="btn-copy-chip" onclick="copyText('<?= htmlspecialchars($iban_str) ?>', this)" title="Copy IBAN">
                                    <i class="fa-regular fa-clipboard me-1"></i>Copy
                                </button>
                            </div>
                            <div class="fw-bold text-dark font-monospace" style="font-size: 0.88rem;"><?= htmlspecialchars($iban_str) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label text-dark fw-bold small mb-1">Enter Recharge Amount (Rs)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted fw-bold">Rs</span>
                        <input type="number" name="amount" id="fund_amount" class="form-control" placeholder="e.g. 1500" required onkeyup="updateFundQR()">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label text-dark fw-bold small mb-1">Transaction Reference / TID</label>
                    <input type="text" name="payment_reference" class="form-control" placeholder="e.g. TRX982736451" required>
                    <div class="form-text text-muted" style="font-size: 0.74rem;">Enter the Transaction ID provided by your banking app after transfer.</div>
                </div>

                <div class="alert alert-light border small text-muted mb-0">
                    <i class="fa-solid fa-circle-check text-success me-1"></i> Once submitted, your operator will verify and credit funds directly to your wallet balance.
                </div>

            </div>
            <div class="modal-footer d-flex justify-content-between">
              <button type="button" class="btn btn-light px-4 rounded-pill fw-semibold" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-success px-4 rounded-pill fw-bold">
                <i class="fa-solid fa-paper-plane me-1"></i> Submit Recharge
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function openBuyModal(id, name, price) {
    document.getElementById('modal_pkg_id').value = id;
    document.getElementById('modal_pkg_name').innerText = name;
    document.getElementById('modal_pkg_price').innerText = Number(price).toFixed(2);
    
    var amtInput = document.getElementById('modal_pkg_amount');
    if (amtInput) amtInput.value = price;
    
    var qrData = encodeURIComponent("PAY_<?= $current_user['id'] ?>_AMT_" + Number(price).toFixed(2) + "_TS_" + Date.now());
    var qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" + qrData;
    var qrElement = document.getElementById('qr_code_img');
    if (qrElement) {
        qrElement.src = qrUrl;
    }
    
    togglePaymentUI();
    var modal = new bootstrap.Modal(document.getElementById('buyModal'));
    modal.show();
}

function togglePaymentUI() {
    var paySafepayEl = document.getElementById('pay_safepay');
    var isSafepay = paySafepayEl && paySafepayEl.checked;
    var isOnline = document.getElementById('pay_online') && document.getElementById('pay_online').checked;
    
    var form = document.getElementById('buyPackageForm');
    var optSafepay = document.getElementById('opt_safepay_box');
    var optOnline = document.getElementById('opt_online_box');
    var optBalance = document.getElementById('opt_balance_box');
    var prefInput = document.getElementById('payment_reference');
    var submitBtn = document.getElementById('submitBtn');
    
    // Hide all boxes first
    document.getElementById('onlinePaymentBox')?.classList.add('d-none');
    document.getElementById('balancePaymentBox')?.classList.add('d-none');
    document.getElementById('safepayPaymentBox')?.classList.add('d-none');
    
    optSafepay?.classList.remove('active');
    optOnline?.classList.remove('active');
    optBalance?.classList.remove('active');
    
    if (isSafepay) {
        if (form) form.action = 'safepay_checkout.php';
        document.getElementById('safepayPaymentBox')?.classList.remove('d-none');
        if (prefInput) prefInput.removeAttribute('required');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fa-solid fa-bolt me-1"></i> Proceed to Pay Online';
            submitBtn.className = 'btn btn-primary px-4 rounded-pill fw-bold shadow-sm';
        }
        optSafepay?.classList.add('active');
    } else if (isOnline) {
        if (form) form.action = 'request_action.php';
        document.getElementById('onlinePaymentBox')?.classList.remove('d-none');
        if (prefInput) prefInput.setAttribute('required', 'required');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Submit Request';
            submitBtn.className = 'btn btn-secondary px-4 rounded-pill fw-bold';
        }
        optOnline?.classList.add('active');
    } else {
        if (form) form.action = 'request_action.php';
        document.getElementById('balancePaymentBox')?.classList.remove('d-none');
        if (prefInput) prefInput.removeAttribute('required');
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fa-solid fa-check me-1"></i> Confirm & Activate';
            submitBtn.className = 'btn btn-success px-4 rounded-pill fw-bold';
        }
        optBalance?.classList.add('active');
    }
}

function updateFundQR() {
    var amount = document.getElementById('fund_amount').value || "0";
    var qrData = encodeURIComponent("FUNDS_<?= $current_user['id'] ?>_AMT_" + amount + "_TS_" + Date.now());
    var qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=" + qrData;
    var qrImg = document.getElementById('fund_qr_code');
    if (qrImg) {
        qrImg.src = qrUrl;
    }
}

function copyText(text, btnElement) {
    if (!navigator.clipboard) {
        var textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
    } else {
        navigator.clipboard.writeText(text);
    }
    
    var originalHTML = btnElement.innerHTML;
    btnElement.innerHTML = '<i class="fa-solid fa-check text-success me-1"></i>Copied!';
    setTimeout(function() {
        btnElement.innerHTML = originalHTML;
    }, 2000);
}
</script>

<?php require_once 'footer.php'; ?>
