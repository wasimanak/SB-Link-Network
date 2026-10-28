<?php
require_once 'header.php';
require_once __DIR__ . '/../config/radius_kick_helper.php';

// Check Permissions
$can_create = (bool)$current_dealer['perm_create_user'];
$can_delete = (bool)$current_dealer['perm_delete_user'];
$can_custom_expiry = (bool)$current_dealer['perm_custom_expiry'];

// Helper to get dealer price for a package
function getDealerPkgPrice($pdo, $dealer_id, $pkg_id) {
    $dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
    $dpStmt->execute([$dealer_id, $pkg_id]);
    $price = $dpStmt->fetchColumn();
    if ($price === false || $price === null) {
        $pStmt = $pdo->prepare("SELECT price FROM packages WHERE id = ?");
        $pStmt->execute([$pkg_id]);
        $price = $pStmt->fetchColumn() ?: 0;
    }
    return (float)$price;
}

// Handle Actions (Add, Renew, Upgrade, Edit, Toggle, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // DELETE USER
    if ($action === 'delete_id' && isset($_POST['id']) && $can_delete) {
        $del = (int)$_POST['id'];
        $uStmt = $pdo->prepare("SELECT username FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$del, $dealer_id]);
        $u = $uStmt->fetchColumn();
        if ($u) {
            $pdo->prepare("DELETE FROM subscribers WHERE id = ?")->execute([$del]);
            $pdo->prepare("DELETE FROM radcheck WHERE username = ?")->execute([$u]);
            $pdo->prepare("DELETE FROM radreply WHERE username = ?")->execute([$u]);
            $pdo->prepare("DELETE FROM radusergroup WHERE username = ?")->execute([$u]);
            $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Deleted User')")
                ->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);
            kick_subscriber_session($pdo, $u, $client_id);
            echo "<script>alert('User deleted successfully!'); window.location='users.php';</script>";
            exit;
        }
    }

    // EDIT USER (Full Profile + Map Location + Optional Package & Expiry Adjustment)
    if ($action === 'edit_user') {
        $id = (int)$_POST['id'];
        $full_name = trim($_POST['full_name']);
        $password = trim($_POST['password']);
        $national_id = trim($_POST['national_id'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $subarea = trim($_POST['subarea'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $latitude = trim($_POST['latitude'] ?? '');
        $longitude = trim($_POST['longitude'] ?? '');
        $new_package_id = (int)($_POST['package_id'] ?? 0);
        $new_expiry = $_POST['expiry_date'] ?? null;

        $uStmt = $pdo->prepare("SELECT username, package_id, expiry_date as old_expiry FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$id, $dealer_id]);
        $subData = $uStmt->fetch(PDO::FETCH_ASSOC);

        if ($subData) {
            $u = $subData['username'];
            $old_package_id = (int)$subData['package_id'];
            if ($new_package_id === 0) {
                $new_package_id = $old_package_id;
            }

            try {
                $pdo->beginTransaction();
                
                $final_expiry = $subData['old_expiry'];
                $package_changed = ($new_package_id !== $old_package_id);
                $expiry_changed = (!empty($new_expiry) && $new_expiry !== $subData['old_expiry']);
                
                if ($package_changed || $expiry_changed) {
                    if ($expiry_changed) {
                        $final_expiry = date('Y-m-d H:i:s', strtotime($new_expiry));
                    }
                    
                    $old_dealerPrice = getDealerPkgPrice($pdo, $dealer_id, $old_package_id);
                    $new_dealerPrice = getDealerPkgPrice($pdo, $dealer_id, $new_package_id);

                    // Calculate old value remaining
                    $old_remaining_value = 0;
                    if (!empty($subData['old_expiry']) && strtotime($subData['old_expiry']) > time()) {
                        $old_seconds = strtotime($subData['old_expiry']) - time();
                        $old_days = $old_seconds / 86400;
                        $old_remaining_value = $old_days * ($old_dealerPrice / 30);
                    }

                    // Calculate new value
                    $new_total_value = 0;
                    $new_seconds = strtotime($final_expiry) - time();
                    if ($new_seconds > 0) {
                        $new_days = $new_seconds / 86400;
                        $new_total_value = $new_days * ($new_dealerPrice / 30);
                    }

                    $net_deduction = round($new_total_value - $old_remaining_value, 2);

                    if ($net_deduction > $current_dealer['balance']) {
                        $pdo->rollBack();
                        echo "<script>alert('Insufficient balance! Need Rs. $net_deduction'); window.location='users.php';</script>";
                        exit;
                    }

                    if ($net_deduction > 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $dealer_id]);
                        $note = "Package/Expiry edited for user $u. Deducted: Rs. $net_deduction";
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                    } elseif ($net_deduction < 0) {
                        $refund = abs($net_deduction);
                        $pdo->prepare("UPDATE dealers SET balance = balance + ? WHERE id = ?")->execute([$refund, $dealer_id]);
                        $note = "Package/Expiry edited for user $u. Refunded: Rs. $refund";
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                    }
                }

                $status = (!empty($final_expiry) && strtotime($final_expiry) > time()) ? 'active' : 'expired';

                $pdo->prepare("UPDATE subscribers SET full_name = ?, password = ?, national_id = ?, mobile = ?, phone = ?, email = ?, subarea = ?, address = ?, latitude = ?, longitude = ?, package_id = ?, expiry_date = ?, status = ? WHERE id = ?")
                    ->execute([$full_name, $password, $national_id, $mobile, $phone, $email, $subarea, $address, $latitude, $longitude, $new_package_id, $final_expiry, $status, $id]);
                
                // Update Password in FreeRADIUS
                $pdo->prepare("UPDATE radcheck SET value = ? WHERE username = ? AND attribute = 'Cleartext-Password'")->execute([$password, $u]);

                // Update Mikrotik-Rate-Limit if package changed
                if ($package_changed) {
                    $pStmt = $pdo->prepare("SELECT rate_limit FROM packages WHERE id = ?");
                    $pStmt->execute([$new_package_id]);
                    $rate_limit = $pStmt->fetchColumn();
                    
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    if (!empty($rate_limit) && $rate_limit !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $rate_limit]);
                    }
                    kick_subscriber_session($pdo, $u, $client_id);
                }

                // Expiry in FreeRADIUS
                if ($final_expiry) {
                    $formatted_expiry = date('d M Y H:i:s', strtotime($final_expiry));
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Expiration'")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
                    
                    if ($status === 'expired') {
                        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$u]);
                        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$u]);
                        kick_subscriber_session($pdo, $u, $client_id);
                    } else {
                        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$u]);
                    }
                }
                
                $pdo->commit();
                echo "<script>alert('User profile updated successfully!'); window.location='users.php';</script>";
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                echo "<script>alert('Error updating user: " . addslashes($e->getMessage()) . "'); window.location='users.php';</script>";
                exit;
            }
        }
    }

    // RENEW USER (Standard 1 Month Renewal of Current Package)
    if ($action === 'renew_user') {
        $id = (int)$_POST['id'];
        $package_id = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
        $expiry_date = $_POST['expiry_date'] ?? null;
        
        $uStmt = $pdo->prepare("SELECT username, package_id as old_package_id, expiry_date as old_expiry FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$id, $dealer_id]);
        $subData = $uStmt->fetch(PDO::FETCH_ASSOC);

        if ($subData) {
            $u = $subData['username'];
            $target_pkg_id = ($package_id > 0) ? $package_id : (int)$subData['old_package_id'];
            
            $p = $pdo->prepare("SELECT name, rate_limit, price, validity_days FROM packages WHERE id = ?");
            $p->execute([$target_pkg_id]);
            $pkg = $p->fetch();

            if ($pkg) {
                try {
                    $pdo->beginTransaction();
                    $now = time();
                    $old_expiry = (!empty($subData['old_expiry']) && $subData['old_expiry'] !== '0000-00-00 00:00:00') ? strtotime($subData['old_expiry']) : 0;
                    $val_days = (int)(($pkg['validity_days'] ?? 0) > 0 ? $pkg['validity_days'] : 30);

                    // Compute New Expiry
                    if ($can_custom_expiry && !empty($expiry_date)) {
                        $new_expiry_time = strtotime($expiry_date);
                    } else {
                        if ($old_expiry > $now) {
                            $new_expiry_time = strtotime("+$val_days days", $old_expiry);
                        } else {
                            $new_expiry_time = strtotime("+$val_days days", $now);
                        }
                    }

                    $dp_price = getDealerPkgPrice($pdo, $dealer_id, $target_pkg_id);
                    $deduction = $dp_price;
                    if ($can_custom_expiry && !empty($expiry_date)) {
                        $seconds = $new_expiry_time - $now;
                        if ($seconds > 0) {
                            $days = $seconds / 86400;
                            $deduction = round($days * ($dp_price / 30), 2);
                        }
                    }

                    if ($deduction > $current_dealer['balance']) {
                        $pdo->rollBack();
                        echo "<script>alert('Insufficient dealer wallet balance! Required: Rs. $deduction, Available: Rs. {$current_dealer['balance']}'); window.location='users.php';</script>";
                        exit;
                    }

                    if ($deduction > 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$deduction, $dealer_id]);
                        $note = "Package Renewal: {$pkg['name']} for $u. Deducted: Rs. $deduction";
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                    }

                    $new_expiry_str = date('Y-m-d H:i:s', $new_expiry_time);
                    $status = ($new_expiry_time > $now) ? 'active' : 'expired';

                    $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = ? WHERE id = ?")
                        ->execute([$target_pkg_id, $new_expiry_str, $status, $id]);
                    
                    // User Ledger entry (archive for 2 years)
                    $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, 0, ?)")
                        ->execute([$client_id, $u, $deduction, "Package Renewed: {$pkg['name']} by Dealer {$current_dealer['username']}"]);

                    // FreeRADIUS Sync
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $pkg['rate_limit']]);
                    }
                    $formatted_expiry = date('d M Y H:i:s', $new_expiry_time);
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);
                    
                    $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Package Renewed')")
                        ->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);
                    
                    $pdo->commit();
                    kick_subscriber_session($pdo, $u, $client_id);
                    echo "<script>alert('User renewed successfully!'); window.location='users.php';</script>";
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo "<script>alert('Error renewing user: " . addslashes($e->getMessage()) . "'); window.location='users.php';</script>";
                    exit;
                }
            }
        }
    }

    // UPGRADE PACKAGE (Dedicated Mid-Cycle Upgrade with Live Pro-Rata Credit Deduction)
    if ($action === 'upgrade_user') {
        $id = (int)$_POST['id'];
        $new_package_id = (int)$_POST['package_id'];

        $uStmt = $pdo->prepare("SELECT username, package_id as old_package_id, expiry_date as old_expiry FROM subscribers WHERE id = ? AND dealer_id = ?");
        $uStmt->execute([$id, $dealer_id]);
        $subData = $uStmt->fetch(PDO::FETCH_ASSOC);

        if ($subData && $new_package_id > 0) {
            $u = $subData['username'];
            $old_package_id = (int)$subData['old_package_id'];
            
            $p = $pdo->prepare("SELECT name, rate_limit, price, validity_days FROM packages WHERE id = ?");
            $p->execute([$new_package_id]);
            $newPkg = $p->fetch();

            if ($newPkg) {
                try {
                    $pdo->beginTransaction();
                    $now = time();
                    $old_expiry = (!empty($subData['old_expiry']) && $subData['old_expiry'] !== '0000-00-00 00:00:00') ? strtotime($subData['old_expiry']) : 0;
                    $val_days = (int)(($newPkg['validity_days'] ?? 0) > 0 ? $newPkg['validity_days'] : 30);

                    $old_dp_price = getDealerPkgPrice($pdo, $dealer_id, $old_package_id);
                    $new_dp_price = getDealerPkgPrice($pdo, $dealer_id, $new_package_id);

                    // Calculate Unused Days Credit on Old Package
                    $old_remaining_credit = 0;
                    $rem_days = 0;
                    if ($old_expiry > $now && $old_dp_price > 0) {
                        $rem_days = ($old_expiry - $now) / 86400;
                        $old_daily_rate = $old_dp_price / 30;
                        $old_remaining_credit = $rem_days * $old_daily_rate;
                    }

                    // Mid-cycle upgrade starts TODAY for full validity period
                    $new_expiry_time = strtotime("+$val_days days", $now);
                    $net_deduction = max(0, round($new_dp_price - $old_remaining_credit, 2));

                    if ($net_deduction > $current_dealer['balance']) {
                        $pdo->rollBack();
                        echo "<script>alert('Insufficient dealer wallet balance! Required: Rs. $net_deduction, Available: Rs. {$current_dealer['balance']}'); window.location='users.php';</script>";
                        exit;
                    }

                    if ($net_deduction > 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $dealer_id]);
                        $rem_str = round($rem_days, 1);
                        $note = "Package Upgrade: {$newPkg['name']} (Full Rs. " . number_format($new_dp_price, 2) . " minus Old Credit Rs. " . number_format($old_remaining_credit, 2) . " for {$rem_str}d left). Deducted: Rs. " . number_format($net_deduction, 2);
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, $note]);
                    }

                    $new_expiry_str = date('Y-m-d H:i:s', $new_expiry_time);
                    $pdo->prepare("UPDATE subscribers SET package_id = ?, expiry_date = ?, status = 'active' WHERE id = ?")
                        ->execute([$new_package_id, $new_expiry_str, $id]);

                    // User Ledger entry
                    $desc = "Package Upgraded to {$newPkg['name']} by Dealer {$current_dealer['username']}" . ($old_remaining_credit > 0 ? " (Credit Rs. " . number_format($old_remaining_credit, 2) . " adjusted)" : "");
                    $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, 0, ?)")
                        ->execute([$client_id, $u, $net_deduction, $desc]);

                    // FreeRADIUS Sync
                    $pdo->prepare("DELETE FROM radreply WHERE username = ? AND attribute = 'Mikrotik-Rate-Limit'")->execute([$u]);
                    if (!empty($newPkg['rate_limit']) && $newPkg['rate_limit'] !== 'No Limit') {
                        $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$u, $newPkg['rate_limit']]);
                    }
                    $formatted_expiry = date('d M Y H:i:s', $new_expiry_time);
                    $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$u]);
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$u, $formatted_expiry]);

                    $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Package Upgraded')")
                        ->execute([$client_id, $dealer_id, $current_dealer['username'], $u]);

                    $pdo->commit();
                    kick_subscriber_session($pdo, $u, $client_id);
                    echo "<script>alert('Package upgraded successfully!'); window.location='users.php';</script>";
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo "<script>alert('Error upgrading package: " . addslashes($e->getMessage()) . "'); window.location='users.php';</script>";
                    exit;
                }
            }
        }
    }

    // TOGGLE USER (Enable / Disable)
    if ($action === 'toggle_user') {
        $id = (int)$_POST['id'];
        
        $subStmt = $pdo->prepare("SELECT username, status, client_id FROM subscribers WHERE id = ? AND dealer_id = ?");
        $subStmt->execute([$id, $dealer_id]);
        $sub = $subStmt->fetch();

        if ($sub) {
            $newStatus = $sub['status'] === 'active' ? 'disabled' : 'active';
            
            $pdo->prepare("UPDATE subscribers SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            
            if ($newStatus === 'disabled') {
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$sub['username']]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$sub['username']]);
                kick_subscriber_session($pdo, $sub['username'], $sub['client_id']);
                echo "<script>alert('User disabled and disconnected from network!'); window.location='users.php';</script>";
            } else {
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Auth-Type'")->execute([$sub['username']]);
                echo "<script>alert('User enabled!'); window.location='users.php';</script>";
            }
            exit;
        }
    }

    // ADD USER (Create as Expired by Default or Activate Immediately)
    if ($action === 'add_user' && $can_create) {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $package_id = (int)$_POST['package_id'];
        $full_name = trim($_POST['full_name']);
        $national_id = trim($_POST['national_id'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $subarea = trim($_POST['subarea'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $latitude = trim($_POST['latitude'] ?? '');
        $longitude = trim($_POST['longitude'] ?? '');
        $activate_now = isset($_POST['activate_now']) && $_POST['activate_now'] == '1';
        $expiry_date = $_POST['expiry_date'] ?? null;
        $router_nasname = trim($_POST['router_nasname'] ?? '');

        if (empty($router_nasname)) {
            echo "<script>alert('Please select a router for this user.'); window.location='users.php';</script>";
            exit;
        }

        $p = $pdo->prepare("SELECT name, rate_limit, price, validity_days FROM packages WHERE id = ?");
        $p->execute([$package_id]);
        $pkg = $p->fetch();

        if ($pkg) {
            try {
                $pdo->beginTransaction();
                
                $dp_price = getDealerPkgPrice($pdo, $dealer_id, $package_id);
                $val_days = (int)(($pkg['validity_days'] ?? 0) > 0 ? $pkg['validity_days'] : 30);
                
                if ($activate_now || ($can_custom_expiry && !empty($expiry_date) && strtotime($expiry_date) > time())) {
                    // Activate immediately
                    if ($can_custom_expiry && !empty($expiry_date)) {
                        $actual_expiry = date('Y-m-d H:i:s', strtotime($expiry_date));
                        $seconds = strtotime($actual_expiry) - time();
                        $deduction = ($seconds > 0) ? round(($seconds / 86400) * ($dp_price / 30), 2) : $dp_price;
                    } else {
                        $actual_expiry = date('Y-m-d H:i:s', strtotime("+$val_days days"));
                        $deduction = $dp_price;
                    }

                    if ($deduction > $current_dealer['balance']) {
                        $pdo->rollBack();
                        echo "<script>alert('Insufficient balance to activate this user! Required: Rs. $deduction, Available: Rs. {$current_dealer['balance']}'); window.location='users.php';</script>";
                        exit;
                    }

                    if ($deduction > 0) {
                        $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$deduction, $dealer_id]);
                        $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$dealer_id, "Created and activated user $username. Deducted: Rs. $deduction"]);
                        $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, 0, ?)")
                            ->execute([$client_id, $username, $deduction, "Initial Package Activation: {$pkg['name']} by Dealer {$current_dealer['username']}"]);
                    }
                    $status = 'active';
                } else {
                    // By Default EXPIRED (Free Creation, user will be activated when renewed)
                    $deduction = 0;
                    $status = 'expired';
                    $actual_expiry = date('Y-m-d H:i:s', time() - 60);
                }

                $pdo->prepare("INSERT INTO subscribers (client_id, dealer_id, package_id, username, password, service_type, full_name, national_id, mobile, phone, email, address, subarea, latitude, longitude, expiry_date, status) VALUES (?, ?, ?, ?, ?, 'pppoe', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$client_id, $dealer_id, $package_id, $username, $password, $full_name, $national_id, $mobile, $phone, $email, $address, $subarea, $latitude, $longitude, $actual_expiry, $status]);
                
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)")->execute([$username, $password]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'NAS-IP-Address', '==', ?)")->execute([$username, $router_nasname]);
                
                if (!empty($pkg['rate_limit']) && $pkg['rate_limit'] !== 'No Limit') {
                    $pdo->prepare("INSERT INTO radreply (username, attribute, op, value) VALUES (?, 'Mikrotik-Rate-Limit', '=', ?)")->execute([$username, $pkg['rate_limit']]);
                }
                
                $formatted_expiry = date('d M Y H:i:s', strtotime($actual_expiry));
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$username]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $formatted_expiry]);

                if ($status === 'expired') {
                    $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')")->execute([$username]);
                }
                
                $pdo->prepare("INSERT INTO activity_logs (client_id, dealer_id, by_user, against_to, against_role, activity) VALUES (?, ?, ?, ?, 'User', 'Created New User')")->execute([$client_id, $dealer_id, $current_dealer['username'], $username]);
                
                $pdo->commit();
                echo "<script>alert('User created successfully!" . ($status === 'expired' ? " (Created as Expired - 0 deduction)'" : " (Activated)'") . "); window.location='users.php';</script>";
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                echo "<script>alert('Error: Username might already exist or DB error.'); window.location='users.php';</script>";
                exit;
            }
        }
    }
}

// Parse Dealer's Assigned Routers
$raw_routers = explode(',', $current_dealer['assigned_routers'] ?? '');
$assigned_nas_ips = [];
foreach($raw_routers as $rr) {
    $val = trim($rr);
    if($val !== '') $assigned_nas_ips[] = $val;
}

$dealer_routers = [];
if (!empty($assigned_nas_ips)) {
    $in = str_repeat('?,', count($assigned_nas_ips) - 1) . '?';
    $rStmt = $pdo->prepare("SELECT id, nasname, shortname FROM nas WHERE id IN ($in)");
    $rStmt->execute($assigned_nas_ips);
    $dealer_routers = $rStmt->fetchAll(PDO::FETCH_ASSOC);
    $unique_routers = [];
    foreach($dealer_routers as $r) {
        $unique_routers[$r['id']] = $r;
    }
    $dealer_routers = array_values($unique_routers);
}

// Fetch Dealer Packages for Modals
$dpStmt = $pdo->prepare("SELECT p.id, p.name, p.rate_limit, dp.dealer_price FROM dealer_packages dp JOIN packages p ON dp.package_id = p.id WHERE dp.dealer_id = ? ORDER BY p.name ASC");
$dpStmt->execute([$dealer_id]);
$dealer_packages = $dpStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($dealer_packages)) {
    $dpStmt = $pdo->prepare("SELECT id, name, rate_limit, price as dealer_price FROM packages WHERE client_id = ? ORDER BY name ASC");
    $dpStmt->execute([$client_id]);
    $dealer_packages = $dpStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Dealer's Users with Filters
$filter = $_GET['filter'] ?? '';
$filter_sql = "";
$params = [$dealer_id];

if ($filter === 'active') {
    $filter_sql = " AND s.status = 'active' AND (s.expiry_date IS NULL OR s.expiry_date >= NOW())";
} elseif ($filter === 'expired') {
    $filter_sql = " AND (s.status = 'expired' OR (s.expiry_date IS NOT NULL AND s.expiry_date < NOW()))";
} elseif ($filter === 'expiring_1w') {
    $filter_sql = " AND s.status = 'active' AND (s.expiry_date IS NOT NULL AND s.expiry_date >= NOW() AND s.expiry_date <= DATE_ADD(NOW(), INTERVAL 7 DAY))";
} elseif ($filter === 'expiring_2w') {
    $filter_sql = " AND s.status = 'active' AND (s.expiry_date IS NOT NULL AND s.expiry_date >= NOW() AND s.expiry_date <= DATE_ADD(NOW(), INTERVAL 14 DAY))";
} elseif ($filter === 'suspended') {
    $filter_sql = " AND s.status = 'suspended'";
} elseif ($filter === 'online') {
    $filter_sql = " AND EXISTS (SELECT 1 FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL)";
} elseif ($filter === 'expired_online') {
    $filter_sql = " AND (s.status = 'expired' OR (s.expiry_date IS NOT NULL AND s.expiry_date < NOW())) AND EXISTS (SELECT 1 FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL)";
} elseif ($filter === 'offline') {
    $filter_sql = " AND NOT EXISTS (SELECT 1 FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL)";
}

// Stats counts strictly for this dealer
$cntStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) THEN 1 ELSE 0 END) as online_users,
        SUM(CASE WHEN (s.status = 'expired' OR (s.expiry_date IS NOT NULL AND s.expiry_date < NOW())) AND EXISTS (SELECT 1 FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) THEN 1 ELSE 0 END) as exp_online_users,
        SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) THEN 1 ELSE 0 END) as offline_users,
        SUM(CASE WHEN (s.status = 'expired' OR (s.expiry_date IS NOT NULL AND s.expiry_date < NOW())) THEN 1 ELSE 0 END) as expired_users,
        SUM(CASE WHEN s.status = 'active' AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as exp_1w_users,
        SUM(CASE WHEN s.status = 'active' AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 14 DAY) THEN 1 ELSE 0 END) as exp_2w_users
    FROM subscribers s
    WHERE s.dealer_id = ?
");
$cntStmt->execute([$dealer_id]);
$cntData = $cntStmt->fetch(PDO::FETCH_ASSOC);

$count_all = (int)($cntData['total_users'] ?? 0);
$count_online = (int)($cntData['online_users'] ?? 0);
$count_exp_online = (int)($cntData['exp_online_users'] ?? 0);
$count_offline = (int)($cntData['offline_users'] ?? 0);
$count_expired = (int)($cntData['expired_users'] ?? 0);
$count_exp_1w = (int)($cntData['exp_1w_users'] ?? 0);
$count_exp_2w = (int)($cntData['exp_2w_users'] ?? 0);

$page_titles = [
    '' => 'All Users',
    'online' => 'Online Users',
    'expired_online' => 'Expired Online Users',
    'offline' => 'Offline Users (Radius)',
    'expired' => 'Expired Users',
    'expiring_1w' => 'Users Expiring in 1 Week',
    'expiring_2w' => 'Users Expiring in 2 Weeks',
    'active' => 'Active Users',
    'suspended' => 'Suspended Users'
];
$current_title = $page_titles[$filter] ?? 'Users Profile';

$sql = "SELECT s.*, p.name as package_name, 
        (SELECT COUNT(*) FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL) as is_online,
        (SELECT framedipaddress FROM radacct r WHERE r.username = s.username AND r.acctstoptime IS NULL ORDER BY radacctid DESC LIMIT 1) as live_ip,
        (SELECT MAX(acctstarttime) FROM radacct r WHERE r.username = s.username) as last_on_time,
        (SELECT MAX(acctstoptime) FROM radacct r WHERE r.username = s.username) as last_off_time,
        (SELECT SUM(acctinputoctets + acctoutputoctets) FROM radacct r 
         WHERE r.username = s.username 
           AND (
               (s.last_renewed_at IS NOT NULL AND (r.acctstarttime >= s.last_renewed_at OR r.acctstoptime >= s.last_renewed_at))
               OR (s.last_renewed_at IS NULL AND (r.acctstarttime >= COALESCE(s.created_at, '2000-01-01 00:00:00') OR r.acctstoptime >= COALESCE(s.created_at, '2000-01-01 00:00:00')))
           )
        ) as total_usage_bytes,
        (SELECT SUM(acctsessiontime) FROM radacct r 
         WHERE r.username = s.username 
           AND (
               (s.last_renewed_at IS NOT NULL AND (r.acctstarttime >= s.last_renewed_at OR r.acctstoptime >= s.last_renewed_at))
               OR (s.last_renewed_at IS NULL AND (r.acctstarttime >= COALESCE(s.created_at, '2000-01-01 00:00:00') OR r.acctstoptime >= COALESCE(s.created_at, '2000-01-01 00:00:00')))
           )
        ) as total_time_sec,
        (SELECT nasipaddress FROM radacct r WHERE r.username = s.username ORDER BY radacctid DESC LIMIT 1) as nas_ip
        FROM subscribers s 
        LEFT JOIN packages p ON s.package_id = p.id 
        WHERE s.dealer_id = ? $filter_sql
        ORDER BY s.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
.table-custom { border-collapse: separate; border-spacing: 0 12px; margin-top: -12px; }
.table-custom thead th { border: none; color: #64748b; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; background: transparent; padding: 0 20px 5px 20px; }
.table-custom tbody tr { background-color: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.02); transition: all 0.2s ease; border-radius: 12px; }
.table-custom tbody tr:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.06); }
.table-custom tbody td { border: none; padding: 15px 20px; vertical-align: middle; }
.table-custom tbody td:first-child { border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
.table-custom tbody td:last-child { border-top-right-radius: 12px; border-bottom-right-radius: 12px; }

.user-avatar { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #eff6ff, #bfdbfe); color: #2563eb; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1.2rem; }

.badge-soft-primary { background-color: rgba(37, 99, 235, 0.1); color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.15); font-weight: 700; }
.badge-soft-success { background-color: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.15); font-weight: 700; }
.badge-soft-danger { background-color: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.15); font-weight: 700; }
.badge-soft-secondary { background-color: rgba(100, 116, 139, 0.1); color: #64748b; border: 1px solid rgba(100, 116, 139, 0.15); font-weight: 700; }
.badge-soft-warning { background-color: rgba(245, 158, 11, 0.1); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.15); font-weight: 700; }

.action-btn { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; transition: all 0.2s; border: none; cursor: pointer; }
.action-btn:hover { transform: scale(1.1); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
.btn-edit-user { background-color: #f1f5f9; color: #475569; }
.btn-edit-user:hover { background-color: #2563eb; color: #fff; }
.btn-renew-user { background-color: rgba(16, 185, 129, 0.1); color: #10b981; }
.btn-renew-user:hover { background-color: #10b981; color: #fff; }
.btn-upgrade-user { background-color: rgba(245, 158, 11, 0.15); color: #d97706; }
.btn-upgrade-user:hover { background-color: #f59e0b; color: #fff; }
.btn-toggle-active { background-color: rgba(245, 158, 11, 0.1); color: #d97706; }
.btn-toggle-active:hover { background-color: #d97706; color: #fff; }
.btn-toggle-disabled { background-color: rgba(16, 185, 129, 0.1); color: #10b981; }
.btn-toggle-disabled:hover { background-color: #10b981; color: #fff; }
.btn-delete-user { background-color: rgba(239, 68, 68, 0.1); color: #ef4444; }
.btn-delete-user:hover { background-color: #ef4444; color: #fff; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="dashboard" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="users" class="text-decoration-none">Users Profile</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($current_title) ?></li>
            </ol>
        </nav>
        <h4 class="fw-bold mb-1"><i class="fa-solid fa-users text-primary me-2"></i> <?= htmlspecialchars($current_title) ?></h4>
        <small class="text-muted">Manage your users, packages, renewals, and profiles</small>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="bg-white px-3 py-2 rounded-pill shadow-sm border border-success border-opacity-25 small fw-bold">
            <span class="text-secondary">Wallet:</span>
            <span class="text-success fs-6 ms-1">Rs. <?= number_format($current_dealer['balance'], 2) ?></span>
        </div>
        <?php if($can_create): ?>
        <button class="btn btn-primary shadow-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fa-solid fa-user-plus me-1"></i> Add New User
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Filter Pills for Quick Switching -->
<div class="d-flex flex-wrap gap-2 mb-4 align-items-center">
    <a href="users" class="btn btn-sm <?= empty($filter) ? 'btn-dark' : 'btn-outline-secondary bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm">
        <i class="fa-solid fa-users me-1"></i> All Users <span class="badge bg-secondary ms-1"><?= $count_all ?></span>
    </a>
    <a href="live_sessions" class="btn btn-sm <?= ($filter === 'online') ? 'btn-success' : 'btn-outline-success bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm" title="View live active sessions">
        <i class="fa-solid fa-wifi me-1"></i> Online Users <span class="badge bg-success ms-1"><?= $count_online ?></span>
    </a>
    <a href="live_sessions?filter=expired_online" class="btn btn-sm <?= ($filter === 'expired_online') ? 'btn-danger' : 'btn-outline-danger bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm" title="View expired users currently online">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> Expired Online <span class="badge bg-danger ms-1"><?= $count_exp_online ?></span>
    </a>
    <a href="users?filter=offline" class="btn btn-sm <?= ($filter === 'offline') ? 'btn-secondary text-white' : 'btn-outline-secondary bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm">
        <i class="fa-solid fa-plug-circle-xmark me-1"></i> Offline Users <span class="badge bg-secondary ms-1"><?= $count_offline ?></span>
    </a>
    <a href="users?filter=expired" class="btn btn-sm <?= ($filter === 'expired') ? 'btn-danger' : 'btn-outline-danger bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm">
        <i class="fa-solid fa-user-xmark me-1"></i> Expired Users <span class="badge bg-danger ms-1"><?= $count_expired ?></span>
    </a>
    <a href="users?filter=expiring_1w" class="btn btn-sm <?= ($filter === 'expiring_1w') ? 'btn-warning text-dark' : 'btn-outline-warning text-dark bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm">
        <i class="fa-solid fa-calendar-week me-1"></i> Expiring In Week <span class="badge bg-warning text-dark ms-1"><?= $count_exp_1w ?></span>
    </a>
    <a href="users?filter=expiring_2w" class="btn btn-sm <?= ($filter === 'expiring_2w') ? 'btn-warning text-dark' : 'btn-outline-warning text-dark bg-white' ?> rounded-pill px-3 fw-semibold shadow-sm">
        <i class="fa-solid fa-calendar-days me-1"></i> Expiring In 2 Weeks <span class="badge bg-warning text-dark ms-1"><?= $count_exp_2w ?></span>
    </a>
</div>

<div class="card border-0 bg-transparent mb-4">
    <div class="card-body p-0">
        <div class="table-responsive pb-3">
            <table id="usersTable" class="table table-custom align-middle w-100">
                <thead>
                    <tr>
                        <th>User Identity</th>
                        <th>Contact / Phone</th>
                        <th>Package</th>
                        <th>Access</th>
                        <th>Connection</th>
                        <th>Expiry</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody class="border-0">
                    <?php foreach($subs as $s): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="user-avatar shadow-sm"><i class="fa-solid fa-user-astronaut"></i></div>
                                <div>
                                    <div class="fw-bold fs-6"><a href="subscriber_view.php?id=<?= $s['id'] ?>" class="text-primary text-decoration-none"><i class="fa-solid fa-user me-1"></i><?= htmlspecialchars($s['username']) ?></a></div>
                                    <div class="small text-muted fw-bold"><?= htmlspecialchars($s['full_name']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="small">
                                <i class="fa-solid fa-phone text-primary me-1"></i><?= htmlspecialchars($s['mobile'] ?: ($s['phone'] ?: 'N/A')) ?>
                                <?php if(!empty($s['subarea'])): ?>
                                    <br><small class="text-muted"><i class="fa-solid fa-location-dot text-warning me-1"></i><?= htmlspecialchars($s['subarea']) ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><span class="badge badge-soft-primary px-3 py-2 rounded-pill"><i class="fa-solid fa-box me-1"></i> <?= htmlspecialchars($s['package_name']) ?></span></td>
                        <td>
                            <?php if(isset($s['status']) && $s['status'] === 'active'): ?>
                                <span class="badge badge-soft-success px-3 py-2 rounded-pill"><i class="fa-solid fa-shield-check me-1"></i> Active</span>
                            <?php else: ?>
                                <span class="badge badge-soft-danger px-3 py-2 rounded-pill"><i class="fa-solid fa-shield-halved me-1"></i> <?= ucfirst($s['status'] ?? 'Disabled') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($s['is_online'] > 0): ?>
                                <span class="badge badge-soft-success px-3 py-2 rounded-pill"><i class="fa-solid fa-wifi me-1"></i> Online</span>
                            <?php else: ?>
                                <span class="badge badge-soft-secondary px-3 py-2 rounded-pill"><i class="fa-solid fa-plug-circle-xmark me-1"></i> Offline</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($s['expiry_date']): ?>
                                <?php 
                                    $is_exp = ($s['status'] === 'expired') || (strtotime($s['expiry_date']) < time());
                                    $badge = $is_exp ? 'badge-soft-danger' : 'badge-soft-success';
                                ?>
                                <span class="badge <?= $badge ?> px-3 py-2 rounded-pill">
                                    <?= $is_exp ? 'Expired (' . date('d M Y', strtotime($s['expiry_date'])) . ')' : 'Valid (' . date('d M Y', strtotime($s['expiry_date'])) . ')' ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-soft-secondary px-3 py-2 rounded-pill">Never</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-flex gap-2 justify-content-end position-relative" style="z-index: 2;">
                                <!-- Edit Profile -->
                                <button type="button" class="action-btn btn-edit-user" title="Edit Profile"
                                    data-id="<?= $s['id'] ?>"
                                    data-username="<?= htmlspecialchars($s['username']) ?>"
                                    data-fullname="<?= htmlspecialchars($s['full_name']) ?>"
                                    data-nationalid="<?= htmlspecialchars($s['national_id'] ?? '') ?>"
                                    data-password="<?= htmlspecialchars($s['password']) ?>"
                                    data-mobile="<?= htmlspecialchars($s['mobile'] ?? '') ?>"
                                    data-phone="<?= htmlspecialchars($s['phone'] ?? '') ?>"
                                    data-email="<?= htmlspecialchars($s['email'] ?? '') ?>"
                                    data-subarea="<?= htmlspecialchars($s['subarea'] ?? '') ?>"
                                    data-address="<?= htmlspecialchars($s['address'] ?? '') ?>"
                                    data-latitude="<?= htmlspecialchars($s['latitude'] ?? '') ?>"
                                    data-longitude="<?= htmlspecialchars($s['longitude'] ?? '') ?>"
                                    data-expiry="<?= $s['expiry_date'] ? date('Y-m-dTH:i', strtotime($s['expiry_date'])) : '' ?>"
                                    data-old-ts="<?= $s['expiry_date'] ? strtotime($s['expiry_date']) : 0 ?>"
                                    data-pkg="<?= $s['package_id'] ?>"
                                    onclick="openEditUserModal(this)"><i class="fa-solid fa-pen"></i></button>

                                <!-- Toggle Active / Disabled -->
                                <form method="POST" onsubmit="return confirm('Are you sure you want to change access status?');" class="m-0">
                                    <input type="hidden" name="action" value="toggle_user">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <?php if(isset($s['status']) && $s['status'] === 'active'): ?>
                                        <button type="submit" class="action-btn btn-toggle-active" title="Disable User"><i class="fa-solid fa-ban"></i></button>
                                    <?php else: ?>
                                        <button type="submit" class="action-btn btn-toggle-disabled" title="Enable User"><i class="fa-solid fa-check"></i></button>
                                    <?php endif; ?>
                                </form>

                                <!-- Renew Package -->
                                <button type="button" class="action-btn btn-renew-user" title="Renew Current Package" 
                                    onclick="openRenewModal(<?= $s['id'] ?>, '<?= addslashes($s['username']) ?>', '<?= addslashes($s['full_name']) ?>', <?= (int)$s['package_id'] ?>, '<?= addslashes($s['package_name'] ?? '') ?>', '<?= $s['expiry_date'] ?? '' ?>')">
                                    <i class="fa-solid fa-rotate"></i>
                                </button>

                                <!-- Dedicated Upgrade Package -->
                                <button type="button" class="action-btn btn-upgrade-user" title="Upgrade Package" 
                                    onclick="openUpgradeModal(<?= $s['id'] ?>, '<?= addslashes($s['username']) ?>', '<?= addslashes($s['full_name']) ?>', <?= (int)$s['package_id'] ?>, '<?= addslashes($s['package_name'] ?? '') ?>', '<?= $s['expiry_date'] ?? '' ?>')">
                                    <i class="fa-solid fa-arrow-up-right-dots"></i>
                                </button>

                                <?php if($can_delete): ?>
                                <form method="POST" onsubmit="return confirm('Delete this user permanently?');" class="m-0">
                                    <input type="hidden" name="action" value="delete_id">
                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="action-btn btn-delete-user" title="Delete User"><i class="fa-solid fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ==================== MODALS ==================== -->

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
      <div class="modal-header bg-dark text-white p-4 border-bottom">
        <h5 class="modal-title fw-bold">
            <i class="fa-solid fa-user-pen text-primary me-2"></i> Edit Subscriber Profile (<span id="edit_user_badge" class="font-monospace text-warning"></span>)
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="id" id="edit_user_id">
        <div class="modal-body p-4 bg-light">
            
            <!-- Personal Info Card -->
            <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                <h6 class="fw-bold text-primary border-bottom pb-2 mb-3">
                    <i class="fa-solid fa-id-card me-2"></i> Personal &amp; Account Details
                </h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Username</label>
                        <input type="text" id="edit_username" class="form-control bg-light" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="edit_fullname" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">CNIC / National ID</label>
                        <input type="text" name="national_id" id="edit_national_id" class="form-control" placeholder="e.g. 35201-1234567-1">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" name="password" id="edit_password" class="form-control font-monospace" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('edit_password').value = Math.random().toString(36).slice(-8);" title="Generate Random Password">
                                <i class="fa-solid fa-shuffle"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Mobile No.</label>
                        <input type="text" name="mobile" id="edit_mobile" class="form-control" placeholder="03001234567">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Phone (Alt / Landline)</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control" placeholder="Optional">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Email Address</label>
                        <input type="email" name="email" id="edit_email" class="form-control" placeholder="user@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Area / City / Subarea</label>
                        <input type="text" name="subarea" id="edit_subarea" class="form-control" placeholder="e.g. Model Town">
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted small fw-bold">Full Street Address</label>
                        <input type="text" name="address" id="edit_address" class="form-control" placeholder="House #, Street #, Colony">
                    </div>
                </div>
            </div>

            <!-- Location from Map Card -->
            <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                    <h6 class="fw-bold text-primary mb-0">
                        <i class="fa-solid fa-map-location-dot me-2"></i> Location from Map (GPS Pin)
                    </h6>
                    <button type="button" class="btn btn-link btn-sm text-decoration-none p-0 text-muted" onclick="toggleEditManualCoords()" style="font-size: 0.75rem;">
                        <i class="fa-solid fa-keyboard me-1"></i> Manual Lat/Lng
                    </button>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-md-5">
                        <button type="button" class="btn btn-outline-primary btn-sm fw-bold w-100 py-2 shadow-sm" id="btn_edit_capture_gps" onclick="captureEditUserGPS()">
                            <i class="fa-solid fa-crosshairs me-1 text-danger"></i> Get Current GPS (10m)
                        </button>
                    </div>
                    <div class="col-md-4">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" id="edit_map_search_input" placeholder="Search area, colony..." onkeydown="if(event.key==='Enter'){event.preventDefault();searchEditMapLocation();}">
                            <button class="btn btn-primary" type="button" onclick="searchEditMapLocation()" title="Search Area">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="btn-group btn-group-sm w-100 shadow-sm" role="group">
                            <button type="button" class="btn btn-primary btn-sm fw-bold" id="btnEditMapHybrid" onclick="switchEditMapLayer('hybrid')"><i class="fa-solid fa-satellite me-1"></i>Sat</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" id="btnEditMapStreets" onclick="switchEditMapLayer('streets')"><i class="fa-solid fa-road me-1"></i>Map</button>
                        </div>
                    </div>
                </div>

                <div class="position-relative rounded border overflow-hidden shadow-sm" style="height: 250px;">
                    <div id="edit_user_map_picker" style="height: 100%; width: 100%; z-index: 1;"></div>
                    <div class="position-absolute bottom-0 start-0 m-2 px-2 py-1 rounded bg-dark bg-opacity-75 text-white small" style="z-index: 1000; font-size: 0.75rem; pointer-events: none;">
                        <i class="fa-solid fa-hand-pointer text-warning me-1"></i> Map par click karein ya pin drag karein
                    </div>
                </div>

                <input type="hidden" name="latitude" id="edit_latitude">
                <input type="hidden" name="longitude" id="edit_longitude">

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

            <!-- Package & Expiry Adjustment -->
            <div class="card border-0 shadow-sm rounded-3 p-3 bg-white">
                <h6 class="fw-bold text-primary border-bottom pb-2 mb-3">
                    <i class="fa-solid fa-sliders me-2"></i> Package &amp; Expiry Adjustment
                </h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small fw-bold">Package</label>
                        <select name="package_id" id="edit_package_id" class="form-select">
                            <?php foreach($dealer_packages as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (Rs. <?= number_format($p['dealer_price']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <?php if($can_custom_expiry): ?>
                            <label class="form-label text-muted small fw-bold">Expiry Date</label>
                            <input type="datetime-local" name="expiry_date" id="edit_expiry" class="form-control">
                        <?php else: ?>
                            <label class="form-label text-muted small fw-bold">Current Expiry</label>
                            <input type="text" id="edit_expiry_display" class="form-control bg-light" readonly>
                            <input type="hidden" name="expiry_date" id="edit_expiry">
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mt-3 p-3 bg-light border rounded shadow-sm d-flex justify-content-between align-items-center">
                    <div class="text-secondary fw-bold small text-uppercase">Est. Balance Adjustment</div>
                    <h4 class="mb-0 fw-bold text-danger" id="live_deduction_amount">Rs. 0.00</h4>
                </div>
            </div>

        </div>
        <div class="modal-footer bg-white border-top px-4 py-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add User Modal -->
<?php if($can_create): ?>
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
      <div class="modal-header bg-primary text-white p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus me-2"></i> Create New User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="needs-validation" novalidate>
        <input type="hidden" name="action" value="add_user">
        <div class="modal-body p-4 bg-light">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control bg-white shadow-none" placeholder="e.g. Ali Khan" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Username (PPPoE ID) <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control bg-white shadow-none" placeholder="e.g. ali123" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Password <span class="text-danger">*</span></label>
                    <input type="text" name="password" class="form-control bg-white shadow-none font-monospace" placeholder="Password" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">CNIC / National ID</label>
                    <input type="text" name="national_id" class="form-control bg-white shadow-none" placeholder="35201-1234567-1">
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Mobile No.</label>
                    <input type="text" name="mobile" class="form-control bg-white shadow-none" placeholder="03001234567">
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Phone (Alt)</label>
                    <input type="text" name="phone" class="form-control bg-white shadow-none" placeholder="Optional">
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Email Address</label>
                    <input type="email" name="email" class="form-control bg-white shadow-none" placeholder="user@example.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Area / City / Subarea</label>
                    <input type="text" name="subarea" class="form-control bg-white shadow-none" placeholder="e.g. Gulberg, Lahore">
                </div>
                <div class="col-12">
                    <label class="form-label text-muted small fw-bold">Full Street Address</label>
                    <input type="text" name="address" class="form-control bg-white shadow-none" placeholder="House #, Street #, Colony">
                </div>

                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Select Router / City <span class="text-danger">*</span></label>
                    <select name="router_nasname" class="form-select bg-white shadow-none" required>
                        <?php if(empty($dealer_routers)): ?>
                            <option value="">No Routers Assigned (Contact Admin)</option>
                        <?php else: ?>
                            <?php foreach($dealer_routers as $r): ?>
                                <option value="<?= $r['nasname'] ?>"><?= htmlspecialchars($r['shortname'] ?: 'Router') ?> (<?= $r['nasname'] ?>)</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label text-muted small fw-bold">Package <span class="text-danger">*</span></label>
                    <select name="package_id" id="add_package_id" class="form-select bg-white shadow-none" required onchange="calculateAddDeduction()">
                        <option value="">Select Package</option>
                        <?php foreach($dealer_packages as $p): ?>
                            <option value="<?= $p['id'] ?>" data-price="<?= $p['dealer_price'] ?>"><?= htmlspecialchars($p['name']) ?> (Rs. <?= number_format($p['dealer_price']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Activation Toggle (Default Expired to manage record) -->
                <div class="col-12 mt-3">
                    <div class="form-check form-switch p-3 bg-white border rounded shadow-sm">
                        <input class="form-check-input ms-0 me-3" type="checkbox" name="activate_now" id="add_activate_now" value="1" onchange="calculateAddDeduction()">
                        <label class="form-check-label fw-bold text-dark" for="add_activate_now">
                            <i class="fa-solid fa-bolt text-warning me-1"></i> Activate User Immediately (Deduct Package Price from Wallet)
                        </label>
                        <div class="form-text small text-muted ms-5">
                            Agar uncheck rahega to user <strong>by default Expired</strong> add hoga (wallet se <strong>Rs. 0</strong> deduct hoga). Aap customer ko bad me Renew/Upgrade button sy activate kar sakeingy.
                        </div>
                    </div>
                </div>

                <?php if($can_custom_expiry): ?>
                <div class="col-12 mt-2" id="custom_expiry_row" style="display: none;">
                    <label class="form-label text-muted small fw-bold">Custom Expiry (Optional)</label>
                    <input type="datetime-local" name="expiry_date" id="add_expiry" class="form-control bg-white shadow-none" onchange="calculateAddDeduction()">
                </div>
                <?php endif; ?>

                <div class="col-12 mt-3">
                    <div class="p-3 bg-white border rounded shadow-sm d-flex justify-content-between align-items-center">
                        <div class="text-secondary fw-bold small text-uppercase">Est. Initial Wallet Deduction</div>
                        <h4 class="mb-0 fw-bold text-danger" id="add_live_deduction">Rs. 0.00</h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer bg-white border-top px-4 py-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm"><i class="fa-solid fa-check me-2"></i>Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Renew Modal (Quick Renewal of Current Package) -->
<div class="modal fade" id="renewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
      <div class="modal-header bg-success text-white p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-rotate me-2"></i> Renew Subscriber Package</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="renew_user">
        <input type="hidden" name="id" id="renew_user_id">
        <input type="hidden" name="package_id" id="renew_pkg_id">

        <div class="modal-body p-4 bg-light">
            <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0" id="renew_fullname"></h5>
                        <span class="badge bg-primary font-monospace" id="renew_username_display"></span>
                    </div>
                    <div class="text-end">
                        <span class="small text-muted fw-bold">Current Expiry</span>
                        <div class="fw-bold text-dark" id="renew_cur_expiry"></div>
                    </div>
                </div>
                <div class="pt-2 border-top text-secondary small d-flex justify-content-between">
                    <div><i class="fa-solid fa-box text-primary me-1"></i> Current Package: <strong class="text-dark" id="renew_cur_pkg"></strong></div>
                    <div>Dealer Price: <strong class="text-success" id="renew_cur_price"></strong></div>
                </div>
            </div>

            <div class="alert alert-success border-success-subtle p-3 rounded-3 mb-3" style="background:#f0fdf4;">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small text-secondary fw-bold"><i class="fa-solid fa-calendar-plus text-success me-1"></i> Extension Period:</span>
                    <span class="badge bg-success">+30 Days</span>
                </div>
                <div class="small text-muted mt-1">
                    Subscriber ka package renew ho jayga aur dealer wallet se package price deduct ho jaygi.
                </div>
            </div>

            <?php if($can_custom_expiry): ?>
            <div class="mb-3">
                <label class="form-label text-muted small fw-bold">Custom Expiry (Optional)</label>
                <input type="datetime-local" name="expiry_date" id="renew_expiry_input" class="form-control bg-white">
            </div>
            <?php endif; ?>

            <div class="p-3 bg-white border rounded shadow-sm d-flex justify-content-between align-items-center">
                <div class="text-secondary fw-bold small text-uppercase">Wallet Deduction</div>
                <h4 class="mb-0 fw-bold text-success" id="renew_deduction_display">Rs. 0</h4>
            </div>
        </div>
        <div class="modal-footer bg-white border-top px-4 py-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm">
              <i class="fa-solid fa-check me-1"></i> Confirm Renewal
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Upgrade Package Modal (Dedicated Pro-Rata Mid-Cycle Upgrade) -->
<div class="modal fade" id="upgradeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 rounded-4 shadow-lg overflow-hidden">
      <div class="modal-header bg-warning text-dark p-4">
        <h5 class="modal-title fw-bold"><i class="fa-solid fa-arrow-up-right-dots me-2"></i> Upgrade Subscriber Package</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="upgrade_user">
        <input type="hidden" name="id" id="up_user_id">

        <div class="modal-body p-4 bg-light">
            <div class="card border-0 shadow-sm rounded-3 p-3 mb-3 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0" id="up_fullname"></h5>
                        <span class="badge bg-primary font-monospace" id="up_username_display"></span>
                    </div>
                    <div class="text-end">
                        <span class="small text-muted fw-bold">Current Expiry</span>
                        <div class="fw-bold text-dark" id="up_cur_expiry"></div>
                    </div>
                </div>
                <div class="pt-2 border-top text-secondary small d-flex justify-content-between">
                    <div><i class="fa-solid fa-box text-primary me-1"></i> Old Package: <strong class="text-dark" id="up_cur_pkg"></strong></div>
                    <div>Days Left: <strong class="text-primary" id="up_rem_days">0</strong></div>
                </div>
            </div>

            <!-- Select New Package -->
            <div class="mb-3 text-start">
                <label class="form-label fw-bold text-dark small text-uppercase">
                    <i class="fa-solid fa-arrow-up text-warning me-1"></i> Select New Package to Upgrade <span class="text-danger">*</span>
                </label>
                <select name="package_id" id="up_newpkg" class="form-select form-select-lg fw-bold text-primary border-warning shadow-sm" required onchange="calcUpgradeAdjustment()">
                    <option value="">-- Choose New Package --</option>
                    <?php foreach($dealer_packages as $p): ?>
                        <option value="<?= $p['id'] ?>" data-price="<?= $p['dealer_price'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>">
                            <?= htmlspecialchars($p['name']) ?> &nbsp;|&nbsp; Dealer Rs. <?= number_format($p['dealer_price']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Live Pro-Rata Breakdown -->
            <div id="up_calc_preview" class="mb-3">
                <div class="alert alert-light border small text-muted text-center py-2 mb-0">
                    <i class="fa-solid fa-arrow-pointer me-1"></i> Upar se naya package select karein, system automatically unused credit deduct kar dega.
                </div>
            </div>
        </div>
        <div class="modal-footer bg-white border-top px-4 py-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning text-dark rounded-pill px-5 fw-bold shadow-sm">
              <i class="fa-solid fa-arrow-up-right-dots me-1"></i> Confirm Upgrade
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// Package prices dictionary for live calculation
const pkgPrices = {
    <?php foreach($dealer_packages as $p) echo $p['id'] . ': ' . $p['dealer_price'] . ','; ?>
};
const dealerWalletBalance = <?= (float)$current_dealer['balance'] ?>;

let current_old_ts = 0;
let current_old_pkg = 0;

// Upgrade Modal State
let curUpPkgId = 0;
let curUpPkgPrice = 0;
let curUpExpiry = '';

function calculateLiveDeduction() {
    let new_pkg = document.getElementById("edit_package_id").value;
    let new_expiry_el = document.getElementById("edit_expiry");
    let new_expiry_val = new_expiry_el ? new_expiry_el.value : "";
    
    if (!new_pkg || !new_expiry_val) {
        document.getElementById("live_deduction_amount").innerText = "Rs. 0.00";
        return;
    }

    let old_price = parseFloat(pkgPrices[current_old_pkg] || 0);
    let new_price = parseFloat(pkgPrices[new_pkg] || 0);
    
    let now_sec = Math.floor(Date.now() / 1000);
    let new_ts = Math.floor(new Date(new_expiry_val).getTime() / 1000);
    
    let old_remaining_value = 0;
    if (current_old_ts > now_sec) {
        let old_days = (current_old_ts - now_sec) / 86400;
        old_remaining_value = old_days * (old_price / 30);
    }
    
    let new_total_value = 0;
    if (new_ts > now_sec) {
        let new_days = (new_ts - now_sec) / 86400;
        new_total_value = new_days * (new_price / 30);
    }
    
    let deduction = new_total_value - old_remaining_value;
    if (deduction > 0) {
        document.getElementById("live_deduction_amount").className = "mb-0 fw-bold text-danger";
        document.getElementById("live_deduction_amount").innerText = "Rs. " + deduction.toFixed(2);
    } else if (deduction < 0) {
        document.getElementById("live_deduction_amount").className = "mb-0 fw-bold text-success";
        document.getElementById("live_deduction_amount").innerText = "Refund: Rs. " + Math.abs(deduction).toFixed(2);
    } else {
        document.getElementById("live_deduction_amount").className = "mb-0 fw-bold text-dark";
        document.getElementById("live_deduction_amount").innerText = "Rs. 0.00";
    }
}

function calculateAddDeduction() {
    let activateCheckbox = document.getElementById("add_activate_now");
    let customRow = document.getElementById("custom_expiry_row");
    
    if (customRow) {
        customRow.style.display = activateCheckbox.checked ? "block" : "none";
    }

    if (!activateCheckbox.checked) {
        document.getElementById("add_live_deduction").innerText = "Rs. 0.00 (Expired / Free)";
        return;
    }

    let pkg = document.getElementById("add_package_id").value;
    let price = parseFloat(pkgPrices[pkg] || 0);
    if (price === 0) {
        document.getElementById("add_live_deduction").innerText = "Rs. 0.00";
        return;
    }

    let expiry_el = document.getElementById("add_expiry");
    let expiry_val = expiry_el ? expiry_el.value : "";
    let now_sec = Math.floor(Date.now() / 1000);

    if (expiry_val) {
        let target_ts = Math.floor(new Date(expiry_val).getTime() / 1000);
        if (target_ts > now_sec) {
            let days = Math.ceil((target_ts - now_sec) / 86400);
            let deduction = days * (price / 30);
            document.getElementById("add_live_deduction").innerText = "Rs. " + deduction.toFixed(2);
            return;
        }
    }

    document.getElementById("add_live_deduction").innerText = "Rs. " + price.toLocaleString();
}

function openRenewModal(id, username, fullname, pkgId, pkgName, expiry) {
    document.getElementById('renew_user_id').value = id;
    document.getElementById('renew_pkg_id').value = pkgId;
    document.getElementById('renew_username_display').innerText = username;
    document.getElementById('renew_fullname').innerText = fullname || username;
    document.getElementById('renew_cur_pkg').innerText = pkgName || 'Current Package';
    
    let price = parseFloat(pkgPrices[pkgId] || 0);
    document.getElementById('renew_cur_price').innerText = 'Rs. ' + price.toLocaleString();
    document.getElementById('renew_deduction_display').innerText = 'Rs. ' + price.toLocaleString();

    if (expiry && expiry !== '0000-00-00 00:00:00') {
        let d = new Date(expiry);
        document.getElementById('renew_cur_expiry').innerText = isNaN(d.getTime()) ? expiry : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    } else {
        document.getElementById('renew_cur_expiry').innerText = 'Expired';
    }

    var modal = new bootstrap.Modal(document.getElementById('renewModal'));
    modal.show();
}

function openUpgradeModal(id, username, fullname, pkgId, pkgName, expiry) {
    document.getElementById('up_user_id').value = id;
    document.getElementById('up_username_display').innerText = username;
    document.getElementById('up_fullname').innerText = fullname || username;
    document.getElementById('up_cur_pkg').innerText = pkgName || 'Current Package';
    
    curUpPkgId = parseInt(pkgId || 0);
    curUpPkgPrice = parseFloat(pkgPrices[pkgId] || 0);
    curUpExpiry = expiry || '';

    let now_sec = Math.floor(Date.now() / 1000);
    let exp_sec = curUpExpiry ? Math.floor(new Date(curUpExpiry).getTime() / 1000) : 0;
    let remDays = 0;
    if (exp_sec > now_sec) {
        remDays = Math.round(((exp_sec - now_sec) / 86400) * 10) / 10;
        document.getElementById('up_rem_days').innerText = remDays + ' days';
    } else {
        document.getElementById('up_rem_days').innerText = '0 days (Expired)';
    }

    if (expiry && expiry !== '0000-00-00 00:00:00') {
        let d = new Date(expiry);
        document.getElementById('up_cur_expiry').innerText = isNaN(d.getTime()) ? expiry : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    } else {
        document.getElementById('up_cur_expiry').innerText = 'Expired';
    }

    const pkgSelect = document.getElementById('up_newpkg');
    if (pkgSelect) pkgSelect.value = '';
    document.getElementById('up_calc_preview').innerHTML = '<div class="alert alert-light border small text-muted text-center py-2 mb-0"><i class="fa-solid fa-arrow-pointer me-1"></i> Upar se naya package select karein, system automatically unused credit deduct kar dega.</div>';

    var modal = new bootstrap.Modal(document.getElementById('upgradeModal'));
    modal.show();
}

function calcUpgradeAdjustment() {
    const pkgSelect = document.getElementById('up_newpkg');
    const previewDiv = document.getElementById('up_calc_preview');
    if (!pkgSelect || !previewDiv) return;

    if (!pkgSelect.value) {
        previewDiv.innerHTML = '<div class="alert alert-light border small text-muted text-center py-2 mb-0"><i class="fa-solid fa-arrow-pointer me-1"></i> Upar se naya package select karein, system automatically unused credit deduct kar dega.</div>';
        return;
    }

    const opt = pkgSelect.options[pkgSelect.selectedIndex];
    const newPrice = parseFloat(opt.dataset.price || 0);
    const newName = opt.dataset.name || 'New Package';

    const now_sec = Math.floor(Date.now() / 1000);
    let exp_sec = curUpExpiry ? Math.floor(new Date(curUpExpiry).getTime() / 1000) : 0;
    if (isNaN(exp_sec)) exp_sec = 0;

    let oldCredit = 0;
    let remDays = 0;
    if (exp_sec > now_sec && curUpPkgPrice > 0) {
        remDays = Math.round(((exp_sec - now_sec) / 86400) * 10) / 10;
        const oldDaily = curUpPkgPrice / 30;
        oldCredit = Math.round(((exp_sec - now_sec) / 86400) * oldDaily * 100) / 100;
    }

    const netPayable = Math.max(0, Math.round(newPrice - oldCredit));
    const walletAfter = dealerWalletBalance - netPayable;
    const walletClass = walletAfter < 0 ? 'text-danger fw-bold' : 'text-success fw-bold';

    if (oldCredit > 0) {
        previewDiv.innerHTML = '<div class="alert alert-success border border-success-subtle p-3 rounded-3 text-start mb-0" style="background:#f0fdf4;">' +
            '<div class="d-flex justify-content-between align-items-center mb-2">' +
                '<span class="fw-bold text-dark"><i class="fa-solid fa-calculator text-primary me-1"></i> Pro-Rata Upgrade Calculation</span>' +
                '<span class="badge bg-success">' + remDays + ' Days Credit Applied</span>' +
            '</div>' +
            '<div class="text-secondary small">' +
                '<div class="d-flex justify-content-between py-1 border-bottom border-success-subtle"><span>New Package Dealer Price (30 Days):</span><strong class="text-dark">Rs. ' + newPrice.toLocaleString() + '</strong></div>' +
                '<div class="d-flex justify-content-between py-1 border-bottom border-success-subtle"><span>Old Package Unused Credit (' + remDays + ' days left):</span><strong class="text-success">- Rs. ' + oldCredit.toFixed(2) + '</strong></div>' +
                '<div class="d-flex justify-content-between pt-2 fs-6"><span><strong>Net Amount to Deduct from Wallet:</strong></span><strong class="text-danger fs-5">Rs. ' + netPayable.toLocaleString() + '</strong></div>' +
                '<div class="d-flex justify-content-between pt-1"><span>Wallet Balance After Upgrade:</span><strong class="' + walletClass + '">Rs. ' + walletAfter.toLocaleString() + '</strong></div>' +
            '</div>' +
        '</div>';
    } else {
        previewDiv.innerHTML = '<div class="alert alert-info border border-info-subtle p-3 rounded-3 text-start mb-0" style="background:#f0f9ff;">' +
            '<div class="d-flex justify-content-between align-items-center mb-2">' +
                '<span class="fw-bold text-dark"><i class="fa-solid fa-info-circle text-info me-1"></i> Standard Upgrade (Package Expired)</span>' +
                '<span class="badge bg-secondary">0 Days Left</span>' +
            '</div>' +
            '<div class="text-secondary small">' +
                '<div class="d-flex justify-content-between py-1 border-bottom border-info-subtle"><span>New Package Dealer Price (30 Days):</span><strong class="text-dark">Rs. ' + newPrice.toLocaleString() + '</strong></div>' +
                '<div class="d-flex justify-content-between pt-2 fs-6"><span><strong>Net Amount to Deduct from Wallet:</strong></span><strong class="text-danger fs-5">Rs. ' + newPrice.toLocaleString() + '</strong></div>' +
                '<div class="d-flex justify-content-between pt-1"><span>Wallet Balance After Upgrade:</span><strong class="' + walletClass + '">Rs. ' + walletAfter.toLocaleString() + '</strong></div>' +
            '</div>' +
        '</div>';
    }
}

// Leaflet Map Picker for Edit User
let editUserMap = null;
let editUserMarker = null;
let editHybridLayer = null;
let editStreetsLayer = null;
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
    const pkg = btn.dataset.pkg || '';
    const expiry = btn.dataset.expiry || '';
    const oldTs = btn.dataset.oldTs || 0;

    current_old_pkg = pkg;
    current_old_ts = oldTs;

    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_user_badge').innerText = username;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_fullname').value = fullname;
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
    document.getElementById('edit_package_id').value = pkg;
    
    const expEl = document.getElementById('edit_expiry');
    if (expEl) expEl.value = expiry;
    const expDisp = document.getElementById('edit_expiry_display');
    if (expDisp) expDisp.value = expiry ? expiry.replace('T', ' ') : 'N/A';

    calculateLiveDeduction();

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

    editHybridLayer = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
        maxZoom: 20,
        attribution: 'Google Hybrid'
    });
    editStreetsLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: 'OpenStreetMap'
    });

    editUserMap = L.map('edit_user_map_picker', {
        center: [startLat, startLng],
        zoom: hasExisting ? 18 : 13,
        layers: [editHybridLayer]
    });

    var pinIcon = L.icon({
        iconUrl: 'https://cdn-icons-png.flaticon.com/512/684/684908.png',
        iconSize: [36, 36],
        iconAnchor: [18, 36],
        popupAnchor: [0, -36]
    });

    editUserMarker = L.marker([startLat, startLng], { draggable: true, icon: pinIcon }).addTo(editUserMap);

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
    } else {
        if (editUserMap.hasLayer(editHybridLayer)) editUserMap.removeLayer(editHybridLayer);
        if (!editUserMap.hasLayer(editStreetsLayer)) editUserMap.addLayer(editStreetsLayer);
        document.getElementById('btnEditMapStreets').className = 'btn btn-primary btn-sm fw-bold';
        document.getElementById('btnEditMapHybrid').className = 'btn btn-outline-secondary btn-sm fw-bold';
    }
}

function toggleEditManualCoords() {
    var row = document.getElementById('edit_manual_coords_row');
    row.style.display = (row.style.display === 'none') ? 'flex' : 'none';
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
    if (!btn || !navigator.geolocation) {
        alert("Geolocation not supported by browser.");
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
                alert("Location not found.");
            }
        })
        .catch(err => { alert("Search error: " + err.message); });
}

$('#editUserModal').on('shown.bs.modal', function () {
    initEditUserMapPicker();
});

$(document).ready(function() {
    $("#edit_package_id, #edit_expiry").on("change input", calculateLiveDeduction);

    $('#usersTable').DataTable({
        language: { lengthMenu: "Show _MENU_ entries" },
        order: [[0, 'desc']]
    });
});

(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms).forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
  })
})()
</script>

<?php require_once 'footer.php'; ?>
