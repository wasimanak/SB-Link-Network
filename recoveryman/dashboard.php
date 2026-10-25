<?php
session_start();
if (!isset($_SESSION['rm_id'])) { header("Location: login.php"); exit; }
require_once '../config/db.php';

// Auto-upgrade recovery_men table for cash_in_hand
try {
    $pdo->exec("ALTER TABLE `recovery_men` ADD COLUMN `cash_in_hand` DECIMAL(10,2) DEFAULT 0.00");
} catch (PDOException $e) {}



$rm_id = $_SESSION['rm_id'];
$client_id = $_SESSION['client_id'];
$rm_name = $_SESSION['rm_name'];


$pkgsStmt = $pdo->prepare("SELECT id, name, price, validity_days FROM packages WHERE client_id = ? OR client_id = 0 ORDER BY name ASC");
$pkgsStmt->execute([$client_id]);
$all_packages = $pkgsStmt->fetchAll(PDO::FETCH_ASSOC);

// Check RM permissions
$chkStmt = $pdo->prepare("SELECT status, can_create_user FROM recovery_men WHERE id = ?");
$chkStmt->execute([$rm_id]);
$rm_data = $chkStmt->fetch();
if (!$rm_data || $rm_data['status'] === 'disabled') {
    session_destroy();
    header("Location: login.php");
    exit;
}


// Handle Renew & Receive Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_only') {
    $sub_id = (int)$_POST['subscriber_id'];
    $sub_username = trim($_POST['subscriber_username']);
    $amount = (float)$_POST['amount'];
    $note = trim($_POST['note']);
    $payment_date = trim($_POST['payment_date']);
    
    if (empty($payment_date)) {
        $payment_date = date('Y-m-d H:i:s');
    } else {
        $payment_date = date('Y-m-d H:i:s', strtotime($payment_date));
    }

    try {
        $pdo->beginTransaction();

        if ($amount > 0) {
            // Add to User Balance (Adding money increases their balance/wallet)
            $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);
            
            // Fetch updated balance
            $tmpBalStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $tmpBalStmt->execute([$sub_id]);
            $balAfterPayment = $tmpBalStmt->fetchColumn();
            
            // Ledger Entry with custom date
            $desc = "Payment collected by {$rm_name} RM" . ($note ? " - Note: $note" : "");
            
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description, created_at) VALUES (?, ?, 'credit', ?, ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $balAfterPayment, $desc, $payment_date]);
                
            // Update RM Cash in Hand
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?")->execute([$amount, $rm_id]);
            
            // Log Activity
            $act_msg = "Collected Cash Rs. $amount from User" . ($note ? " - Note: $note" : "");
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
                ->execute([$client_id, $rm_name, $sub_username, $act_msg]);
                
            $pdo->commit();
            echo "<script>
    alert('Payment Collected Successfully!');
    var pw = window.open('print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=collect', '_blank');
    if(pw){ window.location='dashboard.php'; } else { window.location='print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=collect'; }
</script>";
            exit;
        } else {
            $pdo->rollBack();
            echo "<script>alert('Error: Amount must be greater than 0.'); window.history.back();</script>";
            exit;
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_payment') {
    $sub_id = (int)$_POST['subscriber_id'];
    $sub_username = trim($_POST['subscriber_username']);
    $amount = (float)$_POST['amount']; // Cash collected
    $new_package_id = isset($_POST['new_package_id']) && $_POST['new_package_id'] !== '' ? (int)$_POST['new_package_id'] : 0;
    $note = trim($_POST['note']);

    try {
        $pdo->beginTransaction();

        // 1. Fetch current subscriber details BEFORE modifying package
        $stmt = $pdo->prepare("
            SELECT s.id, s.username, s.balance, s.status, s.expiry_date, s.package_id, s.dealer_id,
                   p.price as cur_pkg_price, p.validity_days as cur_pkg_validity, p.name as cur_pkg_name
            FROM subscribers s 
            LEFT JOIN packages p ON s.package_id = p.id 
            WHERE s.id = ? AND s.client_id = ?
        ");
        $stmt->execute([$sub_id, $client_id]);
        $subData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$subData) {
            $pdo->rollBack();
            echo "<script>alert('Error: Subscriber not found.'); window.location='dashboard.php';</script>";
            exit;
        }

        $now = time();
        $current_expiry = (!empty($subData['expiry_date']) && $subData['expiry_date'] !== '0000-00-00 00:00:00') ? strtotime($subData['expiry_date']) : 0;

        // 2. Target package details
        $target_package_id = ($new_package_id > 0) ? $new_package_id : (int)$subData['package_id'];
        $pkgStmt = $pdo->prepare("SELECT id, name, price, validity_days, rate_limit, pool_id FROM packages WHERE id = ?");
        $pkgStmt->execute([$target_package_id]);
        $targetPkg = $pkgStmt->fetch(PDO::FETCH_ASSOC);

        if (!$targetPkg) {
            $pdo->rollBack();
            echo "<script>alert('Error: Package not found.'); window.location='dashboard.php';</script>";
            exit;
        }

        $package_changed = ($new_package_id > 0 && $new_package_id !== (int)$subData['package_id']);
        $new_validity = (int)(($targetPkg['validity_days'] ?? 0) > 0 ? $targetPkg['validity_days'] : 30);
        $new_pkg_price = (float)$targetPkg['price'];

        // 3. Pro-Rata Credit Calculation (Ensure Amount Is NOT Wasted)
        $old_remaining_credit = 0;
        if ($current_expiry > $now) {
            $old_price = (float)($subData['cur_pkg_price'] ?? 0);
            $old_val = (int)(($subData['cur_pkg_validity'] ?? 0) > 0 ? $subData['cur_pkg_validity'] : 30);
            $old_daily_rate = $old_price / $old_val;
            $remaining_seconds = $current_expiry - $now;
            $old_remaining_credit = ($remaining_seconds / 86400) * $old_daily_rate;
        }

        // 4. Determine New Expiry Date & Net Package Charge
        if ($package_changed && $current_expiry > $now) {
            // Mid-cycle upgrade/change: New package starts TODAY for full validity period
            $new_expiry_time = strtotime("+$new_validity days", $now);
            $new_total_cost = $new_pkg_price;
            // Unused days credit from old package is deducted from new package cost!
            $net_package_charge = round($new_total_cost - $old_remaining_credit, 2);
        } else {
            // Standard renewal
            if ($current_expiry > $now) {
                $new_expiry_time = strtotime("+$new_validity days", $current_expiry);
            } else {
                $new_expiry_time = strtotime("+$new_validity days", $now);
            }
            $net_package_charge = $new_pkg_price;
        }

        $new_expiry_db = date('Y-m-d H:i:s', $new_expiry_time);
        $new_expiry_rad = date('d M Y H:i:s', $new_expiry_time);

        // 5. Adjust Balance & Ledger
        $current_bal = (float)$subData['balance'];
        
        // Step A: Charge the Net Package Charge
        $bal_after_charge = round($current_bal - $net_package_charge, 2);
        if ($net_package_charge > 0) {
            if ($package_changed && $old_remaining_credit > 0) {
                $rem_days_str = round(($current_expiry - $now) / 86400, 1);
                $charge_desc = "Package Upgrade: {$targetPkg['name']} (Full Month Rs. " . number_format($new_pkg_price, 2) . " minus Old Credit Rs. " . number_format($old_remaining_credit, 2) . " for {$rem_days_str}d remaining) by RM {$rm_name}";
            } else {
                $charge_desc = "Package Renewal: {$targetPkg['name']} by RM {$rm_name}";
            }
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $net_package_charge, $bal_after_charge, $charge_desc]);
        } elseif ($net_package_charge < 0) {
            // Downgrade credit refund
            $abs_refund = abs($net_package_charge);
            $charge_desc = "Package Downgrade Credit: {$targetPkg['name']} (Unused Credit Rs. " . number_format($old_remaining_credit, 2) . " covered new package) by RM {$rm_name}";
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $abs_refund, $bal_after_charge, $charge_desc]);
        }

        // Step B: Add the Cash Collected
        $final_bal = $bal_after_charge;
        if ($amount > 0) {
            $final_bal = round($bal_after_charge + $amount, 2);
            $payment_desc = "Cash collected by {$rm_name} RM" . ($note ? " - Note: $note" : "");
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $final_bal, $payment_desc]);
                
            // Update Recovery Man's Cash in Hand
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?")->execute([$amount, $rm_id]);
        }

        // 6. Update Subscriber Record in DB
        $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active', balance = ? WHERE id = ?")
            ->execute([$target_package_id, $new_expiry_db, $final_bal, $sub_id]);

        // 7. Dealer balance handling if subscriber belongs to a dealer
        $sub_dealer_id = (int)($subData['dealer_id'] ?? 0);
        if ($sub_dealer_id > 0) {
            $old_dealer_credit = 0;
            if ($current_expiry > $now) {
                $old_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                $old_dpStmt->execute([$sub_dealer_id, $subData['package_id']]);
                $old_dp_price = $old_dpStmt->fetchColumn();
                if ($old_dp_price === false) {
                    $old_dp_price = (float)($subData['cur_pkg_price'] ?? 0);
                }
                $old_cur_val = (int)(($subData['cur_pkg_validity'] ?? 0) > 0 ? $subData['cur_pkg_validity'] : 30);
                $old_dp_rate = (float)$old_dp_price / $old_cur_val;
                $old_dealer_credit = (($current_expiry - $now) / 86400) * $old_dp_rate;
            }

            $new_dealer_cost = 0;
            if ($new_expiry_time > $now) {
                $new_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                $new_dpStmt->execute([$sub_dealer_id, $target_package_id]);
                $new_dp_price = $new_dpStmt->fetchColumn();
                if ($new_dp_price === false) {
                    $new_dp_price = $new_pkg_price;
                }
                $new_dp_rate = (float)$new_dp_price / $new_validity;
                $new_dealer_cost = (($new_expiry_time - $now) / 86400) * $new_dp_rate;
            }

            $net_dealer_deduction = round($new_dealer_cost - $old_dealer_credit, 2);
            if ($net_dealer_deduction != 0) {
                $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_dealer_deduction, $sub_dealer_id]);
                $d_note = ($net_dealer_deduction > 0)
                    ? "Package Upgrade for user {$sub_username}. Deducted Rs. " . abs($net_dealer_deduction)
                    : "Package Change Credit for user {$sub_username}. Credited Rs. " . abs($net_dealer_deduction);
                $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $d_note]);
            }
        }

        // 8. Update FreeRADIUS radcheck, radreply, radusergroup
        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$sub_username]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$sub_username, $new_expiry_rad]);

        $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$sub_username]);
        $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$sub_username, $targetPkg['name']]);

        if (!empty($targetPkg['rate_limit']) && $targetPkg['rate_limit'] !== 'No Limit' && $targetPkg['rate_limit'] !== 'Unlimited') {
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$sub_username]);
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$sub_username, $targetPkg['rate_limit']]);
        }

        // 9. Activity Log
        $act_text = "Package " . ($package_changed ? "Upgraded to {$targetPkg['name']}" : "Renewed ({$targetPkg['name']})") . 
                    ". Net Charge: Rs. " . number_format($net_package_charge, 2) . 
                    ($old_remaining_credit > 0 ? " (Adjusted Rs. " . number_format($old_remaining_credit, 2) . " old credit)" : "") . 
                    ", Collected: Rs. " . number_format($amount, 2) . 
                    ". Expiry: " . date('d M Y', $new_expiry_time);
        $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
            ->execute([$client_id, $rm_name, $sub_username, $act_text]);

        $pdo->commit();

        // 10. Kick Mikrotik / FreeRADIUS session so new speed takes effect immediately
        try {
            require_once __DIR__ . '/../config/radius_kick_helper.php';
            kick_subscriber_session($pdo, $sub_username, $client_id);
        } catch (Exception $e) {
            try { kick_user_mikrotik($pdo, $client_id, $sub_username); } catch (Exception $ex) {}
        }
        
        $alert_msg = "Package " . ($package_changed ? "Upgraded" : "Renewed") . " Successfully!\n";
        if ($package_changed && $old_remaining_credit > 0) {
            $alert_msg .= "Old Unused Credit Adjusted: - Rs. " . number_format($old_remaining_credit, 2) . "\n";
        }
        $alert_msg .= "Net Charged: Rs. " . number_format($net_package_charge, 2) . "\nCollected: Rs. " . number_format($amount, 2) . "\nNew Balance: Rs. " . number_format($final_bal, 2) . "\nExpiry: " . date('d M Y', $new_expiry_time);
        $sq = urlencode($_GET['search_query']??'');
        echo "<script>
            alert('" . addslashes($alert_msg) . "');
            var pw = window.open('print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=renew', '_blank');
            if(pw){ window.location='dashboard.php?search_query=$sq'; } else { window.location='print_receipt.php?sub_id={$sub_id}&paid={$amount}&type=renew'; }
        </script>";
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    }
}
// Handle Create New User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    if(!isset($rm_data['can_create_user']) || $rm_data['can_create_user'] !== 'yes') { die('Permission Denied.'); }

    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $mobile = trim($_POST['mobile']);
    $address = trim($_POST['address']);
    $package_id = (int)$_POST['package_id'];
    $amount_collected = (float)$_POST['amount_collected'];

    try {
        $pdo->beginTransaction();
        
        // Check if username exists
        $chk = $pdo->prepare("SELECT id FROM subscribers WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            throw new Exception("Username '$username' already exists!");
        }

        // Get Package Details
        $pkgStmt = $pdo->prepare("SELECT name, price, rate_limit FROM packages WHERE id = ?");
        $pkgStmt->execute([$package_id]);
        $pkg = $pkgStmt->fetch();
        if (!$pkg) throw new Exception("Invalid package selected.");
        
        $package_price = (float)$pkg['price'];
        
        // 1 Month Expiry
        $now = time();
        $new_expiry_time = strtotime('+1 month', $now);
        $new_expiry_db = date('Y-m-d H:i:s', $new_expiry_time);
        $new_expiry_rad = date('d M Y H:i:s', $new_expiry_time);
        
        // Calculate Initial Balance (Collected - Package Price)
        $initial_balance = $amount_collected - $package_price;

        // Insert Subscriber
        $insSub = $pdo->prepare("INSERT INTO subscribers (client_id, package_id, username, password, full_name, mobile, address, service_type, status, balance, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, 'pppoe', 'active', ?, ?)");
        $insSub->execute([$client_id, $package_id, $username, $password, $full_name, $mobile, $address, $initial_balance, $new_expiry_db]);
        
        // Insert RADIUS basics
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$username, $password]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $new_expiry_rad]);
        
        if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
            $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$username, $pkg['rate_limit']]);
        }
        
        // Ledger Entries
        // A. Package Charge
        $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, description, balance_after) VALUES (?, ?, 'debit', ?, ?, ?)")
            ->execute([$client_id, $username, $package_price, "Account Created. Initial Package Charge ({$pkg['name']})", (0 - $package_price)]);
            
        // B. Amount Collected
        if ($amount_collected > 0) {
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, description, balance_after) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $username, $amount_collected, "Initial Payment Collected by RM: " . $_SESSION['rm_username'], $initial_balance]);
                
            // Update RM Cash
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?")->execute([$amount_collected, $rm_id]);
        }

        // Activity Log
        $activity_msg = "Created New User with {$pkg['name']}. Charged: Rs. $package_price. Collected: Rs. $amount_collected. Expiry: " . date('d M Y', $new_expiry_time);
        $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
            ->execute([$client_id, $rm_name, $username, $activity_msg]);

        $pdo->commit();
        echo "<script>alert('User Created Successfully!\\nExpiry set to 1 Month.\\nBalance: Rs. $initial_balance'); window.location='dashboard.php';</script>";
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    }
}

// --- HANDLE EDIT SUBSCRIBER PROFILE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_subscriber') {
    $sub_id = (int)$_POST['subscriber_id'];
    $full_name = trim($_POST['full_name'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subarea = trim($_POST['subarea'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');

    try {
        // Fetch existing subscriber
        $subStmt = $pdo->prepare("SELECT id, username, password FROM subscribers WHERE id = ? AND client_id = ?");
        $subStmt->execute([$sub_id, $client_id]);
        $sub = $subStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            echo "<script>alert('Error: Subscriber not found.'); window.history.back();</script>";
            exit;
        }

        $username = $sub['username'];
        $pdo->beginTransaction();

        // 1. Update subscriber table
        $upd = $pdo->prepare("
            UPDATE subscribers SET 
                full_name = ?,
                national_id = ?,
                password = ?,
                mobile = ?,
                phone = ?,
                email = ?,
                subarea = ?,
                address = ?,
                latitude = ?,
                longitude = ?
            WHERE id = ? AND client_id = ?
        ");
        $upd->execute([
            $full_name,
            $national_id,
            $password,
            $mobile,
            $phone,
            $email,
            $subarea,
            $address,
            $latitude,
            $longitude,
            $sub_id,
            $client_id
        ]);

        // 2. Sync FreeRADIUS password if changed
        if (!empty($password)) {
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?) ON DUPLICATE KEY UPDATE value = ?")
                ->execute([$username, $password, $password]);
        }

        // 3. Activity Log
        $act_msg = "Updated Profile Details for User: $username (Name: $full_name, Mobile: $mobile, Area: $subarea)";
        $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
            ->execute([$client_id, $rm_name, $username, $act_msg]);

        $pdo->commit();
        echo "<script>alert('Subscriber profile updated successfully!'); window.location='dashboard.php';</script>";
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
        exit;
    }
}

// Search Logic
$search_results = [];
if (isset($_GET['search_query']) && !empty(trim($_GET['search_query']))) {
    $search = trim($_GET['search_query']);
    $sStmt = $pdo->prepare("
        SELECT s.id, s.username, s.full_name, s.national_id, s.password, s.mobile, s.phone, s.email, s.subarea, s.address, s.latitude, s.longitude,
               s.balance, s.status, s.expiry_date, s.package_id, p.name as package_name, p.price as package_price, p.validity_days as package_validity,
               (SELECT SUM(acctinputoctets) FROM radacct r WHERE r.username = s.username) as upload_bytes,
               (SELECT SUM(acctoutputoctets) FROM radacct r WHERE r.username = s.username) as download_bytes,
               (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
        FROM subscribers s
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE (s.username LIKE ? OR s.full_name LIKE ? OR s.phone LIKE ? OR s.mobile LIKE ?) AND s.client_id = ?
        LIMIT 20
    ");
    $sStmt->execute(["%$search%", "%$search%", "%$search%", "%$search%", $client_id]);
    $search_results = $sStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Metric Queries
$now = date('Y-m-d H:i:s');
$count_expired = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND (expiry_date < NOW() OR status = 'expired')"); $count_expired->execute([$client_id]); $c_expired = $count_expired->fetchColumn();
$count_1d = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)"); $count_1d->execute([$client_id]); $c_1d = $count_1d->fetchColumn();
$count_3d = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)"); $count_3d->execute([$client_id]); $c_3d = $count_3d->fetchColumn();
$count_1w = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"); $count_1w->execute([$client_id]); $c_1w = $count_1w->fetchColumn();
$count_2w = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)"); $count_2w->execute([$client_id]); $c_2w = $count_2w->fetchColumn();

// Filter Logic for Main Table
$filter = $_GET['filter'] ?? 'expired';
$filter_sql = "AND (s.expiry_date < NOW() OR s.status = 'expired')";
$filter_title = "Expired Users";

if ($filter === 'expiring_1d') {
    $filter_sql = "AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 DAY)";
    $filter_title = "Expiring in 1 Day";
} elseif ($filter === 'expiring_3d') {
    $filter_sql = "AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)";
    $filter_title = "Expiring in 3 Days";
} elseif ($filter === 'expiring_1w') {
    $filter_sql = "AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
    $filter_title = "Expiring in 1 Week";
} elseif ($filter === 'expiring_2w') {
    $filter_sql = "AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY)";
    $filter_title = "Expiring in 2 Weeks";
}

// Fetch Filtered Users
$filteredStmt = $pdo->prepare("
    SELECT s.id, s.username, s.full_name, s.national_id, s.password, s.mobile, s.phone, s.email, s.subarea, s.address, s.latitude, s.longitude,
           s.balance, s.status, s.expiry_date, s.package_id, p.name as package_name, p.price as package_price, p.validity_days as package_validity,
           (SELECT SUM(acctinputoctets) FROM radacct r WHERE r.username = s.username) as upload_bytes,
           (SELECT SUM(acctoutputoctets) FROM radacct r WHERE r.username = s.username) as download_bytes,
           (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip
    FROM subscribers s
    LEFT JOIN packages p ON s.package_id = p.id
    WHERE s.client_id = ? $filter_sql
    ORDER BY s.expiry_date ASC
    LIMIT 100
");
$filteredStmt->execute([$client_id]);
$expired_users = $filteredStmt->fetchAll(PDO::FETCH_ASSOC); // Overwrite the expired_users variable so the table renders it

// Fetch Today's Collection for this RM
$collStmt = $pdo->prepare("
    SELECT SUM(amount) FROM user_ledger 
    WHERE client_id = ? AND description LIKE ? AND DATE(created_at) = CURDATE()
");
$collStmt->execute([$client_id, "Cash collected by {$rm_name} RM%"]);
$today_collection = $collStmt->fetchColumn() ?: 0;

// Fetch RM Cash in Hand
$cashStmt = $pdo->prepare("SELECT cash_in_hand FROM recovery_men WHERE id = ?");
$cashStmt->execute([$rm_id]);
$rm_cash_in_hand = $cashStmt->fetchColumn() ?: 0;

// Fetch Pending (Minus Balance) Users Count
$pendingCountStmt = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE client_id = ? AND balance < 0");
$pendingCountStmt->execute([$client_id]);
$pending_count = (int)$pendingCountStmt->fetchColumn();

function formatBytes($bytes) {
    if ($bytes <= 0) return "0 MB";
    $bytes = $bytes / (1024 * 1024);
    if ($bytes > 1024) return round($bytes/1024, 2) . " GB";
    return round($bytes, 2) . " MB";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= BRAND_NAME ?> - Operator Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Leaflet Map CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body { background-color: #f1f5f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .top-navbar { background: #0f172a; color: white; padding: 15px 20px; border-bottom: 3px solid #dc2626; display: flex; justify-content: space-between; align-items: center; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .btn-action-sm { font-size: 0.8rem; padding: 0.3rem 0.5rem; }
        .sub-card { transition: transform 0.2s ease, box-shadow 0.2s ease; border: 1px solid #e2e8f0; background: #ffffff; }
        .sub-card:hover { transform: translateY(-3px); box-shadow: 0 12px 24px -6px rgba(0, 0, 0, 0.1) !important; }
        .sub-avatar { width: 46px; height: 46px; border-radius: 14px; background: linear-gradient(135deg, #1e293b, #334155); color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 700; box-shadow: 0 4px 8px rgba(0,0,0,0.12); }
        .shadow-2xs { box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        .btn-touch { font-size: 0.75rem; min-height: 46px; transition: all 0.15s ease; }
        .btn-touch:hover { transform: translateY(-2px); }
    </style>
</head>
<body>

<div class="top-navbar">
    <div class="fw-bold fs-5"><i class="fa-solid fa-motorcycle me-2 text-danger"></i> <?= BRAND_SHORT_NAME ?> Operator Portal</div>
    <div>
        <span class="me-3 small text-light d-none d-md-inline"><i class="fa-regular fa-user me-1"></i> <?= htmlspecialchars($rm_name) ?></span>
        <a href="pending_payments.php" class="btn btn-sm btn-warning text-dark fw-bold me-2 shadow-sm">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Pending Payments
            <?php if ($pending_count > 0): ?>
                <span class="badge bg-danger text-white rounded-pill ms-1"><?= $pending_count ?></span>
            <?php endif; ?>
        </a>
        <a href="history.php" class="btn btn-sm btn-light text-dark fw-bold me-2"><i class="fa-solid fa-clock-rotate-left me-1"></i> History</a>
        <a href="logout.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
</div>

<div class="container mt-4">
    <div class="row">
        <!-- Collection & Balance Stats -->
        <div class="col-6 mb-2">
            <div class="card card-custom bg-danger text-white h-100 shadow-sm mb-0">
                <div class="card-body p-2 text-center">
                    <h6 class="mb-1 text-white-50 fw-bold small">Today's Collection</h6>
                    <h5 class="mb-0 fw-bold">Rs. <?= number_format($today_collection) ?></h5>
                </div>
            </div>
        </div>
        <div class="col-6 mb-2">
            <div class="card card-custom bg-success text-white h-100 shadow-sm mb-0">
                <div class="card-body p-2 text-center">
                    <h6 class="mb-1 text-white-50 fw-bold small">Pending Cash</h6>
                    <h5 class="mb-0 fw-bold">Rs. <?= number_format($rm_cash_in_hand) ?></h5>
                </div>
            </div>
        </div>

        <!-- Search Box -->
        <div class="col-md-12 mb-4">
            <div class="card card-custom">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold m-0"><i class="fa-solid fa-search me-2 text-primary"></i> Find Subscriber</h5>
    <?php if(isset($rm_data["can_create_user"]) && $rm_data["can_create_user"] === "yes"): ?>
<button type="button" class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa-solid fa-user-plus me-1"></i> Add New User</button>
<?php endif; ?>
</div>
<form method="GET" class="d-flex gap-2">
                        <input type="text" name="search_query" class="form-control form-control-lg bg-light" placeholder="Search by Name, Username or Phone..." value="<?= isset($_GET['search_query']) ? htmlspecialchars($_GET['search_query']) : '' ?>" required>
                        <button type="submit" class="btn btn-danger px-4 fw-bold"><i class="fa-solid fa-magnifying-glass me-2"></i> Search</button>
                    </form>
                </div>
            </div>

            

<!-- Search Results -->
            <?php if(isset($_GET['search_query'])): ?>
                <?php if($search_results): ?>
                    <div class="row g-3 mb-4">
                        <?php foreach($search_results as $u): ?>
<div class="col-12 col-md-6 col-lg-6 col-xl-4 d-flex">
                            <div class="card sub-card w-100 border-0 shadow-sm rounded-4 mb-3 overflow-hidden bg-white d-flex flex-column <?= $u['live_ip'] ? 'border-start border-4 border-success' : (($u['balance'] < 0) ? 'border-start border-4 border-danger' : 'border-start border-4 border-primary') ?>">
                                <!-- Card Header: User Identity & Status -->
                                <div class="card-body p-3 pb-2 flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="d-flex align-items-center gap-2 overflow-hidden">
                                            <div class="sub-avatar shadow-sm flex-shrink-0">
                                                <i class="fa-solid fa-user"></i>
                                            </div>
                                            <div class="overflow-hidden">
                                                <h5 class="fw-bold mb-1 text-dark text-truncate" title="<?= htmlspecialchars($u['full_name']) ?>" style="font-size: 1.05rem; line-height: 1.25;">
                                                    <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                                                </h5>
                                                <span class="badge bg-dark text-warning font-monospace px-2 py-1 rounded-pill shadow-xs border border-dark" style="font-size: 0.82rem; letter-spacing: 0.5px;">
                                                    <i class="fa-solid fa-at text-warning me-1"></i><?= htmlspecialchars($u['username']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-shrink-0 ms-2">
                                            <?php if($u['live_ip']): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;">
                                                    <i class="fa-solid fa-circle text-success me-1 fa-beat-fade" style="font-size: 0.45rem;"></i> Online
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;">
                                                    <i class="fa-regular fa-circle text-muted me-1" style="font-size: 0.45rem;"></i> Offline
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Package & Balance Highlight Strip -->
                                    <div class="p-2 px-3 rounded-3 bg-light border d-flex justify-content-between align-items-center mb-3">
                                        <div class="small fw-bold text-dark text-truncate me-2">
                                            <i class="fa-solid fa-bolt text-warning me-1 fs-6"></i>
                                            <span><?= htmlspecialchars($u['package_name']) ?></span>
                                            <span class="text-muted fw-normal ms-1">(Rs. <?= number_format($u['package_price'], 0) ?>)</span>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <?php if($u['balance'] < 0): ?>
                                                <span class="badge bg-danger rounded-pill px-2 py-1 shadow-sm fw-bold" style="font-size: 0.8rem;">
                                                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Due: Rs. <?= number_format(abs($u['balance']), 0) ?>
                                                </span>
                                            <?php elseif($u['balance'] > 0): ?>
                                                <span class="badge bg-success rounded-pill px-2 py-1 shadow-sm fw-bold" style="font-size: 0.8rem;">
                                                    <i class="fa-solid fa-wallet me-1"></i> Adv: Rs. <?= number_format($u['balance'], 0) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-white text-secondary border rounded-pill px-2 py-1 fw-bold" style="font-size: 0.78rem;">
                                                    <i class="fa-solid fa-check text-success me-1"></i> Clear (Rs. 0)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Details Metric Grid -->
                                    <div class="row g-2 text-secondary small mb-3">
                                        <!-- Expiry -->
                                        <div class="col-6">
                                            <div class="p-2 rounded-3 bg-white border shadow-2xs h-100 d-flex align-items-center gap-2">
                                                <div class="p-2 rounded-circle bg-danger bg-opacity-10 text-danger flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fa-regular fa-calendar-xmark" style="font-size: 0.85rem;"></i>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <span class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Expiry Date</span>
                                                    <strong class="<?= (strtotime($u['expiry_date']) < time()) ? 'text-danger fw-bold' : 'text-dark fw-bold' ?>" style="font-size: 0.85rem;">
                                                        <?= $u['expiry_date'] ? date('d M Y', strtotime($u['expiry_date'])) : 'N/A' ?>
                                                    </strong>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Phone -->
                                        <div class="col-6">
                                            <div class="p-2 rounded-3 bg-white border shadow-2xs h-100 d-flex align-items-center gap-2">
                                                <div class="p-2 rounded-circle bg-primary bg-opacity-10 text-primary flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fa-solid fa-phone" style="font-size: 0.85rem;"></i>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <span class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Phone</span>
                                                    <?php if(!empty($u['phone'])): ?>
                                                        <a href="tel:<?= htmlspecialchars($u['phone']) ?>" class="text-decoration-none fw-bold text-dark d-block text-truncate" style="font-size: 0.85rem;" title="Call Customer">
                                                            <?= htmlspecialchars($u['phone']) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted" style="font-size: 0.85rem;">N/A</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Download -->
                                        <div class="col-6">
                                            <div class="p-2 rounded-3 bg-white border shadow-2xs h-100 d-flex align-items-center gap-2">
                                                <div class="p-2 rounded-circle bg-success bg-opacity-10 text-success flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fa-solid fa-arrow-down" style="font-size: 0.85rem;"></i>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <span class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Download</span>
                                                    <strong class="text-dark fw-bold" style="font-size: 0.85rem;"><?= formatBytes($u['download_bytes'] ?? 0) ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Upload -->
                                        <div class="col-6">
                                            <div class="p-2 rounded-3 bg-white border shadow-2xs h-100 d-flex align-items-center gap-2">
                                                <div class="p-2 rounded-circle bg-info bg-opacity-10 text-info flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fa-solid fa-arrow-up" style="font-size: 0.85rem;"></i>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <span class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Upload</span>
                                                    <strong class="text-dark fw-bold" style="font-size: 0.85rem;"><?= formatBytes($u['upload_bytes'] ?? 0) ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Address -->
                                        <div class="col-12 mt-1">
                                            <div class="p-2 px-3 rounded-2 bg-light border-0 text-muted small text-truncate" title="<?= htmlspecialchars($u['address'] ?: 'N/A') ?>" style="font-size: 0.78rem;">
                                                <i class="fa-solid fa-location-dot text-danger me-1"></i> <strong>Address:</strong> <?= htmlspecialchars($u['address'] ?: 'N/A') ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Card Actions Toolbar: Balanced 4-Button Grid -->
                                <div class="card-footer bg-white border-top p-2 pt-2 pb-2 mt-auto">
                                    <div class="row g-2">
                                        <div class="col-3">
                                            <button class="btn btn-success btn-sm w-100 fw-bold py-2 shadow-sm rounded-3 d-flex flex-column align-items-center justify-content-center btn-touch" 
                                                data-bs-toggle="modal" data-bs-target="#collectModal<?= $u['id'] ?>">
                                                <i class="fa-solid fa-hand-holding-dollar fs-6 mb-1"></i>
                                                <span>Collect</span>
                                            </button>
                                        </div>
                                        <div class="col-3">
                                            <button class="btn btn-danger btn-sm w-100 fw-bold py-2 shadow-sm rounded-3 d-flex flex-column align-items-center justify-content-center btn-touch" 
                                                onclick="openCollectModal('<?= $u['username'] ?>', <?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= $u['balance'] ?>', '<?= $u['package_id'] ?? 0 ?>', '<?= (float)($u['package_price'] ?? 0) ?>', '<?= (int)(($u['package_validity'] ?? 0) > 0 ? $u['package_validity'] : 30) ?>', '<?= $u['expiry_date'] ?? '' ?>')">
                                                <i class="fa-solid fa-rotate fs-6 mb-1"></i>
                                                <span>Renew</span>
                                            </button>
                                        </div>
                                        <div class="col-3">
                                            <button class="btn btn-warning text-dark btn-sm w-100 fw-bold py-2 shadow-sm rounded-3 d-flex flex-column align-items-center justify-content-center btn-touch" 
                                                onclick="openUpgradeModal('<?= $u['username'] ?>', <?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= $u['balance'] ?>', '<?= $u['package_id'] ?? 0 ?>', '<?= (float)($u['package_price'] ?? 0) ?>', '<?= (int)(($u['package_validity'] ?? 0) > 0 ? $u['package_validity'] : 30) ?>', '<?= $u['expiry_date'] ?? '' ?>', '<?= addslashes($u['package_name'] ?? '') ?>')">
                                                <i class="fa-solid fa-arrow-up-right-dots fs-6 mb-1"></i>
                                                <span>Upgrade</span>
                                            </button>
                                        </div>
                                        <div class="col-3">
                                            <button type="button" class="btn btn-outline-primary btn-sm w-100 fw-bold py-2 shadow-sm rounded-3 d-flex flex-column align-items-center justify-content-center btn-touch" 
                                                onclick="openEditUserModal(this)"
                                                data-id="<?= $u['id'] ?>"
                                                data-username="<?= htmlspecialchars($u['username']) ?>"
                                                data-fullname="<?= htmlspecialchars($u['full_name'] ?? '') ?>"
                                                data-nationalid="<?= htmlspecialchars($u['national_id'] ?? '') ?>"
                                                data-password="<?= htmlspecialchars($u['password'] ?? '') ?>"
                                                data-mobile="<?= htmlspecialchars($u['mobile'] ?? '') ?>"
                                                data-phone="<?= htmlspecialchars($u['phone'] ?? '') ?>"
                                                data-email="<?= htmlspecialchars($u['email'] ?? '') ?>"
                                                data-subarea="<?= htmlspecialchars($u['subarea'] ?? '') ?>"
                                                data-address="<?= htmlspecialchars($u['address'] ?? '') ?>"
                                                data-latitude="<?= htmlspecialchars($u['latitude'] ?? '') ?>"
                                                data-longitude="<?= htmlspecialchars($u['longitude'] ?? '') ?>">
                                                <i class="fa-solid fa-user-pen fs-6 mb-1"></i>
                                                <span>Edit</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                            <!-- Collect Payment Modal -->
                            <div class="modal fade" id="collectModal<?= $u['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                                        <div class="modal-header border-bottom p-4">
                                            <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Payment</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="collect_only">
                                            <input type="hidden" name="subscriber_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="subscriber_username" value="<?= htmlspecialchars($u['username']) ?>">
                                            
                                            <div class="modal-body p-4 text-start">
                                                <div class="alert alert-success bg-opacity-10 text-success border-success border-opacity-25 py-2 mb-3">
                                                    <strong>User:</strong> <?= htmlspecialchars($u['username']) ?><br>
                                                    <strong>Current Balance:</strong> Rs. <?= number_format($u['balance'], 0) ?>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Payment Date & Time</label>
                                                    <input type="datetime-local" name="payment_date" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Collected Amount (Rs) <span class="text-danger">*</span></label>
                                                    <input type="number" step="1" name="amount" class="form-control form-control-lg fw-bold text-success" placeholder="e.g. 1500" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Note (Optional)</label>
                                                    <input type="text" name="note" class="form-control" placeholder="e.g. Previous month pending">
                                                </div>
                                            </div>
                                            <div class="modal-footer border-top p-3">
                                                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">Save Payment</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
<?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning fw-bold shadow-sm"><i class="fa-solid fa-circle-exclamation me-2"></i> No users found matching your search.</div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- Expired Users List -->
            <?php if(!isset($_GET['search_query']) || empty(trim($_GET['search_query']))): ?>
                
<!-- Upcoming Recovery Metrics -->
<div class="row g-2 mb-3 mt-1">
    <div class="col-md-4 col-4">
        <a href="?filter=expired" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter=='expired')?'bg-danger text-white':'bg-white' ?> shadow-sm">
                <div class="card-body text-center p-2 py-2">
                    <div class="fw-bold mb-0 text-uppercase" style="font-size:0.65rem;" <?= ($filter=='expired')?'':'text-danger' ?>">Expired</div>
                    <div class="fw-bold mb-0" style="font-size:1.1rem;""><?= $c_expired ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-4">
        <a href="?filter=expiring_1d" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter=='expiring_1d')?'bg-warning text-dark':'bg-white' ?> shadow-sm">
                <div class="card-body text-center p-2 py-2">
                    <div class="fw-bold mb-0 text-uppercase" style="font-size:0.65rem;" <?= ($filter=='expiring_1d')?'':'text-warning' ?>">1 Day</div>
                    <div class="fw-bold mb-0" style="font-size:1.1rem;" <?= ($filter=='expiring_1d')?'':'text-dark' ?>"><?= $c_1d ?></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4 col-4">
        <a href="?filter=expiring_3d" class="text-decoration-none">
            <div class="card card-custom h-100 <?= ($filter=='expiring_3d')?'bg-info text-white':'bg-white' ?> shadow-sm">
                <div class="card-body text-center p-2 py-2">
                    <div class="fw-bold mb-0 text-uppercase" style="font-size:0.65rem;" <?= ($filter=='expiring_3d')?'':'text-info' ?>">3 Days</div>
                    <div class="fw-bold mb-0" style="font-size:1.1rem;" <?= ($filter=='expiring_3d')?'':'text-dark' ?>"><?= $c_3d ?></div>
                </div>
            </div>
        </a>
    </div>
    
    
</div>

<h5 class="fw-bold mt-5 border-bottom pb-2 mb-3 <?= ($filter==='expired')?'text-danger':'text-primary' ?>"><i class="fa-solid <?= ($filter==='expired')?'fa-triangle-exclamation':'fa-clock-rotate-left' ?> me-2"></i> <?= htmlspecialchars($filter_title) ?></h5>
                <?php if($expired_users): ?>
                    <div class="row g-3 mb-4">
                        <?php foreach($expired_users as $u): ?>
                        <div class="col-12 col-md-6 col-lg-6 col-xl-4 d-flex">
                            <div class="card sub-card w-100 border-0 shadow-sm rounded-4 mb-3 overflow-hidden bg-white d-flex flex-column <?= $u['live_ip'] ? 'border-start border-4 border-success' : (($u['balance'] < 0) ? 'border-start border-4 border-danger' : 'border-start border-4 border-primary') ?>">
                                <!-- Card Header: User Identity & Status -->
                                <div class="card-body p-3 pb-2 flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="d-flex align-items-center gap-2 overflow-hidden">
                                            <div class="sub-avatar shadow-sm flex-shrink-0">
                                                <i class="fa-solid fa-user"></i>
                                            </div>
                                            <div class="overflow-hidden">
                                                <h5 class="fw-bold mb-1 text-dark text-truncate" title="<?= htmlspecialchars($u['full_name']) ?>" style="font-size: 1.05rem; line-height: 1.25;">
                                                    <?= htmlspecialchars($u['full_name'] ?: $u['username']) ?>
                                                </h5>
                                                <span class="badge bg-dark text-warning font-monospace px-2 py-1 rounded-pill shadow-xs border border-dark" style="font-size: 0.82rem; letter-spacing: 0.5px;">
                                                    <i class="fa-solid fa-at text-warning me-1"></i><?= htmlspecialchars($u['username']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex-shrink-0 ms-2">
                                            <?php if($u['live_ip']): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;">
                                                    <i class="fa-solid fa-circle text-success me-1 fa-beat-fade" style="font-size: 0.45rem;"></i> Online
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;">
                                                    <i class="fa-regular fa-circle text-muted me-1" style="font-size: 0.45rem;"></i> Offline
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Package & Balance Highlight Strip -->
                                    <div class="p-2 px-3 rounded-3 bg-light border d-flex justify-content-between align-items-center mb-3">
                                        <div class="small fw-bold text-dark text-truncate me-2">
                                            <i class="fa-solid fa-bolt text-warning me-1 fs-6"></i>
                                            <span><?= htmlspecialchars($u['package_name']) ?></span>
                                            <span class="text-muted fw-normal ms-1">(Rs. <?= number_format($u['package_price'], 0) ?>)</span>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <?php if($u['balance'] < 0): ?>
                                                <span class="badge bg-danger rounded-pill px-2 py-1 shadow-sm fw-bold" style="font-size: 0.8rem;">
                                                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Due: Rs. <?= number_format(abs($u['balance']), 0) ?>
                                                </span>
                                            <?php elseif($u['balance'] > 0): ?>
                                                <span class="badge bg-success rounded-pill px-2 py-1 shadow-sm fw-bold" style="font-size: 0.8rem;">
                                                    <i class="fa-solid fa-wallet me-1"></i> Adv: Rs. <?= number_format($u['balance'], 0) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-white text-secondary border rounded-pill px-2 py-1 fw-bold" style="font-size: 0.78rem;">
                                                    <i class="fa-solid fa-check text-success me-1"></i> Clear (Rs. 0)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Details Metric Grid -->
                                    <div class="row g-2 text-secondary small mb-3">
                                        <!-- Expiry -->
                                        <div class="col-6">
                                            <div class="p-2 rounded-3 bg-white border shadow-2xs h-100 d-flex align-items-center gap-2">
                                                <div class="p-2 rounded-circle bg-danger bg-opacity-10 text-danger flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fa-regular fa-calendar-xmark" style="font-size: 0.85rem;"></i>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <span class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Expiry Date</span>
                                                    <strong class="<?= (strtotime($u['expiry_date']) < time()) ? 'text-danger fw-bold' : 'text-dark fw-bold' ?>" style="font-size: 0.85rem;">
                                                        <?= $u['expiry_date'] ? date('d M Y', strtotime($u['expiry_date'])) : 'N/A' ?>
                                                    </strong>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Phone -->
                                        <div class="col-6">
                                            <div class="p-2 rounded-3 bg-white border shadow-2xs h-100 d-flex align-items-center gap-2">
                                                <div class="p-2 rounded-circle bg-primary bg-opacity-10 text-primary flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fa-solid fa-phone" style="font-size: 0.85rem;"></i>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <span class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Phone</span>
                                                    <?php if(!empty($u['phone'])): ?>
                                                        <a href="tel:<?= htmlspecialchars($u['phone']) ?>" class="text-decoration-none fw-bold text-dark d-block text-truncate" style="font-size: 0.85rem;" title="Call Customer">
                                                            <?= htmlspecialchars($u['phone']) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted" style="font-size: 0.85rem;">N/A</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Download -->
                                        <div class="col-6">
                                            <div class="p-2 rounded-3 bg-white border shadow-2xs h-100 d-flex align-items-center gap-2">
                                                <div class="p-2 rounded-circle bg-success bg-opacity-10 text-success flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fa-solid fa-arrow-down" style="font-size: 0.85rem;"></i>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <span class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Download</span>
                                                    <strong class="text-dark fw-bold" style="font-size: 0.85rem;"><?= formatBytes($u['download_bytes'] ?? 0) ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Upload -->
                                        <div class="col-6">
                                            <div class="p-2 rounded-3 bg-white border shadow-2xs h-100 d-flex align-items-center gap-2">
                                                <div class="p-2 rounded-circle bg-info bg-opacity-10 text-info flex-shrink-0" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fa-solid fa-arrow-up" style="font-size: 0.85rem;"></i>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <span class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Upload</span>
                                                    <strong class="text-dark fw-bold" style="font-size: 0.85rem;"><?= formatBytes($u['upload_bytes'] ?? 0) ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- Address -->
                                        <div class="col-12 mt-1">
                                            <div class="p-2 px-3 rounded-2 bg-light border-0 text-muted small text-truncate" title="<?= htmlspecialchars($u['address'] ?: 'N/A') ?>" style="font-size: 0.78rem;">
                                                <i class="fa-solid fa-location-dot text-danger me-1"></i> <strong>Address:</strong> <?= htmlspecialchars($u['address'] ?: 'N/A') ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Card Actions Toolbar: Balanced 4-Button Grid -->
                                <div class="card-footer bg-white border-top p-2 pt-2 pb-2 mt-auto">
                                    <div class="row g-2">
                                        <div class="col-3">
                                            <button class="btn btn-success btn-sm w-100 fw-bold py-2 shadow-sm rounded-3 d-flex flex-column align-items-center justify-content-center btn-touch" 
                                                data-bs-toggle="modal" data-bs-target="#collectModal<?= $u['id'] ?>">
                                                <i class="fa-solid fa-hand-holding-dollar fs-6 mb-1"></i>
                                                <span>Collect</span>
                                            </button>
                                        </div>
                                        <div class="col-3">
                                            <button class="btn btn-danger btn-sm w-100 fw-bold py-2 shadow-sm rounded-3 d-flex flex-column align-items-center justify-content-center btn-touch" 
                                                onclick="openCollectModal('<?= $u['username'] ?>', <?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= $u['balance'] ?>', '<?= $u['package_id'] ?? 0 ?>', '<?= (float)($u['package_price'] ?? 0) ?>', '<?= (int)(($u['package_validity'] ?? 0) > 0 ? $u['package_validity'] : 30) ?>', '<?= $u['expiry_date'] ?? '' ?>')">
                                                <i class="fa-solid fa-rotate fs-6 mb-1"></i>
                                                <span>Renew</span>
                                            </button>
                                        </div>
                                        <div class="col-3">
                                            <button class="btn btn-warning text-dark btn-sm w-100 fw-bold py-2 shadow-sm rounded-3 d-flex flex-column align-items-center justify-content-center btn-touch" 
                                                onclick="openUpgradeModal('<?= $u['username'] ?>', <?= $u['id'] ?>, '<?= addslashes($u['full_name']) ?>', '<?= $u['balance'] ?>', '<?= $u['package_id'] ?? 0 ?>', '<?= (float)($u['package_price'] ?? 0) ?>', '<?= (int)(($u['package_validity'] ?? 0) > 0 ? $u['package_validity'] : 30) ?>', '<?= $u['expiry_date'] ?? '' ?>', '<?= addslashes($u['package_name'] ?? '') ?>')">
                                                <i class="fa-solid fa-arrow-up-right-dots fs-6 mb-1"></i>
                                                <span>Upgrade</span>
                                            </button>
                                        </div>
                                        <div class="col-3">
                                            <button type="button" class="btn btn-outline-primary btn-sm w-100 fw-bold py-2 shadow-sm rounded-3 d-flex flex-column align-items-center justify-content-center btn-touch" 
                                                onclick="openEditUserModal(this)"
                                                data-id="<?= $u['id'] ?>"
                                                data-username="<?= htmlspecialchars($u['username']) ?>"
                                                data-fullname="<?= htmlspecialchars($u['full_name'] ?? '') ?>"
                                                data-nationalid="<?= htmlspecialchars($u['national_id'] ?? '') ?>"
                                                data-password="<?= htmlspecialchars($u['password'] ?? '') ?>"
                                                data-mobile="<?= htmlspecialchars($u['mobile'] ?? '') ?>"
                                                data-phone="<?= htmlspecialchars($u['phone'] ?? '') ?>"
                                                data-email="<?= htmlspecialchars($u['email'] ?? '') ?>"
                                                data-subarea="<?= htmlspecialchars($u['subarea'] ?? '') ?>"
                                                data-address="<?= htmlspecialchars($u['address'] ?? '') ?>"
                                                data-latitude="<?= htmlspecialchars($u['latitude'] ?? '') ?>"
                                                data-longitude="<?= htmlspecialchars($u['longitude'] ?? '') ?>">
                                                <i class="fa-solid fa-user-pen fs-6 mb-1"></i>
                                                <span>Edit</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                            <!-- Collect Payment Modal -->
                            <div class="modal fade" id="collectModal<?= $u['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                                        <div class="modal-header border-bottom p-4">
                                            <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-hand-holding-dollar me-2"></i> Collect Payment</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="collect_only">
                                            <input type="hidden" name="subscriber_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="subscriber_username" value="<?= htmlspecialchars($u['username']) ?>">
                                            
                                            <div class="modal-body p-4 text-start">
                                                <div class="alert alert-success bg-opacity-10 text-success border-success border-opacity-25 py-2 mb-3">
                                                    <strong>User:</strong> <?= htmlspecialchars($u['username']) ?><br>
                                                    <strong>Current Balance:</strong> Rs. <?= number_format($u['balance'], 0) ?>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Payment Date & Time</label>
                                                    <input type="datetime-local" name="payment_date" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Collected Amount (Rs) <span class="text-danger">*</span></label>
                                                    <input type="number" step="1" name="amount" class="form-control form-control-lg fw-bold text-success" placeholder="e.g. 1500" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted text-uppercase">Note (Optional)</label>
                                                    <input type="text" name="note" class="form-control" placeholder="e.g. Previous month pending">
                                                </div>
                                            </div>
                                            <div class="modal-footer border-top p-3">
                                                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">Save Payment</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success fw-bold shadow-sm"><i class="fa-solid fa-check-circle me-2"></i> Great! There are no expired users currently.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Upgrade Package Modal -->
<div class="modal fade" id="upgradeModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
      <div class="modal-header border-bottom px-4 pt-4 pb-3 bg-warning text-dark">
        <h5 class="modal-title fw-bold">
            <i class="fa-solid fa-arrow-up-right-dots me-2"></i> Upgrade Package
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="receive_payment">
        <input type="hidden" name="subscriber_id" id="up_subid">
        <input type="hidden" name="subscriber_username" id="up_subuser">

        <div class="modal-body p-4 bg-light">
            <!-- Subscriber Summary Card -->
            <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0" id="up_fullname"></h5>
                        <span class="badge bg-primary font-monospace" id="up_username"></span>
                    </div>
                    <div class="text-end">
                        <span class="small text-muted fw-bold">Current Balance</span>
                        <div class="fw-bold fs-6" id="up_balance"></div>
                    </div>
                </div>
                <div class="row g-2 pt-2 border-top text-secondary small">
                    <div class="col-7">
                        <i class="fa-solid fa-box text-primary me-1"></i> Current Package: <br>
                        <strong class="text-dark" id="up_curpkg"></strong>
                    </div>
                    <div class="col-5 text-end">
                        <i class="fa-solid fa-calendar-check text-success me-1"></i> Expiry: <br>
                        <strong class="text-dark" id="up_curexpiry"></strong>
                    </div>
                </div>
            </div>

            <!-- New Package Selection -->
            <div class="mb-3 text-start">
                <label class="form-label fw-bold text-dark small text-uppercase">
                    <i class="fa-solid fa-arrow-up text-warning me-1"></i> Select New Package to Upgrade <span class="text-danger">*</span>
                </label>
                <select name="new_package_id" id="up_newpkg" class="form-select form-select-lg fw-bold text-primary border-warning shadow-sm" required onchange="calcUpgradeAdjustment()">
                    <option value="">-- Choose New Package --</option>
                    <?php foreach($all_packages as $pkg): ?>
                        <option value="<?= $pkg['id'] ?>" data-price="<?= $pkg['price'] ?>" data-days="<?= !empty($pkg['validity_days']) ? $pkg['validity_days'] : 30 ?>" data-name="<?= htmlspecialchars($pkg['name']) ?>">
                            <?= htmlspecialchars($pkg['name']) ?> &nbsp;|&nbsp; Rs. <?= number_format($pkg['price']) ?> (<?= !empty($pkg['validity_days']) ? $pkg['validity_days'] : 30 ?> Days)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Live Pro-Rata Preview Container -->
            <div id="up_calc_preview" class="mb-3">
                <div class="alert alert-light border small text-muted text-center py-2 mb-0">
                    <i class="fa-solid fa-arrow-pointer me-1"></i> Upar se naya package select karein, system automatically unused credit deduct kar dega.
                </div>
            </div>

            <!-- Amount to Collect -->
            <div class="mb-3 text-start">
                <label class="form-label fw-bold text-dark small text-uppercase">
                    Amount Received Now (Rs) <span class="text-danger">*</span>
                </label>
                <div class="input-group input-group-lg shadow-sm">
                    <span class="input-group-text bg-white fw-bold text-success">Rs.</span>
                    <input type="number" step="1" name="amount" id="up_amount" class="form-control fw-bold text-success fs-4" placeholder="0" required>
                </div>
                <div class="form-text text-muted small mt-1">
                    <i class="fa-solid fa-info-circle me-1"></i> Package price minus remaining credit will be charged. Collected cash will be added to balance.
                </div>
            </div>

            <!-- Notes -->
            <div class="mb-2 text-start">
                <label class="form-label fw-bold text-secondary small">Notes / Remarks (Optional)</label>
                <input type="text" name="note" id="up_note" class="form-control bg-white" placeholder="e.g. Upgraded package">
            </div>
        </div>

        <div class="modal-footer px-4 py-3 bg-white border-top">
            <button type="button" class="btn btn-light fw-bold px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-warning text-dark fw-bold px-5 rounded-pill shadow-sm">
                <i class="fa-solid fa-arrow-up-right-dots me-1"></i> Confirm Upgrade
            </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Collect Payment Modal -->
<div class="modal fade" id="collectModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-bottom px-4 pt-4 bg-danger text-white rounded-top-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-rotate me-2"></i> Renew User Package</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="stopLiveSpeed()"></button>
      </div>
      <div class="modal-body p-0 bg-light">
          
          <!-- Tabs -->
          <ul class="nav nav-tabs nav-fill bg-white pt-2 border-bottom shadow-sm" id="myTab" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active fw-bold text-dark" id="payment-tab" data-bs-toggle="tab" data-bs-target="#payment" type="button" role="tab"><i class="fa-solid fa-rotate me-1"></i> Renew & Collect</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link fw-bold text-dark" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab" onclick="loadHistory()"><i class="fa-solid fa-clock-rotate-left me-1"></i> History & Usage</button>
            </li>
          </ul>

          <div class="tab-content p-4" id="myTabContent">
            
            <!-- Payment Tab -->
            <div class="tab-pane fade show active" id="payment" role="tabpanel">
                <div class="text-center mb-4">
                    <h4 class="fw-bold text-dark mb-1" id="m_fullname"></h4>
                    <span class="badge bg-primary mb-2" id="m_username"></span>
                    <div class="p-3 bg-white border rounded shadow-sm d-inline-block mt-2">
                        <div class="text-secondary small fw-bold">Current Balance</div>
                        <h3 class="mb-0 fw-bold" id="m_balance"></h3>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="receive_payment">
                    <input type="hidden" name="subscriber_id" id="m_subid">
                    <input type="hidden" name="subscriber_username" id="m_subuser">
                    
                    <div class="mb-3 text-start">
                        <label class="form-label fw-bold text-secondary small">Change Package (Optional)</label>
                        <select name="new_package_id" id="m_newpkg" class="form-select fw-bold text-primary" onchange="updatePkgAmount()">
                            <option value="">-- Keep Current Package --</option>
                            <?php foreach($all_packages as $pkg): ?>
                                <option value="<?= $pkg['id'] ?>" data-price="<?= $pkg['price'] ?>" data-days="<?= !empty($pkg['validity_days']) ? $pkg['validity_days'] : 30 ?>" data-name="<?= htmlspecialchars($pkg['name']) ?>">
                                    <?= htmlspecialchars($pkg['name']) ?> (Rs <?= number_format($pkg['price']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3 text-start">
                        <label class="form-label fw-bold text-secondary small">Enter Amount Received (Rs) <span class="text-danger">*</span></label>
                        <input type="number" step="1" name="amount" id="m_amount" class="form-control form-control-lg fw-bold text-success text-center" placeholder="e.g. 1500" required>
                        <div id="m_pkg_calc_preview" class="mt-2"></div>
                        <div class="form-text text-muted text-center" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-info"></i> Expiry will be extended automatically based on amount vs package price.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold text-secondary small">Notes / Remarks (Optional)</label>
                        <input type="text" name="note" class="form-control bg-white" placeholder="Month of October, etc.">
                    </div>
                    <button type="submit" class="btn btn-success btn-lg w-100 fw-bold shadow-sm"><i class="fa-solid fa-check-circle me-2"></i> Confirm Payment Receipt</button>
                </form>
            </div>

            <!-- History & Usage Tab -->
            <div class="tab-pane fade" id="history" role="tabpanel">
                
                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <div class="p-3 bg-white border border-success rounded text-center shadow-sm">
                            <div class="text-secondary small fw-bold"><i class="fa-solid fa-arrow-down text-success"></i> Live Download</div>
                            <h4 class="mb-0 fw-bold text-success mt-1"><span id="live_down">0.00</span> <small class="fs-6 text-muted">Mbps</small></h4>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-white border border-primary rounded text-center shadow-sm">
                            <div class="text-secondary small fw-bold"><i class="fa-solid fa-arrow-up text-primary"></i> Live Upload</div>
                            <h4 class="mb-0 fw-bold text-primary mt-1"><span id="live_up">0.00</span> <small class="fs-6 text-muted">Mbps</small></h4>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa-solid fa-receipt me-2"></i> Lifetime Transaction History</h6>
                <div id="ledger_content" class="text-center py-4">
                    <div class="spinner-border text-danger spinner-border-sm" role="status"></div> Loading history...
                </div>

            </div>

          </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let speedInterval = null;
let currentUsername = '';
let curSubPkgId = 0;
let curSubPkgPrice = 0;
let curSubPkgVal = 30;
let curSubExpiry = '';
let curSubBalance = 0;

let upSubPkgId = 0;
let upSubPkgPrice = 0;
let upSubPkgVal = 30;
let upSubExpiry = '';
let upSubBalance = 0;

function openUpgradeModal(username, id, fullname, balance, pkgId, pkgPrice, pkgVal, expiry, pkgName) {
    document.getElementById('up_subid').value = id;
    document.getElementById('up_subuser').value = username;
    document.getElementById('up_username').innerText = username;
    document.getElementById('up_fullname').innerText = fullname;
    
    let balColor = parseFloat(balance) < 0 ? 'text-danger' : 'text-success';
    document.getElementById('up_balance').innerHTML = `<span class="${balColor}">Rs. ${balance}</span>`;
    
    document.getElementById('up_curpkg').innerText = (pkgName || 'Current Package') + (pkgPrice > 0 ? ' (Rs. ' + Number(pkgPrice).toLocaleString() + ')' : '');
    
    if (expiry && expiry !== '0000-00-00 00:00:00') {
        let expDate = new Date(expiry);
        document.getElementById('up_curexpiry').innerText = isNaN(expDate.getTime()) ? expiry : expDate.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    } else {
        document.getElementById('up_curexpiry').innerText = 'Expired / None';
    }
    
    upSubPkgId = parseInt(pkgId || 0);
    upSubPkgPrice = parseFloat(pkgPrice || 0);
    upSubPkgVal = parseInt(pkgVal || 30);
    upSubExpiry = expiry || '';
    upSubBalance = parseFloat(balance || 0);
    
    const pkgSelect = document.getElementById('up_newpkg');
    if (pkgSelect) pkgSelect.value = '';
    
    document.getElementById('up_amount').value = '';
    document.getElementById('up_note').value = 'Package Upgrade: ' + (pkgName || 'Old Package');
    document.getElementById('up_calc_preview').innerHTML = '<div class="alert alert-light border small text-muted text-center py-2 mb-0"><i class="fa-solid fa-arrow-pointer me-1"></i> Upar se naya package select karein, system automatically unused credit deduct kar dega.</div>';
    
    var modal = new bootstrap.Modal(document.getElementById('upgradeModal'));
    modal.show();
}

function calcUpgradeAdjustment() {
    const pkgSelect = document.getElementById('up_newpkg');
    const amountInput = document.getElementById('up_amount');
    const previewDiv = document.getElementById('up_calc_preview');
    if (!pkgSelect || !amountInput) return;
    
    if (!pkgSelect.value) {
        amountInput.value = '';
        if (previewDiv) {
            previewDiv.innerHTML = '<div class="alert alert-light border small text-muted text-center py-2 mb-0"><i class="fa-solid fa-arrow-pointer me-1"></i> Upar se naya package select karein, system automatically unused credit deduct kar dega.</div>';
        }
        return;
    }
    
    const opt = pkgSelect.options[pkgSelect.selectedIndex];
    const newPrice = parseFloat(opt.dataset.price || 0);
    const newName = opt.dataset.name || 'New Package';
    const newDays = parseInt(opt.dataset.days || 30);
    
    const now = Math.floor(Date.now() / 1000);
    let curExpTs = upSubExpiry ? Math.floor(new Date(upSubExpiry).getTime() / 1000) : 0;
    if (isNaN(curExpTs)) curExpTs = 0;
    
    let oldCredit = 0;
    let remDays = 0;
    if (curExpTs > now && upSubPkgPrice > 0) {
        remDays = Math.round(((curExpTs - now) / 86400) * 10) / 10;
        const oldDaily = upSubPkgPrice / (upSubPkgVal > 0 ? upSubPkgVal : 30);
        oldCredit = Math.round(((curExpTs - now) / 86400) * oldDaily * 100) / 100;
    }
    
    const netPayable = Math.max(0, Math.round(newPrice - oldCredit));
    amountInput.value = netPayable;
    document.getElementById('up_note').value = 'Upgraded to ' + newName;
    
    if (previewDiv) {
        if (oldCredit > 0) {
            previewDiv.innerHTML = '<div class="alert alert-success border border-success-subtle p-3 rounded-3 text-start mb-0" style="background:#f0fdf4;">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<span class="fw-bold text-dark"><i class="fa-solid fa-calculator text-primary me-1"></i> Pro-Rata Upgrade Calculation</span>' +
                    '<span class="badge bg-success">' + remDays + ' Days Credit Deducted</span>' +
                '</div>' +
                '<div class="text-secondary small">' +
                    '<div class="d-flex justify-content-between py-1 border-bottom border-success-subtle"><span>New Package Price (' + newDays + ' Days):</span><strong class="text-dark">Rs. ' + newPrice.toLocaleString() + '</strong></div>' +
                    '<div class="d-flex justify-content-between py-1 border-bottom border-success-subtle"><span>Old Package Unused Credit (' + remDays + ' days left):</span><strong class="text-success">- Rs. ' + oldCredit.toFixed(2) + '</strong></div>' +
                    '<div class="d-flex justify-content-between pt-2 fs-6"><span><strong>Net Amount to Collect:</strong></span><strong class="text-primary fs-5">Rs. ' + netPayable.toLocaleString() + '</strong></div>' +
                '</div>' +
            '</div>';
        } else {
            previewDiv.innerHTML = '<div class="alert alert-info border border-info-subtle p-3 rounded-3 text-start mb-0" style="background:#f0f9ff;">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<span class="fw-bold text-dark"><i class="fa-solid fa-info-circle text-info me-1"></i> Standard Upgrade (Package Expired)</span>' +
                    '<span class="badge bg-secondary">0 Days Left</span>' +
                '</div>' +
                '<div class="text-secondary small">' +
                    '<div class="d-flex justify-content-between py-1 border-bottom border-info-subtle"><span>New Package Price (' + newDays + ' Days):</span><strong class="text-dark">Rs. ' + newPrice.toLocaleString() + '</strong></div>' +
                    '<div class="d-flex justify-content-between pt-2 fs-6"><span><strong>Net Amount to Collect:</strong></span><strong class="text-primary fs-5">Rs. ' + newPrice.toLocaleString() + '</strong></div>' +
                '</div>' +
            '</div>';
        }
    }
}

function openCollectModal(username, id, fullname, balance, pkgId, pkgPrice, pkgVal, expiry) {
    document.getElementById('m_username').innerText = username;
    document.getElementById('m_subuser').value = username;
    document.getElementById('m_subid').value = id;
    document.getElementById('m_fullname').innerText = fullname;
    
    let balColor = parseFloat(balance) < 0 ? 'text-danger' : 'text-success';
    document.getElementById('m_balance').innerHTML = `<span class="${balColor}">Rs. ${balance}</span>`;
    
    currentUsername = username;
    curSubPkgId = parseInt(pkgId || 0);
    curSubPkgPrice = parseFloat(pkgPrice || 0);
    curSubPkgVal = parseInt(pkgVal || 30);
    curSubExpiry = expiry || '';
    curSubBalance = parseFloat(balance || 0);

    const pkgSelect = document.getElementById('m_newpkg');
    if (pkgSelect) pkgSelect.value = '';
    
    updatePkgAmount();

    // Switch to payment tab by default
    var triggerEl = document.querySelector('#payment-tab');
    bootstrap.Tab.getInstance(triggerEl) || new bootstrap.Tab(triggerEl).show();

    var modal = new bootstrap.Modal(document.getElementById('collectModal'));
    modal.show();
    
    // Load history immediately so the bottom section populates
    loadHistory();
}

function updatePkgAmount() {
    const pkgSelect = document.getElementById('m_newpkg');
    const amountInput = document.getElementById('m_amount');
    const previewDiv = document.getElementById('m_pkg_calc_preview');
    if (!amountInput) return;

    const now = Math.floor(Date.now() / 1000);
    let curExpTs = curSubExpiry ? Math.floor(new Date(curSubExpiry).getTime() / 1000) : 0;
    if (isNaN(curExpTs)) curExpTs = 0;

    let targetPrice = curSubPkgPrice;
    let targetName = 'Current Package';
    let targetVal = curSubPkgVal;
    let isPkgChanged = false;

    if (pkgSelect && pkgSelect.value !== '') {
        const opt = pkgSelect.options[pkgSelect.selectedIndex];
        if (opt) {
            targetPrice = parseFloat(opt.dataset.price || 0);
            targetName = opt.dataset.name || 'New Package';
            targetVal = parseInt(opt.dataset.days || 30);
            isPkgChanged = (parseInt(pkgSelect.value) !== curSubPkgId);
        }
    }

    let oldCredit = 0;
    let remDays = 0;
    if (curExpTs > now) {
        remDays = Math.round(((curExpTs - now) / 86400) * 10) / 10;
        const oldDaily = curSubPkgPrice / (curSubPkgVal > 0 ? curSubPkgVal : 30);
        oldCredit = Math.round(((curExpTs - now) / 86400) * oldDaily * 100) / 100;
    }

    if (isPkgChanged && oldCredit > 0) {
        const netPayable = Math.max(0, Math.round(targetPrice - oldCredit));
        amountInput.value = netPayable;
        if (previewDiv) {
            previewDiv.innerHTML = '<div class="alert alert-success border border-success-subtle p-2 rounded-3 text-start mb-0" style="background:#f0fdf4;">' +
                '<div class="d-flex justify-content-between align-items-center mb-1">' +
                    '<span class="fw-bold text-dark small"><i class="fa-solid fa-arrow-up-right-dots text-primary me-1"></i> Package Change Adjustment</span>' +
                    '<span class="badge bg-success">Old Credit Applied</span>' +
                '</div>' +
                '<div class="text-secondary" style="font-size:0.75rem;">' +
                    'New Package Price (1 Month): <strong>Rs. ' + targetPrice.toLocaleString() + '</strong><br>' +
                    'Old Package Unused Credit: <strong class="text-success">- Rs. ' + oldCredit.toFixed(2) + '</strong> (' + remDays + ' days left)<br>' +
                    'Suggested Amount to Collect: <strong class="text-dark fs-6">Rs. ' + netPayable.toLocaleString() + '</strong>' +
                '</div>' +
            '</div>';
        }
    } else {
        amountInput.value = targetPrice > 0 ? targetPrice : '';
        if (previewDiv) {
            previewDiv.innerHTML = '';
        }
    }
}

function loadHistory() {
    if(!currentUsername) return;
    
    // Fetch Ledger History
    fetch(`api_user_details.php?username=${encodeURIComponent(currentUsername)}`)
        .then(res => res.text())
        .then(html => { document.getElementById('ledger_content').innerHTML = html; })
        .catch(err => { document.getElementById('ledger_content').innerHTML = '<span class="text-danger">Failed to load history.</span>'; });

    // Start live speed polling
    startLiveSpeed();
}

let lastBytesIn = null;
let lastBytesOut = null;
let lastTime = null;

function startLiveSpeed() {
    stopLiveSpeed();
    lastBytesIn = null; lastBytesOut = null; lastTime = null;
    
    function fetchSpeed() {
        fetch(`../lineman/api_bandwidth.php?username=${encodeURIComponent(currentUsername)}`)
        .then(response => response.json())
        .then(data => {
            if (data.error || data.msg) {
                document.getElementById('live_down').innerText = "0.00";
                document.getElementById('live_up').innerText = "0.00";
                return;
            }
            let currentBytesIn = data.bytes_in;
            let currentBytesOut = data.bytes_out;
            let currentTime = Date.now();
            if (lastBytesIn !== null && lastBytesOut !== null && lastTime !== null) {
                let timeDiffSecs = (currentTime - lastTime) / 1000;
                if (timeDiffSecs > 0) {
                    let bytesInDiff = currentBytesIn - lastBytesIn;
                    let bytesOutDiff = currentBytesOut - lastBytesOut;
                    if (bytesInDiff < 0) bytesInDiff = 0;
                    if (bytesOutDiff < 0) bytesOutDiff = 0;
                    let rx_mbps = (bytesInDiff * 8 / timeDiffSecs) / 1048576; 
                    let tx_mbps = (bytesOutDiff * 8 / timeDiffSecs) / 1048576; 
                    document.getElementById('live_up').innerText = rx_mbps.toFixed(2);
                    document.getElementById('live_down').innerText = tx_mbps.toFixed(2);
                }
            }
            lastBytesIn = currentBytesIn; lastBytesOut = currentBytesOut; lastTime = currentTime;
        }).catch(err => {});
    }
    
    speedInterval = setInterval(fetchSpeed, 3000);
    fetchSpeed();
}

function stopLiveSpeed() {
    if(speedInterval) clearInterval(speedInterval);
}
</script>
<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-bottom px-4 pt-4 bg-primary text-white rounded-top-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus me-2"></i> Create New User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="create_user">
        <div class="modal-body p-4 bg-light">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Full Name</label>
                    <input type="text" name="full_name" class="form-control" required placeholder="John Doe">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Mobile No.</label>
                    <input type="text" name="mobile" class="form-control" placeholder="03001234567">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Username (PPPoE ID)</label>
                    <input type="text" name="username" class="form-control" required placeholder="john123">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-secondary">Password</label>
                    <input type="text" name="password" class="form-control" required placeholder="pass123">
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-secondary">Address</label>
                    <input type="text" name="address" class="form-control" placeholder="Street 1, House 2">
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-secondary">Select Package (1 Month Validity)</label>
                    <select name="package_id" class="form-select fw-bold text-primary" required>
                        <option value="">-- Choose Package --</option>
                        <?php foreach($all_packages as $pkg): ?>
                            <option value="<?= $pkg['id'] ?>"><?= htmlspecialchars($pkg['name']) ?> (Price: Rs <?= number_format($pkg['price']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-secondary">Amount Collected Now (Optional)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white">Rs.</span>
                        <input type="number" step="0.01" name="amount_collected" class="form-control" value="0" placeholder="0.00">
                    </div>
                    <small class="text-muted">If you don't collect the full package price, the remaining will be saved as a Minus (-) Balance for this user.</small>
                </div>
            </div>
        </div>
        <div class="modal-footer px-4 py-3 bg-white border-top">
            <button type="button" class="btn btn-light fw-bold px-4" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary fw-bold px-5 shadow-sm"><i class="fa-solid fa-check me-1"></i> Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit User Profile Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-bottom px-4 pt-4 bg-dark text-white rounded-top-4">
        <h5 class="modal-title fw-bold">
            <i class="fa-solid fa-user-pen text-primary me-2"></i> Edit Subscriber Profile (<span id="edit_user_badge" class="text-warning font-monospace"></span>)
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_subscriber">
        <input type="hidden" name="subscriber_id" id="edit_subscriber_id">
        <div class="modal-body p-4 bg-light">
            
            <!-- Basic Profile Information -->
            <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                <h6 class="fw-bold text-primary border-bottom pb-2 mb-3">
                    <i class="fa-solid fa-id-card me-2"></i> Personal &amp; Account Details
                </h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-secondary">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="edit_full_name" class="form-control" required placeholder="Full Name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-secondary">CNIC / National ID</label>
                        <input type="text" name="national_id" id="edit_national_id" class="form-control" placeholder="e.g. 35201-1234567-1">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-secondary">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" name="password" id="edit_password" class="form-control font-monospace" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('edit_password').value = Math.random().toString(36).slice(-8);" title="Generate Random Password">
                                <i class="fa-solid fa-shuffle"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-secondary">Mobile No.</label>
                        <input type="text" name="mobile" id="edit_mobile" class="form-control" placeholder="03001234567">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-secondary">Phone (Alt / Landline)</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control" placeholder="Optional">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-secondary">Email Address</label>
                        <input type="email" name="email" id="edit_email" class="form-control" placeholder="user@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-secondary">Area / City / Subarea</label>
                        <input type="text" name="subarea" id="edit_subarea" class="form-control" placeholder="e.g. Model Town / Lahore">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-secondary">Full Street Address</label>
                        <input type="text" name="address" id="edit_address" class="form-control" placeholder="House #, Street #, Colony">
                    </div>
                </div>
            </div>

            <!-- Location from Map (GPS & Pin) -->
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                    <h6 class="fw-bold text-primary mb-0">
                        <i class="fa-solid fa-map-location-dot me-2"></i> Location from Map (GPS Pin)
                    </h6>
                    <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 text-muted" onclick="toggleEditManualCoords()" style="font-size: 0.75rem;">
                        <i class="fa-solid fa-keyboard me-1"></i> Manual Lat/Lng
                    </button>
                </div>

                <!-- GPS Capture & Search Controls -->
                <div class="row g-2 mb-2">
                    <div class="col-md-5">
                        <button type="button" class="btn btn-outline-primary btn-sm fw-bold w-100 py-2 shadow-sm" id="btn_edit_capture_gps" onclick="captureEditUserGPS()">
                            <i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)
                        </button>
                    </div>
                    <div class="col-md-4">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" id="edit_map_search_input" placeholder="Search area, colony..." onkeydown="if(event.key==='Enter'){event.preventDefault();searchEditMapLocation();}">
                            <button class="btn btn-primary" type="button" onclick="searchEditMapLocation()" title="Search Area on Map">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="btn-group btn-group-sm w-100 shadow-sm" role="group">
                            <button type="button" class="btn btn-primary btn-sm fw-bold" id="btnEditMapHybrid" onclick="switchEditMapLayer('hybrid')" title="Satellite View with Streets"><i class="fa-solid fa-satellite me-1"></i>Sat</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" id="btnEditMapStreets" onclick="switchEditMapLayer('streets')" title="Streets Map View"><i class="fa-solid fa-road me-1"></i>Map</button>
                        </div>
                    </div>
                </div>

                <!-- Interactive Leaflet Map Picker -->
                <div class="position-relative rounded border overflow-hidden shadow-sm" style="height: 280px;">
                    <div id="edit_user_map_picker" style="height: 100%; width: 100%; z-index: 1;"></div>
                    <div class="position-absolute bottom-0 start-0 m-2 px-2 py-1 rounded bg-dark bg-opacity-75 text-white small" style="z-index: 1000; font-size: 0.75rem; pointer-events: none;">
                        <i class="fa-solid fa-hand-pointer text-warning me-1"></i> Map par click karein ya pin ko drag kar ke customer ki location par set karein
                    </div>
                </div>

                <!-- Hidden inputs passed to DB -->
                <input type="hidden" name="latitude" id="edit_latitude">
                <input type="hidden" name="longitude" id="edit_longitude">

                <!-- Manual Coordinate Inputs (Collapsible) -->
                <div class="row g-2 mt-2" id="edit_manual_coords_row" style="display: none;">
                    <div class="col-6">
                        <label class="small text-muted fw-bold">Latitude</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="edit_manual_lat" placeholder="e.g. 31.520371" oninput="syncEditManualCoords()">
                    </div>
                    <div class="col-6">
                        <label class="small text-muted fw-bold">Longitude</label>
                        <input type="text" class="form-control form-control-sm font-monospace" id="edit_manual_lng" placeholder="e.g. 74.358742" oninput="syncEditManualCoords()">
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-footer px-4 py-3 bg-white border-top">
            <button type="button" class="btn btn-light fw-bold px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary fw-bold px-5 rounded-pill shadow-sm">
                <i class="fa-solid fa-check me-1"></i> Save Changes
            </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Leaflet Map JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
let editUserMap = null;
let editUserMarker = null;
let editHybridLayer = null;
let editStreetsLayer = null;
let editActiveLayerType = 'hybrid';
const defaultMapLat = 31.5204;
const defaultMapLng = 74.3587; // Pakistan default

function openEditUserModal(btn) {
    const id = btn.dataset.id;
    const username = btn.dataset.username || '';
    const fullname = btn.dataset.fullname || '';
    const nationalid = btn.dataset.nationalid || '';
    const password = btn.dataset.password || '';
    const mobile = btn.dataset.mobile || '';
    const phone = btn.dataset.phone || '';
    const email = btn.dataset.email || '';
    const subarea = btn.dataset.subarea || '';
    const address = btn.dataset.address || '';
    const lat = btn.dataset.latitude || '';
    const lng = btn.dataset.longitude || '';

    document.getElementById('edit_subscriber_id').value = id;
    document.getElementById('edit_user_badge').innerText = username;
    document.getElementById('edit_full_name').value = fullname;
    document.getElementById('edit_national_id').value = nationalid;
    document.getElementById('edit_password').value = password;
    document.getElementById('edit_mobile').value = mobile;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_subarea').value = subarea;
    document.getElementById('edit_address').value = address;
    document.getElementById('edit_latitude').value = lat;
    document.getElementById('edit_longitude').value = lng;
    document.getElementById('edit_manual_lat').value = lat;
    document.getElementById('edit_manual_lng').value = lng;

    const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    modal.show();
}

function initEditUserMapPicker() {
    var latVal = parseFloat(document.getElementById('edit_latitude').value);
    var lngVal = parseFloat(document.getElementById('edit_longitude').value);
    var hasExisting = !isNaN(latVal) && latVal !== 0 && !isNaN(lngVal) && lngVal !== 0;
    var startLat = hasExisting ? latVal : defaultMapLat;
    var startLng = hasExisting ? lngVal : defaultMapLng;

    if (editUserMap !== null) {
        setTimeout(function() {
            if (editUserMap) {
                editUserMap.invalidateSize();
                editUserMap.setView([startLat, startLng], hasExisting ? 18 : 13);
                if (editUserMarker) {
                    editUserMarker.setLatLng([startLat, startLng]);
                }
            }
        }, 150);
        return;
    }

    var container = document.getElementById('edit_user_map_picker');
    if (!container) return;

    editHybridLayer = L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
        maxZoom: 22,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
        attribution: '&copy; Google Maps'
    });

    editStreetsLayer = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
        maxZoom: 22,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
        attribution: '&copy; Google Maps'
    });

    editUserMap = L.map('edit_user_map_picker', {
        center: [startLat, startLng],
        zoom: hasExisting ? 18 : 13,
        maxZoom: 22,
        zoomControl: true,
        layers: [editHybridLayer]
    });

    var pinIcon = L.icon({
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34],
        shadowSize: [41, 41]
    });

    editUserMarker = L.marker([startLat, startLng], {
        draggable: true,
        icon: pinIcon
    }).addTo(editUserMap);

    function updateCoords(lat, lng) {
        document.getElementById('edit_latitude').value = lat.toFixed(6);
        document.getElementById('edit_longitude').value = lng.toFixed(6);
        document.getElementById('edit_manual_lat').value = lat.toFixed(6);
        document.getElementById('edit_manual_lng').value = lng.toFixed(6);
    }

    editUserMarker.on('dragend', function(e) {
        var pos = editUserMarker.getLatLng();
        updateCoords(pos.lat, pos.lng);
    });

    editUserMap.on('click', function(e) {
        editUserMarker.setLatLng(e.latlng);
        updateCoords(e.latlng.lat, e.latlng.lng);
    });

    setTimeout(function() {
        if (editUserMap) editUserMap.invalidateSize();
    }, 200);
}

function switchEditMapLayer(type) {
    if (!editUserMap) return;
    if (type === 'hybrid') {
        if (editUserMap.hasLayer(editStreetsLayer)) editUserMap.removeLayer(editStreetsLayer);
        if (!editUserMap.hasLayer(editHybridLayer)) editUserMap.addLayer(editHybridLayer);
        document.getElementById('btnEditMapHybrid').className = 'btn btn-primary btn-sm fw-bold';
        document.getElementById('btnEditMapStreets').className = 'btn btn-outline-secondary btn-sm fw-bold';
        editActiveLayerType = 'hybrid';
    } else {
        if (editUserMap.hasLayer(editHybridLayer)) editUserMap.removeLayer(editHybridLayer);
        if (!editUserMap.hasLayer(editStreetsLayer)) editUserMap.addLayer(editStreetsLayer);
        document.getElementById('btnEditMapStreets').className = 'btn btn-primary btn-sm fw-bold';
        document.getElementById('btnEditMapHybrid').className = 'btn btn-outline-secondary btn-sm fw-bold';
        editActiveLayerType = 'streets';
    }
}

function toggleEditManualCoords() {
    var row = document.getElementById('edit_manual_coords_row');
    if (row.style.display === 'none') {
        row.style.display = 'flex';
    } else {
        row.style.display = 'none';
    }
}

function syncEditManualCoords() {
    var lat = parseFloat(document.getElementById('edit_manual_lat').value);
    var lng = parseFloat(document.getElementById('edit_manual_lng').value);
    if (!isNaN(lat) && !isNaN(lng)) {
        document.getElementById('edit_latitude').value = lat;
        document.getElementById('edit_longitude').value = lng;
        if (editUserMap && editUserMarker) {
            editUserMarker.setLatLng([lat, lng]);
            editUserMap.setView([lat, lng], 18);
        }
    }
}

function captureEditUserGPS() {
    var btn = document.getElementById('btn_edit_capture_gps');
    if (!btn) return;
    if (!navigator.geolocation) {
        alert("Geolocation is not supported by your browser/device.");
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Getting GPS...';

    navigator.geolocation.getCurrentPosition(
        function(pos) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check text-success me-1"></i> GPS Captured (' + Math.round(pos.coords.accuracy) + 'm)';
            var lat = pos.coords.latitude;
            var lng = pos.coords.longitude;
            document.getElementById('edit_latitude').value = lat.toFixed(6);
            document.getElementById('edit_longitude').value = lng.toFixed(6);
            document.getElementById('edit_manual_lat').value = lat.toFixed(6);
            document.getElementById('edit_manual_lng').value = lng.toFixed(6);
            if (editUserMap && editUserMarker) {
                editUserMarker.setLatLng([lat, lng]);
                editUserMap.setView([lat, lng], 18);
            }
        },
        function(err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)';
            alert("Could not retrieve GPS location: " + err.message);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

function searchEditMapLocation() {
    var q = document.getElementById('edit_map_search_input').value.trim();
    if (!q) return;
    fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}`)
        .then(res => res.json())
        .then(data => {
            if (data && data.length > 0) {
                var lat = parseFloat(data[0].lat);
                var lon = parseFloat(data[0].lon);
                document.getElementById('edit_latitude').value = lat.toFixed(6);
                document.getElementById('edit_longitude').value = lon.toFixed(6);
                document.getElementById('edit_manual_lat').value = lat.toFixed(6);
                document.getElementById('edit_manual_lng').value = lon.toFixed(6);
                if (editUserMap && editUserMarker) {
                    editUserMarker.setLatLng([lat, lon]);
                    editUserMap.setView([lat, lon], 17);
                }
            } else {
                alert("Location not found. Please try searching with city name (e.g. 'Gulberg, Lahore').");
            }
        })
        .catch(err => { alert("Search error: " + err.message); });
}

// When editUserModal opens, initialize or resize Leaflet map
$('#editUserModal').on('shown.bs.modal', function () {
    initEditUserMapPicker();
});
</script>

<style>
    body { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
    input, textarea, select, [contenteditable="true"] { -webkit-user-select: text !important; -moz-user-select: text !important; -ms-user-select: text !important; user-select: text !important; }
</style>
<script src="/security-shield.js?v=2.1"></script>
</body>
</html>