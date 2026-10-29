<?php
session_start();
require_once '../config/db.php';
require_once '../config/radius_kick_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_SESSION['operator_id'];
    $user_id = (int)$_POST['user_id'];
    $package_id = (int)$_POST['package_id'];
    $is_mgr = !empty($_SESSION['is_manager']);
    $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
    $expiry_type = $is_mgr ? 'default' : ($_POST['expiry_type'] ?? 'default'); // Managers cannot set custom expiry!
    $custom_expiry = $is_mgr ? '' : ($_POST['custom_expiry'] ?? '');
    $payment_amount = isset($_POST['payment_amount']) ? (float)$_POST['payment_amount'] : 0;
    if ($payment_amount < 0) $payment_amount = 0;
    $redirect_url = (!empty($_POST['redirect_to']) && strpos($_POST['redirect_to'], 'subscribers') !== false) ? 'subscribers.php' : 'dashboard.php';
    
    try {
        $userStmt = $pdo->prepare("SELECT username, expiry_date, balance, dealer_id, package_id FROM subscribers WHERE id = ? AND client_id = ?");
        $userStmt->execute([$user_id, $client_id]);
        $uData = $userStmt->fetch();
        
        if (!$uData) {
            die("<script>alert('User not found!'); window.location='dashboard.php';</script>");
        }
        
        $cd = check_renewal_cooldown($pdo, $user_id, 600);
        if (!$cd['allowed']) {
            $rem_mins = $cd['remaining_minutes'];
            $rem_secs = $cd['remaining_seconds'];
            echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Duplicate Renewal Blocked</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
</head>
<body class='bg-light d-flex align-items-center justify-content-center' style='min-height: 100vh;'>
    <div class='card border-0 shadow-sm rounded-4 p-4 text-center' style='max-width: 480px; width: 92%;'>
        <div class='text-warning mb-3' style='font-size: 3.5rem;'><i class='fa-solid fa-clock-rotate-left'></i></div>
        <h4 class='fw-bold text-dark mb-2'>Duplicate Renewal Blocked!</h4>
        <div class='alert alert-warning text-dark border-0 rounded-3 text-start small mb-3'>
            <strong>⚠️ Is user ka package abhi renew ho chuka hai!</strong><br>
            Network slow hony ya multi-click ki waja se double package renewal se bachne k liye agle <strong>{$rem_mins} minute ({$rem_secs}s)</strong> tak dubara renew nahi kiya ja sakta.
        </div>
        <a href='{$redirect_url}' class='btn btn-primary fw-bold px-4 py-2 rounded-pill shadow-sm'>
            <i class='fa-solid fa-arrow-left me-1'></i> Wapis Jayein
        </a>
    </div>
    <script>
        alert('Duplicate Renewal Blocked!\\n\\nIs user ka package abhi renew ho chuka hai.\\nDubara renew karne k liye {$rem_mins} minute intezar karein taake double deduction na ho.');
        window.location.href = '{$redirect_url}';
    </script>
</body>
</html>";
            exit;
        }

        $u = $uData['username'];
        $current_expiry = strtotime($uData['expiry_date'] ?? '1970-01-01');
        $now = time();
        
        $p = $pdo->prepare("SELECT p.name, p.validity_days, p.rate_limit, p.price, p.wan_provider, p.pool_id, pl.pool_name 
                            FROM packages p 
                            LEFT JOIN ip_pools pl ON p.pool_id = pl.id 
                            WHERE p.id = ? AND (p.client_id = ? OR p.client_id = 0)");
        $p->execute([$package_id, $client_id]);
        $pkg = $p->fetch();
        
        if (!$pkg) {
            die("<script>alert('Package not found!'); window.location='dashboard.php';</script>");
        }
        
        if ($expiry_type === 'custom') {
            $expiry_date = date('Y-m-d H:i:s', strtotime($custom_expiry));
            $new_exp_ts = strtotime($expiry_date);

            // Pro-rata adjustment for custom expiry
            $old_remaining_credit = 0;
            if ($current_expiry > $now) {
                $cur_pq = $pdo->prepare("SELECT price, validity_days FROM packages WHERE id = ?");
                $cur_pq->execute([$uData['package_id']]);
                $cur_pkg = $cur_pq->fetch();
                $cur_price = (float)($cur_pkg['price'] ?? $pkg['price']);
                $cur_val = (int)(($cur_pkg['validity_days'] ?? 0) > 0 ? $cur_pkg['validity_days'] : 30);
                $cur_daily = $cur_price / $cur_val;
                $old_remaining_credit = (($current_expiry - $now) / 86400) * $cur_daily;
            }

            $new_total_cost = 0;
            $pkg_validity = (int)(($pkg['validity_days'] ?? 0) > 0 ? $pkg['validity_days'] : 30);
            $new_daily = (float)$pkg['price'] / $pkg_validity;
            if ($new_exp_ts > $now) {
                $new_total_cost = (($new_exp_ts - $now) / 86400) * $new_daily;
            }

            // Positive = charge subscriber (balance minus), Negative = refund subscriber (balance plus)
            $net_adjustment = round($new_total_cost - $old_remaining_credit, 2);
        } else {
            $days = (int)(($pkg['validity_days'] ?? 0) > 0 ? $pkg['validity_days'] : 30);
            $package_changed = ($package_id !== (int)$uData['package_id']);

            if ($package_changed && $current_expiry > $now) {
                // Package upgraded/changed mid-cycle!
                // Start new package today for full validity
                $expiry_date = date('Y-m-d H:i:s', strtotime("+$days days", $now));
                $new_exp_ts = strtotime($expiry_date);

                $cur_pq = $pdo->prepare("SELECT price, validity_days FROM packages WHERE id = ?");
                $cur_pq->execute([$uData['package_id']]);
                $cur_pkg = $cur_pq->fetch();
                $cur_price = (float)($cur_pkg['price'] ?? $pkg['price']);
                $cur_val = (int)(($cur_pkg['validity_days'] ?? 0) > 0 ? $cur_pkg['validity_days'] : 30);
                $cur_daily = $cur_price / $cur_val;
                $old_remaining_credit = (($current_expiry - $now) / 86400) * $cur_daily;

                $new_total_cost = (float)$pkg['price'];
                $net_adjustment = round($new_total_cost - $old_remaining_credit, 2);
            } else {
                if ($current_expiry > $now) {
                    $expiry_date = date('Y-m-d H:i:s', strtotime("+$days days", $current_expiry));
                } else {
                    $expiry_date = date('Y-m-d H:i:s', strtotime("+$days days", $now));
                }
                $new_exp_ts = strtotime($expiry_date);
                $net_adjustment = (float)$pkg['price'];
            }
        }
        
        $current_bal = (float)$uData['balance'];
        $bal_after_debit = round($current_bal - $net_adjustment, 2);
        $final_bal = round($bal_after_debit + $payment_amount, 2);
        $new_bal = $bal_after_debit; // For debit ledger entry
        
        $pdo->beginTransaction();

        // Dealer Balance Handling (if user belongs to a dealer)
        $sub_dealer_id = (int)($uData['dealer_id'] ?? 0);
        if ($sub_dealer_id > 0) {
            $old_remaining_value = 0;
            if (!empty($uData['expiry_date']) && strtotime($uData['expiry_date']) > time()) {
                $old_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                $old_dpStmt->execute([$sub_dealer_id, $uData['package_id']]);
                $old_dp_price = $old_dpStmt->fetchColumn();
                if ($old_dp_price === false) {
                    $opq = $pdo->prepare("SELECT price FROM packages WHERE id = ?");
                    $opq->execute([$uData['package_id']]);
                    $old_dp_price = $opq->fetchColumn() ?: 0;
                }
                $old_seconds = strtotime($uData['expiry_date']) - time();
                $old_days = $old_seconds / 86400;
                $old_price_per_day = $old_dp_price / 30;
                $old_remaining_value = $old_days * $old_price_per_day;
            }

            $new_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
            $new_dpStmt->execute([$sub_dealer_id, $package_id]);
            $new_dp_price = $new_dpStmt->fetchColumn();
            if ($new_dp_price === false) {
                $new_dp_price = $pkg['price'] ?? 0;
            }

            $new_total_value = 0;
            $new_seconds = strtotime($expiry_date) - time();
            if ($new_seconds > 0) {
                $new_days = $new_seconds / 86400;
                $new_price_per_day = $new_dp_price / 30;
                $new_total_value = $new_days * $new_price_per_day;
            }

            $net_deduction = round($new_total_value - $old_remaining_value, 2);
            if ($net_deduction != 0) {
                $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $sub_dealer_id]);
                $note = ($net_deduction > 0)
                    ? "Package Upgrade/Renew for user $u. Total Cost: Rs." . round($new_total_value, 2) . ", Old Credit: Rs." . round($old_remaining_value, 2) . ". Net Deducted: Rs. $net_deduction"
                    : "Package Change/Refund for user $u. Total Cost: Rs." . round($new_total_value, 2) . ", Old Credit: Rs." . round($old_remaining_value, 2) . ". Net Credited: Rs. " . abs($net_deduction);
                $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
            }
        }
        
        $new_status = ($new_exp_ts > $now) ? 'active' : 'expired';

        // Update DB
        $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = ?, balance = ?, last_renewed_at = NOW() WHERE id = ?")
            ->execute([$package_id, $expiry_date, $new_status, $final_bal, $user_id]);

        // Actor details for receipt & logs
        $actor_role = !empty($_SESSION['is_manager']) ? 'Manager' : 'Admin';
        $actor_name = !empty($_SESSION['manager_name']) ? $_SESSION['manager_name'] : (!empty($_SESSION['operator_name']) ? $_SESSION['operator_name'] : 'Admin');
        $actor_mgr_id = !empty($_SESSION['is_manager']) ? (int)($_SESSION['manager_id'] ?? 0) : null;

        // Handle optional renewal receipt upload
        if (!empty($_FILES['renewal_receipt']) && isset($_FILES['renewal_receipt']['error']) && $_FILES['renewal_receipt']['error'] === UPLOAD_ERR_OK) {
            handle_renewal_receipt_upload($_FILES['renewal_receipt'], $user_id, $pdo, "{$actor_role} ({$actor_name})");
        }
            
        // Update Radius
        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
        if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit' && $pkg['rate_limit'] !== 'Unlimited') {
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
        }

        // NEVER inject Mikrotik-Address-List (prevents 700 dynamic entries and WAN hijacking)
        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$u]);

        // Determine profile name (e.g. 4Mbps)
        $target_profile = !empty($pkg['pool_name']) ? $pkg['pool_name'] : '';
        if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
            if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg['name'], $m_prof)) {
                $target_profile = $m_prof[1] . 'Mbps';
            }
        }
        
        if (!empty($target_profile)) {
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$u]);
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$u, $target_profile]);
        }
        
        $formatted_expiry = date('d M Y H:i:s', strtotime($expiry_date));
        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
        if ($new_status === 'expired') {
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
        }
        

        // Logs
        $act_dealer_id = ($sub_dealer_id > 0) ? $sub_dealer_id : null;
        $act_log_text = "Package Renewed to " . date('d M Y, h:i A', $new_exp_ts);
        if ($payment_amount > 0) {
            $act_log_text .= ". Payment received: Rs. " . number_format($payment_amount, 2);
        }
        if ($net_adjustment > 0) {
            $act_log_text .= ". Charged: Rs. " . number_format($net_adjustment, 2);
        } elseif ($net_adjustment < 0) {
            $act_log_text .= ". Credited: Rs. " . number_format(abs($net_adjustment), 2);
        }
        $act_log_text .= " ({$pkg['name']}) by $actor_role: $actor_name";

        $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, dealer_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, ?, 'User', ?)")
            ->execute([$client_id, $actor_mgr_id, $act_dealer_id, $actor_name, $actor_role, $u, $act_log_text]);
            
        // Ledger Entry 1: Renewal debit
        if ($net_adjustment > 0) {
            if (!empty($package_changed) && !empty($old_remaining_credit) && $old_remaining_credit > 0) {
                $ledger_desc = "Package Upgraded to {$pkg['name']} (Full Month Rs. " . number_format($new_total_cost, 2) . " minus Unused Credit Rs. " . number_format($old_remaining_credit, 2) . ") by $actor_role: $actor_name";
            } else {
                $ledger_desc = "Package Renewed: {$pkg['name']} by $actor_role: $actor_name";
            }
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, ?, ?)")
                ->execute([$client_id, $u, $net_adjustment, $bal_after_debit, $ledger_desc]);
        } elseif ($net_adjustment < 0) {
            $abs_refund = abs($net_adjustment);
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $u, $abs_refund, $bal_after_debit, "Expiry Adjustment Refund ({$pkg['name']}) by $actor_role: $actor_name"]);
        }

        // Ledger Entry 2: Payment Received Credit
        if ($payment_amount > 0) {
            $pay_desc = "Payment received during Renewal (Cash) by $actor_role: $actor_name";
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $u, $payment_amount, $final_bal, $pay_desc]);

            // Add to Manager cash_in_hand
            if ($is_mgr && $actor_mgr_id > 0) {
                try {
                    $pdo->prepare("UPDATE managers SET cash_in_hand = cash_in_hand + ? WHERE id = ? AND client_id = ?")
                        ->execute([$payment_amount, $actor_mgr_id, $client_id]);
                } catch (Exception $e) {}
            }
        }
        
        $pdo->commit();
        
        // Kick active session so router reconnects immediately with renewed speed & profile
        try {
            kick_subscriber_session($pdo, $u, $client_id);
        } catch (Exception $e) {}

        $ret = urlencode($redirect_url);
        $paid_val = ($payment_amount > 0) ? $payment_amount : (($current_bal > 0) ? min((float)$current_bal, (float)$net_adjustment) : 0);
        $target_url = "print_receipt.php?sub_id={$user_id}&paid={$paid_val}&type=renew&return={$ret}";
        $json_alert = json_encode("User renewed successfully! Opening 80mm Print Receipt...");

        echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>User Renewed</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
</head>
<body class='bg-light d-flex align-items-center justify-content-center' style='min-height: 100vh;'>
    <div class='card border-0 shadow-sm rounded-4 p-4 text-center' style='max-width: 440px; width: 92%;'>
        <div class='spinner-border text-success mb-3' style='width: 3rem; height: 3rem;' role='status'></div>
        <h4 class='fw-bold text-dark mb-1'><i class='fa-solid fa-circle-check text-success me-2'></i> Renewed Successfully!</h4>
        <p class='text-muted small mb-3'>Opening 80mm Print Receipt...</p>
        <a href='{$target_url}' class='btn btn-success fw-bold px-4 py-2 rounded-pill shadow-sm'>
            <i class='fa-solid fa-print me-1'></i> Open 80mm Receipt Now
        </a>
    </div>
    <script>
        var alertMsg = {$json_alert};
        if (alertMsg) alert(alertMsg);
        window.location.href = '{$target_url}';
    </script>
</body>
</html>";
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        die("<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location='$redirect_url';</script>");
    }
} else {
    header("Location: dashboard.php");
    exit;
}
?>
