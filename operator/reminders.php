<?php
// =========================================================================
// Official SMS Reminders & Mobile Gateway Control Center
// Android Smartphone SIM-Based Gateway, Auto Expiry Alerts & Bulk SMS
// =========================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/sms_helper.php';

// Auth Guard: Only Admin / Operator can access Gateway settings
if (!isset($_SESSION['operator_logged_in']) || !isset($_SESSION['operator_id'])) {
    header("Location: ../login.php");
    exit;
}
if (!empty($_SESSION['is_manager'])) {
    echo "<script>alert('Permission Denied: Managers cannot configure SMS Gateway.'); window.location='dashboard.php';</script>";
    exit;
}

$client_id = (int)$_SESSION['operator_id'];
ensure_sms_tables_exist($pdo);

$flash_msg = '';
$flash_type = 'success';

// -------------------------------------------------------------------------
// Handle POST Actions
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. Save Gateway & Expiry Rules Settings
    if ($action === 'save_settings') {
        try {
            save_sms_gateway_settings($pdo, $client_id, $_POST);
            $flash_msg = "SMS Gateway settings and automated reminder rules saved successfully!";
            $flash_type = "success";
        } catch (Exception $e) {
            $flash_msg = "Error saving settings: " . htmlspecialchars($e->getMessage());
            $flash_type = "danger";
        }
    }

    // 2. Regenerate Gateway Token
    elseif ($action === 'regenerate_token') {
        try {
            $newToken = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE sms_gateway_settings SET gateway_token = ? WHERE client_id = ?")->execute([$newToken, $client_id]);
            $flash_msg = "Gateway secret token regenerated! Please update your mobile phone app with the new token.";
            $flash_type = "warning";
        } catch (Exception $e) {
            $flash_msg = "Error regenerating token: " . htmlspecialchars($e->getMessage());
            $flash_type = "danger";
        }
    }

    // 3. Send Single Test SMS
    elseif ($action === 'send_test_sms') {
        $testMobile = trim($_POST['test_mobile'] ?? '');
        $testMsg = trim($_POST['test_message'] ?? '');
        if (empty($testMobile) || empty($testMsg)) {
            $flash_msg = "Please enter both mobile number and test message.";
            $flash_type = "danger";
        } else {
            $cleanM = format_mobile_for_sms($testMobile);
            $qId = queue_sms_message($pdo, $client_id, $cleanM, $testMsg, 'test', null, null, 'Test Tool');
            if ($qId) {
                $flash_msg = "Test SMS queued successfully (ID #{$qId}) for <strong>{$cleanM}</strong>. Your mobile phone will send it via SIM shortly!";
                $flash_type = "success";
            } else {
                $flash_msg = "Invalid mobile number format. Please enter a valid Pakistani mobile number (e.g. 03001234567).";
                $flash_type = "danger";
            }
        }
    }

    // 4. Send Custom / Bulk SMS
    elseif ($action === 'send_bulk_sms') {
        $targetAudience = $_POST['target_audience'] ?? 'single';
        $customText     = trim($_POST['custom_message'] ?? '');

        if (empty($customText)) {
            $flash_msg = "Please write a message to send.";
            $flash_type = "danger";
        } else {
            // Fetch client info for tag replacement
            $cStmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
            $cStmt->execute([$client_id]);
            $client = $cStmt->fetch() ?: [];

            $queuedCount = 0;

            // Scenario A: Manual Custom Mobile Numbers
            if ($targetAudience === 'manual') {
                $rawNumbers = explode(',', str_replace(["\n", "\r", ";"], ',', $_POST['manual_numbers'] ?? ''));
                foreach ($rawNumbers as $rn) {
                    $rn = trim($rn);
                    if (empty($rn)) continue;
                    $cleanM = format_mobile_for_sms($rn);
                    if (!empty($cleanM)) {
                        if (queue_sms_message($pdo, $client_id, $cleanM, $customText, 'custom_bulk', null, null, 'Admin Composer', $scheduledAt)) {
                            $queuedCount++;
                        }
                    }
                }
            }
            // Scenario B: Specific Single Subscriber
            elseif ($targetAudience === 'single_sub') {
                $subId = (int)($_POST['subscriber_id'] ?? 0);
                if ($subId > 0) {
                    $sStmt = $pdo->prepare("
                        SELECT s.*, p.name as package_name, p.price as package_price 
                        FROM subscribers s 
                        LEFT JOIN packages p ON s.package_id = p.id 
                        WHERE s.id = ? AND s.client_id = ? LIMIT 1
                    ");
                    $sStmt->execute([$subId, $client_id]);
                    $sub = $sStmt->fetch();
                    if ($sub) {
                        $mobile = !empty($sub['mobile']) ? $sub['mobile'] : $sub['phone'];
                        $resolved = resolve_sms_template_tags($customText, $sub, $client);
                        if (queue_sms_message($pdo, $client_id, $mobile, $resolved, 'custom_single', $sub['id'], $sub['username'], 'Admin Composer', $scheduledAt)) {
                            $queuedCount = 1;
                        }
                    }
                }
            }
            // Scenario C: Filter-based Bulk (Active, Expired, Expiring in 1d/3d)
            else {
                $where = "client_id = $client_id AND ((mobile IS NOT NULL AND mobile != '') OR (phone IS NOT NULL AND phone != ''))";
                if ($targetAudience === 'active') {
                    $where .= " AND (expiry_date >= NOW() OR expiry_date IS NULL OR expiry_date < '2000-01-01') AND LOWER(status) != 'disabled'";
                } elseif ($targetAudience === 'expired') {
                    $where .= " AND expiry_date < NOW() AND expiry_date > '2000-01-01'";
                } elseif ($targetAudience === 'expiring_1d') {
                    $where .= " AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)";
                } elseif ($targetAudience === 'expiring_3d') {
                    $where .= " AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)";
                }

                $sList = $pdo->query("
                    SELECT s.*, p.name as package_name, p.price as package_price 
                    FROM subscribers s 
                    LEFT JOIN packages p ON s.package_id = p.id 
                    WHERE $where
                ")->fetchAll();

                foreach ($sList as $sub) {
                    $m = !empty($sub['mobile']) ? $sub['mobile'] : $sub['phone'];
                    $resolved = resolve_sms_template_tags($customText, $sub, $client);
                    if (queue_sms_message($pdo, $client_id, $m, $resolved, 'custom_bulk', $sub['id'], $sub['username'], 'Admin Composer', $scheduledAt)) {
                        $queuedCount++;
                    }
                }
            }

            if ($queuedCount > 0) {
                $flash_msg = "Successfully queued <strong>{$queuedCount}</strong> SMS messages! Your mobile phone SMS gateway will dispatch them via SIM card.";
                $flash_type = "success";
            } else {
                $flash_msg = "No eligible subscribers with valid mobile numbers found for the selected audience.";
                $flash_type = "warning";
            }
        }
    }

    // 5. Trigger Auto-Reminders Scan Now
    elseif ($action === 'run_auto_reminders') {
        $res = scan_and_queue_expiry_reminders($pdo, $client_id);
        if ($res['status'] === 'disabled') {
            $flash_msg = "Automated expiry reminders are currently disabled. Enable them in the Expiry Rules tab.";
            $flash_type = "warning";
        } elseif ($res['status'] === 'all_stages_off') {
            $flash_msg = "Automated expiry scan skipped: All reminder stages (Before Expiry, On Expiry Day, and After Expiry) are currently switched OFF.";
            $flash_type = "warning";
        } else {
            $flash_msg = "Automated expiry scan finished! <strong>{$res['queued']}</strong> reminder messages queued for expiring/expired customers.";
            $flash_type = "success";
        }
    }

    // 6b. Send Pending Message Immediately (Bypass Schedule)
    elseif ($action === 'send_now') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        if ($msgId > 0) {
            $pdo->prepare("UPDATE sms_queue_logs SET scheduled_at = NOW() WHERE id = ? AND client_id = ? AND status = 'pending'")->execute([$msgId, $client_id]);
            $flash_msg = "Message #{$msgId} was dispatched for immediate sending (schedule bypassed).";
            $flash_type = "success";
        }
    }

    // 6. Cancel Pending Message
    elseif ($action === 'cancel_message') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        if ($msgId > 0) {
            $pdo->prepare("UPDATE sms_queue_logs SET status = 'cancelled' WHERE id = ? AND client_id = ? AND status = 'pending'")->execute([$msgId, $client_id]);
            $flash_msg = "Message #{$msgId} was cancelled from queue.";
            $flash_type = "info";
        }
    }

    // 7. Resend Failed Message
    elseif ($action === 'resend_message') {
        $msgId = (int)($_POST['message_id'] ?? 0);
        if ($msgId > 0) {
            $pdo->prepare("UPDATE sms_queue_logs SET status = 'pending', error_message = NULL, scheduled_at = NOW() WHERE id = ? AND client_id = ?")->execute([$msgId, $client_id]);
            $flash_msg = "Message #{$msgId} re-queued for sending.";
            $flash_type = "success";
        }
    }

    // 8. Clear Sent Logs
    elseif ($action === 'clear_sent_logs') {
        $pdo->prepare("DELETE FROM sms_queue_logs WHERE client_id = ? AND status IN ('sent', 'delivered', 'cancelled')")->execute([$client_id]);
        $flash_msg = "Cleared sent & cancelled history logs.";
        $flash_type = "info";
    }
}

// -------------------------------------------------------------------------
// Load Current Settings, Metrics & Lists
// -------------------------------------------------------------------------
$settings = get_sms_gateway_settings($pdo, $client_id);

// Opportunistic daily automated reminder scan if not yet run today
if (!empty($settings['auto_expiry_reminders'])) {
    $todayDate = date('Y-m-d');
    if (empty($settings['last_auto_scan_date']) || $settings['last_auto_scan_date'] !== $todayDate) {
        scan_and_queue_expiry_reminders($pdo, $client_id);
        $settings = get_sms_gateway_settings($pdo, $client_id);
    }
}

$stats = get_sms_dashboard_stats($pdo, $client_id);

// Expiring counts for display
$expiringStats = $pdo->query("
    SELECT 
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as exp_1d,
        SUM(CASE WHEN expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) as exp_3d,
        SUM(CASE WHEN expiry_date < NOW() AND expiry_date > '2000-01-01' THEN 1 ELSE 0 END) as expired_total
    FROM subscribers 
    WHERE client_id = $client_id
")->fetch();

$exp_1d = (int)($expiringStats['exp_1d'] ?? 0);
$exp_3d = (int)($expiringStats['exp_3d'] ?? 0);
$exp_total = (int)($expiringStats['expired_total'] ?? 0);

// Load Pending Queue (Top 50)
$pendingQueue = $pdo->query("
    SELECT * FROM sms_queue_logs 
    WHERE client_id = $client_id AND status = 'pending'
    ORDER BY scheduled_at ASC, id ASC LIMIT 50
")->fetchAll();

// Load SMS Logs (Last 100)
$statusFilter = $_GET['status_filter'] ?? 'all';
$filterSql = "";
if (in_array($statusFilter, ['pending', 'sent', 'delivered', 'failed', 'cancelled'])) {
    $filterSql = " AND status = " . $pdo->quote($statusFilter);
}
$smsLogs = $pdo->query("
    SELECT * FROM sms_queue_logs 
    WHERE client_id = $client_id $filterSql
    ORDER BY id DESC LIMIT 100
")->fetchAll();

// Fetch all active subscribers for single recipient dropdown
$allSubscribers = $pdo->query("SELECT id, username, full_name, mobile, phone, expiry_date FROM subscribers WHERE client_id = $client_id ORDER BY username ASC")->fetchAll();

// Absolute API Endpoint URL for Phone App
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$gatewayEndpointUrl = $protocol . $host . "/api/sms_gateway";

// Determine Active Tab from query param or default
$initialTab = 'queue';
if (isset($_GET['tab'])) {
    $t = strtolower(trim($_GET['tab']));
    if (in_array($t, ['custom', 'composer'])) {
        $initialTab = 'composer';
    } elseif (in_array($t, ['queue', 'rules', 'logs', 'connect'])) {
        $initialTab = $t;
    }
}

require_once 'header.php';
?>

<style>
    body { background-color: #f8fafc; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .card-ui { background: #ffffff; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; margin-bottom: 24px; }
    
    .gateway-stat-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 18px 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02);
        height: 100%;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .gateway-stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(0,0,0,0.05);
    }
    .gateway-stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        flex-shrink: 0;
    }

    /* Pulse animation for live connected phone */
    .pulse-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        position: relative;
    }
    .pulse-dot.online {
        background-color: #10b981;
        box-shadow: 0 0 0 rgba(16, 185, 129, 0.4);
        animation: pulseGreen 1.8s infinite;
    }
    .pulse-dot.offline {
        background-color: #ef4444;
    }
    @keyframes pulseGreen {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    .smart-tag-pill {
        display: inline-block;
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
        border-radius: 50px;
        padding: 3px 10px;
        font-size: 0.75rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
        margin: 2px 2px;
        user-select: none;
    }
    .smart-tag-pill:hover {
        background: #1d4ed8;
        color: #ffffff;
        border-color: #1d4ed8;
        transform: scale(1.05);
    }

    .sms-preview-screen {
        background: #f1f5f9;
        border: 12px solid #0f172a;
        border-radius: 28px;
        padding: 16px;
        max-width: 330px;
        margin: 0 auto;
        box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        position: relative;
    }
    .sms-bubble {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px 14px 14px 2px;
        padding: 12px 14px;
        color: #1e293b;
        font-size: 0.85rem;
        line-height: 1.45;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        position: relative;
    }

    .tab-pill-btn {
        border-radius: 50px;
        padding: 8px 20px;
        font-weight: 600;
        font-size: 0.88rem;
        border: none;
        transition: all 0.2s ease;
        color: #64748b;
        background: transparent;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .tab-pill-btn.active {
        background: #2563eb !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(37,99,235,0.25);
    }
    .tab-pill-btn:not(.active):hover {
        background: #f1f5f9;
        color: #0f172a;
    }
</style>

<div class="container-fluid px-3 px-md-4 py-3">

    <!-- Top Header & Actions -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary px-3 py-1 fw-bold" style="font-size: 0.78rem;">
                    <i class="fa-solid fa-mobile-screen-button me-1"></i> Android SMS Gateway
                </span>
                <?php if ($stats['gateway_online']): ?>
                    <span class="badge rounded-pill bg-success bg-opacity-10 text-success px-3 py-1 fw-bold d-inline-flex align-items-center gap-1" style="font-size: 0.78rem;">
                        <span class="pulse-dot online"></span> Phone Gateway Online (<?= $stats['phone_seen_ago'] ?>)
                    </span>
                <?php elseif ($stats['phone_seen_ago'] !== 'Never' && !empty($settings['last_phone_ping_at']) && (time() - strtotime($settings['last_phone_ping_at'])) <= 3600): ?>
                    <span class="badge rounded-pill bg-warning bg-opacity-10 text-dark px-3 py-1 fw-bold d-inline-flex align-items-center gap-1" style="font-size: 0.78rem;">
                        <span class="pulse-dot" style="background:#f59e0b;"></span> Phone Standby (<?= $stats['phone_seen_ago'] ?>)
                    </span>
                <?php else: ?>
                    <span class="badge rounded-pill bg-danger bg-opacity-10 text-danger px-3 py-1 fw-bold d-inline-flex align-items-center gap-1" style="font-size: 0.78rem;">
                        <span class="pulse-dot offline"></span> Phone Gateway Offline
                    </span>
                <?php endif; ?>
            </div>
            <h3 class="fw-bold text-dark mb-0" style="letter-spacing: -0.5px;">SMS Reminders &amp; Mobile Gateway</h3>
            <p class="text-secondary small mb-0">Use your own Android Phone's SIM to send automated expiry alerts &amp; custom bulk messages directly to customers</p>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <!-- Manual Run Expiry Scan -->
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="run_auto_reminders">
                <button type="submit" class="btn btn-outline-primary fw-bold px-3 py-2 rounded-3 bg-white shadow-sm d-flex align-items-center gap-2" title="Scan subscribers & queue expiry reminders right now">
                    <i class="fa-solid fa-arrows-rotate"></i>
                    <span>Run Auto-Reminders</span>
                </button>
            </form>

            <!-- Git Update Trigger -->
            <a href="git_pull.php" class="btn btn-outline-secondary fw-semibold px-3 py-2 rounded-3 bg-white shadow-sm d-flex align-items-center gap-2" title="Pull & Activate Latest Updates from GitHub">
                <i class="fa-solid fa-cloud-arrow-down text-primary"></i>
                <span>Sync Updates</span>
            </a>

            <!-- Test SMS Trigger Modal -->
            <button type="button" class="btn btn-light border fw-bold px-3 py-2 rounded-3 shadow-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#testSmsModal">
                <i class="fa-solid fa-paper-plane text-secondary"></i>
                <span>Send Test SMS</span>
            </button>

            <!-- Quick Compose Tab Switcher -->
            <button type="button" class="btn btn-primary fw-bold px-3 py-2 rounded-3 shadow-sm d-flex align-items-center gap-2" onclick="switchMainTab('composer')" style="background: linear-gradient(135deg, #2563eb, #1d4ed8); border: none;">
                <i class="fa-solid fa-comment-medical"></i>
                <span>+ Compose Custom SMS</span>
            </button>
        </div>
    </div>

    <!-- Flash Alert -->
    <?php if (!empty($flash_msg)): ?>
        <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show rounded-4 border-0 shadow-sm p-3 mb-4" role="alert">
            <div class="d-flex align-items-start gap-2">
                <i class="fa-solid <?= $flash_type === 'success' ? 'fa-circle-check text-success' : ($flash_type === 'warning' ? 'fa-triangle-exclamation text-warning' : 'fa-circle-exclamation text-danger') ?> fs-5 mt-1"></i>
                <div class="flex-grow-1">
                    <?= $flash_msg ?>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>

    <!-- 4 High-Level Metric Cards -->
    <div class="row g-3 mb-4">
        <!-- 1. Gateway Phone Status -->
        <?php
            $isStandby = (!$stats['gateway_online'] && $stats['phone_seen_ago'] !== 'Never' && !empty($settings['last_phone_ping_at']) && (time() - strtotime($settings['last_phone_ping_at'])) <= 3600);
            $cardBg = $stats['gateway_online'] ? '#ecfdf5' : ($isStandby ? '#fffbeb' : '#fff1f2');
            $cardIconColor = $stats['gateway_online'] ? '#059669' : ($isStandby ? '#d97706' : '#e11d48');
            $cardTextColor = $stats['gateway_online'] ? 'text-success' : ($isStandby ? 'text-warning text-dark' : 'text-danger');
            $cardStatusLabel = $stats['gateway_online'] ? 'Connected (Active)' : ($isStandby ? 'Connected (Standby)' : 'Phone Offline');
        ?>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="gateway-stat-card">
                <div class="gateway-stat-icon" style="background-color: <?= $cardBg ?>; color: <?= $cardIconColor ?>;">
                    <i class="fa-solid fa-mobile-screen-button"></i>
                </div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Mobile Gateway</div>
                    <h5 class="fw-bold mb-0 <?= $cardTextColor ?>" style="font-size: 1.05rem;">
                        <?= $cardStatusLabel ?>
                    </h5>
                    <div class="text-muted small text-truncate" style="font-size: 0.78rem;">
                        SIM <?= (int)($settings['sim_slot'] ?? 1) ?> &bull; <?= htmlspecialchars($stats['device_name']) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Pending Queue -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="gateway-stat-card">
                <div class="gateway-stat-icon" style="background-color: #fffbeb; color: #d97706;">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Pending Queue</div>
                    <div class="d-flex align-items-baseline gap-2">
                        <h4 class="fw-bold text-dark mb-0"><?= number_format($stats['pending_queue']) ?></h4>
                        <span class="text-muted small">messages waiting</span>
                    </div>
                    <div class="text-secondary small" style="font-size: 0.75rem;">Phone pulls every 5–10s</div>
                </div>
            </div>
        </div>

        <!-- 3. Sent Today / Month -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="gateway-stat-card">
                <div class="gateway-stat-icon" style="background-color: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-paper-plane"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Dispatched SMS</div>
                    <div class="d-flex align-items-baseline gap-2">
                        <h4 class="fw-bold text-dark mb-0"><?= number_format($stats['sent_today']) ?></h4>
                        <span class="text-muted small">Today</span>
                        <span class="text-muted small ms-2">(<?= number_format($stats['sent_month']) ?> Month)</span>
                    </div>
                    <div class="text-success small fw-semibold" style="font-size: 0.75rem;"><i class="fa-solid fa-check-double me-1"></i> Sent via SIM</div>
                </div>
            </div>
        </div>

        <!-- 4. Expiring Soon -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="gateway-stat-card">
                <div class="gateway-stat-icon" style="background-color: #fdf4ff; color: #c026d3;">
                    <i class="fa-solid fa-user-clock"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="text-secondary small fw-bold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">Expiring Subscribers</div>
                    <div class="d-flex align-items-baseline gap-2">
                        <h4 class="fw-bold text-dark mb-0"><?= number_format($exp_1d) ?> <span class="fs-6 text-muted fw-normal">(1 Day)</span></h4>
                        <span class="text-muted small ms-1">&bull; <?= number_format($exp_3d) ?> (3 Days)</span>
                    </div>
                    <div class="text-danger small" style="font-size: 0.75rem;"><?= number_format($exp_total) ?> total expired users</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Navigation Pills -->
    <div class="card-ui p-4">
        <div class="d-flex flex-wrap gap-2 mb-4 pb-2 border-bottom">
            <button type="button" class="tab-pill-btn active" onclick="switchMainTab('queue', this)">
                <i class="fa-solid fa-list-check"></i> Pending Queue (<?= count($pendingQueue) ?>)
            </button>
            <button type="button" class="tab-pill-btn" onclick="switchMainTab('rules', this)">
                <i class="fa-solid fa-sliders"></i> Expiry Rules &amp; Templates
            </button>
            <button type="button" class="tab-pill-btn" onclick="switchMainTab('composer', this)">
                <i class="fa-solid fa-pen-to-square"></i> Send Custom / Bulk SMS
            </button>
            <button type="button" class="tab-pill-btn" onclick="switchMainTab('logs', this)">
                <i class="fa-solid fa-clock-rotate-left"></i> Delivery Logs (<?= count($smsLogs) ?>)
            </button>
            <button type="button" class="tab-pill-btn" onclick="switchMainTab('connect', this)">
                <i class="fa-solid fa-plug-circle-bolt"></i> Mobile App Connection &amp; Settings
            </button>
        </div>

        <!-- =================================================================== -->
        <!-- TAB 1: PENDING QUEUE -->
        <!-- =================================================================== -->
        <div id="tab-content-queue" class="<?= $initialTab === 'queue' ? '' : 'd-none' ?>">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="fw-bold text-dark mb-0">Pending SMS Queue</h5>
                    <p class="text-secondary small mb-0">These messages are ready and will be sent automatically as soon as your Android Phone connects.</p>
                </div>
                <div>
                    <?php if (!empty($pendingQueue)): ?>
                        <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2 rounded-pill fw-bold">
                            <i class="fa-solid fa-spinner fa-spin me-1"></i> Waiting for Phone Dispatch
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($pendingQueue)): ?>
                <div class="text-center py-5">
                    <i class="fa-solid fa-circle-check text-success fs-1 mb-2 d-block opacity-75"></i>
                    <h6 class="fw-bold text-dark">Queue is Clean &amp; Empty!</h6>
                    <p class="text-muted small mb-3">There are no pending SMS waiting to be sent. All messages have been dispatched.</p>
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="switchMainTab('composer')">
                        + Compose New SMS
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle table-hover">
                        <thead class="table-light">
                            <tr style="font-size: 0.78rem; text-transform: uppercase;">
                                <th>#</th>
                                <th>Scheduled Time</th>
                                <th>Recipient</th>
                                <th>Subscriber</th>
                                <th>Type</th>
                                <th>Message Text</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingQueue as $idx => $pq): ?>
                                <tr>
                                    <td class="text-muted small fw-bold">#<?= $pq['id'] ?></td>
                                    <td class="small text-secondary"><?= date('d M, h:i A', strtotime($pq['scheduled_at'])) ?></td>
                                    <td>
                                        <span class="fw-bold text-dark font-monospace"><?= htmlspecialchars($pq['recipient_mobile']) ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($pq['username'])): ?>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($pq['username']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">Manual Number</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" style="font-size: 0.72rem;">
                                            <?= htmlspecialchars($pq['message_type']) ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 380px;">
                                        <div class="small text-dark text-truncate" title="<?= htmlspecialchars($pq['message_text']) ?>">
                                            <?= htmlspecialchars($pq['message_text']) ?>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this pending SMS?');">
                                            <input type="hidden" name="action" value="cancel_message">
                                            <input type="hidden" name="message_id" value="<?= $pq['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2 rounded-2" title="Cancel SMS">
                                                <i class="fa-solid fa-xmark"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- =================================================================== -->
        <!-- TAB 2: EXPIRY RULES & TEMPLATES -->
        <!-- =================================================================== -->
        <div id="tab-content-rules" class="<?= $initialTab === 'rules' ? '' : 'd-none' ?>">
            <form method="POST">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="gateway_mode" value="<?= htmlspecialchars($settings['gateway_mode']) ?>">
                <input type="hidden" name="sim_slot" value="<?= (int)$settings['sim_slot'] ?>">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-0">Automated Expiry Alerts &amp; Template Rules</h5>
                        <p class="text-secondary small mb-0">Configure when and what SMS message is automatically sent to expiring customers.</p>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary fw-bold px-4 rounded-3 shadow-sm">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Rules &amp; Templates
                        </button>
                    </div>
                </div>

                <!-- Master Toggle -->
                <div class="p-3 rounded-4 mb-4 border d-flex justify-content-between align-items-center shadow-sm" style="background: #f8fafc;">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-primary text-white rounded-3 p-2 fs-5">
                            <i class="fa-solid fa-robot"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <h6 class="fw-bold text-dark mb-0">Automated Daily Expiry Reminders (Master Switch)</h6>
                                <span id="masterToggleBadge" class="badge <?= !empty($settings['auto_expiry_reminders']) ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?>">
                                    <?= !empty($settings['auto_expiry_reminders']) ? 'Automated Scanning Active' : 'Master Scanner Paused' ?>
                                </span>
                            </div>
                            <p class="text-secondary small mb-0">When enabled, the server scans daily and queues reminder SMS for each active stage below.</p>
                        </div>
                    </div>
                    <div class="form-check form-switch fs-4">
                        <input class="form-check-input" type="checkbox" name="auto_expiry_reminders" value="1" id="autoExpiryToggle" <?= !empty($settings['auto_expiry_reminders']) ? 'checked' : '' ?> onchange="syncMasterToggleState()">
                    </div>
                </div>

                <!-- 3 Independent Milestone Stages (Each with ON/OFF Toggle) -->
                <div class="row g-3 mb-4">
                    <!-- Milestone 1: Before Expiry -->
                    <div class="col-md-4">
                        <div class="p-3 rounded-4 border h-100 bg-white shadow-sm d-flex flex-column" id="card_milestone_before">
                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                <h6 class="fw-bold text-primary mb-0"><i class="fa-solid fa-clock me-1"></i> 1. Before Expiry</h6>
                                <div class="form-check form-switch m-0 fs-5">
                                    <input class="form-check-input" type="checkbox" name="remind_before_expiry" value="1" id="remind_before_toggle" <?= !empty($settings['remind_before_expiry']) ? 'checked' : '' ?> onchange="syncMilestoneState('before')">
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small text-muted">Expiry se pehlay SMS</span>
                                <span id="badge_milestone_before" class="badge <?= !empty($settings['remind_before_expiry']) ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?> px-2 py-1">
                                    <?= !empty($settings['remind_before_expiry']) ? '<i class="fa-solid fa-check me-1"></i> Enabled (ON)' : '<i class="fa-solid fa-ban me-1"></i> Turned OFF' ?>
                                </span>
                            </div>
                            <div id="content_milestone_before" class="flex-grow-1" style="<?= empty($settings['remind_before_expiry']) ? 'opacity: 0.45; pointer-events: none;' : '' ?>">
                                <p class="small text-muted mb-2">Select kitne din pehlay reminder bhejna hai:</p>
                                <?php $currBefore = array_map('trim', explode(',', $settings['remind_days_before'] ?? '3,1')); ?>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-pill-day <?= in_array('7', $currBefore) ? 'btn-primary text-white active' : 'btn-outline-secondary' ?>" onclick="toggleDayPill(this, 'before', 7)">7 Days</button>
                                    <button type="button" class="btn btn-sm btn-pill-day <?= in_array('5', $currBefore) ? 'btn-primary text-white active' : 'btn-outline-secondary' ?>" onclick="toggleDayPill(this, 'before', 5)">5 Days</button>
                                    <button type="button" class="btn btn-sm btn-pill-day <?= in_array('3', $currBefore) ? 'btn-primary text-white active' : 'btn-outline-secondary' ?>" onclick="toggleDayPill(this, 'before', 3)">3 Days</button>
                                    <button type="button" class="btn btn-sm btn-pill-day <?= in_array('2', $currBefore) ? 'btn-primary text-white active' : 'btn-outline-secondary' ?>" onclick="toggleDayPill(this, 'before', 2)">2 Days</button>
                                    <button type="button" class="btn btn-sm btn-pill-day <?= in_array('1', $currBefore) ? 'btn-primary text-white active' : 'btn-outline-secondary' ?>" onclick="toggleDayPill(this, 'before', 1)">1 Day</button>
                                </div>
                                <div class="mt-2">
                                    <label class="form-label small fw-bold text-dark mb-1">Days List (Comma-separated):</label>
                                    <input type="text" name="remind_days_before" id="remind_days_before_input" class="form-control form-control-sm font-monospace" value="<?= htmlspecialchars($settings['remind_days_before'] ?? '3,1') ?>" oninput="syncPillsFromInput('before')" placeholder="e.g. 3, 1">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Milestone 2: On Expiry Day -->
                    <div class="col-md-4">
                        <div class="p-3 rounded-4 border h-100 bg-white shadow-sm d-flex flex-column" id="card_milestone_on">
                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                <h6 class="fw-bold text-warning text-dark mb-0"><i class="fa-solid fa-calendar-check text-warning me-1"></i> 2. On Expiry Day</h6>
                                <div class="form-check form-switch m-0 fs-5">
                                    <input class="form-check-input" type="checkbox" name="remind_on_expiry" value="1" id="remind_on_toggle" <?= !empty($settings['remind_on_expiry']) ? 'checked' : '' ?> onchange="syncMilestoneState('on')">
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small text-muted">Expiry walay din SMS</span>
                                <span id="badge_milestone_on" class="badge <?= !empty($settings['remind_on_expiry']) ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?> px-2 py-1">
                                    <?= !empty($settings['remind_on_expiry']) ? '<i class="fa-solid fa-check me-1"></i> Enabled (ON)' : '<i class="fa-solid fa-ban me-1"></i> Turned OFF' ?>
                                </span>
                            </div>
                            <div id="content_milestone_on" class="flex-grow-1 d-flex flex-column justify-content-center" style="<?= empty($settings['remind_on_expiry']) ? 'opacity: 0.45;' : '' ?>">
                                <div class="p-3 bg-light rounded-3 border">
                                    <div class="fw-semibold text-dark small mb-1"><i class="fa-solid fa-bell text-warning me-1"></i> Expiry Day Notification</div>
                                    <p class="small text-muted mb-0">Subscribers whose internet package expires <strong>today</strong> will receive an urgent renewal alert to avoid disconnection.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Milestone 3: After Expiry (Recovery) -->
                    <div class="col-md-4">
                        <div class="p-3 rounded-4 border h-100 bg-white shadow-sm d-flex flex-column" id="card_milestone_after">
                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                <h6 class="fw-bold text-danger mb-0"><i class="fa-solid fa-rotate-left me-1"></i> 3. After Expiry (Recovery)</h6>
                                <div class="form-check form-switch m-0 fs-5">
                                    <input class="form-check-input" type="checkbox" name="remind_after_expiry" value="1" id="remind_after_toggle" <?= !empty($settings['remind_after_expiry']) ? 'checked' : '' ?> onchange="syncMilestoneState('after')">
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small text-muted">Expiry k baad follow-up</span>
                                <span id="badge_milestone_after" class="badge <?= !empty($settings['remind_after_expiry']) ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?> px-2 py-1">
                                    <?= !empty($settings['remind_after_expiry']) ? '<i class="fa-solid fa-check me-1"></i> Enabled (ON)' : '<i class="fa-solid fa-ban me-1"></i> Turned OFF' ?>
                                </span>
                            </div>
                            <div id="content_milestone_after" class="flex-grow-1" style="<?= empty($settings['remind_after_expiry']) ? 'opacity: 0.45; pointer-events: none;' : '' ?>">
                                <p class="small text-muted mb-2">Select kitne din baad follow-up SMS bhejna hai:</p>
                                <?php $currAfter = array_map('trim', explode(',', $settings['remind_days_after'] ?? '1,3')); ?>
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <button type="button" class="btn btn-sm btn-pill-day <?= in_array('1', $currAfter) ? 'btn-danger text-white active' : 'btn-outline-secondary' ?>" onclick="toggleDayPill(this, 'after', 1)">1 Day After</button>
                                    <button type="button" class="btn btn-sm btn-pill-day <?= in_array('2', $currAfter) ? 'btn-danger text-white active' : 'btn-outline-secondary' ?>" onclick="toggleDayPill(this, 'after', 2)">2 Days After</button>
                                    <button type="button" class="btn btn-sm btn-pill-day <?= in_array('3', $currAfter) ? 'btn-danger text-white active' : 'btn-outline-secondary' ?>" onclick="toggleDayPill(this, 'after', 3)">3 Days After</button>
                                    <button type="button" class="btn btn-sm btn-pill-day <?= in_array('7', $currAfter) ? 'btn-danger text-white active' : 'btn-outline-secondary' ?>" onclick="toggleDayPill(this, 'after', 7)">7 Days After</button>
                                </div>
                                <div class="mt-2">
                                    <label class="form-label small fw-bold text-dark mb-1">Days List (Comma-separated):</label>
                                    <input type="text" name="remind_days_after" id="remind_days_after_input" class="form-control form-control-sm font-monospace" value="<?= htmlspecialchars($settings['remind_days_after'] ?? '1,3') ?>" oninput="syncPillsFromInput('after')" placeholder="e.g. 1, 3">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Smart Tags Helper Bar -->
                <div class="mb-3 p-3 bg-light rounded-3 border">
                    <div class="fw-bold small text-dark mb-1"><i class="fa-solid fa-tags text-primary me-1"></i> Click any Smart Tag below to insert into the active template:</div>
                    <div class="d-flex flex-wrap gap-1">
                        <span class="smart-tag-pill" onclick="insertTag('{name}')">{name} (Customer Name)</span>
                        <span class="smart-tag-pill" onclick="insertTag('{username}')">{username} (Login ID)</span>
                        <span class="smart-tag-pill" onclick="insertTag('{package}')">{package} (Plan Name)</span>
                        <span class="smart-tag-pill" onclick="insertTag('{price}')">{price} (Monthly Fee PKR)</span>
                        <span class="smart-tag-pill" onclick="insertTag('{expiry_date}')">{expiry_date} (Date &amp; Time)</span>
                        <span class="smart-tag-pill" onclick="insertTag('{days_left}')">{days_left} (Days Remaining)</span>
                        <span class="smart-tag-pill" onclick="insertTag('{days_expired}')">{days_expired} (Days Expired)</span>
                        <span class="smart-tag-pill" onclick="insertTag('{balance}')">{balance} (Account Balance)</span>
                        <span class="smart-tag-pill" onclick="insertTag('{company}')">{company} (Your Brand)</span>
                        <span class="smart-tag-pill" onclick="insertTag('{phone}')">{phone} (Your Helpline)</span>
                    </div>
                </div>

                <!-- 3 Editable Templates -->
                <div class="row g-4 mb-3">
                    <!-- Template 1: Before Expiry -->
                    <div class="col-lg-4">
                        <div class="card h-100 border p-3 rounded-4 shadow-sm" id="card_tpl_before">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="fw-bold text-dark mb-0">
                                    <i class="fa-solid fa-bell text-primary me-1"></i> Before Expiry Template
                                </label>
                                <span id="tpl_badge_before" class="badge <?= !empty($settings['remind_before_expiry']) ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?>">
                                    <?= !empty($settings['remind_before_expiry']) ? 'Stage 1 Active' : 'Stage 1 OFF' ?>
                                </span>
                            </div>
                            <textarea name="template_before_expiry" id="template_before_expiry" rows="6" class="form-control form-control-sm font-monospace" required onfocus="setActiveTextarea('template_before_expiry')"><?= htmlspecialchars($settings['template_before_expiry']) ?></textarea>
                            <small class="text-muted mt-2"><i class="fa-solid fa-circle-info me-1"></i> Sent X days prior to package end date.</small>
                        </div>
                    </div>

                    <!-- Template 2: On Expiry Day -->
                    <div class="col-lg-4">
                        <div class="card h-100 border p-3 rounded-4 shadow-sm" id="card_tpl_on">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="fw-bold text-dark mb-0">
                                    <i class="fa-solid fa-calendar-day text-warning me-1"></i> On Expiry Day Template
                                </label>
                                <span id="tpl_badge_on" class="badge <?= !empty($settings['remind_on_expiry']) ? 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?>">
                                    <?= !empty($settings['remind_on_expiry']) ? 'Stage 2 Active' : 'Stage 2 OFF' ?>
                                </span>
                            </div>
                            <textarea name="template_on_expiry" id="template_on_expiry" rows="6" class="form-control form-control-sm font-monospace" required onfocus="setActiveTextarea('template_on_expiry')"><?= htmlspecialchars($settings['template_on_expiry']) ?></textarea>
                            <small class="text-muted mt-2"><i class="fa-solid fa-circle-info me-1"></i> Sent on the day subscriber package expires.</small>
                        </div>
                    </div>

                    <!-- Template 3: After Expiry -->
                    <div class="col-lg-4">
                        <div class="card h-100 border p-3 rounded-4 shadow-sm" id="card_tpl_after">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="fw-bold text-dark mb-0">
                                    <i class="fa-solid fa-circle-exclamation text-danger me-1"></i> Expired Recovery Template
                                </label>
                                <span id="tpl_badge_after" class="badge <?= !empty($settings['remind_after_expiry']) ? 'bg-danger-subtle text-danger border border-danger-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle' ?>">
                                    <?= !empty($settings['remind_after_expiry']) ? 'Stage 3 Active' : 'Stage 3 OFF' ?>
                                </span>
                            </div>
                            <textarea name="template_after_expiry" id="template_after_expiry" rows="6" class="form-control form-control-sm font-monospace" required onfocus="setActiveTextarea('template_after_expiry')"><?= htmlspecialchars($settings['template_after_expiry']) ?></textarea>
                            <small class="text-muted mt-2">Sent to recover inactive / expired subscribers.</small>
                        </div>
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-primary fw-bold px-4 py-2 rounded-3 shadow-sm">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Rules &amp; Templates
                    </button>
                </div>
            </form>
        </div>

        <!-- =================================================================== -->
        <!-- TAB 3: CUSTOM & BULK SMS COMPOSER -->
        <!-- =================================================================== -->
        <div id="tab-content-composer" class="<?= $initialTab === 'composer' ? '' : 'd-none' ?>">
            <div class="row g-4">
                <div class="col-lg-7">
                    <form method="POST" id="bulkSmsForm" onsubmit="return confirmSendBroadcast();">
                        <input type="hidden" name="action" value="send_bulk_sms">

                        <h5 class="fw-bold text-dark mb-1">Compose &amp; Dispatch SMS Broadcast</h5>
                        <p class="text-secondary small mb-3">Send customized notices, offers, or billing updates to your customers via your phone SIM.</p>

                        <!-- Audience Target Selector -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small">Select Target Audience</label>
                            <select name="target_audience" id="target_audience" class="form-select" onchange="toggleAudienceOptions()">
                                <option value="all">All Subscribers (Active + Inactive / Expired)</option>
                                <option value="active">All Active Subscribers</option>
                                <option value="expired">All Expired Subscribers</option>
                                <option value="expiring_1d">Subscribers Expiring in 1 Day (<?= $exp_1d ?>)</option>
                                <option value="expiring_3d">Subscribers Expiring in 3 Days (<?= $exp_3d ?>)</option>
                                <option value="single_sub">Single Specific Subscriber</option>
                                <option value="manual">Manual Phone Number(s)</option>
                            </select>
                        </div>

                        <!-- Single Subscriber Selector -->
                        <div id="single_sub_container" class="mb-3 d-none">
                            <label class="form-label fw-bold text-dark small">Choose Subscriber</label>
                            <select name="subscriber_id" id="subscriber_id" class="form-select" onchange="previewSubscriberSms()">
                                <option value="">-- Search &amp; Choose Subscriber --</option>
                                <?php foreach ($allSubscribers as $sb): ?>
                                    <option value="<?= $sb['id'] ?>" data-username="<?= htmlspecialchars($sb['username']) ?>" data-name="<?= htmlspecialchars($sb['full_name']) ?>" data-mobile="<?= htmlspecialchars($sb['mobile'] ?: $sb['phone']) ?>">
                                        <?= htmlspecialchars($sb['username']) ?> - <?= htmlspecialchars($sb['full_name']) ?> (<?= htmlspecialchars($sb['mobile'] ?: $sb['phone'] ?: 'No Phone') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Manual Mobile Numbers -->
                        <div id="manual_numbers_container" class="mb-3 d-none">
                            <label class="form-label fw-bold text-dark small">Enter Mobile Numbers (Comma-separated)</label>
                            <textarea name="manual_numbers" id="manual_numbers" class="form-control form-control-sm font-monospace" rows="2" placeholder="03001234567, 03019876543, 03451122334"></textarea>
                        </div>

                        <!-- Message Textarea -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-bold text-dark small mb-0">Message Content</label>
                                <span class="badge bg-light text-secondary border font-monospace" id="charCounter">0 Chars | 1 SMS</span>
                            </div>
                            <textarea name="custom_message" id="composer_message" rows="5" class="form-control" placeholder="Type your message here... You can use smart tags like {name}, {username}, {package}, {expiry_date}, {company}" required oninput="updateComposerPreview()"></textarea>
                        </div>

                        <!-- Tags for composer -->
                        <div class="mb-4">
                            <small class="text-muted d-block mb-1">Quick Smart Tags:</small>
                            <div class="d-flex flex-wrap gap-1">
                                <span class="smart-tag-pill" onclick="insertComposerTag('{name}')">{name}</span>
                                <span class="smart-tag-pill" onclick="insertComposerTag('{username}')">{username}</span>
                                <span class="smart-tag-pill" onclick="insertComposerTag('{package}')">{package}</span>
                                <span class="smart-tag-pill" onclick="insertComposerTag('{price}')">{price}</span>
                                <span class="smart-tag-pill" onclick="insertComposerTag('{expiry_date}')">{expiry_date}</span>
                                <span class="smart-tag-pill" onclick="insertComposerTag('{company}')">{company}</span>
                                <span class="smart-tag-pill" onclick="insertComposerTag('{phone}')">{phone}</span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary fw-bold px-4 py-2 rounded-3 shadow-sm">
                            <i class="fa-solid fa-paper-plane me-2"></i> Queue SMS Broadcast
                        </button>
                    </form>
                </div>

                <!-- Right Live Preview Screen -->
                <div class="col-lg-5">
                    <div class="text-center mb-2">
                        <small class="text-secondary fw-bold text-uppercase" style="letter-spacing: 0.5px;">Live Mobile Handset Preview</small>
                    </div>
                    <div class="sms-preview-screen">
                        <div class="d-flex justify-content-between align-items-center mb-3 text-secondary small" style="font-size: 0.72rem;">
                            <span><i class="fa-solid fa-signal me-1"></i> SIM <?= (int)($settings['sim_slot'] ?? 1) ?></span>
                            <span><?= date('h:i A') ?></span>
                            <span><i class="fa-solid fa-battery-three-quarters"></i></span>
                        </div>
                        <div class="text-center text-muted small mb-3">
                            <i class="fa-solid fa-shield-halved text-success me-1"></i> Verified Sender: <strong><?= BRAND_SHORT_NAME ?></strong>
                        </div>
                        <div class="sms-bubble">
                            <div id="previewBubbleText" class="text-dark">
                                Salam! Your message preview will appear here in real-time as you type...
                            </div>
                            <div class="text-end text-muted mt-2" style="font-size: 0.68rem;">
                                <?= date('h:i A') ?> &bull; Delivered
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- TAB 4: SMS HISTORY & DELIVERY LOGS -->
        <!-- =================================================================== -->
        <div id="tab-content-logs" class="<?= $initialTab === 'logs' ? '' : 'd-none' ?>">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h5 class="fw-bold text-dark mb-0">SMS Logs &amp; Delivery History</h5>
                    <p class="text-secondary small mb-0">Record of all automated reminders and custom messages sent through your SIM.</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm" style="width: 160px;" onchange="window.location.href='?status_filter=' + this.value + '#tab-logs'">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
                        <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                        <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
                    </select>

                    <form method="POST" onsubmit="return confirm('Clear all sent logs from history?');">
                        <input type="hidden" name="action" value="clear_sent_logs">
                        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3">
                            <i class="fa-solid fa-trash-can me-1"></i> Clear Sent
                        </button>
                    </form>
                </div>
            </div>

            <?php if (empty($smsLogs)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-regular fa-message fs-1 mb-2 d-block opacity-50"></i>
                    No SMS logs found for this filter.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle table-hover" id="smsLogsTable">
                        <thead class="table-light">
                            <tr style="font-size: 0.78rem; text-transform: uppercase;">
                                <th>#</th>
                                <th>Date &amp; Time</th>
                                <th>Recipient</th>
                                <th>Subscriber</th>
                                <th>Type</th>
                                <th>Message</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($smsLogs as $l): ?>
                                <?php
                                    $bClass = 'bg-secondary';
                                    if ($l['status'] === 'sent' || $l['status'] === 'delivered') $bClass = 'bg-success';
                                    elseif ($l['status'] === 'pending') $bClass = 'bg-warning text-dark';
                                    elseif ($l['status'] === 'failed') $bClass = 'bg-danger';
                                    elseif ($l['status'] === 'cancelled') $bClass = 'bg-secondary text-white';
                                ?>
                                <tr>
                                    <td class="text-muted small">#<?= $l['id'] ?></td>
                                    <td class="small text-secondary"><?= date('d M Y, h:i A', strtotime($l['created_at'])) ?></td>
                                    <td class="fw-bold font-monospace text-dark"><?= htmlspecialchars($l['recipient_mobile']) ?></td>
                                    <td>
                                        <?php if (!empty($l['username'])): ?>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($l['username']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-secondary border" style="font-size: 0.72rem;">
                                            <?= htmlspecialchars($l['message_type']) ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 320px;">
                                        <div class="small text-dark text-truncate" title="<?= htmlspecialchars($l['message_text']) ?>">
                                            <?= htmlspecialchars($l['message_text']) ?>
                                        </div>
                                        <?php if (!empty($l['error_message'])): ?>
                                            <div class="text-danger small" style="font-size: 0.7rem;"><?= htmlspecialchars($l['error_message']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $bClass ?> rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                            <?= ucfirst($l['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($l['status'] === 'failed'): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="resend_message">
                                                <input type="hidden" name="message_id" value="<?= $l['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-2" title="Retry sending">
                                                    <i class="fa-solid fa-rotate-right"></i> Retry
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- =================================================================== -->
        <!-- TAB 5: MOBILE APP CONNECTION & GATEWAY SETTINGS -->
        <!-- =================================================================== -->
        <div id="tab-content-connect" class="<?= $initialTab === 'connect' ? '' : 'd-none' ?>">
            <div class="row g-4">
                <!-- Left: Step-by-Step Connection Guide -->
                <div class="col-lg-7">
                    <div class="card border rounded-4 p-4 shadow-sm h-100 bg-white">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div class="bg-success text-white rounded-3 p-2 fs-5">
                                <i class="fa-brands fa-android"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold text-dark mb-0">Android Phone SMS Gateway Setup</h5>
                                <p class="text-secondary small mb-0">Apne Android phone ko 2 minute me connect karein aur unlimited SIM SMS send karein</p>
                            </div>
                        </div>

                        <!-- Step 1 -->
                        <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                            <div class="badge rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; flex-shrink: 0;">1</div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Android Mobile Phone me App Install Karein</h6>
                                <p class="small text-muted mb-2">
                                    Play Store ya GitHub se <strong>Android SMS Gateway</strong> (by Capcom6) ya koi bhi standard SMS Webhook / Polling Gateway application download karein:
                                </p>
                                <a href="https://github.com/capcom6/android-sms-gateway/releases" target="_blank" class="btn btn-sm btn-outline-success fw-bold rounded-pill px-3">
                                    <i class="fa-brands fa-github me-1"></i> Download Android SMS Gateway APK
                                </a>
                                <span class="small text-muted ms-2">(Play Store: "SMS Gateway by Capcom6")</span>
                            </div>
                        </div>

                        <!-- Step 2 -->
                        <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                            <div class="badge rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; flex-shrink: 0;">2</div>
                            <div class="w-100">
                                <h6 class="fw-bold text-dark mb-1">Gateway Endpoint URL App me Enter Karein</h6>
                                <p class="small text-muted mb-2">App ki settings me ja kar <strong>Server URL / Webhook URL</strong> me ye link paste karein:</p>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control font-monospace bg-light fw-bold" id="gatewayUrlInput" value="<?= htmlspecialchars($gatewayEndpointUrl) ?>" readonly>
                                    <button class="btn btn-primary" type="button" onclick="copyToClipboardText('gatewayUrlInput')">
                                        <i class="fa-regular fa-copy me-1"></i> Copy URL
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                            <div class="badge rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; flex-shrink: 0;">3</div>
                            <div class="w-100">
                                <h6 class="fw-bold text-dark mb-1">Secret Gateway Token Enter Karein</h6>
                                <p class="small text-muted mb-2">App me <strong>API Key / Secret Token</strong> me apna ye unique security token paste karein:</p>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control font-monospace bg-light fw-bold" id="gatewayTokenInput" value="<?= htmlspecialchars($settings['gateway_token']) ?>" readonly>
                                    <button class="btn btn-primary" type="button" onclick="copyToClipboardText('gatewayTokenInput')">
                                        <i class="fa-regular fa-copy me-1"></i> Copy Token
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Step 4 -->
                        <div class="d-flex gap-3 mb-3 pb-3 border-bottom">
                            <div class="badge rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; flex-shrink: 0;">4</div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">Background Permission &amp; Battery Saver Disable Karein</h6>
                                <p class="small text-muted mb-0">
                                    Phone ki Settings &rarr; Apps me ja kar is app ke liye <strong>Battery Optimization</strong> ko "Unrestricted / Not Optimized" par set karein ta k mobile screen lock hone par bhi background me 24/7 SMS automatically send hote rahen.
                                </p>
                            </div>
                        </div>

                        <!-- Step 5 -->
                        <div class="d-flex gap-3">
                            <div class="badge rounded-circle bg-success text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; flex-shrink: 0;">5</div>
                            <div>
                                <h6 class="fw-bold text-dark mb-1">App me "Start Service" par tap karein</h6>
                                <p class="small text-muted mb-0">
                                    URL aur Token daalnay k baad app ki Main screen par ja kar <strong>"Start Service"</strong> karein (agar pehlay se chalu ho to aik baar <strong>Stop</strong> kar k dobara <strong>Start</strong> karein). Status <strong>Running</strong> aana chahiye.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Gateway Settings Form -->
                <div class="col-lg-5">
                    <div class="card border rounded-4 p-4 shadow-sm h-100 bg-white">
                        <form method="POST">
                            <input type="hidden" name="action" value="save_settings">
                            <input type="hidden" name="auto_expiry_reminders" value="<?= !empty($settings['auto_expiry_reminders']) ? 1 : 0 ?>">
                            <input type="hidden" name="remind_days_before" value="<?= htmlspecialchars($settings['remind_days_before']) ?>">
                            <input type="hidden" name="remind_on_expiry" value="<?= !empty($settings['remind_on_expiry']) ? 1 : 0 ?>">
                            <input type="hidden" name="remind_days_after" value="<?= htmlspecialchars($settings['remind_days_after']) ?>">
                            <input type="hidden" name="template_before_expiry" value="<?= htmlspecialchars($settings['template_before_expiry']) ?>">
                            <input type="hidden" name="template_on_expiry" value="<?= htmlspecialchars($settings['template_on_expiry']) ?>">
                            <input type="hidden" name="template_after_expiry" value="<?= htmlspecialchars($settings['template_after_expiry']) ?>">

                            <h5 class="fw-bold text-dark mb-1">Gateway Hardware Configuration</h5>
                            <p class="text-secondary small mb-3">Adjust SIM selection and advanced gateway protocols.</p>

                            <!-- Gateway Mode -->
                            <div class="mb-3">
                                <label class="form-label fw-bold text-dark small">Gateway Mode</label>
                                <select name="gateway_mode" id="gateway_mode_select" class="form-select form-select-sm" onchange="toggleGatewayModeInputs()">
                                    <option value="polling" <?= $settings['gateway_mode'] === 'polling' ? 'selected' : '' ?>>Android Mobile Phone (Polling Queue - Recommended)</option>
                                    <option value="direct_http" <?= $settings['gateway_mode'] === 'direct_http' ? 'selected' : '' ?>>Direct HTTP Push (Phone Local IP / Webhook Server)</option>
                                    <option value="custom_api" <?= $settings['gateway_mode'] === 'custom_api' ? 'selected' : '' ?>>Custom 3rd-Party SMS API</option>
                                </select>
                                <small class="text-muted">Polling mode works everywhere (even when phone is on 4G without public IP).</small>
                            </div>

                            <!-- SIM Slot Selection -->
                            <div class="mb-3">
                                <label class="form-label fw-bold text-dark small">Phone SIM Slot for Sending</label>
                                <div class="d-flex gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="sim_slot" id="sim1" value="1" <?= (int)($settings['sim_slot'] ?? 1) === 1 ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-semibold text-dark small" for="sim1">SIM Card 1</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="sim_slot" id="sim2" value="2" <?= (int)($settings['sim_slot'] ?? 1) === 2 ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-semibold text-dark small" for="sim2">SIM Card 2</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Optional Direct HTTP URL -->
                            <div id="direct_http_box" class="mb-3 <?= $settings['gateway_mode'] !== 'direct_http' ? 'd-none' : '' ?>">
                                <label class="form-label fw-bold text-dark small">Direct Phone Webhook URL</label>
                                <input type="text" name="gateway_http_url" class="form-control form-control-sm font-monospace" placeholder="http://192.168.1.50:8080/v1/sms/send" value="<?= htmlspecialchars($settings['gateway_http_url'] ?? '') ?>">
                                <small class="text-muted">Only needed if phone runs local HTTP server.</small>
                            </div>

                            <button type="submit" class="btn btn-primary fw-bold w-100 py-2 rounded-3 mb-3 shadow-sm">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Save Configuration
                            </button>
                        </form>

                        <hr class="my-3">

                        <!-- Token Regeneration -->
                        <form method="POST" onsubmit="return confirm('Regenerating this token will disconnect your phone until you update the app with the new token. Proceed?');">
                            <input type="hidden" name="action" value="regenerate_token">
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100 rounded-3">
                                <i class="fa-solid fa-arrows-rotate me-1"></i> Regenerate Secret Gateway Token
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Send Test SMS Modal -->
<div class="modal fade" id="testSmsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fa-solid fa-paper-plane text-primary me-2"></i> Send Real Test SMS
                </h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="send_test_sms">
                <div class="modal-body p-4">
                    <p class="text-secondary small mb-3">Send a test message through your mobile phone SIM gateway to ensure everything is working 100%.</p>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark small">Mobile Number</label>
                        <input type="text" name="test_mobile" class="form-control font-monospace" placeholder="e.g. 03016542060" value="<?= htmlspecialchars(BRAND_SUPPORT_PHONE) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark small">Test Message</label>
                        <textarea name="test_message" rows="3" class="form-control font-monospace" required>Salam! This is a test SMS from LASANI NETWORK SMS Gateway. Connection is 100% working!</textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4">
                        <i class="fa-solid fa-paper-plane me-1"></i> Send via Phone
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var activeTemplateId = 'template_before_expiry';

function setActiveTextarea(id) {
    activeTemplateId = id;
}

function insertTag(tag) {
    var ta = document.getElementById(activeTemplateId);
    if (!ta) return;
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var text = ta.value;
    ta.value = text.substring(0, start) + tag + text.substring(end);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = start + tag.length;
}

function insertComposerTag(tag) {
    var ta = document.getElementById('composer_message');
    if (!ta) return;
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var text = ta.value;
    ta.value = text.substring(0, start) + tag + text.substring(end);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = start + tag.length;
    updateComposerPreview();
}

function syncMasterToggleState() {
    var chk = document.getElementById('autoExpiryToggle');
    var isMaster = chk ? chk.checked : false;
    var badge = document.getElementById('masterToggleBadge');
    if (badge) {
        if (isMaster) {
            badge.className = 'badge bg-success-subtle text-success border border-success-subtle';
            badge.innerText = 'Automated Scanning Active';
        } else {
            badge.className = 'badge bg-secondary-subtle text-secondary border border-secondary-subtle';
            badge.innerText = 'Master Scanner Paused';
        }
    }
}

function syncMilestoneState(stage) {
    var chk = document.getElementById('remind_' + stage + '_toggle');
    var isEnabled = chk ? chk.checked : false;
    var badge = document.getElementById('badge_milestone_' + stage);
    var content = document.getElementById('content_milestone_' + stage);
    var tplBadge = document.getElementById('tpl_badge_' + stage);

    if (badge) {
        if (isEnabled) {
            badge.className = 'badge bg-success-subtle text-success border border-success-subtle px-2 py-1';
            badge.innerHTML = '<i class="fa-solid fa-check me-1"></i> Enabled (ON)';
        } else {
            badge.className = 'badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1';
            badge.innerHTML = '<i class="fa-solid fa-ban me-1"></i> Turned OFF';
        }
    }

    if (content) {
        if (isEnabled) {
            content.style.opacity = '1';
            content.style.pointerEvents = 'auto';
        } else {
            content.style.opacity = '0.45';
            content.style.pointerEvents = 'none';
        }
    }

    if (tplBadge) {
        var num = stage === 'before' ? '1' : (stage === 'on' ? '2' : '3');
        var color = stage === 'before' ? 'primary' : (stage === 'on' ? 'warning' : 'danger');
        if (isEnabled) {
            tplBadge.className = 'badge bg-' + color + '-subtle text-' + color + (color === 'warning' ? '-emphasis' : '') + ' border border-' + color + '-subtle';
            tplBadge.innerText = 'Stage ' + num + ' Active';
        } else {
            tplBadge.className = 'badge bg-secondary-subtle text-secondary border border-secondary-subtle';
            tplBadge.innerText = 'Stage ' + num + ' OFF';
        }
    }
}

function toggleDayPill(btn, stage, day) {
    var input = document.getElementById('remind_days_' + stage + '_input');
    if (!input) return;

    var days = input.value.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0; });
    var sDay = String(day);
    var idx = days.indexOf(sDay);

    var activeColor = stage === 'before' ? 'btn-primary' : 'btn-danger';

    if (idx !== -1) {
        days.splice(idx, 1);
        btn.classList.remove(activeColor, 'text-white', 'active');
        btn.classList.add('btn-outline-secondary');
    } else {
        days.push(sDay);
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add(activeColor, 'text-white', 'active');
    }

    // Sort descending for before, ascending for after
    days.sort(function(a, b) {
        return stage === 'before' ? (parseInt(b) - parseInt(a)) : (parseInt(a) - parseInt(b));
    });

    input.value = days.join(',');
}

function syncPillsFromInput(stage) {
    var input = document.getElementById('remind_days_' + stage + '_input');
    if (!input) return;
    var activeColor = stage === 'before' ? 'btn-primary' : 'btn-danger';
    var days = input.value.split(',').map(function(s) { return s.trim(); });

    var container = document.getElementById('content_milestone_' + stage);
    if (!container) return;

    container.querySelectorAll('.btn-pill-day').forEach(function(btn) {
        var txt = btn.innerText.replace(/[^0-9]/g, '');
        if (days.includes(txt)) {
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add(activeColor, 'text-white', 'active');
        } else {
            btn.classList.remove(activeColor, 'text-white', 'active');
            btn.classList.add('btn-outline-secondary');
        }
    });
}

function updateMilestonesString() {
    var beforeArr = [];
    document.querySelectorAll('.before-day-chk:checked').forEach(function(el) {
        beforeArr.push(el.value);
    });
    if (beforeArr.length > 0) {
        document.getElementById('remind_days_before_input').value = beforeArr.join(',');
    }

    var afterArr = [];
    document.querySelectorAll('.after-day-chk:checked').forEach(function(el) {
        afterArr.push(el.value);
    });
    if (afterArr.length > 0) {
        document.getElementById('remind_days_after_input').value = afterArr.join(',');
    }
}

function toggleAudienceOptions() {
    var val = document.getElementById('target_audience').value;
    var singleBox = document.getElementById('single_sub_container');
    var manualBox = document.getElementById('manual_numbers_container');
    
    if (val === 'single_sub') {
        singleBox.classList.remove('d-none');
        manualBox.classList.add('d-none');
    } else if (val === 'manual') {
        manualBox.classList.remove('d-none');
        singleBox.classList.add('d-none');
    } else {
        singleBox.classList.add('d-none');
        manualBox.classList.add('d-none');
    }
    updateComposerPreview();
}

function updateComposerPreview() {
    var text = document.getElementById('composer_message').value;
    var counterEl = document.getElementById('charCounter');
    var previewEl = document.getElementById('previewBubbleText');
    
    var len = text.length;
    var segments = Math.max(1, Math.ceil(len / 160));
    if (counterEl) {
        counterEl.innerText = len + ' Chars | ' + segments + ' SMS';
    }

    if (previewEl) {
        if (!text.trim()) {
            previewEl.innerText = "Salam! Your message preview will appear here in real-time as you type...";
        } else {
            // Sample placeholder replacement for preview
            var sample = text
                .replace(/{name}/g, "Muhammad Ali")
                .replace(/{username}/g, "ali_net1")
                .replace(/{package}/g, "10Mbps")
                .replace(/{price}/g, "2,000")
                .replace(/{expiry_date}/g, "<?= date('d M Y') ?>")
                .replace(/{company}/g, "<?= BRAND_SHORT_NAME ?>")
                .replace(/{phone}/g, "<?= BRAND_SUPPORT_PHONE ?>");
            previewEl.innerText = sample;
        }
    }
}

function previewSubscriberSms() {
    var sel = document.getElementById('subscriber_id');
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        var uName = opt.getAttribute('data-username');
        var fName = opt.getAttribute('data-name');
        var text = document.getElementById('composer_message').value;
        if (text) {
            var sample = text
                .replace(/{name}/g, fName || uName)
                .replace(/{username}/g, uName)
                .replace(/{company}/g, "<?= BRAND_SHORT_NAME ?>")
                .replace(/{phone}/g, "<?= BRAND_SUPPORT_PHONE ?>");
            document.getElementById('previewBubbleText').innerText = sample;
        }
    }
}

function toggleGatewayModeInputs() {
    var m = document.getElementById('gateway_mode_select').value;
    var box = document.getElementById('direct_http_box');
    if (m === 'direct_http') {
        box.classList.remove('d-none');
    } else {
        box.classList.add('d-none');
    }
}

function copyToClipboardText(inputId) {
    var copyText = document.getElementById(inputId);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(function() {
        alert("Copied to clipboard: " + copyText.value);
    });
}

function toggleSchedulerInput() {
    var chk = document.getElementById('enable_scheduler');
    var isSched = chk ? chk.checked : false;
    var container = document.getElementById('scheduler_container');
    var btn = document.getElementById('submitComposerBtn');
    var badge = document.getElementById('sendModeBadge');
    var dateInput = document.getElementById('scheduled_at_input');
    var desc = document.getElementById('scheduler_desc');

    if (isSched) {
        if (container) container.classList.remove('d-none');
        if (dateInput) {
            dateInput.setAttribute('required', 'required');
            if (!dateInput.value) {
                var now = new Date();
                now.setHours(now.getHours() + 1);
                var pad = function(n) { return String(n).padStart(2, '0'); };
                var yyyy = now.getFullYear();
                var mm = pad(now.getMonth() + 1);
                var dd = pad(now.getDate());
                var hh = pad(now.getHours());
                var min = pad(now.getMinutes());
                dateInput.value = yyyy + '-' + mm + '-' + dd + 'T' + hh + ':' + min;
            }
        }
        if (btn) {
            btn.innerHTML = '<i class="fa-solid fa-clock me-2"></i> Schedule SMS Broadcast';
            btn.className = 'btn btn-warning text-dark fw-bold px-4 py-2 rounded-3 shadow-sm';
        }
        if (badge) {
            badge.className = 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2 rounded-pill';
            badge.innerHTML = '<i class="fa-solid fa-clock me-1"></i> Mode: Scheduled Dispatch';
        }
        if (desc) {
            desc.innerHTML = 'Scheduler is <strong>ACTIVE</strong>. Message will wait until the selected date and time.';
        }
    } else {
        if (container) container.classList.add('d-none');
        if (dateInput) dateInput.removeAttribute('required');
        if (btn) {
            btn.innerHTML = '<i class="fa-solid fa-bolt me-2"></i> Send Immediately (Direct Send)';
            btn.className = 'btn btn-primary fw-bold px-4 py-2 rounded-3 shadow-sm';
        }
        if (badge) {
            badge.className = 'badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill';
            badge.innerHTML = '<i class="fa-solid fa-bolt me-1"></i> Mode: Direct Instant Send';
        }
        if (desc) {
            desc.innerHTML = 'Default: <strong>Direct Send (Immediate)</strong>. Turn this switch ON if you want to set a specific future date and time.';
        }
    }
}

function confirmSendBroadcast() {
    var chk = document.getElementById('enable_scheduler');
    var isSched = chk ? chk.checked : false;
    if (isSched) {
        var dateInput = document.getElementById('scheduled_at_input');
        if (!dateInput || !dateInput.value) {
            alert('Please select a scheduled date and time.');
            if (dateInput) dateInput.focus();
            return false;
        }
        return confirm('Confirm scheduled dispatch for: ' + dateInput.value + '?');
    }
    return confirm('Send this SMS broadcast immediately (Direct Send)?');
}

function switchMainTab(tabName, el) {
    if (tabName === 'custom') tabName = 'composer';
    var tabs = ['queue', 'rules', 'composer', 'logs', 'connect'];
    tabs.forEach(function(t) {
        var content = document.getElementById('tab-content-' + t);
        if (content) content.classList.add('d-none');
    });

    document.querySelectorAll('.tab-pill-btn').forEach(function(btn) {
        btn.classList.remove('active');
    });

    var targetContent = document.getElementById('tab-content-' + tabName);
    if (targetContent) targetContent.classList.remove('d-none');

    if (el) {
        el.classList.add('active');
    } else {
        // Find corresponding button
        document.querySelectorAll('.tab-pill-btn').forEach(function(btn) {
            if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(tabName)) {
                btn.classList.add('active');
            }
        });
    }

    if (tabName === 'composer') {
        updateComposerPreview();
    }
}

// Auto open tab from hash if provided
document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash) {
        var h = window.location.hash.replace('#tab-', '').replace('#', '');
        if (['queue', 'rules', 'composer', 'logs', 'connect'].includes(h)) {
            switchMainTab(h);
        }
    }
});
</script>

<?php require_once 'footer.php'; ?>
