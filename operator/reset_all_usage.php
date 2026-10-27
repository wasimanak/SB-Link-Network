<?php
require_once 'header.php';

// Security check: only Operators (not Managers) can perform this mass reset
if (!empty($_SESSION['is_manager'])) {
    echo "<div class='container my-5'><div class='alert alert-danger'>Access Denied. Only primary Operators can perform a mass data usage reset.</div></div>";
    require_once 'footer.php';
    exit;
}

$client_id = (int)$_SESSION['operator_id'];
$actor_name = !empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin';

// Auto-migrate column if missing
try {
    $cols = $pdo->query("SHOW COLUMNS FROM subscribers LIKE 'last_renewed_at'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE subscribers ADD COLUMN last_renewed_at DATETIME DEFAULT NULL");
    }
} catch (Exception $e) {}

$reset_done = false;
$subs_updated = 0;
$rad_updated = 0;
$now_dt = date('Y-m-d H:i:s');

if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) || (isset($_GET['confirm']) && $_GET['confirm'] === '1')) {
    try {
        // 1. Update all subscribers: set last_renewed_at = NOW()
        // This binds their active billing cycle to start right now, resetting current cycle used volume to 0.
        $stmtSub = $pdo->prepare("UPDATE subscribers SET last_renewed_at = ? WHERE client_id = ?");
        $stmtSub->execute([$now_dt, $client_id]);
        $subs_updated = $stmtSub->rowCount();

        // 2. Zero-out past octets in FreeRADIUS radacct for subscribers of this operator
        // All session histories (IP, MAC, session times, logins/logouts) remain 100% intact!
        try {
            $stmtRad = $pdo->prepare("UPDATE radacct r
                JOIN subscribers s ON r.username = s.username COLLATE utf8mb4_general_ci
                SET r.acctinputoctets = 0, r.acctoutputoctets = 0 
                WHERE s.client_id = ?");
            $stmtRad->execute([$client_id]);
            $rad_updated = $stmtRad->rowCount();
        } catch (Exception $eRad) {
            try {
                $stmtRad = $pdo->prepare("UPDATE radacct SET acctinputoctets = 0, acctoutputoctets = 0 WHERE username IN (SELECT username FROM subscribers WHERE client_id = ?)");
                $stmtRad->execute([$client_id]);
                $rad_updated = $stmtRad->rowCount();
            } catch (Exception $eRad2) {}
        }

        // 3. Log into activity logs safely
        try {
            $actText = "Reset all subscribers data usage counters to 0. Updated $subs_updated subscribers and cleared octets on $rad_updated accounting session records.";
            $stmtLog = $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) 
                VALUES (?, ?, 'Admin', 'ALL_SUBSCRIBERS', 'User', ?)");
            $stmtLog->execute([$client_id, $actor_name, $actText]);
        } catch (Exception $eLog) {}

        $reset_done = true;
    } catch (Exception $e) {
        $error_msg = $e->getMessage();
    }
}

// Current system counts
$total_subs = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ?");
$total_subs->execute([$client_id]);
$sub_count = $total_subs->fetchColumn() ?: 0;

$active_sess = $pdo->prepare("SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL AND username IN (SELECT username FROM subscribers WHERE client_id = ?)");
$active_sess->execute([$client_id]);
$online_count = $active_sess->fetchColumn() ?: 0;
?>

<div class="container-fluid px-4 py-4">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="subscribers.php" class="text-decoration-none">Subscribers</a></li>
            <li class="breadcrumb-item active" aria-current="page">Reset Data Usage</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-arrows-rotate text-warning me-2"></i> Reset Subscriber Data Usage
            </h3>
            <p class="text-muted mb-0">Reset all customer data usage counters to 0 MB so new cycles track accurate consumption.</p>
        </div>
        <a href="subscribers.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
            <i class="fa-solid fa-arrow-left me-1"></i> Back to Subscribers
        </a>
    </div>

    <?php if ($reset_done): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4" style="background: linear-gradient(135deg, #ecfdf5, #d1fae5); border: 1px solid #a7f3d0 !important;">
            <div class="card-body p-4 text-center">
                <div class="rounded-circle bg-success text-white d-inline-flex align-items-center justify-content-center mb-3 shadow" style="width: 68px; height: 68px;">
                    <i class="fa-solid fa-check fs-2"></i>
                </div>
                <h4 class="fw-bold text-success mb-2">All Subscriber Data Usage Successfully Reset to 0!</h4>
                <p class="text-secondary mb-4 mx-auto" style="max-width: 600px;">
                    Har subscriber ka billing cycle counter zero kar diya gaya hai. Ab se MikroTik aur FreeRADIUS se real-time new usage count hogi. Tamam customer accounts, passwords, expiry dates, aur balance record bilkul mehfooz hain.
                </p>

                <div class="row g-3 justify-content-center mb-4">
                    <div class="col-md-3">
                        <div class="bg-white rounded-3 p-3 border shadow-sm">
                            <div class="text-muted small fw-semibold">Subscribers Reset</div>
                            <div class="fs-4 fw-bold text-primary"><?= number_format($subs_updated) ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="bg-white rounded-3 p-3 border shadow-sm">
                            <div class="text-muted small fw-semibold">Session Octets Cleared</div>
                            <div class="fs-4 fw-bold text-success"><?= number_format($rad_updated) ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="bg-white rounded-3 p-3 border shadow-sm">
                            <div class="text-muted small fw-semibold">Reset Timestamp</div>
                            <div class="fs-6 fw-bold text-dark mt-1"><?= date('d M Y, h:i A', strtotime($now_dt)) ?></div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-center gap-3">
                    <a href="subscribers.php" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                        <i class="fa-solid fa-users me-1"></i> View Subscribers List
                    </a>
                    <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">
                        <i class="fa-solid fa-gauge me-1"></i> Go to Dashboard
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger rounded-3 mb-4">
                <i class="fa-solid fa-circle-exclamation me-2"></i> Error resetting data usage: <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-body p-4 p-md-5">
                        <div class="d-flex align-items-start gap-3 mb-4">
                            <div class="rounded-circle bg-warning-subtle text-warning d-flex align-items-center justify-content-center flex-shrink-0" style="width: 54px; height: 54px;">
                                <i class="fa-solid fa-triangle-exclamation fs-3"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold text-dark mb-1">Confirm System-Wide Data Usage Reset</h5>
                                <p class="text-muted mb-0">Yeh action tamam customers ki data usage ko <strong>0 MB</strong> se start karne ke liye unka cycle reset karega.</p>
                            </div>
                        </div>

                        <div class="alert alert-light border rounded-3 p-3 mb-4">
                            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-shield-halved text-success me-2"></i> Kya mehfooz rahega? (Safe Records)</h6>
                            <ul class="mb-0 text-secondary small">
                                <li><strong>Customer Accounts & Credentials:</strong> Kisi user ka account, username, password ya profile delete nahi hogi.</li>
                                <li><strong>Packages & Expiry Dates:</strong> Har user ka current package aur expiration date bilkul waisi hi rahegi.</li>
                                <li><strong>Ledger & Wallet Balances:</strong> Kisi customer ya dealer ka balance ya billing transaction change nahi hogi.</li>
                                <li><strong>Connection History:</strong> Past sessions ke IP addresses, timestamps aur connection logs database mein mehfooz rahenge.</li>
                            </ul>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <div class="p-3 bg-light rounded-3 border text-center">
                                    <div class="text-muted small">Total Subscribers</div>
                                    <div class="fs-4 fw-bold text-dark"><?= number_format($sub_count) ?></div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="p-3 bg-light rounded-3 border text-center">
                                    <div class="text-muted small">Active Live Sessions</div>
                                    <div class="fs-4 fw-bold text-success"><?= number_format($online_count) ?></div>
                                </div>
                            </div>
                        </div>

                        <form method="POST" onsubmit="return confirm('Kya aap waqai tamam subscribers ki data usage zero karna chahte hain?');">
                            <input type="hidden" name="confirm_reset" value="1">
                            <div class="d-flex flex-wrap gap-2 justify-content-end">
                                <a href="subscribers.php" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
                                <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm">
                                    <i class="fa-solid fa-arrows-rotate me-1"></i> Reset All Data Usage to 0 Now
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
