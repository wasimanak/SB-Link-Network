<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['rm_id'])) { 
    header("Location: login.php"); 
    exit; 
}
require_once '../config/db.php';

$rm_id = (int)$_SESSION['rm_id'];
$client_id = (int)$_SESSION['client_id'];
$rm_name = $_SESSION['rm_name'] ?? 'Recovery Agent';

// Check RM permissions
$chkStmt = $pdo->prepare("SELECT status, can_create_user FROM recovery_men WHERE id = ?");
$chkStmt->execute([$rm_id]);
$rm_data = $chkStmt->fetch();
if (!$rm_data || $rm_data['status'] === 'disabled') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Handle Payment Collection (Collect Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_only') {
    $sub_id = (int)($_POST['subscriber_id'] ?? 0);
    $sub_username = trim($_POST['subscriber_username'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    $payment_date = trim($_POST['payment_date'] ?? '');
    
    if (empty($payment_date)) {
        $payment_date = date('Y-m-d H:i:s');
    } else {
        $payment_date = date('Y-m-d H:i:s', strtotime($payment_date));
    }

    try {
        $chkSub = $pdo->prepare("SELECT dealer_id FROM subscribers WHERE id = ? AND client_id = ?");
        $chkSub->execute([$sub_id, $client_id]);
        $subDId = $chkSub->fetchColumn();
        if (!empty($subDId)) {
            echo "<script>alert('Access Denied: Recovery agents cannot collect payment for dealer subscribers.'); window.location='pending_payments.php';</script>";
            exit;
        }

        // 10-Minute Financial Cooldown Guard (600 seconds) - Restricts ANY payment addition within 10 mins for this user
        if ($amount > 0) {
            $cooldownCheck = check_financial_duplicate_cooldown($pdo, $client_id, 'subscriber_payment', $sub_username, $amount, 600);
            if (!$cooldownCheck['allowed']) {
                $rem_mins = $cooldownCheck['remaining_minutes'];
                $prev_amt = number_format((float)($cooldownCheck['prev_amount'] ?? 0), 0);
                echo "<script>alert('Payment Cooldown Shield Active (10 Minutes Restriction)!\n\nIs user k account me abhi Rs. {$prev_amt} ki payment add/collect ki ja chuki hai.\n\nKisi bhi mazeed payment k liye baraye meharbani {$rem_mins} minute intezar karein.'); window.location.replace('pending_payments.php');</script>";
                exit;
            }
        }

        $pdo->beginTransaction();

        if ($amount > 0) {
            // Add to User Balance
            $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);
            
            // Fetch updated balance
            $tmpBalStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $tmpBalStmt->execute([$sub_id]);
            $balAfterPayment = (float)$tmpBalStmt->fetchColumn();
            
            // Ledger Entry with date
            $desc = "Payment collected by {$rm_name} RM" . ($note ? " - Note: $note" : "");
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description, created_at) VALUES (?, ?, 'credit', ?, ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $balAfterPayment, $desc, $payment_date]);
            $new_ledger_id = (int)$pdo->lastInsertId();
                
            // Update RM Cash in Hand
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?")->execute([$amount, $rm_id]);
            
            // Log Activity
            $act_msg = "Collected Cash Rs. " . number_format($amount, 0) . " from User" . ($note ? " - Note: $note" : "");
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
                ->execute([$client_id, $rm_name, $sub_username, $act_msg]);
                
            $pdo->commit();
            $ret = urlencode('pending_payments.php');
            $target_url = "print_receipt.php?sub_id={$sub_id}&ledger_id={$new_ledger_id}&paid={$amount}&type=collect&return={$ret}";
            safe_redirect($target_url);
        } else {
            $pdo->rollBack();
            echo "<script>alert('Error: Amount must be greater than 0.'); window.history.back();</script>";
            exit;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    }
}

// Search Filter
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Stats Queries
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_users, 
        COALESCE(SUM(ABS(balance)), 0) as total_pending_amount 
    FROM subscribers 
    WHERE client_id = ? AND (dealer_id IS NULL OR dealer_id = 0) AND balance < 0
");
$statsStmt->execute([$client_id]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
$total_pending_users = (int)($stats['total_users'] ?? 0);
$total_pending_amount = (float)($stats['total_pending_amount'] ?? 0);

// Today's Collection for RM (Robust: counts both 'Payment collected by' and 'Cash collected by')
$today_date = date('Y-m-d');
$collStmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM user_ledger 
    WHERE client_id = ? 
      AND type = 'credit'
      AND (
          description LIKE ? 
          OR description LIKE ? 
          OR description LIKE ? 
          OR description LIKE ?
      ) 
      AND (
          DATE(created_at) = CURDATE() 
          OR DATE(created_at) = ?
      )
");
$collStmt->execute([
    $client_id, 
    "Payment collected by {$rm_name}%", 
    "Cash collected by {$rm_name}%", 
    "%by {$rm_name} RM%", 
    "%by RM: {$rm_name}%",
    $today_date
]);
$today_collection = (float)$collStmt->fetchColumn();

// Fetch Pending Subscribers List
if ($search_query !== '') {
    $subStmt = $pdo->prepare("
        SELECT s.*, p.name as package_name, p.price as package_price
        FROM subscribers s
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE s.client_id = ? AND (s.dealer_id IS NULL OR s.dealer_id = 0) AND s.balance < 0 
          AND (s.username LIKE ? OR s.full_name LIKE ? OR s.mobile LIKE ? OR s.address LIKE ?)
        ORDER BY s.balance ASC
    ");
    $term = "%{$search_query}%";
    $subStmt->execute([$client_id, $term, $term, $term, $term]);
} else {
    $subStmt = $pdo->prepare("
        SELECT s.*, p.name as package_name, p.price as package_price
        FROM subscribers s
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE s.client_id = ? AND (s.dealer_id IS NULL OR s.dealer_id = 0) AND s.balance < 0
        ORDER BY s.balance ASC
    ");
    $subStmt->execute([$client_id]);
}
$pending_subscribers = $subStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Payments - Recovery Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            background-color: #f1f5f9; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        }
        .top-navbar { 
            background: #0f172a; 
            color: white; 
            padding: 14px 20px; 
            border-bottom: 3px solid #f59e0b; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
        }
        .card-custom { 
            border: none; 
            border-radius: 14px; 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05); 
            margin-bottom: 16px; 
        }
        .user-card {
            border-left: 5px solid #ef4444;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
        }
        .amount-badge {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
            font-weight: 800;
            padding: 8px 14px;
            border-radius: 10px;
            display: inline-block;
        }
        .search-box {
            background: #ffffff;
            border-radius: 12px;
            padding: 12px 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        }
    </style>
</head>
<body>

<div class="top-navbar sticky-top">
    <div class="fw-bold fs-5 d-flex align-items-center">
        <i class="fa-solid fa-file-invoice-dollar me-2 text-warning"></i> 
        <span>Pending Payments</span>
    </div>
    <div class="d-flex align-items-center">
        <span class="me-3 small text-light d-none d-md-inline">
            <i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($rm_name) ?>
        </span>
        <a href="dashboard.php" class="btn btn-sm btn-outline-light fw-bold me-2">
            <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
        </a>
        <a href="history.php" class="btn btn-sm btn-light text-dark fw-bold me-2">
            <i class="fa-solid fa-clock-rotate-left me-1"></i> History
        </a>
        <a href="logout.php" class="btn btn-sm btn-outline-light" title="Logout">
            <i class="fa-solid fa-right-from-bracket"></i>
        </a>
    </div>
</div>

<div class="container mt-4 mb-5">
    
    <!-- Top Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card card-custom bg-danger text-white h-100 shadow-sm">
                <div class="card-body p-3 text-center">
                    <div class="small fw-semibold text-white-50 text-uppercase mb-1">
                        <i class="fa-solid fa-users me-1"></i> Pending Users
                    </div>
                    <h3 class="mb-0 fw-bold"><?= number_format($total_pending_users) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card card-custom bg-dark text-white h-100 shadow-sm">
                <div class="card-body p-3 text-center">
                    <div class="small fw-semibold text-warning text-uppercase mb-1">
                        <i class="fa-solid fa-money-bill-wave me-1"></i> Total Pending
                    </div>
                    <h3 class="mb-0 fw-bold text-warning">Rs. <?= number_format($total_pending_amount) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card card-custom bg-success text-white h-100 shadow-sm">
                <div class="card-body p-3 text-center">
                    <div class="small fw-semibold text-white-50 text-uppercase mb-1">
                        <i class="fa-solid fa-calendar-check me-1"></i> Today's Collected
                    </div>
                    <h3 class="mb-0 fw-bold">Rs. <?= number_format($today_collection) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Search Box -->
    <div class="card card-custom search-box mb-4">
        <form method="GET" action="pending_payments.php" class="row g-2 align-items-center">
            <div class="col-12 col-md-9">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </span>
                    <input type="text" name="search" class="form-control border-start-0 ps-0" 
                           placeholder="Search by Username, Full Name, Phone, or Address..." 
                           value="<?= htmlspecialchars($search_query) ?>">
                </div>
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn btn-warning w-100 fw-bold text-dark shadow-sm">
                    <i class="fa-solid fa-filter me-1"></i> Search
                </button>
            </div>
            <div class="col-6 col-md-1">
                <a href="pending_payments.php" class="btn btn-light w-100 border text-muted">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Subscribers List Section -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0 text-dark">
            <i class="fa-solid fa-list-check me-2 text-danger"></i> Subscribers with Minus Balance
            <span class="badge bg-secondary ms-2 small"><?= count($pending_subscribers) ?> Found</span>
        </h5>
    </div>

    <?php if (empty($pending_subscribers)): ?>
        <div class="card card-custom p-5 text-center bg-white">
            <div class="mb-3">
                <i class="fa-solid fa-circle-check text-success" style="font-size: 3.5rem;"></i>
            </div>
            <h5 class="fw-bold text-dark">No Pending Payments!</h5>
            <p class="text-muted mb-3">
                <?= $search_query !== '' ? 'No pending users found matching your search term.' : 'All users have cleared their dues or have positive/zero balances.' ?>
            </p>
            <div>
                <a href="dashboard.php" class="btn btn-primary fw-bold px-4">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($pending_subscribers as $sub): 
                $pending_due = abs((float)$sub['balance']);
                $clean_mobile = preg_replace('/[^0-9]/', '', $sub['mobile'] ?? '');
                if (substr($clean_mobile, 0, 1) === '0') {
                    $wa_mobile = '92' . substr($clean_mobile, 1);
                } else {
                    $wa_mobile = $clean_mobile;
                }

                // Check 10-Minute Financial Cooldown for this subscriber
                $subCooldown = check_financial_duplicate_cooldown($pdo, $client_id, 'subscriber_payment', $sub['username'], 0, 600);
                $isSubLocked = !$subCooldown['allowed'];
                $subRemMins = $subCooldown['remaining_minutes'] ?? 0;
                $subPrevAmt = number_format((float)($subCooldown['prev_amount'] ?? 0), 0);
                $subPrevTime = !empty($subCooldown['created_at']) ? date('h:i:s A', strtotime($subCooldown['created_at'])) : '';
            ?>
            <div class="col-12 col-md-6 col-lg-4 mb-3">
                <div class="card card-custom user-card bg-white p-3 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <!-- User Header -->
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($sub['full_name'] ?: $sub['username']) ?></h6>
                                <span class="badge bg-primary-subtle text-primary fw-bold font-monospace">
                                    <i class="fa-solid fa-user me-1"></i> <?= htmlspecialchars($sub['username']) ?>
                                </span>
                            </div>
                            <span class="badge <?= $sub['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?> text-uppercase" style="font-size: 0.7rem;">
                                <?= htmlspecialchars($sub['status']) ?>
                            </span>
                        </div>

                        <!-- Amount Pending Display -->
                        <div class="amount-badge w-100 text-center my-2">
                            <div class="small text-uppercase text-muted" style="font-size: 0.75rem; letter-spacing: 0.5px;">Pending Amount (Minus Balance)</div>
                            <div class="fs-4 fw-black text-danger">Rs. <?= number_format($pending_due, 0) ?></div>
                        </div>

                        <!-- Details -->
                        <div class="small text-secondary mt-2 mb-3">
                            <div class="mb-1">
                                <i class="fa-solid fa-box-open text-primary me-2"></i>
                                <strong>Package:</strong> <?= htmlspecialchars($sub['package_name'] ?? 'N/A') ?> 
                                <?php if (!empty($sub['package_price'])): ?>
                                    <span class="text-muted">(Rs. <?= number_format($sub['package_price']) ?>)</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($sub['mobile'])): ?>
                            <div class="mb-1 d-flex align-items-center">
                                <i class="fa-solid fa-phone text-success me-2"></i>
                                <span class="me-2"><?= htmlspecialchars($sub['mobile']) ?></span>
                                <a href="tel:<?= htmlspecialchars($sub['mobile']) ?>" class="btn btn-xs btn-outline-success py-0 px-2 me-1" title="Call">
                                    <i class="fa-solid fa-phone-flip" style="font-size: 0.75rem;"></i>
                                </a>
                                <?php if (!empty($wa_mobile)): ?>
                                <a href="https://wa.me/<?= $wa_mobile ?>?text=<?= urlencode("Dear " . ($sub['full_name'] ?: $sub['username']) . ", your internet dues of Rs. " . number_format($pending_due) . " are pending. Please pay at your earliest. - " . BRAND_NAME) ?>" 
                                   target="_blank" class="btn btn-xs btn-outline-success py-0 px-2" title="WhatsApp Reminder">
                                    <i class="fa-brands fa-whatsapp" style="font-size: 0.75rem;"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($sub['address'])): ?>
                            <div class="mb-1">
                                <i class="fa-solid fa-location-dot text-danger me-2"></i>
                                <span><?= htmlspecialchars($sub['address']) ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($sub['expiry_date'])): ?>
                            <div class="mb-1">
                                <i class="fa-solid fa-calendar text-info me-2"></i>
                                <strong>Expiry:</strong> <?= safe_date('d M Y', $sub['expiry_date'] ?? null, 'N/A') ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Actions Footer -->
                    <div class="border-top pt-2 mt-auto">
                        <div class="row g-2">
                            <div class="col-7">
                                <button class="btn <?= $isSubLocked ? 'btn-warning text-dark' : 'btn-success' ?> btn-sm w-100 fw-bold shadow-sm" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#collectModal<?= $sub['id'] ?>">
                                    <i class="fa-solid <?= $isSubLocked ? 'fa-clock' : 'fa-hand-holding-dollar' ?> me-1"></i> 
                                    <?= $isSubLocked ? "Locked ({$subRemMins}m)" : "Collect" ?>
                                </button>
                            </div>
                            <div class="col-5">
                                <a href="dashboard.php?search_query=<?= urlencode($sub['username']) ?>" 
                                   class="btn btn-outline-danger btn-sm w-100 fw-bold" title="Open in Dashboard to renew package">
                                    <i class="fa-solid fa-rotate me-1"></i> Renew
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Collect Payment Modal for this subscriber -->
                <div class="modal fade" id="collectModal<?= $sub['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                            <div class="modal-header border-bottom p-4">
                                <h5 class="modal-title fw-bold text-success">
                                    <i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Pending Payment
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST" onsubmit="var b=this.querySelector('button[type=submit]'); if(b){b.disabled=true; b.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-1\'></i> Saving...';}">
                                <input type="hidden" name="action" value="collect_only">
                                <input type="hidden" name="subscriber_id" value="<?= $sub['id'] ?>">
                                <input type="hidden" name="subscriber_username" value="<?= htmlspecialchars($sub['username']) ?>">
                                
                                <div class="modal-body p-4 text-start">
                                    <?php if ($isSubLocked): ?>
                                        <div class="alert alert-warning border-0 rounded-3 p-3 mb-3 d-flex align-items-center gap-3 shadow-sm">
                                            <div class="rounded-circle bg-warning bg-opacity-25 text-warning-emphasis d-flex align-items-center justify-content-center p-2" style="width: 40px; height: 40px; flex-shrink: 0;">
                                                <i class="fa-solid fa-clock-rotate-left fs-5"></i>
                                            </div>
                                            <div class="small">
                                                <div class="fw-bold text-dark">10-Minute Payment Shield Active!</div>
                                                <span class="text-secondary">Is user se abhi <strong>(<?= $subPrevTime ?>)</strong> par <strong>Rs. <?= $subPrevAmt ?></strong> collect kiye ja chukay hain. Mazeed payment k liye <strong><?= $subRemMins ?> minute</strong> intezar karein.</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="alert alert-danger bg-opacity-10 text-danger border-danger border-opacity-25 py-2 mb-3">
                                        <div class="fw-bold fs-6"><?= htmlspecialchars($sub['full_name'] ?: $sub['username']) ?> (<?= htmlspecialchars($sub['username']) ?>)</div>
                                        <div><strong>Total Pending Dues:</strong> Rs. <?= number_format($pending_due, 0) ?></div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-muted text-uppercase">Payment Date & Time</label>
                                        <input type="datetime-local" name="payment_date" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required <?= $isSubLocked ? 'disabled' : '' ?>>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-muted text-uppercase">Amount Received (Rs) <span class="text-danger">*</span></label>
                                        <input type="number" step="1" name="amount" class="form-control form-control-lg fw-bold text-success text-center" 
                                               value="<?= $pending_due ?>" placeholder="e.g. <?= $pending_due ?>" required <?= $isSubLocked ? 'disabled' : '' ?>>
                                        <div class="d-flex align-items-center gap-1 text-muted mt-2" style="font-size: 0.76rem;">
                                            <i class="fa-solid fa-shield-halved text-success"></i> 
                                            <span>10-minute duplicate payment shield active.</span>
                                        </div>
                                        <div class="form-text small text-muted">Prefilled with pending amount. You can edit if partial payment received.</div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-muted text-uppercase">Note / Remarks (Optional)</label>
                                        <input type="text" name="note" class="form-control" placeholder="e.g. Cleared pending dues" <?= $isSubLocked ? 'disabled' : '' ?>>
                                    </div>
                                </div>
                                <div class="modal-footer border-top p-3">
                                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                    <?php if ($isSubLocked): ?>
                                        <button type="button" class="btn btn-secondary rounded-pill px-4 fw-bold" disabled>
                                            <i class="fa-solid fa-lock me-1"></i> Locked (<?= $subRemMins ?>m wait)
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
                                            <i class="fa-solid fa-check me-1"></i> Save Payment
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Force reload if page is restored from bfcache
    window.addEventListener('pageshow', function (event) {
        if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
            window.location.reload();
        }
    });
</script>
</body>
</html>
