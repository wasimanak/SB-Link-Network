<?php
require_once 'header.php';
$client_id = $_SESSION['operator_id'];

if (!isset($_GET['id'])) {
    header("Location: subscribers.php");
    exit;
}
$id = (int)$_GET['id'];

require_once '../config/routeros_api.class.php';

try {
    $cols = $pdo->query("SHOW COLUMNS FROM subscribers LIKE 'last_renewed_at'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE subscribers ADD COLUMN last_renewed_at DATETIME DEFAULT NULL");
    }
} catch (Exception $e) {}

function kick_user_mikrotik($pdo, $client_id, $username) {
    $stmt = $pdo->prepare("SELECT * FROM nas WHERE client_id = ? LIMIT 1");
    $stmt->execute([$client_id]);
    $nas = $stmt->fetch();
    if ($nas) {
        $api = new RouterosAPI();
        $api->timeout = 2;
        if ($api->connect($nas['nasname'], $nas['api_user'], $nas['api_password'], $nas['api_port'])) {
            // Kick from Hotspot
            $api->write('/ip/hotspot/active/print', false);
            $api->write('?user=' . $username, true);
            $hotspot = $api->read();
            if (!empty($hotspot) && isset($hotspot[0]['.id'])) {
                $api->write('/ip/hotspot/active/remove', false);
                $api->write('=.id=' . $hotspot[0]['.id'], true);
                $api->read();
            }
            // Kick from PPPoE
            $api->write('/ppp/active/print', false);
            $api->write('?name=' . $username, true);
            $ppp = $api->read();
            if (!empty($ppp) && isset($ppp[0]['.id'])) {
                $api->write('/ppp/active/remove', false);
                $api->write('=.id=' . $ppp[0]['.id'], true);
                $api->read();
            }
            $api->disconnect();
        }
    }
}

// --- HANDLE ACTIONS ---
$is_manager = !empty($_SESSION['is_manager']);
$current_manager_id = $is_manager ? (int)($_SESSION['manager_id'] ?? 0) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND client_id = ?");
    $uStmt->execute([$id, $client_id]);
    $u = $uStmt->fetchColumn();

    if ($u) {
        if ($action === 'delete_user') {
            kick_user_mikrotik($pdo, $client_id, $u);
            $pdo->prepare("DELETE FROM subscribers WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
            echo "<script>alert('Profile deleted successfully!'); window.location='subscribers.php';</script>";
            exit;
        }
        elseif ($action === 'disconnect_user') {
            kick_user_mikrotik($pdo, $client_id, $u);
            $is_mgr = !empty($_SESSION['is_manager']);
            $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
            $actor_name = $is_mgr ? ($_SESSION['manager_name'] ?? ($_SESSION['manager_username'] ?? 'Manager')) : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
            $actor_role = $is_mgr ? 'Manager' : 'Admin';
            $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', 'User Disconnected by $actor_role ($actor_name)')")->execute([$client_id, $mgr_id, $actor_name, $actor_role, $u]);
            echo "<script>alert('User Kicked Successfully!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'disable_net' || $action === 'profile_disable') {
            kick_user_mikrotik($pdo, $client_id, $u);
            $pdo->prepare("UPDATE subscribers SET status = 'disabled' WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$u]);
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
            $is_mgr = !empty($_SESSION['is_manager']);
            $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
            $actor_name = $is_mgr ? ($_SESSION['manager_name'] ?? ($_SESSION['manager_username'] ?? 'Manager')) : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
            $actor_role = $is_mgr ? 'Manager' : 'Admin';
            $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', 'Profile Disabled by $actor_role ($actor_name)')")->execute([$client_id, $mgr_id, $actor_name, $actor_role, $u]);
            echo "<script>alert('Internet Disabled & User Kicked!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'enable_net') {
            $pdo->prepare("UPDATE subscribers SET status = 'active' WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'")->execute([$u]);
            $is_mgr = !empty($_SESSION['is_manager']);
            $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
            $actor_name = $is_mgr ? ($_SESSION['manager_name'] ?? ($_SESSION['manager_username'] ?? 'Manager')) : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
            $actor_role = $is_mgr ? 'Manager' : 'Admin';
            $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', 'Profile Enabled by $actor_role ($actor_name)')")->execute([$client_id, $mgr_id, $actor_name, $actor_role, $u]);
            echo "<script>alert('Internet Enabled!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'change_password') {
            $new_pass = trim($_POST['new_password']);
            $pdo->prepare("UPDATE subscribers SET password = ? WHERE id = ?")->execute([$new_pass, $id]);
            $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$new_pass, $u]);
            $is_mgr = !empty($_SESSION['is_manager']);
            $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
            $actor_name = $is_mgr ? ($_SESSION['manager_name'] ?? ($_SESSION['manager_username'] ?? 'Manager')) : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
            $actor_role = $is_mgr ? 'Manager' : 'Admin';
            $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', 'Changed Password by $actor_role ($actor_name)')")->execute([$client_id, $mgr_id, $actor_name, $actor_role, $u]);
            echo "<script>alert('Password updated successfully!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'reset_user_usage') {
            $now_dt = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE subscribers SET last_renewed_at = ? WHERE id = ? AND client_id = ?")->execute([$now_dt, $id, $client_id]);

            try {
                $pdo->prepare("UPDATE radacct SET acctinputoctets = 0, acctoutputoctets = 0 WHERE username = ?")->execute([$u]);
            } catch (Exception $e) {}

            try {
                require_once __DIR__ . '/../config/radius_kick_helper.php';
                kick_subscriber_session($pdo, $u, $client_id);
            } catch (Exception $e) {
                try { kick_user_mikrotik($pdo, $client_id, $u); } catch (Exception $ex) {}
            }

            try {
                $actor_role = !empty($_SESSION['is_manager']) ? 'Manager' : 'Admin';
                $actor_name = !empty($_SESSION['manager_name']) ? $_SESSION['manager_name'] : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
                $mgr_id = !empty($_SESSION['is_manager']) ? (int)($_SESSION['manager_id'] ?? 0) : null;
                $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', 'Reset data usage counter to 0 MB')")
                    ->execute([$client_id, $mgr_id, $actor_name, $actor_role, $u]);
            } catch (Exception $e) {}

            echo "<script>alert('Data usage successfully reset to 0 MB for $u!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'apply_uncredited_refund') {
            $refund_amount = (float)($_POST['refund_amount'] ?? 0);
            $refund_reason = trim($_POST['reason'] ?? 'Backward Expiry Adjustment Refund');
            
            if ($refund_amount > 0) {
                $subStmt = $pdo->prepare("SELECT id, username, balance, dealer_id FROM subscribers WHERE id = ? AND client_id = ?");
                $subStmt->execute([$id, $client_id]);
                $sub = $subStmt->fetch();
                
                if ($sub) {
                    $pdo->beginTransaction();
                    $new_bal = round((float)$sub['balance'] + $refund_amount, 2);
                    
                    // Update balance
                    $pdo->prepare("UPDATE subscribers SET balance = ? WHERE id = ?")->execute([$new_bal, $id]);
                    
                    // Ledger entry
                    $actor_role = !empty($_SESSION['is_manager']) ? 'Manager' : 'Admin';
                    $actor_name = !empty($_SESSION['manager_name']) ? $_SESSION['manager_name'] : (!empty($_SESSION['operator_name']) ? $_SESSION['operator_name'] : 'Admin');
                    $actor_mgr_id = !empty($_SESSION['is_manager']) ? (int)($_SESSION['manager_id'] ?? 0) : null;
                    
                    $desc = "Reconciliation: " . $refund_reason . " by $actor_role ($actor_name)";
                    $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                        ->execute([$client_id, $sub['username'], $refund_amount, $new_bal, $desc]);
                    
                    // Activity log
                    $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', ?)")
                        ->execute([$client_id, $actor_mgr_id, $actor_name, $actor_role, $sub['username'], "Reconciliation: Credited Rs. " . number_format($refund_amount, 2) . " into balance for backward expiry adjustment"]);
                    
                    // Dealer handling if applicable
                    $dealer_id = (int)($sub['dealer_id'] ?? 0);
                    if ($dealer_id > 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance + ? WHERE id = ?")->execute([$refund_amount, $dealer_id]);
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, "Reconciliation Credit: Rs. " . number_format($refund_amount, 2) . " credited back for user {$sub['username']}"]);
                    }
                    
                    $pdo->commit();
                    echo "<script>alert('Successfully credited Rs. " . number_format($refund_amount, 2) . " to {$sub['username']} balance! New Balance: Rs. " . number_format($new_bal, 2) . "'); window.location='subscriber_view.php?id=$id';</script>";
                    exit;
                }
            }
        }
        elseif ($action === 'edit_profile') {
            $full_name = trim($_POST['full_name']);
            $national_id = trim($_POST['national_id']);
            $new_username = trim($_POST['username']);
            $password = trim($_POST['password']);
            $service_type = $_POST['service_type'];
            $package_id = (int)$_POST['package_id'];
            $dealer_id = !empty($_POST['dealer_id']) ? (int)$_POST['dealer_id'] : null;
            $expiry = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
            if ($is_manager) {
                // Manager cannot set or alter custom expiry
                $expiry = (!empty($cur_sub['expiry_date']) && $cur_sub['expiry_date'] !== '0000-00-00 00:00:00') ? $cur_sub['expiry_date'] : null;
            }
            $mobile = trim($_POST['mobile']);
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            $address = trim($_POST['address'] ?? '');
            $subarea = trim($_POST['subarea'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $latitude = !empty($_POST['latitude']) ? trim($_POST['latitude']) : (!empty($_POST['gps_lat']) ? trim($_POST['gps_lat']) : null);
            $longitude = !empty($_POST['longitude']) ? trim($_POST['longitude']) : (!empty($_POST['gps_lng']) ? trim($_POST['gps_lng']) : null);
            
            // Check if username is being changed and if it already exists
            if ($new_username !== $u) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE username = ?");
                $chk->execute([$new_username]);
                if ($chk->fetchColumn() > 0) {
                    echo "<script>alert('Error: Username already exists!'); window.location='subscriber_view.php?id=$id';</script>";
                    exit;
                }
            }

            // Fetch current subscriber details for comparison and pro-rata calculation
            $subStmt = $pdo->prepare("
                SELECT s.id, s.username, s.balance, s.status, s.expiry_date, s.package_id, s.dealer_id,
                       p.price as cur_pkg_price, p.validity_days as cur_pkg_validity, p.name as cur_pkg_name
                FROM subscribers s
                LEFT JOIN packages p ON s.package_id = p.id
                WHERE s.id = ? AND s.client_id = ?
            ");
            $subStmt->execute([$id, $client_id]);
            $cur_sub = $subStmt->fetch(PDO::FETCH_ASSOC);

            if (!$cur_sub) {
                echo "<script>alert('Subscriber not found.'); window.location='subscribers.php';</script>";
                exit;
            }

            $now = time();
            $cur_exp_ts = (!empty($cur_sub['expiry_date']) && $cur_sub['expiry_date'] !== '0000-00-00 00:00:00') ? strtotime($cur_sub['expiry_date']) : 0;
            $new_exp_ts = !empty($expiry) ? strtotime($expiry) : 0;
            if ($is_manager) {
                $new_exp_ts = $cur_exp_ts;
                $exp_changed = false;
            }

            // Fetch selected package details
            $pkgStmt = $pdo->prepare("SELECT id, name, price, validity_days, rate_limit, pool_id FROM packages WHERE id = ?");
            $pkgStmt->execute([$package_id]);
            $pkg_row = $pkgStmt->fetch(PDO::FETCH_ASSOC);

            // Check if expiry or package changed
            $exp_changed = false;
            if ($cur_exp_ts > 0 && $new_exp_ts > 0) {
                $exp_changed = (abs($cur_exp_ts - $new_exp_ts) > 60);
            } elseif ($cur_exp_ts !== $new_exp_ts) {
                $exp_changed = true;
            }
            $pkg_changed = ($package_id !== (int)$cur_sub['package_id']);

            $net_adjustment = 0;
            $current_bal = (float)($cur_sub['balance'] ?? 0);
            $new_bal = $current_bal;

            if ($exp_changed || ($pkg_changed && $cur_exp_ts > $now)) {
                // 1. Calculate remaining credit on currently active period
                $old_remaining_credit = 0;
                if ($cur_exp_ts > $now) {
                    $cur_pkg_price = (float)($cur_sub['cur_pkg_price'] ?? 0);
                    $cur_validity = (int)(($cur_sub['cur_pkg_validity'] ?? 0) > 0 ? $cur_sub['cur_pkg_validity'] : 30);
                    $cur_daily_rate = $cur_pkg_price / $cur_validity;
                    $remaining_seconds = $cur_exp_ts - $now;
                    $old_remaining_credit = ($remaining_seconds / 86400) * $cur_daily_rate;
                }

                // 2. Calculate total cost for new future period
                $new_total_cost = 0;
                if ($new_exp_ts > $now && $pkg_row) {
                    $new_pkg_price = (float)$pkg_row['price'];
                    $new_validity = (int)(($pkg_row['validity_days'] ?? 0) > 0 ? $pkg_row['validity_days'] : 30);
                    $new_daily_rate = $new_pkg_price / $new_validity;
                    $new_seconds = $new_exp_ts - $now;
                    $new_total_cost = ($new_seconds / 86400) * $new_daily_rate;
                }

                // 3. Net adjustment:
                // Date goes forward -> net_adjustment is positive -> CHARGE user (balance decreases / minus)
                // Date goes backward -> net_adjustment is negative -> REFUND user (balance increases / plus)
                $net_adjustment = round($new_total_cost - $old_remaining_credit, 2);
                $new_bal = round($current_bal - $net_adjustment, 2);
            }

            $pdo->beginTransaction();

            // Dealer handling if subscriber belongs to a dealer
            $sub_dealer_id = (int)($cur_sub['dealer_id'] ?? 0);
            if ($sub_dealer_id > 0 && $net_adjustment != 0) {
                $old_dealer_credit = 0;
                if ($cur_exp_ts > $now) {
                    $old_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                    $old_dpStmt->execute([$sub_dealer_id, $cur_sub['package_id']]);
                    $old_dp_price = $old_dpStmt->fetchColumn();
                    if ($old_dp_price === false) {
                        $old_dp_price = (float)($cur_sub['cur_pkg_price'] ?? 0);
                    }
                    $old_cur_val = (int)(($cur_sub['cur_pkg_validity'] ?? 0) > 0 ? $cur_sub['cur_pkg_validity'] : 30);
                    $old_dp_rate = (float)$old_dp_price / $old_cur_val;
                    $old_dealer_credit = (($cur_exp_ts - $now) / 86400) * $old_dp_rate;
                }

                $new_dealer_cost = 0;
                if ($new_exp_ts > $now && $pkg_row) {
                    $new_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                    $new_dpStmt->execute([$sub_dealer_id, $package_id]);
                    $new_dp_price = $new_dpStmt->fetchColumn();
                    if ($new_dp_price === false) {
                        $new_dp_price = (float)($pkg_row['price'] ?? 0);
                    }
                    $new_validity = (int)(($pkg_row['validity_days'] ?? 0) > 0 ? $pkg_row['validity_days'] : 30);
                    $new_dp_rate = (float)$new_dp_price / $new_validity;
                    $new_dealer_cost = (($new_exp_ts - $now) / 86400) * $new_dp_rate;
                }

                $net_dealer_deduction = round($new_dealer_cost - $old_dealer_credit, 2);
                if ($net_dealer_deduction != 0) {
                    $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_dealer_deduction, $sub_dealer_id]);
                    $d_note = ($net_dealer_deduction > 0) 
                        ? "Profile Edit: Expiry Extended for user {$new_username}. Deducted Rs. " . abs($net_dealer_deduction)
                        : "Profile Edit: Expiry Reduced for user {$new_username}. Credited Rs. " . abs($net_dealer_deduction);
                    $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $d_note]);
                }
            }

            // Auto-determine status based on expiry
            $cur_status = $cur_sub['status'];
            $new_status = 'active';
            if ($cur_status === 'disabled') {
                $new_status = 'disabled';
            } elseif (empty($expiry) || $new_exp_ts <= $now) {
                $new_status = 'expired';
            }

            $formatted_expiry_db = !empty($expiry) ? date('Y-m-d H:i:s', $new_exp_ts) : null;

            // Update subscriber
            $pdo->prepare("UPDATE subscribers SET 
                full_name=?, national_id=?, username=?, password=?, service_type=?, 
                package_id=?, dealer_id=?, expiry_date=?, status=?, balance=?, mobile=?, phone=?, email=?, 
                address=?, subarea=?, city=?, latitude=?, longitude=?, gps_lat=?, gps_lng=? WHERE id=?")
                ->execute([$full_name, $national_id, $new_username, $password, $service_type, 
                           $package_id, $dealer_id, $formatted_expiry_db, $new_status, $new_bal, $mobile, $phone, $email, 
                           $address, $subarea, $city, $latitude, $longitude, $latitude, $longitude, $id]);
            
            // Update RADIUS Cleartext-Password & Username
            if ($new_username !== $u) {
                $pdo->prepare("UPDATE radcheck SET username=? WHERE username=?")->execute([$new_username, $u]);
                $pdo->prepare("UPDATE radreply SET username=? WHERE username=?")->execute([$new_username, $u]);
                $pdo->prepare("UPDATE radusergroup SET username=? WHERE username=?")->execute([$new_username, $u]);
            }
            $pdo->prepare("UPDATE radcheck SET value=? WHERE username=? AND attribute='Cleartext-Password'")->execute([$password, $new_username]);
            
            // RADIUS Expiration & Auth-Type Sync
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$new_username]);
            if (!empty($expiry)) {
                $formatted_exp = date('d M Y H:i:s', $new_exp_ts);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$new_username, $formatted_exp]);
            }
            if ($new_status === 'expired' || $new_status === 'disabled') {
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$new_username]);
            }

            // Update Package Group in FreeRADIUS
            if ($pkg_row) {
                $pkg_name = $pkg_row['name'];
                $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$new_username]);
                $pdo->prepare("INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)")->execute([$new_username, $pkg_name]);

                if (!empty($pkg_row['rate_limit']) && $pkg_row['rate_limit'] !== 'No Limit' && $pkg_row['rate_limit'] !== 'Unlimited') {
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$new_username]);
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$new_username, $pkg_row['rate_limit']]);
                }

                $target_profile = !empty($pkg_row['pool_name']) ? $pkg_row['pool_name'] : '';
                if (empty($target_profile) || $target_profile === 'PPPoE Pool') {
                    if (preg_match('/(\d+)\s*(?:M|MB|Mbps)/i', $pkg_name, $m_prof)) {
                        $target_profile = $m_prof[1] . 'Mbps';
                    }
                }
                if (!empty($target_profile) && $new_status === 'active') {
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Group'")->execute([$new_username]);
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Group', '=', ?)")->execute([$new_username, $target_profile]);
                }
            }

            // Actor details (Admin or Manager)
            $actor_role = !empty($_SESSION['is_manager']) ? 'Manager' : 'Admin';
            $actor_name = !empty($_SESSION['manager_name']) ? $_SESSION['manager_name'] : (!empty($_SESSION['operator_name']) ? $_SESSION['operator_name'] : 'Admin');
            $actor_mgr_id = !empty($_SESSION['is_manager']) ? (int)($_SESSION['manager_id'] ?? 0) : null;

            // Ledger Entry and Activity Log
            if ($net_adjustment > 0) {
                $days_label = ($new_exp_ts > $now) ? round(($new_exp_ts - max($now, $cur_exp_ts)) / 86400, 1) : 0;
                $desc = "Profile Edit: Expiry Extended (+{$days_label}d) to " . date('d M Y, h:i A', $new_exp_ts) . " ({$pkg_row['name']}) by $actor_role ($actor_name)";
                $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, ?, ?)")
                    ->execute([$client_id, $new_username, $net_adjustment, $new_bal, $desc]);

                $act_text = "Profile Edit: Extended Expiry to " . date('d M Y, h:i A', $new_exp_ts) . ". Charged Rs. " . number_format($net_adjustment, 2) . " ({$pkg_row['name']})";
                $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', ?)")
                    ->execute([$client_id, $actor_mgr_id, $actor_name, $actor_role, $new_username, $act_text]);
            } elseif ($net_adjustment < 0) {
                $abs_refund = abs($net_adjustment);
                $desc = "Profile Edit: Expiry Reduced to " . date('d M Y, h:i A', $new_exp_ts) . " (Pro-rata Refund) by $actor_role ($actor_name)";
                $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                    ->execute([$client_id, $new_username, $abs_refund, $new_bal, $desc]);

                $act_text = "Profile Edit: Reduced Expiry to " . date('d M Y, h:i A', $new_exp_ts) . ". Credited Rs. " . number_format($abs_refund, 2) . " ({$pkg_row['name']})";
                $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', ?)")
                    ->execute([$client_id, $actor_mgr_id, $actor_name, $actor_role, $new_username, $act_text]);
            } else {
                $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', 'Updated Profile Details')")
                    ->execute([$client_id, $actor_mgr_id, $actor_name, $actor_role, $new_username]);
            }

            $pdo->commit();
            
            // Kick user session
            try {
                require_once __DIR__ . '/../config/radius_kick_helper.php';
                kick_subscriber_session($pdo, $new_username, $client_id);
                if ($new_username !== $u) {
                    kick_subscriber_session($pdo, $u, $client_id);
                }
            } catch (Exception $e) {
                try { kick_user_mikrotik($pdo, $client_id, $new_username); } catch (Exception $ex) {}
            }
            
            $alert_msg = "Profile updated successfully!";
            if ($net_adjustment > 0) {
                $alert_msg .= " Expiry extended. Charged Rs. " . number_format($net_adjustment, 2) . ". New Balance: Rs. " . number_format($new_bal, 2);
            } elseif ($net_adjustment < 0) {
                $alert_msg .= " Expiry reduced. Credited Rs. " . number_format(abs($net_adjustment), 2) . " to balance. New Balance: Rs. " . number_format($new_bal, 2);
            }
            echo "<script>alert('" . addslashes($alert_msg) . "'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        elseif ($action === 'add_note') {
            $pdo->prepare("UPDATE subscribers SET notes=? WHERE id=?")->execute([$_POST['notes'], $id]);
            echo "<script>alert('Note saved!'); window.location='subscriber_view.php?id=$id';</script>";
            exit;
        }
        
        elseif ($action === 'upload_photo') {
            if (!isset($_FILES['profile_photo'])) {
                echo "<script>alert('No file uploaded.'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }
            if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
                $errCode = $_FILES['profile_photo']['error'];
                echo "<script>alert('Upload error code: $errCode. (1=Too large for PHP, 2=Too large for form, 3=Partial, 4=No file)'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }
            
            $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed)) {
                echo "<script>alert('Invalid file format: $ext. Allowed: JPG, PNG, GIF, WEBP'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }

            // Ensure directory exists
            $uploadDir = '../uploads/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $filename = 'user_' . $id . '_' . time() . '.' . $ext;
            $dest = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest)) {
                
                // Safely add column if it doesn't exist
                try {
                    $pdo->exec("ALTER TABLE subscribers ADD COLUMN photo VARCHAR(255) DEFAULT NULL");
                } catch (PDOException $e) {
                    // Ignore, column likely exists
                }

                try {
                    // Delete old photo if exists
                    $oldStmt = $pdo->prepare('SELECT photo FROM subscribers WHERE id=?');
                    $oldStmt->execute([$id]);
                    $oldPhoto = $oldStmt->fetchColumn();
                    if ($oldPhoto && file_exists('../uploads/profiles/' . $oldPhoto)) {
                        unlink('../uploads/profiles/' . $oldPhoto);
                    }
                    
                    $pdo->prepare('UPDATE subscribers SET photo = ? WHERE id=?')->execute([$filename, $id]);
                    $is_mgr = !empty($_SESSION['is_manager']);
                    $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
                    $actor_name = $is_mgr ? ($_SESSION['manager_name'] ?? ($_SESSION['manager_username'] ?? 'Manager')) : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
                    $actor_role = $is_mgr ? 'Manager' : 'Admin';
                    $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', 'Updated Profile Photo by $actor_role ($actor_name)')")->execute([$client_id, $mgr_id, $actor_name, $actor_role, $u]);
                    
                    echo "<script>window.location='subscriber_view.php?id=$id';</script>";
                    exit;
                } catch (PDOException $e) {
                    $dbErr = addslashes($e->getMessage());
                    echo "<script>alert('Database error: $dbErr'); window.location='subscriber_view.php?id=$id';</script>";
                    exit;
                }
            } else {
                echo "<script>alert('Server error: Failed to save file. Check directory permissions for uploads/profiles/'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }
        }
        elseif ($action === 'add_balance') {
            $amount = (float)$_POST['amount'];
            if ($amount != 0) {
                // Get current balance before adding
                $bStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id=?");
                $bStmt->execute([$id]);
                $current_bal = (float)$bStmt->fetchColumn();
                $new_bal = $current_bal + $amount;
                
                $pdo->prepare("UPDATE subscribers SET balance = ? WHERE id=?")->execute([$new_bal, $id]);
                
                $type = $amount > 0 ? 'credit' : 'debit';
                $absAmount = abs($amount);

                $is_mgr = !empty($_SESSION['is_manager']);
                $mgr_id = $is_mgr ? (int)($_SESSION['manager_id'] ?? 0) : null;
                $actor_name = $is_mgr ? ($_SESSION['manager_name'] ?? 'Manager') : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
                $actor_role = $is_mgr ? 'Manager' : 'Admin';

                // If Manager collects payment in cash, add to manager's cash_in_hand
                if ($is_mgr && $mgr_id > 0 && $amount > 0) {
                    try {
                        $pdo->prepare("UPDATE managers SET cash_in_hand = cash_in_hand + ? WHERE id = ? AND client_id = ?")
                            ->execute([$absAmount, $mgr_id, $client_id]);
                    } catch (Exception $e) {}
                }

                $desc = $amount > 0 
                    ? "Funds added by $actor_role: $actor_name" 
                    : "Funds deducted by $actor_role: $actor_name";
                $actStr = $amount > 0 
                    ? "Added Balance: Rs. " . number_format($absAmount, 2) . " by $actor_role ($actor_name)" 
                    : "Deducted Balance: Rs. " . number_format($absAmount, 2) . " by $actor_role ($actor_name)";

                $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', ?)")
                    ->execute([$client_id, $mgr_id, $actor_name, $actor_role, $u, $actStr]);
                
                // Insert into ledger
                $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$client_id, $u, $type, $absAmount, $new_bal, $desc]);

                if ($amount > 0) {
                    $ret = urlencode("subscriber_view.php?id=$id");
                    $target_url = "print_receipt.php?sub_id={$id}&paid={$absAmount}&type=collect&return={$ret}";
                    $json_alert = json_encode("Payment Added Successfully! Opening 80mm Print Receipt...");
                    echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Payment Added</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
</head>
<body class='bg-light d-flex align-items-center justify-content-center' style='min-height: 100vh;'>
    <div class='card border-0 shadow-sm rounded-4 p-4 text-center' style='max-width: 440px; width: 92%;'>
        <div class='spinner-border text-success mb-3' style='width: 3rem; height: 3rem;' role='status'></div>
        <h4 class='fw-bold text-dark mb-1'><i class='fa-solid fa-circle-check text-success me-2'></i> Payment Added!</h4>
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
                } else {
                    echo "<script>alert('Balance updated!'); window.location='subscriber_view.php?id=$id';</script>";
                    exit;
                }
                exit;
            }
        }
        elseif ($action === 'renew_user') {
            $cd = check_renewal_cooldown($pdo, $id, 600);
            if (!$cd['allowed']) {
                $rem_mins = $cd['remaining_minutes'];
                echo "<script>alert('Duplicate Renewal Blocked!\\n\\nIs user ka package abhi renew ho chuka hai.\\nDubara renew karne k liye {$rem_mins} minute intezar karein.'); window.location='subscriber_view.php?id=$id';</script>";
                exit;
            }

            $package_id = (int)$_POST['package_id'];
            $expiry_date = trim($_POST['expiry_date'] ?? '');
            $payment_amount = isset($_POST['payment_amount']) ? (float)$_POST['payment_amount'] : 0;
            if ($payment_amount < 0) $payment_amount = 0;
            
            $p = $pdo->prepare("SELECT p.name, p.rate_limit, p.price, p.validity_days, p.pool_id, pl.pool_name FROM packages p LEFT JOIN ip_pools pl ON p.pool_id = pl.id WHERE p.id = ? AND (p.client_id = ? OR p.client_id = 0)");
            $p->execute([$package_id, $client_id]);
            $pkg = $p->fetch();

            if ($pkg) {
                // Fetch full subscriber details including current package details
                $subStmt = $pdo->prepare("
                    SELECT s.id, s.username, s.balance, s.status, s.expiry_date, s.package_id, s.dealer_id,
                           p.price as cur_pkg_price, p.validity_days as cur_pkg_validity, p.name as cur_pkg_name
                    FROM subscribers s
                    LEFT JOIN packages p ON s.package_id = p.id
                    WHERE s.id = ? AND s.client_id = ?
                ");
                $subStmt->execute([$id, $client_id]);
                $sub = $subStmt->fetch();

                if (!$sub) {
                    echo "<script>alert('Subscriber not found.'); window.location='subscribers.php';</script>";
                    exit;
                }

                $now = time();
                $cur_exp_ts = (!empty($sub['expiry_date']) && $sub['expiry_date'] !== '0000-00-00 00:00:00') ? strtotime($sub['expiry_date']) : 0;
                $pkg_val = (int)(($pkg['validity_days'] ?? 0) > 0 ? $pkg['validity_days'] : 30);

                if ($is_manager) {
                    // Manager is strictly restricted: NO custom expiry allowed!
                    // Calculated strictly from current expiry (if active) or now (if expired) + package validity days
                    $base_ts = ($cur_exp_ts > $now) ? $cur_exp_ts : $now;
                    $new_exp_ts = $base_ts + ($pkg_val * 86400);
                    $expiry_date = date('Y-m-d H:i:s', $new_exp_ts);
                } else {
                    if (empty($expiry_date)) {
                        $base_ts = ($cur_exp_ts > $now) ? $cur_exp_ts : $now;
                        $new_exp_ts = $base_ts + ($pkg_val * 86400);
                        $expiry_date = date('Y-m-d H:i:s', $new_exp_ts);
                    } else {
                        $new_exp_ts = strtotime($expiry_date);
                    }
                    if ($new_exp_ts === false || $new_exp_ts <= 0) {
                        echo "<script>alert('Invalid Expiry Date & Time.'); window.location='subscriber_view.php?id=$id';</script>";
                        exit;
                    }
                }

                // 1. Calculate remaining credit on currently active period (if user was still active)
                $old_remaining_credit = 0;
                if ($cur_exp_ts > $now) {
                    $cur_pkg_price = (float)($sub['cur_pkg_price'] ?? 0);
                    $cur_validity = (int)(($sub['cur_pkg_validity'] ?? 0) > 0 ? $sub['cur_pkg_validity'] : 30);
                    $cur_daily_rate = $cur_pkg_price / $cur_validity;
                    $remaining_seconds = $cur_exp_ts - $now;
                    $old_remaining_credit = ($remaining_seconds / 86400) * $cur_daily_rate;
                }

                // 2. Calculate total cost for new future period
                $new_total_cost = 0;
                $new_pkg_price = (float)$pkg['price'];
                $new_validity = (int)(($pkg['validity_days'] ?? 0) > 0 ? $pkg['validity_days'] : 30);
                $new_daily_rate = $new_pkg_price / $new_validity;

                if ($new_exp_ts > $now) {
                    $new_seconds = $new_exp_ts - $now;
                    $new_total_cost = ($new_seconds / 86400) * $new_daily_rate;
                }

                // 3. Financial Net Adjustment:
                // Date goes forward -> net_adjustment is positive -> CHARGE user (balance decreases / minus)
                // Date goes backward -> net_adjustment is negative -> REFUND user (balance increases / plus)
                $net_adjustment = round($new_total_cost - $old_remaining_credit, 2);

                $current_bal = (float)$sub['balance'];
                $bal_after_debit = round($current_bal - $net_adjustment, 2);
                $final_bal = round($bal_after_debit + $payment_amount, 2);

                $pdo->beginTransaction();

                // Dealer handling if subscriber belongs to a dealer
                $sub_dealer_id = (int)($sub['dealer_id'] ?? 0);
                if ($sub_dealer_id > 0) {
                    $old_dealer_credit = 0;
                    if ($cur_exp_ts > $now) {
                        $old_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                        $old_dpStmt->execute([$sub_dealer_id, $sub['package_id']]);
                        $old_dp_price = $old_dpStmt->fetchColumn();
                        if ($old_dp_price === false) {
                            $old_dp_price = (float)($sub['cur_pkg_price'] ?? 0);
                        }
                        $old_cur_val = (int)(($sub['cur_pkg_validity'] ?? 0) > 0 ? $sub['cur_pkg_validity'] : 30);
                        $old_dp_rate = (float)$old_dp_price / $old_cur_val;
                        $old_dealer_credit = (($cur_exp_ts - $now) / 86400) * $old_dp_rate;
                    }

                    $new_dealer_cost = 0;
                    if ($new_exp_ts > $now) {
                        $new_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                        $new_dpStmt->execute([$sub_dealer_id, $package_id]);
                        $new_dp_price = $new_dpStmt->fetchColumn();
                        if ($new_dp_price === false) {
                            $new_dp_price = $new_pkg_price;
                        }
                        $new_dp_rate = (float)$new_dp_price / $new_validity;
                        $new_dealer_cost = (($new_exp_ts - $now) / 86400) * $new_dp_rate;
                    }

                    $net_dealer_deduction = round($new_dealer_cost - $old_dealer_credit, 2);
                    if ($net_dealer_deduction != 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_dealer_deduction, $sub_dealer_id]);
                        $d_note = ($net_dealer_deduction > 0) 
                            ? "Expiry Extended for user {$sub['username']}. Deducted Rs. " . abs($net_dealer_deduction)
                            : "Expiry Reduced for user {$sub['username']}. Credited Rs. " . abs($net_dealer_deduction);
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $d_note]);
                    }
                }

                $new_status = ($new_exp_ts > $now) ? 'active' : 'expired';
                $formatted_expiry_db = date('Y-m-d H:i:s', $new_exp_ts);

                // Update subscriber record with current renewal timestamp (resets data usage for new cycle)
                $now_dt = date('Y-m-d H:i:s');
                $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = ?, balance = ?, last_renewed_at = ? WHERE id = ?")
                    ->execute([$package_id, $formatted_expiry_db, $new_status, $final_bal, $now_dt, $id]);

                // Handle optional renewal receipt upload
                if (!empty($_FILES['renewal_receipt']) && isset($_FILES['renewal_receipt']['error']) && $_FILES['renewal_receipt']['error'] === UPLOAD_ERR_OK) {
                    $by_role = $is_manager ? 'Manager' : 'Admin';
                    $by_name = $is_manager ? ($_SESSION['manager_name'] ?? 'Manager') : ($_SESSION['operator_username'] ?? 'Admin');
                    handle_renewal_receipt_upload($_FILES['renewal_receipt'], $id, $pdo, "{$by_role} ({$by_name})");
                }

                // RADIUS sync
                $formatted_expiry_rad = date('d M Y H:i:s', $new_exp_ts);
                if ($new_status === 'active') {
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry_rad]);

                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Framed-Pool')")->execute([$u]);
                    
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit' && $pkg['rate_limit'] !== 'Unlimited') {
                        $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
                    }

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
                } else {
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry_rad]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
                }

                // Actor details (Admin or Manager)
                $actor_role = $is_manager ? 'Manager' : 'Admin';
                $actor_name = $is_manager ? ($_SESSION['manager_name'] ?? ($_SESSION['manager_username'] ?? 'Manager')) : (!empty($_SESSION['operator_username']) ? $_SESSION['operator_username'] : 'Admin');
                $actor_mgr_id = $is_manager ? (int)($_SESSION['manager_id'] ?? 0) : null;

                // Activity Log
                $act_text = "Renewed Package ({$pkg['name']}) to " . date('d M Y, h:i A', $new_exp_ts);
                if ($payment_amount > 0) {
                    $act_text .= ". Payment received: Rs. " . number_format($payment_amount, 2);
                }
                if ($net_adjustment > 0) {
                    $act_text .= ". Charged: Rs. " . number_format($net_adjustment, 2);
                } elseif ($net_adjustment < 0) {
                    $act_text .= ". Credited: Rs. " . number_format(abs($net_adjustment), 2);
                }
                $act_text .= " by $actor_role: $actor_name";

                $pdo->prepare("INSERT INTO activity_logs (client_id, manager_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, ?, ?, ?, 'User', ?)")
                    ->execute([$client_id, $actor_mgr_id, $actor_name, $actor_role, $u, $act_text]);

                // Ledger Entry 1: Renewal debit / pro-rata adjustment
                if ($net_adjustment > 0) {
                    $days_label = ($new_exp_ts > $now) ? round(($new_exp_ts - max($now, $cur_exp_ts)) / 86400, 1) : 0;
                    $desc = "Expiry Extended (+{$days_label}d) to " . date('d M Y, h:i A', $new_exp_ts) . " ({$pkg['name']}) by $actor_role: $actor_name";
                    $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, ?, ?)")
                        ->execute([$client_id, $u, $net_adjustment, $bal_after_debit, $desc]);
                } elseif ($net_adjustment < 0) {
                    $abs_refund = abs($net_adjustment);
                    $desc = "Expiry Reduced to " . date('d M Y, h:i A', $new_exp_ts) . " (Pro-rata Refund) by $actor_role: $actor_name";
                    $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                        ->execute([$client_id, $u, $abs_refund, $bal_after_debit, $desc]);
                }

                // Ledger Entry 2: Payment Received Credit
                if ($payment_amount > 0) {
                    $pay_desc = "Payment received during Renewal (Cash) by $actor_role: $actor_name";
                    $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                        ->execute([$client_id, $u, $payment_amount, $final_bal, $pay_desc]);

                    // Add to Manager cash_in_hand
                    if ($is_manager && $actor_mgr_id > 0) {
                        try {
                            $pdo->prepare("UPDATE managers SET cash_in_hand = cash_in_hand + ? WHERE id = ? AND client_id = ?")
                                ->execute([$payment_amount, $actor_mgr_id, $client_id]);
                        } catch (Exception $e) {}
                    }
                }

                $pdo->commit();

                // Kick session
                try {
                    require_once __DIR__ . '/../config/radius_kick_helper.php';
                    kick_subscriber_session($pdo, $u, $client_id);
                } catch (Exception $e) {
                    try { kick_user_mikrotik($pdo, $client_id, $u); } catch (Exception $ex) {}
                }

                $alert_msg = "Successfully updated! Expiry set to " . date('d M Y, h:i A', $new_exp_ts) . ". ";
                if ($payment_amount > 0) {
                    $alert_msg .= "Payment Collected: Rs. " . number_format($payment_amount, 2) . ". ";
                }
                if ($net_adjustment > 0) {
                    $alert_msg .= "Charged Rs. " . number_format($net_adjustment, 2) . ". ";
                }
                $alert_msg .= "Current Balance: Rs. " . number_format($final_bal, 2);

                $ret = urlencode("subscriber_view.php?id=$id");
                $paid_val = ($payment_amount > 0) ? $payment_amount : (($current_bal > 0) ? min((float)$current_bal, (float)$net_adjustment) : 0);
                $target_url = "print_receipt.php?sub_id={$id}&paid={$paid_val}&type=renew&return={$ret}";
                $json_alert = json_encode($alert_msg);

                echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Package Renewed</title>
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
            }
        }
    }
}
// --- END ACTIONS ---

// Fetch user data
$sql = "SELECT s.*, p.name as pkg_name, p.price as pkg_price, p.data_limit_gb, p.validity_days, c.company_name 
        FROM subscribers s 
        JOIN clients c ON s.client_id = c.id 
        LEFT JOIN packages p ON s.package_id = p.id 
        WHERE s.id = ? AND s.client_id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$id, $client_id]);
$user = $stmt->fetch();

if (!$user) {
    echo "<script>alert('User not found!'); window.location='subscribers.php';</script>";
    exit;
}

// Fetch packages for renew modal
$pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE client_id = ?");
$pkgStmt->execute([$client_id]);
$packages = $pkgStmt->fetchAll();

// Determine current package cycle start date (reset point for data usage & session uptime)
// If last_renewed_at is set, use it. Otherwise use the most recent renewal activity log or calculated cycle start.
$cycle_start = null;
if (!empty($user['last_renewed_at']) && $user['last_renewed_at'] !== '0000-00-00 00:00:00') {
    $cycle_start = $user['last_renewed_at'];
} else {
    // Check if there is a recorded renewal activity log
    $actStmt = $pdo->prepare("SELECT created_at FROM activity_logs WHERE against_to = ? AND (activity LIKE '%Package Renewed%' OR activity LIKE '%Renewed Package%') ORDER BY id DESC LIMIT 1");
    $actStmt->execute([$user['username']]);
    $last_renewal_log = $actStmt->fetchColumn();

    $validity_days = (int)(($user['validity_days'] ?? 0) > 0 ? $user['validity_days'] : 30);
    $created_at = !empty($user['created_at']) ? $user['created_at'] : '2000-01-01 00:00:00';

    if (!empty($last_renewal_log)) {
        $cycle_start = $last_renewal_log;
    } elseif (!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00') {
        $computed_start = date('Y-m-d H:i:s', strtotime($user['expiry_date'] . " -{$validity_days} days"));
        // Current cycle can NEVER begin before the subscriber account was actually created
        $cycle_start = ($computed_start > $created_at) ? $computed_start : $created_at;
    } else {
        $cycle_start = $created_at;
    }
}
$last_act = $cycle_start;

// 1. Fetch data usage from CLOSED sessions in this current cycle
$volStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down 
    FROM radacct 
    WHERE username = ? 
      AND acctstoptime IS NOT NULL 
      AND acctstarttime >= ?");
$volStmt->execute([$user['username'], $cycle_start]);
$vol = $volStmt->fetch();

$closed_up = (float)($vol['up'] ?? 0);
$closed_down = (float)($vol['down'] ?? 0);
$past_bytes = $closed_up + $closed_down;

// 2. Fetch active session in radacct (if online and started within current cycle)
$liveStmt = $pdo->prepare("SELECT acctinputoctets, acctoutputoctets 
    FROM radacct 
    WHERE username = ? 
      AND acctstoptime IS NULL 
      AND acctstarttime >= ? 
    ORDER BY radacctid DESC LIMIT 1");
$liveStmt->execute([$user['username'], $cycle_start]);
$live_rad = $liveStmt->fetch();

$live_rad_up = (float)($live_rad['acctinputoctets'] ?? 0);
$live_rad_down = (float)($live_rad['acctoutputoctets'] ?? 0);
$live_rad_bytes = $live_rad_up + $live_rad_down;

// Initial total bytes (closed + active in radacct) - dynamically updated in real-time by JS if online
$total_bytes = $past_bytes + $live_rad_bytes;

if (!function_exists('formatSubscriberBytes')) {
    function formatSubscriberBytes($bytes) {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . " GB";
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . " MB";
        if ($bytes > 0) return round($bytes / 1024, 2) . " KB";
        return "0 MB";
    }
}

$used_volume_str = formatSubscriberBytes($total_bytes);
$cycle_up_str = formatSubscriberBytes($closed_up + $live_rad_up);
$cycle_down_str = formatSubscriberBytes($closed_down + $live_rad_down);

// 3. Lifetime all-time volume across entire history (for reference in service details)
$lifeStmt = $pdo->prepare("SELECT SUM(acctinputoctets) as up, SUM(acctoutputoctets) as down FROM radacct WHERE username = ?");
$lifeStmt->execute([$user['username']]);
$lifeVol = $lifeStmt->fetch();
$lifetime_bytes = (float)($lifeVol['up'] ?? 0) + (float)($lifeVol['down'] ?? 0);
$lifetime_vol_str = formatSubscriberBytes($lifetime_bytes);

// Check if currently online
$onStmt = $pdo->prepare("SELECT * FROM radacct WHERE username = ? AND acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1");
$onStmt->execute([$user['username']]);
$online = $onStmt->fetch();

$uptime_str = "0.00";
if ($online) {
    $raw_start = $online['acctstarttime'] ?? '';
    $start_time = null;
    if (!empty($raw_start) && $raw_start !== '0000-00-00 00:00:00') {
        $start_time = is_numeric($raw_start) ? (int)$raw_start : strtotime($raw_start);
    }
    if ($start_time && $start_time > 86400) {
        $diff = max(0, time() - $start_time);
    } else {
        $diff = (int)($online['acctsessiontime'] ?? 0);
    }
    $hours = floor($diff / 3600);
    $mins = floor(($diff % 3600) / 60);
    $uptime_str = "{$hours}h {$mins}m";
}

// Session hours in current cycle
$sessStmt = $pdo->prepare("SELECT SUM(acctsessiontime) as total_time FROM radacct WHERE username = ? AND acctstarttime >= ?");
$sessStmt->execute([$user['username'], $cycle_start]);
$total_time_sec = (int)$sessStmt->fetchColumn();
$used_sess_hours = floor($total_time_sec / 3600);
$used_sess_mins = floor(($total_time_sec % 3600) / 60);
$used_sess_str = sprintf("%02d Hours %02d Minutes", $used_sess_hours, $used_sess_mins);

$latestStmt = $pdo->prepare("SELECT * FROM radacct WHERE username = ? ORDER BY radacctid DESC LIMIT 1");
$latestStmt->execute([$user['username']]);
$latest_conn = $latestStmt->fetch();

// Calculate Remaining Volume
$data_limit_gb = (float)($user['data_limit_gb'] ?? 0);
if ($data_limit_gb > 0) {
    $total_vol_str = $data_limit_gb . " GB";
    $rem_gb = $data_limit_gb - ($total_bytes / 1073741824);
    $rem_vol_str = round($rem_gb, 2) . " GB";
} else {
    $total_vol_str = "Unlimited";
    $rem_vol_str = "Unlimited";
}

// Fetch monthly payments and deductions from user_ledger for the current year
$year = date('Y');
$payStmt = $pdo->prepare("SELECT MONTH(created_at) as m, type, SUM(amount) as total 
                          FROM user_ledger 
                          WHERE client_id = ? AND username = ? AND YEAR(created_at) = ?
                          GROUP BY m, type");
$payStmt->execute([$client_id, $user['username'], $year]);
$monthly_added = array_fill(1, 12, 0);
$monthly_deducted = array_fill(1, 12, 0);

while ($row = $payStmt->fetch()) {
    if ($row['type'] == 'credit') {
        $monthly_added[(int)$row['m']] += (float)$row['total'];
    } else {
        $monthly_deducted[(int)$row['m']] += (float)$row['total'];
    }
}
$payment_data_json = json_encode(array_values($monthly_added));
$deducted_data_json = json_encode(array_values($monthly_deducted));

// Fetch full ledger history for the accordion
$ledgerStmt = $pdo->prepare("SELECT * FROM user_ledger WHERE client_id = ? AND username = ? ORDER BY id DESC LIMIT 50");
$ledgerStmt->execute([$client_id, $user['username']]);
$ledger_history = $ledgerStmt->fetchAll();

// PROFILE STATUS LOGIC & AUTO-HEALING
$has_expiry = (!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00');
$expiry_ts  = $has_expiry ? strtotime($user['expiry_date']) : 0;
$now_ts     = time();
$is_time_expired = ($has_expiry && $expiry_ts <= $now_ts);
$is_disabled = (strtolower($user['status'] ?? '') === 'disabled');

// Auto-heal status if expiry_date and status are out of sync:
if (!$is_disabled) {
    if ($has_expiry && $expiry_ts > $now_ts && $user['status'] !== 'active') {
        // Expiry is in future but status was marked expired -> heal to 'active'
        $user['status'] = 'active';
        try {
            $pdo->prepare("UPDATE subscribers SET status = 'active' WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type' AND value = 'Reject'")->execute([$user['username']]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute IN ('Mikrotik-Address-List', 'Mikrotik-Group') AND value IN ('EXPIRED_USERS', 'expired')")->execute([$user['username']]);
        } catch (Exception $e) {}
    } elseif ($is_time_expired && $user['status'] !== 'expired') {
        // Expiry has passed but status was marked active -> mark expired
        $user['status'] = 'expired';
        try {
            $pdo->prepare("UPDATE subscribers SET status = 'expired' WHERE id = ?")->execute([$id]);
        } catch (Exception $e) {}
    }
}

// $is_expired is true IF AND ONLY IF expiry has actually passed or user has no valid expiry and is marked expired
$is_expired = (!$is_disabled && ($is_time_expired || (!$has_expiry && strtolower($user['status'] ?? '') === 'expired')));

$profile_status = 'Offline';
$status_color = 'secondary';
$header_badge_class = 'offline';

if ($is_disabled) {
    $profile_status = 'Disabled';
    $status_color = 'danger';
    $header_badge_class = 'disabled';
} elseif ($is_expired) {
    if ($online) {
        $profile_status = 'Expired (Online)';
        $status_color = 'warning text-dark';
        $header_badge_class = 'expired';
    } else {
        $profile_status = 'Expired (Offline)';
        $status_color = 'danger';
        $header_badge_class = 'offline';
    }
} elseif ($online) {
    $profile_status = 'Online';
    $status_color = 'success';
    $header_badge_class = '';
} else {
    $profile_status = 'Offline';
    $status_color = 'secondary';
    $header_badge_class = 'offline';
}

// DETECT UNCREDITED EXPIRY REDUCTION (Backward Expiry Adjustment Without Ledger Credit)
$uncredited_refund_amount = 0;
$discrepancy_reason = '';
$billed_expiry_str = '';

$lastRenewLedgerStmt = $pdo->prepare("
    SELECT * FROM user_ledger 
    WHERE client_id = ? AND username = ? AND type = 'debit' 
    ORDER BY id DESC LIMIT 1
");
$lastRenewLedgerStmt->execute([$client_id, $user['username']]);
$last_debit = $lastRenewLedgerStmt->fetch(PDO::FETCH_ASSOC);

if ($last_debit) {
    $cur_exp_ts = (!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00') ? strtotime($user['expiry_date']) : 0;
    $billed_expiry_ts = 0;

    // Pattern 1: "to 25 Nov 2026, 02:00 PM" or "to 2026-11-25 14:00:00"
    if (preg_match('/to\s+([0-9]{1,4}[-\/ ][A-Za-z0-9]+[-\/ ][0-9]{2,4}(?:[,\s]+[0-9]{1,2}:[0-9]{2}(?:\s*[AP]M)?)?)/i', $last_debit['description'], $matches)) {
        $parsed = strtotime(trim($matches[1]));
        if ($parsed && $parsed > 0) {
            $billed_expiry_ts = $parsed;
        }
    }
    
    // Pattern 2: If description has "+X days" or "+30d"
    if (!$billed_expiry_ts && preg_match('/\+([0-9.]+)\s*(?:d|days)/i', $last_debit['description'], $d_matches)) {
        $days_added = (float)$d_matches[1];
        $debit_time = strtotime($last_debit['created_at']);
        $billed_expiry_ts = $debit_time + ($days_added * 86400);
    }

    // Pattern 3: Standard package renewal debit (amount approx equals package price)
    if (!$billed_expiry_ts && (float)$last_debit['amount'] > 0) {
        $pkg_price = (float)($user['pkg_price'] ?? 0);
        $pkg_val = (int)(($user['validity_days'] ?? 0) > 0 ? $user['validity_days'] : 30);
        if ($pkg_price > 0 && abs((float)$last_debit['amount'] - $pkg_price) < 10) {
            $debit_time = strtotime($last_debit['created_at']);
            $billed_expiry_ts = $debit_time + ($pkg_val * 86400);
        }
    }

    // Also check activity_logs for renewal timestamp/target date if not found
    if (!$billed_expiry_ts) {
        $actStmt = $pdo->prepare("SELECT * FROM activity_logs WHERE client_id = ? AND against_to = ? AND (activity LIKE '%Renew%' OR activity LIKE '%Extended Expiry to%') ORDER BY id DESC LIMIT 1");
        $actStmt->execute([$client_id, $user['username']]);
        $act_row = $actStmt->fetch(PDO::FETCH_ASSOC);
        if ($act_row && preg_match('/to\s+([0-9]{1,4}[-\/ ][A-Za-z0-9]+[-\/ ][0-9]{2,4}(?:[,\s]+[0-9]{1,2}:[0-9]{2}(?:\s*[AP]M)?)?)/i', $act_row['activity'], $a_matches)) {
            $parsed_act = strtotime(trim($a_matches[1]));
            if ($parsed_act && $parsed_act > 0) {
                $billed_expiry_ts = $parsed_act;
            }
        }
    }

    // Check if billed_expiry_ts > cur_exp_ts (expiry was reduced backward by more than 1 hour)
    if ($billed_expiry_ts > 0 && $cur_exp_ts > 0 && ($billed_expiry_ts - $cur_exp_ts) > 3600) {
        // Check if there was already a credit in user_ledger after this debit
        $chkRefundStmt = $pdo->prepare("
            SELECT COUNT(*) FROM user_ledger 
            WHERE client_id = ? AND username = ? AND type = 'credit' AND id > ? AND (description LIKE '%Refund%' OR description LIKE '%Reduced%' OR description LIKE '%Reconciliation%')
        ");
        $chkRefundStmt->execute([$client_id, $user['username'], $last_debit['id']]);
        $already_refunded = ((int)$chkRefundStmt->fetchColumn() > 0);

        if (!$already_refunded) {
            $pkg_price = (float)($user['pkg_price'] ?? 0);
            $pkg_val = (int)(($user['validity_days'] ?? 0) > 0 ? $user['validity_days'] : 30);
            $daily_rate = $pkg_price / $pkg_val;
            $diff_seconds = $billed_expiry_ts - $cur_exp_ts;
            $diff_days = round($diff_seconds / 86400, 1);
            $uncredited_refund_amount = round(($diff_seconds / 86400) * $daily_rate, 2);
            $billed_expiry_str = date('d M Y, h:i A', $billed_expiry_ts);
            $discrepancy_reason = "Customer expiry was reduced backward by {$diff_days} days (from {$billed_expiry_str} to " . date('d M Y, h:i A', $cur_exp_ts) . ") during Edit Profile without crediting Rs. " . number_format($uncredited_refund_amount, 2) . " into balance.";
        }
    }
}


?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- Leaflet Map CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<!-- DataTables & Buttons CSS -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    .dt-buttons .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
        margin-right: 2px;
        margin-bottom: 10px;
    }
    .dataTables_filter {
        margin-bottom: 10px;
    }
    .dataTables_filter input { background-color: #ffffff; border: 1px solid #ced4da; color: #333; border-radius: 20px; padding: 4px 15px; }
    .dataTables_length select { background-color: #ffffff; border: 1px solid #ced4da; color: #333; border-radius: 6px; }

    .view-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; margin-bottom: 20px; }
    
    .profile-header { display: flex; align-items: center; gap: 15px; padding: 20px; border-bottom: 1px solid #f1f5f9; }
    .avatar-large { width: 60px; height: 60px; background-color: #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #94a3b8; }
    .profile-info h5 { margin: 0; font-weight: 700; color: #1e293b; font-size: 1.1rem; }
    .profile-info p { margin: 0; color: #64748b; font-size: 0.9rem; }
    .status-badge { display: inline-block; background: rgba(16, 185, 129, 0.15); color: #10b981; padding: 2px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; margin-top: 5px; border: 1px solid rgba(16, 185, 129, 0.2); }
    .status-badge.offline { background: rgba(148, 163, 184, 0.15); color: #64748b; border-color: rgba(148, 163, 184, 0.2); }
    .status-badge.disabled { background: rgba(239, 68, 68, 0.15); color: #ef4444; border-color: rgba(239, 68, 68, 0.2); }
    .status-badge.expired { background: rgba(245, 158, 11, 0.15); color: #d97706; border-color: rgba(245, 158, 11, 0.2); }
    
    .profile-list { list-style: none; padding: 0; margin: 0; }
    .profile-list li { padding: 12px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid #f8fafc; color: #475569; font-size: 0.9rem; }
    .profile-list li i { color: #64748b; width: 16px; text-align: center; }
    
    .action-grid { padding: 20px; display: flex; flex-wrap: wrap; gap: 10px; }
    .btn-pill { background: #fff; border: 1px solid #e2e8f0; color: #334155; border-radius: 20px; padding: 6px 14px; font-size: 0.85rem; font-weight: 500; transition: 0.2s; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
    .btn-pill:hover { background: #f8fafc; border-color: #cbd5e1; }
    .btn-pill.dark { background: #1e293b; color: #fff; border-color: #1e293b; }
    .btn-pill.dark:hover { background: #0f172a; }
    .btn-pill.danger { color: #ef4444; border-color: rgba(239, 68, 68, 0.3); }
    .btn-pill.danger:hover { background: rgba(239, 68, 68, 0.05); }

    .metric-card { display: flex; align-items: center; gap: 15px; padding: 15px 20px; }
    .metric-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
    .bg-light-info { background: rgba(14, 165, 233, 0.1); color: #0ea5e9; }
    .bg-light-primary { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
    .bg-light-warning { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
    .bg-light-success { background: rgba(16, 185, 129, 0.1); color: #10b981; }
    .metric-data h6 { margin: 0; font-size: 0.8rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; }
    .metric-data h4 { margin: 0; font-size: 1.25rem; font-weight: 700; color: #1e293b; }

    .custom-accordion .accordion-item { border: none; background: #fff; border-radius: 12px !important; margin-bottom: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); overflow: hidden; }
    .custom-accordion .accordion-button { background: #fff; color: #1e293b; font-weight: 600; padding: 15px 20px; box-shadow: none; border-bottom: 1px solid #f1f5f9; }
    .custom-accordion .accordion-button:not(.collapsed) { background: #f8fafc; color: #3b82f6; }
    .custom-accordion .accordion-button i { color: #64748b; margin-right: 10px; width: 16px; text-align: center; }
    .btn-acc-action { background: #f59e0b; color: #fff; border: none; font-size: 0.8rem; padding: 4px 12px; border-radius: 12px; font-weight: 600; text-decoration: none; }
    .btn-acc-action:hover { background: #d97706; color: #fff; }
</style>

<?php if ($uncredited_refund_amount > 0): ?>
<div class="alert alert-warning border border-warning shadow-sm rounded-4 p-3 mb-4 d-flex flex-wrap align-items-center justify-content-between gap-3" style="background: #fffbeb;">
    <div class="d-flex align-items-center gap-3">
        <div class="bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.25rem;">
            <i class="fa-solid fa-coins"></i>
        </div>
        <div>
            <h6 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
                <span>Missing Expiry Refund Detected!</span>
                <span class="badge bg-warning text-dark border border-warning-subtle fw-bold">Uncredited Balance</span>
            </h6>
            <div class="text-secondary small">
                <?= htmlspecialchars($discrepancy_reason) ?>
            </div>
        </div>
    </div>
    <form method="POST" class="d-flex align-items-center gap-2 m-0 flex-shrink-0">
        <input type="hidden" name="action" value="apply_uncredited_refund">
        <input type="hidden" name="reason" value="<?= htmlspecialchars($discrepancy_reason) ?>">
        <div class="input-group input-group-sm shadow-sm" style="width: 260px;">
            <span class="input-group-text bg-white fw-bold text-muted">Rs.</span>
            <input type="number" step="0.01" name="refund_amount" class="form-control fw-bold text-success" value="<?= $uncredited_refund_amount ?>" required>
            <button type="submit" class="btn btn-success fw-bold px-3">
                <i class="fa-solid fa-plus-circle me-1"></i> Add to Balance
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="row">
    <!-- Left Column: Profile Card -->
    <div class="col-lg-4 col-md-5">
        <div class="view-card">
            <div class="profile-header">
                <?php if (!empty($user['photo']) && file_exists("../uploads/profiles/" . $user['photo'])): ?>
                    <div style="width: 60px; height: 60px; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; background-color: #e2e8f0; flex-shrink: 0; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#photoViewModal" title="Click to view">
                        <img src="../uploads/profiles/<?= htmlspecialchars($user['photo']) ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                <?php else: ?>
                    <div class="avatar-large"><i class="fa-solid fa-user"></i></div>
                <?php endif; ?>
                <div class="profile-info">
                    <h5><?= htmlspecialchars($user['full_name'] ?: 'Unknown Name') ?></h5>
                    <p class="mb-1 d-inline-flex align-items-center gap-2">
                        <span class="fw-bold text-primary font-monospace"><?= htmlspecialchars($user['username']) ?></span>
                        <button type="button" 
                                class="copy-user-btn" 
                                onclick="copyToClipboard(event, '<?= addslashes(htmlspecialchars($user['username'])) ?>', this)" 
                                title="Copy Username">
                            <i class="fa-regular fa-copy" style="font-size: 0.8rem;"></i>
                        </button>
                    </p>
                    <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
                        <span class="status-badge <?= $header_badge_class ?>"><?= $profile_status ?></span>
                        <?php if ($online): ?>
                            <span class="badge rounded-pill bg-success text-white px-2 py-1 shadow-sm d-inline-flex align-items-center gap-1" style="font-size: 0.72rem; letter-spacing: 0.3px;">
                                <span class="spinner-grow spinner-grow-sm text-white" style="width: 6px; height: 6px;" role="status"></span>
                                <span>ONLINE</span>
                            </span>
                        <?php else: ?>
                            <span class="badge rounded-pill bg-secondary text-white px-2 py-1 d-inline-flex align-items-center gap-1" style="font-size: 0.72rem; letter-spacing: 0.3px;">
                                <i class="fa-solid fa-circle text-white-50" style="font-size: 0.45rem;"></i>
                                <span>OFFLINE</span>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <ul class="profile-list">
                <li><i class="fa-solid fa-building"></i> <?= htmlspecialchars($user['company_name']) ?></li>
                <li><i class="fa-solid fa-user-shield"></i> Admin</li>
                <li><i class="fa-solid fa-id-card"></i> <?= htmlspecialchars($user['national_id'] ?: 'N/A') ?></li>
                <li><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($user['mobile'] ?: ($user['phone'] ?: 'N/A')) ?></li>
                <li><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($user['address'] ?: 'N/A') ?>
                    <?php if (!empty($user['latitude']) && !empty($user['longitude']) && (float)$user['latitude'] != 0): ?>
                        <a href="https://www.google.com/maps?q=<?= $user['latitude'] ?>,<?= $user['longitude'] ?>" target="_blank" class="badge bg-primary text-white text-decoration-none ms-1 px-2 py-1" style="font-size: 0.7rem;" title="View exact house location on Google Maps">
                            <i class="fa-solid fa-map-location-dot me-1"></i> Maps
                        </a>
                    <?php endif; ?>
                </li>
                <li>
                    <i class="fa-solid fa-calendar-plus text-primary"></i> 
                    <span class="text-muted small me-1">Created:</span> 
                    <span class="fw-semibold text-dark"><?= !empty($user['created_at']) ? date('d M Y, h:i A', strtotime($user['created_at'])) : 'N/A' ?></span>
                </li>
                <li>
                    <i class="fa-solid fa-calendar-xmark text-danger"></i> 
                    <span class="text-muted small me-1">Expiry:</span> 
                    <span class="fw-semibold text-dark"><?= !empty($user['expiry_date']) ? date('d M Y, h:i A', strtotime($user['expiry_date'])) : 'N/A' ?></span>
                </li>
            </ul>
            <?php if (!empty($user['last_renewal_receipt'])): ?>
                <?php 
                    $rcpt_path = $user['last_renewal_receipt'];
                    $is_pdf = (strtolower(pathinfo($rcpt_path, PATHINFO_EXTENSION)) === 'pdf');
                    $rcpt_url = '../' . htmlspecialchars($rcpt_path);
                ?>
                <div class="p-3 rounded-3 border mb-3 shadow-sm" style="background: #f8fafc; border-left: 4px solid #0d6efd !important;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-uppercase fw-bold text-primary" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                                <i class="fa-solid fa-receipt me-1"></i> Last Renewal Receipt
                            </div>
                            <div class="small fw-semibold text-dark mt-1">
                                Uploaded: <?= !empty($user['last_renewal_receipt_at']) ? date('d M Y, h:i A', strtotime($user['last_renewal_receipt_at'])) : 'Recently' ?>
                            </div>
                            <?php if (!empty($user['last_renewal_receipt_by'])): ?>
                                <div class="text-muted" style="font-size: 0.75rem;">
                                    By: <strong class="text-secondary"><?= htmlspecialchars($user['last_renewal_receipt_by']) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="text-end">
                            <a href="<?= $rcpt_url ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3 py-1.5 fw-bold shadow-sm d-inline-flex align-items-center gap-1" style="font-size: 0.78rem;">
                                <i class="fa-solid <?= $is_pdf ? 'fa-file-pdf' : 'fa-image' ?>"></i>
                                <span>View Receipt</span>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="action-grid">
                <!-- Modals Triggers -->
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="fa-brands fa-paypal"></i> Payment</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#renewModal"><i class="fa-solid fa-rotate"></i> Renew</button>
                <a href="print_receipt.php?sub_id=<?= $id ?>&return=<?= urlencode('subscriber_view.php?id=' . $id) ?>" target="_blank" class="btn-pill text-primary fw-bold"><i class="fa-solid fa-receipt text-primary"></i> Print Receipt</a>
                <button type="button" class="btn-pill" onclick="alert('User Password: <?= htmlspecialchars($user['password']) ?>')"><i class="fa-solid fa-lock"></i> Toggle Password</button>
                <button type="button" class="btn-pill dark" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fa-solid fa-user-pen"></i> Edit Profile</button>
                <form method="POST" enctype="multipart/form-data" id="photoForm" style="display:none;">
                    <input type="hidden" name="action" value="upload_photo">
                    <input type="file" name="profile_photo" id="photoInput" accept="image/*" onchange="document.getElementById('photoForm').submit();">
                </form>
                <button type="button" class="btn-pill" onclick="document.getElementById('photoInput').click();"><i class="fa-regular fa-image"></i> Change Photo</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#noteModal"><i class="fa-regular fa-note-sticky"></i> Add Note</button>
                <button type="button" class="btn-pill" data-bs-toggle="modal" data-bs-target="#passwordModal"><i class="fa-solid fa-key"></i> Change Password</button>
                <button type="button" class="btn-pill"><i class="fa-solid fa-bars-progress"></i> Service Settings</button>
                <button type="button" class="btn-pill"><i class="fa-solid fa-file-circle-plus"></i> Add User Document</button>
                
                <!-- Action Forms -->
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this profile permanently?');">
                    <input type="hidden" name="action" value="delete_user">
                    <button type="submit" class="btn-pill danger"><i class="fa-solid fa-trash"></i> Delete Profile</button>
                </form>

                <?php if($user['status'] === 'disabled'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="enable_net">
                        <button type="submit" class="btn-pill" style="color: #10b981; border-color: rgba(16, 185, 129, 0.3);"><i class="fa-solid fa-wifi"></i> Enable Net</button>
                    </form>
                    
                    <form method="POST" class="d-inline" onsubmit="return confirm('Disconnect user from router?');">
                        <input type="hidden" name="action" value="disconnect_user">
                        <button type="submit" class="btn-pill danger"><i class="fa-solid fa-plug-circle-xmark"></i> Disconnect</button>
                    </form>
                    
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="enable_net">
                        <button type="submit" class="btn-pill" style="color: #10b981; border-color: rgba(16, 185, 129, 0.3);"><i class="fa-solid fa-user-check"></i> Profile Enable</button>
                    </form>
                <?php else: ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Disable internet for this user?');">
                        <input type="hidden" name="action" value="disable_net">
                        <button type="submit" class="btn-pill danger"><i class="fa-solid fa-wifi"></i> Disable Net</button>
                    </form>
                    
                    <form method="POST" class="d-inline" onsubmit="return confirm('Disconnect user from router?');">
                        <input type="hidden" name="action" value="disconnect_user">
                        <button type="submit" class="btn-pill danger"><i class="fa-solid fa-plug-circle-xmark"></i> Disconnect</button>
                    </form>

                    <form method="POST" class="d-inline" onsubmit="return confirm('Disable this profile?');">
                        <input type="hidden" name="action" value="profile_disable">
                        <button type="submit" class="btn-pill danger"><i class="fa-solid fa-user-slash"></i> Profile Disable</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Metrics & Accordions -->
    <div class="col-lg-8 col-md-7">
        
        <!-- Metrics Row -->
        <div class="row mb-4">
            <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
                <div class="view-card metric-card mb-0 h-100">
                    <div class="metric-icon <?= $online ? 'bg-light-success text-success' : 'bg-light-secondary text-secondary' ?>"><i class="fa-solid fa-wifi"></i></div>
                    <div class="metric-data">
                        <h6>Connection Status</h6>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <?php if ($online): ?>
                                <span class="badge bg-success rounded-pill px-2 py-1" style="font-size: 0.75rem;">Online</span>
                                <h5 class="mb-0 fw-bold text-dark font-monospace" id="live_uptime" style="font-size: 0.95rem;"><?= $uptime_str ?></h5>
                            <?php else: ?>
                                <span class="badge bg-secondary rounded-pill px-2 py-1" style="font-size: 0.75rem;">Offline</span>
                                <span class="text-muted small" id="live_uptime">Disconnected</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0">
                <div class="view-card metric-card mb-0 h-100">
                    <div class="metric-icon bg-light-primary"><i class="fa-solid fa-hourglass-start"></i></div>
                    <div class="metric-data">
                        <h6>Total Volume</h6>
                        <h4>Unlimited</h4>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-3 mb-sm-0">
                <div class="view-card metric-card mb-0 h-100">
                    <div class="metric-icon bg-light-warning"><i class="fa-solid fa-hourglass-half"></i></div>
                    <div class="metric-data">
                        <h6>Used Volume</h6>
                        <h4 id="live_used_volume"><?= $used_volume_str ?></h4>
                        <small class="text-muted" style="font-size: 0.72rem;" >Since <?= $cycle_start ? date('d M Y', strtotime($cycle_start)) : 'Activation' ?></small>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="view-card metric-card mb-0 h-100">
                    <div class="metric-icon bg-light-success"><i class="fa-regular fa-credit-card"></i></div>
                    <div class="metric-data">
                        <h6>Current Balance</h6>
                        <h4><?= number_format($user['balance'], 0) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Graph Card -->
        <div class="view-card p-4 mb-4 border-0 shadow-sm" style="border-radius: 14px; background: #ffffff;">
            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; border-radius: 10px;">
                        <i class="fa-solid fa-chart-line fs-5"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark">Live Bandwidth Monitor &amp; Ledger</h6>
                        <small class="text-muted" style="font-size: 0.78rem;">Real-time MikroTik traffic throughput &amp; billing history</small>
                    </div>
                </div>
                <div>
                    <span id="live_status_badge" class="badge bg-secondary bg-opacity-10 text-secondary border px-3 py-1.5 rounded-pill fs-7 d-inline-flex align-items-center gap-1.5">
                        <i class="fa-solid fa-circle text-muted me-1" style="font-size:0.45rem;"></i> Connecting...
                    </span>
                </div>
            </div>
            
            <div class="row g-4">
                <!-- Left: Annual Financial Ledger -->
                <div class="col-lg-6 border-end">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="fw-bold text-secondary small text-uppercase" style="letter-spacing: 0.5px;">
                            <i class="fa-solid fa-scale-balanced me-1 text-primary"></i> Annual Ledger (Funds vs Usage)
                        </span>
                        <span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.72rem;">Last 12 Months</span>
                    </div>
                    <div style="position: relative; height: 215px;">
                        <canvas id="ledgerChart"></canvas>
                    </div>
                </div>
                
                <!-- Right: Live MikroTik Bandwidth -->
                <div class="col-lg-6">
                    <div id="bw_router_alert" class="d-none"></div>
                    <!-- Live Speed Stat Cards -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="p-2.5 rounded-3 border" style="background: linear-gradient(135deg, rgba(6, 182, 212, 0.08) 0%, rgba(14, 165, 233, 0.03) 100%); border-color: rgba(6, 182, 212, 0.25) !important;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-muted text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-circle-down text-info me-1"></i> Live Download
                                    </span>
                                    <span class="badge bg-info bg-opacity-10 text-info font-monospace px-1.5 py-0.5" id="peak_down_badge" style="font-size: 0.68rem;">Peak: 0.00</span>
                                </div>
                                <div class="d-flex align-items-baseline gap-1">
                                    <span id="live_down_val" class="font-monospace fw-bolder fs-3 text-info" style="line-height: 1.1;">0.00</span>
                                    <span id="live_down_unit" class="text-info fw-bold small">Mbps</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2.5 rounded-3 border" style="background: linear-gradient(135deg, rgba(168, 85, 247, 0.08) 0%, rgba(217, 70, 239, 0.03) 100%); border-color: rgba(168, 85, 247, 0.25) !important;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-muted text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-circle-up me-1" style="color: #a855f7;"></i> Live Upload
                                    </span>
                                    <span class="badge font-monospace px-1.5 py-0.5" id="peak_up_badge" style="font-size: 0.68rem; background: rgba(168, 85, 247, 0.12); color: #a855f7;">Peak: 0.00</span>
                                </div>
                                <div class="d-flex align-items-baseline gap-1">
                                    <span id="live_up_val" class="font-monospace fw-bolder fs-3" style="color: #a855f7; line-height: 1.1;">0.00</span>
                                    <span id="live_up_unit" class="fw-bold small" style="color: #a855f7;">Mbps</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Waveform Canvas -->
                    <div style="position: relative; height: 160px;">
                        <canvas id="bwChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <!-- Accordions -->
        <div class="accordion custom-accordion" id="userDetailsAccordion">
            
            <div class="accordion-item">
                <h2 class="accordion-header d-flex align-items-center">
                    <button class="accordion-button collapsed flex-grow-1" type="button" data-bs-toggle="collapse" data-bs-target="#accService">
                        <i class="fa-solid fa-wrench"></i> Service Details
                    </button>
                    <a href="#" class="btn-acc-action position-absolute" style="right: 50px; z-index: 5;">
                        <i class="fa-solid fa-bars-progress me-1"></i> Service Settings
                    </a>
                </h2>
                <div id="accService" class="accordion-collapse collapse show" data-bs-parent="#userDetailsAccordion">
                    <div class="accordion-body text-secondary p-3">
                        <table class="table table-sm table-borderless text-secondary mb-0" style="font-size: 0.9rem;">
                            <tbody>
                                <tr>
                                    <td class="fw-bold w-50">Profile Status</td>
                                    <td>
                                        <?php if ($is_disabled): ?>
                                            <span class="badge bg-danger">Disabled</span>
                                        <?php elseif ($is_expired): ?>
                                            <span class="badge bg-warning text-dark">Expired</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Connection State</td>
                                    <td>
                                        <?php if ($online): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-wifi me-1"></i>Online</span>
                                            <span class="text-success small fw-semibold ms-2">(Connected - Uptime: <span id="svc_uptime_display"><?= $uptime_str ?></span>)</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fa-solid fa-power-off me-1"></i>Offline</span>
                                            <span class="text-muted small ms-2">(Disconnected)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr><td class="fw-bold">Connection Type</td><td>Radius <?= strtoupper($user['service_type']) ?></td></tr>
                                <tr><td class="fw-bold">Package</td><td><?= htmlspecialchars($user['pkg_name'] ?? 'N/A') ?></td></tr>
                                <tr><td class="fw-bold">Package Duration</td><td><?= $user['validity_days'] ? $user['validity_days'] . ' Days' : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Created / Registered Date</td><td><?= !empty($user['created_at']) ? date('d M Y H:i:s', strtotime($user['created_at'])) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Last Expiration Date</td><td><?= $user['expiry_date'] ? date('d M Y H:i:s', strtotime($user['expiry_date'])) : 'N/A' ?></td></tr>
                                <tr>
                                    <td class="fw-bold">Last Renewal Receipt</td>
                                    <td>
                                        <?php if (!empty($user['last_renewal_receipt'])): ?>
                                            <a href="../<?= htmlspecialchars($user['last_renewal_receipt']) ?>" target="_blank" class="badge bg-primary text-white text-decoration-none px-2.5 py-1.5 fw-bold d-inline-flex align-items-center gap-1">
                                                <i class="fa-solid fa-paperclip"></i> View Uploaded Receipt
                                            </a>
                                            <span class="text-muted small ms-2">
                                                (<?= !empty($user['last_renewal_receipt_at']) ? date('d M Y, h:i A', strtotime($user['last_renewal_receipt_at'])) : '' ?>
                                                <?= !empty($user['last_renewal_receipt_by']) ? 'by ' . htmlspecialchars($user['last_renewal_receipt_by']) : '' ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small"><i class="fa-regular fa-circle-xmark me-1"></i> No receipt attached on last renewal</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr><td class="fw-bold">Total Volume</td><td><?= $total_vol_str ?></td></tr>
                                <tr>
                                    <td class="fw-bold">Used Volume (Current Cycle)</td>
                                    <td id="svc_used_volume">
                                        <strong><?= $used_volume_str ?></strong>
                                        <span class="text-muted small ms-1">(Up: <?= $cycle_up_str ?> | Down: <?= $cycle_down_str ?>)</span>
                                    </td>
                                </tr>
                                <tr><td class="fw-bold">Remaining Volume</td><td id="svc_rem_volume"><?= $rem_vol_str ?></td></tr>
                                <tr><td class="fw-bold">All-Time Lifetime Usage</td><td><span class="text-muted"><?= $lifetime_vol_str ?></span></td></tr>
                                <tr><td class="fw-bold">Current Cycle Started</td><td><?= $cycle_start ? date('d M Y, h:i A', strtotime($cycle_start)) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Total Session Time</td><td>Unlimited</td></tr>
                                <tr><td class="fw-bold">Used Session Time</td><td><?= $used_sess_str ?></td></tr>
                                <tr><td class="fw-bold">Remaining Session Time</td><td>Unlimited</td></tr>
                                <tr><td class="fw-bold">Last Activation Date</td><td><?= $last_act ? date('d M Y H:i:s', strtotime($last_act)) : 'N/A' ?></td></tr>
                                <?php
$latest_login_ts = null;
if (!empty($latest_conn['acctstarttime']) && $latest_conn['acctstarttime'] !== '0000-00-00 00:00:00') {
    $latest_login_ts = is_numeric($latest_conn['acctstarttime']) ? (int)$latest_conn['acctstarttime'] : strtotime($latest_conn['acctstarttime']);
}
if (!$latest_login_ts && !empty($latest_conn['acctstoptime']) && $latest_conn['acctstoptime'] !== '0000-00-00 00:00:00') {
    $stop_ts = is_numeric($latest_conn['acctstoptime']) ? (int)$latest_conn['acctstoptime'] : strtotime($latest_conn['acctstoptime']);
    if ($stop_ts > 86400) {
        $latest_login_ts = $stop_ts - (int)($latest_conn['acctsessiontime'] ?? 0);
    }
}
$last_login_display = ($latest_login_ts && $latest_login_ts > 86400) ? date('d M Y H:i:s', $latest_login_ts) : 'N/A';
?>
<tr><td class="fw-bold">Last Login</td><td><?= $last_login_display ?></td></tr>
                                <tr><td class="fw-bold">IPv6 IP Address</td><td><?= !empty($latest_conn['framedipv6address']) ? htmlspecialchars($latest_conn['framedipv6address']) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Connected IP</td><td><?= !empty($latest_conn['framedipaddress']) ? htmlspecialchars($latest_conn['framedipaddress']) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Connected MAC</td><td><?= !empty($latest_conn['callingstationid']) ? htmlspecialchars($latest_conn['callingstationid']) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Connected NAS/Router</td><td><?= !empty($latest_conn['nasipaddress']) ? htmlspecialchars($latest_conn['nasipaddress']) : 'N/A' ?></td></tr>
                                <tr><td class="fw-bold">Connected Port</td><td><?= !empty($latest_conn['nasportid']) ? htmlspecialchars($latest_conn['nasportid']) : 'N/A' ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accNote">
                        <i class="fa-regular fa-note-sticky"></i> Profile Notes
                    </button>
                </h2>
                <div id="accNote" class="accordion-collapse collapse" data-bs-parent="#userDetailsAccordion">
                    <div class="accordion-body text-secondary">
                        <p><?= nl2br(htmlspecialchars($user['notes'] ?: 'No notes found for this user.')) ?></p>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#accLedger">
                        <i class="fa-solid fa-chart-line"></i> Ledger
                    </button>
                </h2>
                <div id="accLedger" class="accordion-collapse collapse" data-bs-parent="#userDetailsAccordion">
                    <div class="accordion-body text-secondary p-0">
                        <?php if (empty($ledger_history)): ?>
                            <p class="p-3 mb-0">No ledger transactions found yet.</p>
                        <?php else: ?>
                            <div class="row px-3 pt-3 pb-0">
                                <div class="col-md-4 mb-2">
                                    <label class="form-label small text-secondary mb-1">From Date</label>
                                    <input type="date" id="minLedgerDate" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label small text-secondary mb-1">To Date</label>
                                    <input type="date" id="maxLedgerDate" class="form-control form-control-sm">
                                </div>
                            </div>
                            <div class="table-responsive p-3 pt-0">
                                <table id="ledgerTable" class="table table-hover table-bordered mb-0 text-secondary w-100" style="font-size: 0.85rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th>Amount</th>
                                            <th>Balance After</th>
                                            <th class="text-end">Receipt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($ledger_history as $l): ?>
                                            <tr>
                                                <td data-order="<?= date('Y-m-d H:i:s', strtotime($l['created_at'])) ?>"><?= date('d M Y, h:i A', strtotime($l['created_at'])) ?></td>
                                                <td><?= htmlspecialchars($l['description']) ?></td>
                                                <td class="<?= $l['type'] == 'credit' ? 'text-success' : 'text-danger' ?>">
                                                    <?= $l['type'] == 'credit' ? '+' : '-' ?> Rs <?= number_format($l['amount'], 0) ?>
                                                </td>
                                                <td class="fw-bold">Rs <?= number_format($l['balance_after'], 0) ?></td>
                                                <td class="text-end">
                                                    <a href="print_receipt.php?ledger_id=<?= $l['id'] ?>&sub_id=<?= $id ?>&return=<?= urlencode('subscriber_view.php?id=' . $id) ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-bold" style="font-size: 0.78rem;">
                                                        <i class="fa-solid fa-print me-1"></i> Receipt
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODALS ================= -->

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-brands fa-paypal"></i> Add Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_balance">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Amount</label>
                <input type="number" step="1" name="amount" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Add Balance</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Renew & Custom Expiry Modal -->
<div class="modal fade" id="renewModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content shadow-lg border-0 rounded-4">
      <div class="modal-header bg-light border-bottom px-4 py-3">
        <h5 class="modal-title fw-bold text-dark">
          <i class="fa-solid fa-rotate text-primary me-2"></i> <?= $is_manager ? 'Renew Subscriber Package' : 'Renew &amp; Custom Expiry Management' ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data" onsubmit="var btn=this.querySelector('button[type=submit]'); if(this.submitted){return false;} this.submitted=true; if(btn){btn.disabled=true; btn.innerHTML='<i class=\'fa-solid fa-spinner fa-spin me-2\'></i> Processing Renewal... Please wait...';} return true;">
        <input type="hidden" name="action" value="renew_user">
        <div class="modal-body p-4">

            <!-- Subscriber Quick Status Strip -->
            <div class="bg-light rounded-3 p-3 mb-3 border">
                <div class="row g-2 align-items-center">
                    <div class="col-6 col-md-3">
                        <div class="text-muted small fw-semibold">Status</div>
                        <div class="mt-1">
                            <?php if (!$is_disabled && !$is_expired): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold rounded-pill px-2 py-1">
                                    <i class="fa-solid fa-circle-check me-1"></i> Active
                                </span>
                            <?php elseif ($is_disabled): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold rounded-pill px-2 py-1">
                                    <i class="fa-solid fa-ban me-1"></i> Disabled
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold rounded-pill px-2 py-1">
                                    <i class="fa-solid fa-circle-xmark me-1"></i> Expired
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small fw-semibold">Current Expiry</div>
                        <div class="fw-bold text-dark mt-1 font-monospace small">
                            <?= (!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00') ? date('d M Y, h:i A', strtotime($user['expiry_date'])) : '<span class="text-muted">None / Expired</span>' ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small fw-semibold">Current Balance</div>
                        <div class="fw-bold mt-1 <?= (float)$user['balance'] < 0 ? 'text-danger' : 'text-success' ?>">
                            Rs. <?= number_format((float)$user['balance'], 2) ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-muted small fw-semibold">Current Package</div>
                        <div class="fw-bold text-dark mt-1 small">
                            <?= htmlspecialchars($user['pkg_name'] ?? 'N/A') ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Inputs -->
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-secondary small">Package <span class="text-danger">*</span></label>
                    <select name="package_id" id="renew_package_select" class="form-select form-select-sm" required onchange="recalculateRenewAdjustment()">
                        <?php foreach($packages as $p): ?>
                            <option value="<?= $p['id'] ?>" 
                                data-price="<?= (float)$p['price'] ?>" 
                                data-days="<?= (int)(($p['validity_days'] ?? 0) > 0 ? $p['validity_days'] : 30) ?>" 
                                data-name="<?= htmlspecialchars($p['name']) ?>"
                                <?= $p['id'] == $user['package_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?> (Rs. <?= number_format($p['price']) ?> / <?= $p['validity_days'] ?: 30 ?>d) - <?= htmlspecialchars($p['rate_limit']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!$is_manager): ?>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-secondary small">Set Expiry Date &amp; Time <span class="text-danger">*</span></label>
                    <input type="datetime-local" name="expiry_date" id="renew_expiry_input" class="form-control form-control-sm" 
                           value="<?= (!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00') ? date('Y-m-d\TH:i', strtotime($user['expiry_date'])) : date('Y-m-d\TH:i', strtotime('+30 days')) ?>" 
                           required oninput="recalculateRenewAdjustment()">
                </div>
                <?php else: ?>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-secondary small">Package Validity / Expiry</label>
                    <div class="form-control form-control-sm bg-light text-primary fw-bold d-flex align-items-center justify-content-between">
                        <span><i class="fa-solid fa-calendar-check me-2 text-success"></i> Auto (+<span id="mgr_pkg_days_display">30</span> Days)</span>
                        <span class="badge bg-primary text-white" id="mgr_target_expiry_display">Calculating...</span>
                    </div>
                    <input type="hidden" name="expiry_date" id="renew_expiry_input" value="">
                </div>
                <?php endif; ?>

                <!-- Dedicated Payment Received Field -->
                <div class="col-12 mt-2">
                    <div class="p-3 rounded-3 border border-success-subtle bg-success bg-opacity-10 shadow-sm">
                        <div class="row align-items-center g-2">
                            <div class="col-md-7">
                                <label class="form-label fw-bold text-success mb-0 d-flex align-items-center fs-6">
                                    <i class="fa-solid fa-money-bill-wave me-2 text-success fs-5"></i> Payment Received (Rs)
                                </label>
                                <div class="text-muted small mt-1" style="font-size: 0.78rem;">
                                    Enter cash received from subscriber at renewal. Recorded in Ledger <?= $is_manager ? "and added to your <strong>Cash in Hand</strong>" : "and subscriber balance" ?>.
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="input-group">
                                    <span class="input-group-text bg-white fw-bold text-success border-success-subtle">Rs.</span>
                                    <input type="number" step="any" min="0" name="payment_amount" id="renew_payment_amount" class="form-control form-control-lg fw-bold text-success border-success-subtle shadow-none" placeholder="0.00" value="<?= (float)($user['pkg_price'] ?? 0) ?>" oninput="recalculateRenewAdjustment()">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Optional Renewal Receipt Upload -->
                <div class="col-12 mb-3">
                    <div class="p-3 rounded-3 border bg-white shadow-sm">
                        <div class="row align-items-center">
                            <div class="col-md-7">
                                <label class="form-label fw-bold text-dark mb-0 d-flex align-items-center small">
                                    <i class="fa-solid fa-paperclip text-primary me-2 fs-6"></i> Attach Receipt / Slip <span class="badge bg-light text-muted border ms-2" style="font-size: 0.65rem;">Optional</span>
                                </label>
                                <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                                    Customer deposit slip, transfer screenshot, or voucher (JPG, PNG, PDF).
                                </div>
                            </div>
                            <div class="col-md-5">
                                <input type="file" name="renewal_receipt" class="form-control form-control-sm" accept="image/*,.pdf">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$is_manager): ?>
            <!-- Quick Preset Helper Buttons -->
            <div class="mt-3">
                <label class="form-label fw-semibold text-secondary small mb-1">
                    <i class="fa-solid fa-wand-magic-sparkles text-primary me-1"></i> Quick Expiry Presets:
                </label>
                <div class="d-flex flex-wrap gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setRenewPreset(7)">+7 Days</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setRenewPreset(15)">+15 Days</button>
                    <button type="button" class="btn btn-sm btn-outline-primary fw-semibold rounded-pill px-3" onclick="setRenewPreset(30)">+30 Days (1 Month)</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="setRenewPreset(60)">+60 Days (2 Months)</button>
                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2" onclick="setRenewPreset(0)" title="Set Expiry to Right Now (Expire User)">Expire Now</button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Live Financial Calculation Container -->
            <div class="mt-4">
                <label class="form-label fw-semibold text-secondary small mb-1">
                    <i class="fa-solid fa-calculator text-primary me-1"></i> Live Financial Impact &amp; Ledger Calculation:
                </label>
                <div id="renew_calculation_result">
                    <!-- Populated dynamically by JavaScript -->
                </div>
            </div>

        </div>
        <div class="modal-footer bg-light border-top px-4 py-3">
          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-semibold rounded-pill px-4 shadow-sm">
              <i class="fa-solid fa-check me-1"></i> <?= $is_manager ? 'Confirm Renewal' : 'Save Expiry &amp; Apply Ledger' ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const isManagerUser = <?= $is_manager ? 'true' : 'false' ?>;
const userCurBal = <?= (float)($user['balance'] ?? 0) ?>;
const userCurExpStr = <?= json_encode((!empty($user['expiry_date']) && $user['expiry_date'] !== '0000-00-00 00:00:00') ? $user['expiry_date'] : '') ?>;
const userCurPkgPrice = <?= (float)($user['pkg_price'] ?? 0) ?>;
const userCurPkgValidity = <?= (int)(($user['validity_days'] ?? 0) > 0 ? $user['validity_days'] : 30) ?>;

function recalculateRenewAdjustment() {
    const pkgSelect = document.getElementById('renew_package_select');
    const expiryInput = document.getElementById('renew_expiry_input');
    const resultBox = document.getElementById('renew_calculation_result');
    const payInput = document.getElementById('renew_payment_amount');
    if (!pkgSelect || !expiryInput || !resultBox) return;

    const opt = pkgSelect.options[pkgSelect.selectedIndex];
    const newPrice = opt ? parseFloat(opt.dataset.price || 0) : 0;
    const newValidity = opt ? parseInt(opt.dataset.days || 30) : 30;
    const newDaily = newPrice / (newValidity > 0 ? newValidity : 30);

    const now = Math.floor(Date.now() / 1000);
    let curExpTs = userCurExpStr ? Math.floor(new Date(userCurExpStr).getTime() / 1000) : 0;
    if (isNaN(curExpTs)) curExpTs = 0;

    // If manager, auto-calculate target expiry strictly from validity days
    if (isManagerUser) {
        const mgrDaysDisp = document.getElementById('mgr_pkg_days_display');
        const mgrExpDisp = document.getElementById('mgr_target_expiry_display');
        if (mgrDaysDisp) mgrDaysDisp.innerText = newValidity;

        const baseTs = (curExpTs > now) ? curExpTs : now;
        const targetTs = baseTs + (newValidity * 86400);
        const targetDate = new Date(targetTs * 1000);
        
        const y = targetDate.getFullYear();
        const m = String(targetDate.getMonth() + 1).padStart(2, '0');
        const d = String(targetDate.getDate()).padStart(2, '0');
        const h = String(targetDate.getHours()).padStart(2, '0');
        const min = String(targetDate.getMinutes()).padStart(2, '0');
        expiryInput.value = `${y}-${m}-${d}T${h}:${min}`;

        if (mgrExpDisp) {
            mgrExpDisp.innerText = targetDate.toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) + ', ' + targetDate.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit', hour12: true});
        }
    }

    let newExpTs = expiryInput.value ? Math.floor(new Date(expiryInput.value).getTime() / 1000) : 0;
    if (isNaN(newExpTs) || newExpTs <= 0) {
        resultBox.innerHTML = '<div class="alert alert-secondary py-2 px-3 small mb-0"><i class="fa-solid fa-circle-info me-1"></i> Please enter an Expiry Date &amp; Time.</div>';
        return;
    }

    // 1. Old remaining credit if currently active
    let oldCredit = 0;
    let curDaysLeft = 0;
    if (curExpTs > now) {
        curDaysLeft = (curExpTs - now) / 86400;
        const curDaily = userCurPkgPrice / (userCurPkgValidity > 0 ? userCurPkgValidity : 30);
        oldCredit = curDaysLeft * curDaily;
    }

    // 2. New period cost
    let newCost = 0;
    let newDaysAhead = 0;
    if (newExpTs > now) {
        newDaysAhead = (newExpTs - now) / 86400;
        newCost = newDaysAhead * newDaily;
    }

    // 3. Net adjustment: positive = charge (balance minus), negative = refund (balance plus)
    const net = Math.round((newCost - oldCredit) * 100) / 100;
    
    // Read payment amount
    let paymentVal = payInput ? parseFloat(payInput.value || 0) : 0;
    if (isNaN(paymentVal) || paymentVal < 0) paymentVal = 0;

    const balAfterDebit = Math.round((userCurBal - net) * 100) / 100;
    const projectedBal = Math.round((balAfterDebit + paymentVal) * 100) / 100;

    let html = '';
    const diffSecs = newExpTs - Math.max(now, curExpTs);
    const diffDays = Math.round((diffSecs / 86400) * 10) / 10;

    const cashBadge = isManagerUser ? (
        paymentVal > 0 
            ? `<div class="mt-2 pt-2 border-top d-flex align-items-center justify-content-between small text-success fw-bold"><span><i class="fa-solid fa-hand-holding-dollar me-1"></i> Manager Cash in Hand:</span><span>+ Rs. ${paymentVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></div>`
            : `<div class="mt-2 pt-2 border-top small text-muted"><i class="fa-solid fa-info-circle me-1"></i> No payment entered (Renewal on credit). Manager Cash in Hand unchanged.</div>`
    ) : '';

    if (net > 0) {
        html = `
            <div class="alert alert-warning border border-warning-subtle rounded-3 p-3 mb-0" style="background:#fffbeb;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-bold text-dark"><i class="fa-solid fa-arrow-up-right-dots text-danger me-1"></i> Date Extended (+${Math.abs(diffDays)} Days)</span>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 fw-bold fs-6">Charge: - Rs. ${net.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
                <div class="small text-muted mb-2">
                    Package: <strong>${opt ? opt.dataset.name : ''}</strong> (Rs. ${newPrice} / ${newValidity}d). 
                    ${oldCredit > 0 ? `Unused Credit: <strong>Rs. ${oldCredit.toFixed(2)}</strong>. ` : ''}
                    New Cost: <strong>Rs. ${newCost.toFixed(2)}</strong>.
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top small">
                    <span class="text-secondary">Cash Payment Received:</span>
                    <span class="fw-bold text-success fs-6">+ Rs. ${paymentVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-1 small">
                    <span class="text-secondary">Net Balance After Renewal:</span>
                    <span class="fw-bold text-dark">Rs. ${userCurBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} &rarr; <span class="${projectedBal < 0 ? 'text-danger' : 'text-success'} fw-bold">Rs. ${projectedBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></span>
                </div>
                ${cashBadge}
            </div>
        `;
    } else if (net < 0) {
        const absNet = Math.abs(net);
        const daysReduced = Math.round(((curExpTs - newExpTs) / 86400) * 10) / 10;
        html = `
            <div class="alert alert-success border border-success-subtle rounded-3 p-3 mb-0" style="background:#f0fdf4;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-bold text-dark"><i class="fa-solid fa-arrow-down-left-and-up-right-to-center text-success me-1"></i> Date Reduced (-${daysReduced} Days)</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fw-bold fs-6">+ Rs. ${absNet.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} (Refund)</span>
                </div>
                <div class="small text-muted mb-2">
                    Unused days refunded at package rate. 
                    Unused Credit: <strong>Rs. ${oldCredit.toFixed(2)}</strong>. 
                    New Cost: <strong>Rs. ${newCost.toFixed(2)}</strong>.
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top small">
                    <span class="text-secondary">Net Balance After Renewal:</span>
                    <span class="fw-bold text-dark">Rs. ${userCurBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} &rarr; <span class="${projectedBal < 0 ? 'text-danger' : 'text-success'} fw-bold">Rs. ${projectedBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></span>
                </div>
                ${cashBadge}
            </div>
        `;
    } else {
        html = `
            <div class="alert alert-secondary border rounded-3 p-3 mb-0 small text-secondary">
                <div class="d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-check text-success me-1"></i> Expiry date unchanged.</span>
                    <span class="fw-bold text-success">+ Rs. ${paymentVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} (Payment)</span>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 mt-2 border-top small">
                    <span class="text-secondary">Balance Impact:</span>
                    <span class="fw-bold text-dark">Rs. ${userCurBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})} &rarr; <span class="${projectedBal < 0 ? 'text-danger' : 'text-success'} fw-bold">Rs. ${projectedBal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></span>
                </div>
                ${cashBadge}
            </div>
        `;
    }

    resultBox.innerHTML = html;
    return;
}


function setRenewPreset(days) {
    const input = document.getElementById('renew_expiry_input');
    if (!input) return;

    let base = new Date();
    if (userCurExpStr) {
        const cd = new Date(userCurExpStr);
        if (cd > base) {
            base = cd;
        }
    }

    if (days === 0) {
        base = new Date();
    } else {
        base.setDate(base.getDate() + days);
    }

    const y = base.getFullYear();
    const m = String(base.getMonth() + 1).padStart(2, '0');
    const d = String(base.getDate()).padStart(2, '0');
    const h = String(base.getHours()).padStart(2, '0');
    const min = String(base.getMinutes()).padStart(2, '0');

    input.value = `${y}-${m}-${d}T${h}:${min}`;
    recalculateRenewAdjustment();
}

function resetToCurrentExpiry() {
    const input = document.getElementById('renew_expiry_input');
    if (!input) return;
    if (userCurExpStr && userCurExpStr !== '0000-00-00 00:00:00') {
        const d = new Date(userCurExpStr);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const h = String(d.getHours()).padStart(2, '0');
        const min = String(d.getMinutes()).padStart(2, '0');
        input.value = `${y}-${m}-${day}T${h}:${min}`;
    } else {
        input.value = '';
    }
    recalculateRenewAdjustment();
}

const userCurPkgId = <?= (int)($user['package_id'] ?? 0) ?>;

function recalculateEditProfileAdjustment() {
    const pkgSelect = document.getElementById('edit_package_select');
    const expiryInput = document.getElementById('edit_expiry_input');
    const resultBox = document.getElementById('edit_profile_calculation_result');
    if (!pkgSelect || !expiryInput || !resultBox) return;

    const opt = pkgSelect.options[pkgSelect.selectedIndex];
    const newPrice = opt ? parseFloat(opt.dataset.price || 0) : 0;
    const newValidity = opt ? parseInt(opt.dataset.days || 30) : 30;
    const newDaily = newPrice / (newValidity > 0 ? newValidity : 30);
    const selectedPkgId = opt ? parseInt(opt.value || 0) : 0;

    const now = Math.floor(Date.now() / 1000);
    let curExpTs = userCurExpStr ? Math.floor(new Date(userCurExpStr).getTime() / 1000) : 0;
    if (isNaN(curExpTs)) curExpTs = 0;

    let newExpTs = expiryInput.value ? Math.floor(new Date(expiryInput.value).getTime() / 1000) : 0;
    if (isNaN(newExpTs)) newExpTs = 0;

    const expChanged = (Math.abs(curExpTs - newExpTs) > 60);
    const pkgChanged = (selectedPkgId !== userCurPkgId);

    if (!expChanged && !pkgChanged) {
        resultBox.innerHTML = '<div class="alert alert-secondary border rounded-3 p-2 px-3 mb-0 small text-secondary"><i class="fa-solid fa-check text-success me-1"></i> Expiry date and package unchanged. Balance will not be affected.</div>';
        return;
    }

    // 1. Old remaining credit
    let oldCredit = 0;
    if (curExpTs > now) {
        const curDaysLeft = (curExpTs - now) / 86400;
        const curDaily = userCurPkgPrice / (userCurPkgValidity > 0 ? userCurPkgValidity : 30);
        oldCredit = curDaysLeft * curDaily;
    }

    // 2. New period cost
    let newCost = 0;
    if (newExpTs > now) {
        const newDaysAhead = (newExpTs - now) / 86400;
        newCost = newDaysAhead * newDaily;
    }

    // 3. Net adjustment: positive = charge (balance minus), negative = refund (balance plus)
    const net = Math.round((newCost - oldCredit) * 100) / 100;
    const projectedBal = Math.round((userCurBal - net) * 100) / 100;

    let html = '';
    if (net > 0) {
        const diffDays = Math.round(((newExpTs - Math.max(now, curExpTs)) / 86400) * 10) / 10;
        html = '<div class="alert alert-warning border border-warning-subtle rounded-3 p-2 px-3 mb-0" style="background:#fffbeb;">' +
            '<div class="d-flex align-items-center justify-content-between mb-1">' +
                '<span class="fw-bold text-dark small"><i class="fa-solid fa-arrow-up-right-dots text-danger me-1"></i> Expiry Extended (+' + Math.abs(diffDays) + ' Days)</span>' +
                '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 fw-bold fs-6">- Rs. ' + net.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span>' +
            '</div>' +
            '<div class="small text-muted mb-1" style="font-size: 0.78rem;">' +
                'Daily Rate: <strong>Rs. ' + newDaily.toFixed(2) + '/day</strong>. ' +
                (oldCredit > 0 ? ('Unused Credit: <strong>Rs. ' + oldCredit.toFixed(2) + '</strong>. ') : '') +
                'New Cost: <strong>Rs. ' + newCost.toFixed(2) + '</strong>.' +
            '</div>' +
            '<div class="d-flex justify-content-between align-items-center pt-1 border-top small" style="font-size: 0.8rem;">' +
                '<span class="text-secondary">Balance Impact (Deduction):</span>' +
                '<span class="fw-bold text-dark">Rs. ' + userCurBal.toFixed(2) + ' &rarr; <span class="' + (projectedBal < 0 ? 'text-danger' : 'text-success') + ' fw-bold">Rs. ' + projectedBal.toFixed(2) + '</span></span>' +
            '</div>' +
        '</div>';
    } else if (net < 0) {
        const absNet = Math.abs(net);
        const daysReduced = Math.round(((curExpTs - newExpTs) / 86400) * 10) / 10;
        html = '<div class="alert alert-success border border-success-subtle rounded-3 p-2 px-3 mb-0" style="background:#f0fdf4;">' +
            '<div class="d-flex align-items-center justify-content-between mb-1">' +
                '<span class="fw-bold text-dark small"><i class="fa-solid fa-arrow-down-left-and-up-right-to-center text-success me-1"></i> Expiry Reduced (-' + daysReduced + ' Days)</span>' +
                '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fw-bold fs-6">+ Rs. ' + absNet.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (Refund)</span>' +
            '</div>' +
            '<div class="small text-muted mb-1" style="font-size: 0.78rem;">' +
                'Unused days will be refunded and <strong>added to customer balance</strong>. ' +
                (oldCredit > 0 ? ('Unused Credit: <strong>Rs. ' + oldCredit.toFixed(2) + '</strong>. ') : '') +
                'New Cost: <strong>Rs. ' + newCost.toFixed(2) + '</strong>.' +
            '</div>' +
            '<div class="d-flex justify-content-between align-items-center pt-1 border-top small" style="font-size: 0.8rem;">' +
                '<span class="text-secondary">Balance Impact (Refund to Balance):</span>' +
                '<span class="fw-bold text-dark">Rs. ' + userCurBal.toFixed(2) + ' &rarr; <span class="' + (projectedBal < 0 ? 'text-danger' : 'text-success') + ' fw-bold">Rs. ' + projectedBal.toFixed(2) + '</span></span>' +
            '</div>' +
        '</div>';
    } else {
        html = '<div class="alert alert-secondary border rounded-3 p-2 px-3 mb-0 small text-secondary"><i class="fa-solid fa-check text-success me-1"></i> Balance unchanged.</div>';
    }

    resultBox.innerHTML = html;
}

// Auto calculate on modal open
document.addEventListener('DOMContentLoaded', function() {
    const renewModalEl = document.getElementById('renewModal');
    if (renewModalEl) {
        renewModalEl.addEventListener('shown.bs.modal', function () {
            recalculateRenewAdjustment();
        });
    }

    const editProfileModalEl = document.getElementById('editProfileModal');
    if (editProfileModalEl) {
        editProfileModalEl.addEventListener('shown.bs.modal', function () {
            recalculateEditProfileAdjustment();
        });
    }
});
</script>

<!-- Edit Profile Modal -->
<div class="modal fade" id="editProfileModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content light-modal">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-user-pen text-primary"></i> Edit Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="edit_profile">
        <div class="modal-body p-4">
            <div class="add-user-flat-form">
                <!-- Account Info -->
                <div class="mb-4">
                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-user"></i> Account Information</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Full Name</label>
                            <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">National ID (CNIC)</label>
                            <input type="text" name="national_id" class="form-control" value="<?= htmlspecialchars($user['national_id']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="password" id="editPassword" class="form-control" value="<?= htmlspecialchars($user['password']) ?>" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('editPassword').value = Math.random().toString(36).slice(-8);"><i class="fa-solid fa-shuffle"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Service Info -->
                <div class="mb-4">
                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-wifi"></i> Service & Package</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Service Type</label>
                            <select name="service_type" class="form-select">
                                <option value="pppoe" <?= $user['service_type']=='pppoe'?'selected':'' ?>>PPPoE</option>
                                <option value="hotspot" <?= $user['service_type']=='hotspot'?'selected':'' ?>>Hotspot</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Select Package <span class="text-danger">*</span></label>
                            <select name="package_id" id="edit_package_select" class="form-select" required onchange="recalculateEditProfileAdjustment()">
                                <option value="">Choose...</option>
                                <?php 
                                $pkgs = $pdo->query("SELECT * FROM packages WHERE client_id=$client_id")->fetchAll();
                                foreach($pkgs as $p): ?>
                                    <option value="<?= $p['id'] ?>" 
                                        data-price="<?= (float)$p['price'] ?>" 
                                        data-days="<?= (int)(($p['validity_days'] ?? 0) > 0 ? $p['validity_days'] : 30) ?>" 
                                        data-name="<?= htmlspecialchars($p['name']) ?>"
                                        <?= $p['id']==$user['package_id']?'selected':'' ?>>
                                        <?= htmlspecialchars($p['name']) ?> (Rs. <?= number_format($p['price']) ?> / <?= $p['validity_days'] ?: 30 ?>d) - <?= htmlspecialchars($p['rate_limit']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Assign Dealer (Optional)</label>
                            <select name="dealer_id" class="form-select">
                                <option value="">None</option>
                                <?php 
                                $dlrs = $pdo->query("SELECT * FROM dealers WHERE client_id=$client_id")->fetchAll();
                                foreach($dlrs as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $d['id']==$user['dealer_id']?'selected':'' ?>><?= htmlspecialchars($d['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if (!$is_manager): ?>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Custom Expiry</label>
                            <input type="datetime-local" name="expiry_date" id="edit_expiry_input" class="form-control" 
                                   value="<?= $user['expiry_date'] ? date('Y-m-d\TH:i', strtotime($user['expiry_date'])) : '' ?>"
                                   oninput="recalculateEditProfileAdjustment()">
                        </div>
                        <?php else: ?>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label text-muted">Package Expiry Date</label>
                            <input type="text" class="form-control bg-light text-muted fw-bold" value="<?= !empty($user['expiry_date']) ? date('d M Y, h:i A', strtotime($user['expiry_date'])) : 'N/A' ?>" readonly disabled>
                            <small class="text-muted" style="font-size: 11px;"><i class="fa-solid fa-lock text-secondary me-1"></i> Custom Expiry locked for Manager. Use "Renew" button.</small>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label text-muted"><i class="fa-solid fa-calendar-plus text-primary me-1"></i> Registration Date (Created)</label>
                            <input type="text" class="form-control bg-light" value="<?= !empty($user['created_at']) ? date('d M Y, h:i A', strtotime($user['created_at'])) : 'N/A' ?>" readonly disabled>
                        </div>
                        <?php if (!$is_manager): ?>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-semibold text-secondary small mb-1">
                                <i class="fa-solid fa-calculator text-primary me-1"></i> Financial Impact &amp; Balance Adjustment Preview:
                            </label>
                            <div id="edit_profile_calculation_result">
                                <!-- Populated dynamically by JavaScript -->
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Contact Info -->
                <div class="mb-4">
                    <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fa-solid fa-address-book"></i> Contact & Location</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Mobile</label>
                            <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($user['mobile']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="col-form-label">Area / City</label>
                            <input type="text" name="subarea" class="form-control" value="<?= htmlspecialchars($user['subarea']) ?>">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="col-form-label">Street Address</label>
                            <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($user['address']) ?>">
                        </div>

                        <div class="col-md-12 mb-3">
                            <label class="col-form-label text-secondary small fw-bold d-flex justify-content-between align-items-center">
                                <span><i class="fa-solid fa-map-location-dot text-primary me-1"></i> Customer Location (Map Pin & GPS)</span>
                                <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 text-muted" onclick="toggleEditManualCoords()" style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-keyboard me-1"></i> Manual Enter
                                </button>
                            </label>
                            
                            <!-- Action controls: GPS Button + Map Search Box + Satellite/Map toggle -->
                            <div class="row g-2 mb-2">
                                <div class="col-md-5">
                                    <button type="button" class="btn btn-outline-primary btn-sm fw-bold w-100 py-2 shadow-sm" id="btn_edit_capture_location" onclick="captureEditUserLocation()">
                                        <i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)
                                    </button>
                                </div>
                                <div class="col-md-4">
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" id="edit_map_search_input" placeholder="Search area, colony..." onkeydown="if(event.key==='Enter'){event.preventDefault();searchEditLocationOnMap();}">
                                        <button class="btn btn-primary" type="button" id="btn_edit_map_search" onclick="searchEditLocationOnMap()" title="Search Area on Map">
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

                            <!-- Interactive Leaflet Map Container -->
                            <div class="position-relative rounded border overflow-hidden shadow-sm" style="height: 280px;">
                                <div id="edit_user_map_picker" style="height: 100%; width: 100%; z-index: 1;"></div>
                                <div class="position-absolute bottom-0 start-0 m-2 px-2 py-1 rounded bg-dark bg-opacity-75 text-white small" style="z-index: 1000; font-size: 0.75rem; pointer-events: none;">
                                    <i class="fa-solid fa-hand-pointer text-warning me-1"></i> Map par click karein ya pin ko drag kar ke customer ki location par set karein
                                </div>
                            </div>

                            <!-- Actual inputs sent to DB -->
                            <?php 
                                $user_lat = !empty($user['latitude']) ? $user['latitude'] : (!empty($user['gps_lat']) ? $user['gps_lat'] : '');
                                $user_lng = !empty($user['longitude']) ? $user['longitude'] : (!empty($user['gps_lng']) ? $user['gps_lng'] : '');
                            ?>
                            <input type="hidden" name="latitude" id="edit_user_latitude" value="<?= htmlspecialchars($user_lat) ?>">
                            <input type="hidden" name="longitude" id="edit_user_longitude" value="<?= htmlspecialchars($user_lng) ?>">
                            <input type="hidden" name="gps_lat" id="edit_user_gps_lat" value="<?= htmlspecialchars($user_lat) ?>">
                            <input type="hidden" name="gps_lng" id="edit_user_gps_lng" value="<?= htmlspecialchars($user_lng) ?>">

                            <!-- Live Pinned / Captured Status Card -->
                            <div id="edit_location_status_box" class="mt-2">
                                <div class="d-flex align-items-center justify-content-between p-2 rounded bg-light border small">
                                    <div>
                                        <span class="text-secondary fw-semibold"><i class="fa-solid fa-location-dot text-danger me-1"></i> Pinned Coordinates:</span>
                                        <span class="font-monospace text-dark fw-bold ms-1" id="edit_pinned_coords_text">
                                            <?= ($user_lat && $user_lng) ? htmlspecialchars($user_lat . ', ' . $user_lng) : 'Not set (Click map or drag pin)' ?>
                                        </span>
                                    </div>
                                    <div id="edit_location_accuracy_badge">
                                        <?php if ($user_lat && $user_lng): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-map-pin me-1"></i> Pinned on Map</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Draggable Pin</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Collapsible Manual Inputs -->
                            <div id="edit_manual_coords_container" class="row mt-2 d-none">
                                <div class="col-6">
                                    <label class="small text-muted fw-bold">Latitude</label>
                                    <input type="text" class="form-control form-control-sm font-monospace" id="edit_manual_lat" value="<?= htmlspecialchars($user_lat) ?>" placeholder="e.g. 31.520371" oninput="syncEditManualCoords()">
                                </div>
                                <div class="col-6">
                                    <label class="small text-muted fw-bold">Longitude</label>
                                    <input type="text" class="form-control form-control-sm font-monospace" id="edit_manual_lng" value="<?= htmlspecialchars($user_lng) ?>" placeholder="e.g. 74.358747" oninput="syncEditManualCoords()">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Note Modal -->
<div class="modal fade" id="noteModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-regular fa-note-sticky"></i> Add Note</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="add_note">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Note</label>
                <textarea name="notes" class="form-control" rows="4"><?= htmlspecialchars($user['notes']) ?></textarea>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Save Note</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-key"></i> Change Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="text" name="new_password" class="form-control" required>
            </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Update Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- jQuery & DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Leaflet Map JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<!-- DataTables Buttons JS -->
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<script>
$(document).ready(function() {
    // Custom filtering function for date range
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex, rowData, counter) {
        let min = $('#minLedgerDate').val();
        let max = $('#maxLedgerDate').val();
        // Get the date string from the data-order attribute
        let dateStr = settings.aoData[dataIndex].anCells[0].getAttribute('data-order');
        if (!dateStr) return true;
        
        let rowDate = new Date(dateStr.split(' ')[0]); // Get Y-m-d part
        let minDate = min ? new Date(min) : null;
        let maxDate = max ? new Date(max) : null;

        if (
            (minDate === null && maxDate === null) ||
            (minDate === null && rowDate <= maxDate) ||
            (minDate <= rowDate && maxDate === null) ||
            (minDate <= rowDate && rowDate <= maxDate)
        ) {
            return true;
        }
        return false;
    });

    var table = $('#ledgerTable').DataTable({
        dom: '<"row"<"col-sm-12 col-md-6"B><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        buttons: [
            { extend: 'copy', className: 'btn btn-light border btn-sm text-secondary', title: 'Ledger Details' },
            { extend: 'csv', className: 'btn btn-light border btn-sm text-secondary', title: 'Ledger Details' },
            { extend: 'excel', className: 'btn btn-light border btn-sm text-secondary', title: 'Ledger Details' },
            { extend: 'pdf', className: 'btn btn-light border btn-sm text-secondary', title: 'Ledger Details' },
            { extend: 'print', className: 'btn btn-light border btn-sm text-secondary', title: 'Ledger Details' }
        ],
        pageLength: 10,
        order: [[0, 'desc']]
    });

    // Refilter the table on input change
    $('#minLedgerDate, #maxLedgerDate').on('change', function() {
        table.draw();
    });
});
const ctxLedger = document.getElementById('ledgerChart').getContext('2d');
new Chart(ctxLedger, {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        datasets: [
            { label: 'Funds Added', data: <?= $payment_data_json ?>, borderColor: '#10b981', tension: 0.1, fill: false },
            { label: 'Usage / Deductions', data: <?= $deducted_data_json ?>, borderColor: '#ef4444', tension: 0.1, fill: false }
        ]
    },
    options: { responsive: true, plugins: { legend: { position: 'top', labels: { boxWidth: 12 } } }, scales: { y: { beginAtZero: true } } }
});

const ctxBw = document.getElementById('bwChart').getContext('2d');

// Dynamic Gradient Fills
const downGrad = ctxBw.createLinearGradient(0, 0, 0, 160);
downGrad.addColorStop(0, 'rgba(6, 182, 212, 0.35)');
downGrad.addColorStop(1, 'rgba(6, 182, 212, 0.00)');

const upGrad = ctxBw.createLinearGradient(0, 0, 0, 160);
upGrad.addColorStop(0, 'rgba(168, 85, 247, 0.30)');
upGrad.addColorStop(1, 'rgba(168, 85, 247, 0.00)');

const bwChart = new Chart(ctxBw, {
    type: 'line',
    data: {
        labels: ['--', '--', '--', '--', '--', '--', '--', '--', '--', '--', '--', '--'],
        datasets: [
            { 
                label: 'Download', 
                data: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 
                borderColor: '#06b6d4', 
                backgroundColor: downGrad,
                borderWidth: 2.5,
                tension: 0.4, 
                fill: true,
                pointRadius: 0,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#ffffff',
                pointHoverBorderColor: '#06b6d4',
                pointHoverBorderWidth: 2.5
            },
            { 
                label: 'Upload', 
                data: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], 
                borderColor: '#a855f7', 
                backgroundColor: upGrad,
                borderWidth: 2.5,
                tension: 0.4, 
                fill: true,
                pointRadius: 0,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#ffffff',
                pointHoverBorderColor: '#a855f7',
                pointHoverBorderWidth: 2.5
            }
        ]
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false,
        plugins: { 
            legend: { 
                position: 'top', 
                align: 'end',
                labels: { 
                    boxWidth: 8, 
                    boxHeight: 8,
                    usePointStyle: true,
                    pointStyle: 'circle',
                    font: { size: 11, weight: 'bold' },
                    padding: 8
                } 
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                backgroundColor: 'rgba(15, 23, 42, 0.92)',
                titleFont: { size: 11 },
                bodyFont: { size: 12, weight: 'bold' },
                padding: 10,
                cornerRadius: 8,
                callbacks: {
                    label: function(ctx) {
                        return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' Mbps';
                    }
                }
            }
        }, 
        scales: { 
            x: {
                grid: { display: false },
                ticks: { font: { size: 9 }, maxRotation: 0 }
            },
            y: { 
                beginAtZero: true,
                suggestedMax: 2,
                grid: {
                    color: 'rgba(0, 0, 0, 0.04)',
                    borderDash: [4, 4]
                },
                ticks: { 
                    font: { size: 10 },
                    callback: function(v) { return v + ' M'; } 
                }
            } 
        }, 
        animation: false 
    }
});

let prevBytesIn = null;
let prevBytesOut = null;
let lastTime = null;
const pastBytes = <?= (int)$past_bytes ?>;
let peakDown = 0;
let peakUp = 0;
let isFetchingBw = false;
let bwPollTimer = null;

function splitSpeed(mbps) {
    if (isNaN(mbps) || mbps <= 0) return { val: '0.00', unit: 'Mbps' };
    if (mbps < 0.1) {
        return { val: (mbps * 1024).toFixed(1), unit: 'Kbps' };
    }
    return { val: mbps.toFixed(2), unit: 'Mbps' };
}

function fetchLiveBandwidth() {
    if (isFetchingBw) return;
    isFetchingBw = true;

    fetch(`api_bandwidth.php?username=<?= urlencode($user['username']) ?>&sub_id=<?= (int)$user['id'] ?>&_=${Date.now()}`, {
        cache: 'no-store'
    })
        .then(res => res.json())
        .then(data => {
            if (data.bytes_in !== undefined && data.bytes_out !== undefined) {
                if (data.uptime !== undefined && data.uptime !== null && data.uptime !== '') {
                    let uEl = document.getElementById('live_uptime');
                    if (uEl) uEl.innerText = data.uptime;
                    let svcU = document.getElementById('svc_uptime_display');
                    if (svcU) svcU.innerText = data.uptime;
                }
                
                // Live status pill & diagnostic alert
                let sBadge = document.getElementById('live_status_badge');
                let alertBox = document.getElementById('bw_router_alert');
                
                if (sBadge) {
                    if (data.status === 'online') {
                        if (data.source === 'mikrotik') {
                            sBadge.className = 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-1.5 rounded-pill fs-7 d-inline-flex align-items-center gap-1.5';
                            sBadge.innerHTML = '<span class="spinner-grow spinner-grow-sm text-success" style="width:7px; height:7px;" role="status"></span> Active (Live MikroTik)';
                            if (alertBox) alertBox.className = 'd-none';
                        } else {
                            sBadge.className = 'badge bg-warning bg-opacity-15 text-dark border border-warning border-opacity-50 px-3 py-1.5 rounded-pill fs-7 d-inline-flex align-items-center gap-1.5';
                            sBadge.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> RADIUS Accounting (API Offline)';
                            if (alertBox) {
                                let errMsg = '';
                                if (data.diag && data.diag.attempts && data.diag.attempts.length > 0) {
                                    errMsg = data.diag.attempts.map(a => {
                                        if (!a.sock_ok) return `${a.ip}:${a.port} (Port unreachable/offline)`;
                                        if (!a.login_ok) return `${a.ip}:${a.port} (API Login failed: check user/pass)`;
                                        if (!a.user_found) return `${a.ip} (Connected, but subscriber not active on this router)`;
                                        return `${a.ip} (Connected)`;
                                    }).join(', ');
                                } else {
                                    errMsg = `No reachable router IP found. Ensure router public IP or VPN IP is in NAS table.`;
                                }
                                alertBox.className = 'alert alert-warning py-1 px-2.5 mb-2 rounded-2 d-flex align-items-center gap-2 small text-dark';
                                alertBox.style.fontSize = '0.75rem';
                                alertBox.innerHTML = `<i class="fa-solid fa-triangle-exclamation text-warning flex-shrink-0"></i> <span><strong>Router Status:</strong> ${errMsg}</span>`;
                            }
                        }
                    } else {
                        sBadge.className = 'badge bg-secondary bg-opacity-10 text-secondary border px-3 py-1.5 rounded-pill fs-7 d-inline-flex align-items-center gap-1.5';
                        sBadge.innerHTML = '<i class="fa-solid fa-circle text-muted me-1" style="font-size:0.45rem;"></i> Offline';
                        if (alertBox) alertBox.className = 'd-none';
                    }
                }

                // Update Used Volume LIVE
                let currentLiveBytes = parseFloat(data.bytes_in) + parseFloat(data.bytes_out);
                let totalCurrentBytes = pastBytes + currentLiveBytes;
                
                let volStr = "0 MB";
                if (totalCurrentBytes >= 1073741824) {
                    volStr = (totalCurrentBytes / 1073741824).toFixed(2) + " GB";
                } else if (totalCurrentBytes >= 1048576) {
                    volStr = (totalCurrentBytes / 1048576).toFixed(2) + " MB";
                } else if (totalCurrentBytes > 0) {
                    volStr = (totalCurrentBytes / 1024).toFixed(2) + " KB";
                }
                
                let usedElem = document.getElementById('live_used_volume');
                if (usedElem) usedElem.innerText = volStr;
                let svcU = document.getElementById('svc_used_volume');
                if (svcU) svcU.innerText = volStr;
                
                let svcR = document.getElementById('svc_rem_volume');
                const dataLimitGb = <?= (float)($user['data_limit_gb'] ?? 0) ?>;
                if (svcR && dataLimitGb > 0) {
                    let remGb = dataLimitGb - (totalCurrentBytes / 1073741824);
                    svcR.innerText = remGb.toFixed(2) + " GB";
                }

                let nowTime = Date.now();
                let currentBytesIn = parseFloat(data.bytes_in) || 0;
                let currentBytesOut = parseFloat(data.bytes_out) || 0;
                
                let tx_mbps = 0; // Download (transmitted by router to user)
                let rx_mbps = 0; // Upload (received by router from user)

                if (data.rx_bps !== undefined && data.tx_bps !== undefined && (parseFloat(data.rx_bps) > 0 || parseFloat(data.tx_bps) > 0)) {
                    // Direct bps from MikroTik Simple Queue or Monitor-Traffic
                    rx_mbps = parseFloat(data.rx_bps) / 1048576;
                    tx_mbps = parseFloat(data.tx_bps) / 1048576;
                } else if (data.source === 'mikrotik' && prevBytesIn !== null && prevBytesOut !== null && lastTime !== null) {
                    let timeDiffSecs = (nowTime - lastTime) / 1000;
                    if (timeDiffSecs > 0.5) {
                        let bytesInDiff = currentBytesIn - prevBytesIn;
                        let bytesOutDiff = currentBytesOut - prevBytesOut;
                        if (bytesInDiff < 0) bytesInDiff = 0;
                        if (bytesOutDiff < 0) bytesOutDiff = 0;
                        rx_mbps = (bytesInDiff * 8 / timeDiffSecs) / 1048576;
                        tx_mbps = (bytesOutDiff * 8 / timeDiffSecs) / 1048576;
                    }
                } else {
                    // If on RADIUS fallback, do NOT compute fake spikes from cumulative session bytes
                    rx_mbps = 0;
                    tx_mbps = 0;
                }

                if (isNaN(tx_mbps) || tx_mbps < 0) tx_mbps = 0;
                if (isNaN(rx_mbps) || rx_mbps < 0) rx_mbps = 0;

                prevBytesIn = currentBytesIn;
                prevBytesOut = currentBytesOut;
                lastTime = nowTime;

                // Track Peak Speed
                if (tx_mbps > peakDown) {
                    peakDown = tx_mbps;
                    let sp = splitSpeed(peakDown);
                    let pEl = document.getElementById('peak_down_badge');
                    if (pEl) pEl.innerText = 'Peak: ' + sp.val + ' ' + sp.unit;
                }
                if (rx_mbps > peakUp) {
                    peakUp = rx_mbps;
                    let sp = splitSpeed(peakUp);
                    let pEl = document.getElementById('peak_up_badge');
                    if (pEl) pEl.innerText = 'Peak: ' + sp.val + ' ' + sp.unit;
                }

                // Update live speed numbers
                let spDown = splitSpeed(tx_mbps);
                let spUp = splitSpeed(rx_mbps);
                let dVal = document.getElementById('live_down_val');
                let dUnit = document.getElementById('live_down_unit');
                if (dVal) dVal.innerText = spDown.val;
                if (dUnit) dUnit.innerText = spDown.unit;

                let uVal = document.getElementById('live_up_val');
                let uUnit = document.getElementById('live_up_unit');
                if (uVal) uVal.innerText = spUp.val;
                if (uUnit) uUnit.innerText = spUp.unit;

                // Shift old data and push new sample
                bwChart.data.labels.shift();
                bwChart.data.datasets[0].data.shift();
                bwChart.data.datasets[1].data.shift();
                
                let now = new Date();
                let timeStr = String(now.getHours()).padStart(2, '0') + ':' + 
                              String(now.getMinutes()).padStart(2, '0') + ':' + 
                              String(now.getSeconds()).padStart(2, '0');
                bwChart.data.labels.push(timeStr);
                
                bwChart.data.datasets[0].data.push(parseFloat(tx_mbps.toFixed(2)));
                bwChart.data.datasets[1].data.push(parseFloat(rx_mbps.toFixed(2)));
                
                bwChart.update();
            }
        })
        .catch(err => console.error("Error fetching bandwidth:", err))
        .finally(() => {
            isFetchingBw = false;
            bwPollTimer = setTimeout(fetchLiveBandwidth, 3000);
        });
}

// Initial poll start
fetchLiveBandwidth();

// --- Edit Profile GPS & Interactive Leaflet Map Logic ---
let editGeoWatchId = null;
let editBestPosition = null;
let editUserMap = null;
let editUserMarker = null;
let editHybridLayer = null;
let editStreetsLayer = null;
const defaultMapLat = 31.5204;
const defaultMapLng = 74.3587; // Central Pakistan default

function initEditUserMapPicker() {
    if (editUserMap !== null) {
        setTimeout(function() {
            if (editUserMap) editUserMap.invalidateSize();
        }, 150);
        return;
    }
    
    var container = document.getElementById('edit_user_map_picker');
    if (!container) return;
    
    var curLat = parseFloat(document.getElementById('edit_user_latitude').value);
    var curLng = parseFloat(document.getElementById('edit_user_longitude').value);
    var hasExisting = !isNaN(curLat) && curLat !== 0 && !isNaN(curLng) && curLng !== 0;
    var startLat = hasExisting ? curLat : defaultMapLat;
    var startLng = hasExisting ? curLng : defaultMapLng;

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

    var editEsri = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri'
    });

    var editOsm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    });

    var editBaseMaps = {
        "🛰️ Google Satellite (Hybrid)": editHybridLayer,
        "🗺️ Google Streets": editStreetsLayer,
        "🌍 Esri Satellite": editEsri,
        "📍 OpenStreetMap": editOsm
    };

    editUserMap = L.map('edit_user_map_picker', {
        center: [startLat, startLng],
        zoom: hasExisting ? 18 : 14,
        maxZoom: 22,
        zoomControl: true,
        layers: [editHybridLayer]
    });

    L.control.layers(editBaseMaps, null, { position: 'topright' }).addTo(editUserMap);

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

    editUserMarker.bindTooltip("Drag me or click map", { permanent: false, direction: 'top' });

    if (hasExisting) {
        updateEditCoordsFromMap(startLat, startLng, false);
    }

    editUserMarker.on('dragend', function () {
        var pos = editUserMarker.getLatLng();
        updateEditCoordsFromMap(pos.lat, pos.lng);
    });

    editUserMap.on('click', function (e) {
        editUserMarker.setLatLng(e.latlng);
        updateEditCoordsFromMap(e.latlng.lat, e.latlng.lng);
    });

    setTimeout(function() {
        if (editUserMap) editUserMap.invalidateSize();
    }, 200);
}

function switchEditMapLayer(type) {
    if (!editUserMap || !editHybridLayer || !editStreetsLayer) return;
    var btnHybrid = document.getElementById('btnEditMapHybrid');
    var btnStreets = document.getElementById('btnEditMapStreets');
    if (type === 'hybrid') {
        editUserMap.removeLayer(editStreetsLayer);
        editUserMap.addLayer(editHybridLayer);
        if (btnHybrid) { btnHybrid.className = 'btn btn-primary btn-sm fw-bold'; }
        if (btnStreets) { btnStreets.className = 'btn btn-outline-secondary btn-sm fw-bold'; }
    } else if (type === 'streets') {
        editUserMap.removeLayer(editHybridLayer);
        editUserMap.addLayer(editStreetsLayer);
        if (btnStreets) { btnStreets.className = 'btn btn-primary btn-sm fw-bold'; }
        if (btnHybrid) { btnHybrid.className = 'btn btn-outline-secondary btn-sm fw-bold'; }
    }
}

function updateEditCoordsFromMap(lat, lng, panTo) {
    var fixedLat = parseFloat(lat).toFixed(7);
    var fixedLng = parseFloat(lng).toFixed(7);
    
    document.getElementById('edit_user_latitude').value = fixedLat;
    document.getElementById('edit_user_longitude').value = fixedLng;
    var latGps = document.getElementById('edit_user_gps_lat');
    var lngGps = document.getElementById('edit_user_gps_lng');
    if (latGps) latGps.value = fixedLat;
    if (lngGps) lngGps.value = fixedLng;
    
    var manLat = document.getElementById('edit_manual_lat');
    var manLng = document.getElementById('edit_manual_lng');
    if (manLat) manLat.value = fixedLat;
    if (manLng) manLng.value = fixedLng;
    
    var textEl = document.getElementById('edit_pinned_coords_text');
    if (textEl) {
        textEl.innerHTML = `<span class="text-primary">${fixedLat}</span>, <span class="text-primary">${fixedLng}</span>`;
    }
    
    var badgeEl = document.getElementById('edit_location_accuracy_badge');
    if (badgeEl) {
        badgeEl.innerHTML = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="fa-solid fa-map-pin me-1"></i> Pinned on Map</span>';
    }
    
    if (panTo && editUserMap) {
        editUserMap.panTo([fixedLat, fixedLng]);
    }
}

function searchEditLocationOnMap() {
    var query = document.getElementById('edit_map_search_input').value.trim();
    if (!query) return;
    var btn = document.getElementById('btn_edit_map_search');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    
    fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=1`)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i>';
            if (data && data.length > 0) {
                var lat = parseFloat(data[0].lat);
                var lon = parseFloat(data[0].lon);
                if (editUserMap && editUserMarker) {
                    editUserMap.setView([lat, lon], 17);
                    editUserMarker.setLatLng([lat, lon]);
                }
                updateEditCoordsFromMap(lat, lon);
            } else {
                alert('Area/location not found. Please try another search term or drag the map manually.');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i>';
        });
}

function toggleEditManualCoords() {
    var cont = document.getElementById('edit_manual_coords_container');
    if (!cont) return;
    if (cont.classList.contains('d-none')) {
        cont.classList.remove('d-none');
        document.getElementById('edit_manual_lat').value = document.getElementById('edit_user_latitude').value;
        document.getElementById('edit_manual_lng').value = document.getElementById('edit_user_longitude').value;
    } else {
        cont.classList.add('d-none');
    }
}

function syncEditManualCoords() {
    var lat = parseFloat(document.getElementById('edit_manual_lat').value.trim());
    var lng = parseFloat(document.getElementById('edit_manual_lng').value.trim());
    
    if (!isNaN(lat) && !isNaN(lng)) {
        if (editUserMap && editUserMarker) {
            editUserMarker.setLatLng([lat, lng]);
            editUserMap.setView([lat, lng], 18);
        }
        updateEditCoordsFromMap(lat, lng);
    }
}

function captureEditUserLocation() {
    if (!navigator.geolocation) {
        alert("Geolocation is not supported by your browser/device.");
        return;
    }
    
    const btn = document.getElementById('btn_edit_capture_location');
    const statusEl = document.getElementById('edit_location_accuracy_badge');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-1"></i> Locking GPS (&le;10m)...';
    if (statusEl) {
        statusEl.innerHTML = '<span class="badge bg-info-subtle text-info border border-info-subtle"><i class="fa-solid fa-satellite-dish fa-spin me-1"></i> Locking GPS...</span>';
    }
    
    if (editGeoWatchId !== null) {
        navigator.geolocation.clearWatch(editGeoWatchId);
        editGeoWatchId = null;
    }
    editBestPosition = null;

    const finishCapture = (pos) => {
        if (editGeoWatchId !== null) {
            navigator.geolocation.clearWatch(editGeoWatchId);
            editGeoWatchId = null;
        }
        btn.disabled = false;
        btn.className = 'btn btn-outline-success btn-sm fw-bold w-100 py-2 shadow-sm';
        btn.innerHTML = '<i class="fa-solid fa-arrows-rotate me-1"></i> Re-capture GPS';
        
        const lat = pos.coords.latitude.toFixed(7);
        const lng = pos.coords.longitude.toFixed(7);
        const acc = Math.round(pos.coords.accuracy);
        
        if (editUserMap && editUserMarker) {
            editUserMap.setView([lat, lng], 18);
            editUserMarker.setLatLng([lat, lng]);
        }
        
        updateEditCoordsFromMap(lat, lng);
        
        let accBadge = acc <= 10 
            ? `<span class="badge bg-success text-white px-2 py-1"><i class="fa-solid fa-bullseye me-1"></i> High GPS: ±${acc}m (&le;10m)</span>`
            : `<span class="badge bg-warning text-dark px-2 py-1"><i class="fa-solid fa-satellite me-1"></i> GPS: ±${acc}m</span>`;
            
        if (statusEl) statusEl.innerHTML = accBadge;
    };

    const timeoutTimer = setTimeout(() => {
        if (editBestPosition) {
            finishCapture(editBestPosition);
        } else {
            if (editGeoWatchId !== null) {
                navigator.geolocation.clearWatch(editGeoWatchId);
                editGeoWatchId = null;
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)';
            if (statusEl) statusEl.innerHTML = '<span class="badge bg-warning text-dark">GPS Timeout</span>';
            alert("GPS request timed out. Make sure Location/GPS is turned ON, or use the interactive map to pin the location!");
        }
    }, 12000);

    try {
        editGeoWatchId = navigator.geolocation.watchPosition(
            (pos) => {
                const acc = pos.coords.accuracy;
                if (!editBestPosition || acc < editBestPosition.coords.accuracy) {
                    editBestPosition = pos;
                }
                
                if (statusEl) {
                    statusEl.innerHTML = `<span class="badge bg-info-subtle text-info">GPS reading: ±${Math.round(acc)}m</span>`;
                }
                
                if (acc <= 10) {
                    clearTimeout(timeoutTimer);
                    finishCapture(pos);
                }
            },
            (err) => {
                clearTimeout(timeoutTimer);
                if (editGeoWatchId !== null) {
                    navigator.geolocation.clearWatch(editGeoWatchId);
                    editGeoWatchId = null;
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)';
                if (statusEl) statusEl.innerHTML = '<span class="badge bg-danger-subtle text-danger">Location Denied</span>';
                alert("Location access denied or unavailable. You can click on the map directly or enter coordinates manually.");
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
    } catch(e) {
        clearTimeout(timeoutTimer);
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)';
        alert("Geolocation error: " + e.message);
    }
}

// Initialize map whenever editProfileModal is opened
$('#editProfileModal').on('shown.bs.modal', function () {
    initEditUserMapPicker();
});
</script>


<!-- View Photo Modal -->
<div class="modal fade" id="photoViewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-transparent border-0">
            <div class="modal-header border-0 pb-0 justify-content-end">
                <button type="button" class="btn-close bg-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center pt-0">
                <?php if (!empty($user['photo'])): ?>
                    <img src="../uploads/profiles/<?= htmlspecialchars($user['photo']) ?>" class="img-fluid rounded shadow-lg" oncontextmenu="return false;" style="max-height: 80vh; pointer-events: none; border: 4px solid white;" alt="Profile View">
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
