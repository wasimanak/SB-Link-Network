<?php
session_start();
require_once '../config/db.php';
require_once '../config/radius_kick_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_SESSION['operator_id'];
    $user_id = (int)$_POST['user_id'];
    $package_id = (int)$_POST['package_id'];
    $expiry_type = $_POST['expiry_type']; // 'default' or 'custom'
    $custom_expiry = $_POST['custom_expiry'] ?? '';
    $redirect_url = (!empty($_POST['redirect_to']) && strpos($_POST['redirect_to'], 'subscribers') !== false) ? 'subscribers.php' : 'dashboard.php';
    
    try {
        $userStmt = $pdo->prepare("SELECT username, expiry_date, balance, dealer_id, package_id FROM subscribers WHERE id = ? AND client_id = ?");
        $userStmt->execute([$user_id, $client_id]);
        $uData = $userStmt->fetch();
        
        if (!$uData) {
            die("<script>alert('User not found!'); window.location='dashboard.php';</script>");
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
        } else {
            $days = (int)$pkg['validity_days'];
            if ($current_expiry > $now) {
                // Active: Extend from current expiry
                $expiry_date = date('Y-m-d H:i:s', strtotime("+$days days", $current_expiry));
            } else {
                // Expired: Extend from now
                $expiry_date = date('Y-m-d H:i:s', strtotime("+$days days", $now));
            }
        }
        
        $current_bal = (float)$uData['balance'];
        $pkg_price = (float)$pkg['price'];
        $new_bal = $current_bal - $pkg_price;
        
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
            if ($net_deduction > 0) {
                $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $sub_dealer_id]);
                $note = "Package Upgrade/Renew for user $u. Total Cost: Rs." . round($new_total_value, 2) . ", Old Credit: Rs." . round($old_remaining_value, 2) . ". Net Deducted: Rs. $net_deduction";
                $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
            } else {
                $note = "Package Downgrade/Change for user $u. Total Cost: Rs." . round($new_total_value, 2) . " covered by Old Credit Rs." . round($old_remaining_value, 2) . ". No balance deducted.";
                $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
            }
        }
        
        // Update DB
        $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active', balance = ? WHERE id = ?")
            ->execute([$package_id, $expiry_date, $new_bal, $user_id]);
            
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
        
        // Logs
        $act_dealer_id = ($sub_dealer_id > 0) ? $sub_dealer_id : null;
        $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, 'Admin', ?, 'User', 'Package Renewed & Expiry Updated')")
            ->execute([$client_id, $act_dealer_id, $u]);
            
        if ($pkg_price > 0) {
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, ?, ?)")
                ->execute([$client_id, $u, $pkg_price, $new_bal, "Package Renewed: " . $pkg['name']]);
        }
        
        $pdo->commit();
        
        // Kick active session so router reconnects immediately with renewed speed & profile
        try {
            kick_subscriber_session($pdo, $u, $client_id);
        } catch (Exception $e) {}

        echo "<script>alert('User renewed successfully!'); window.location='$redirect_url';</script>";
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
