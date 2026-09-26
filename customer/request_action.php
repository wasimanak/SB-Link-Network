<?php
require_once 'auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['package_id'])) {
    $package_id = (int)$_POST['package_id'];
    $payment_method = $_POST['payment_method'] ?? 'balance';
    $payment_reference = trim($_POST['payment_reference'] ?? '');

    // Check if package exists and get price & validity
    $pkgStmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND client_id = ?");
    $pkgStmt->execute([$package_id, $client_id]);
    $package = $pkgStmt->fetch();

    if (!$package) {
        echo "<script>alert('Invalid package selected.'); window.location='dashboard.php';</script>";
        exit;
    }

    $price = (float)$package['price'];
    $validity_days = (int)$package['validity_days'];
    
    // Check pending requests
    $reqStmt = $pdo->prepare("SELECT COUNT(*) FROM package_requests WHERE subscriber_id = ? AND status = 'pending'");
    $reqStmt->execute([$current_user['id']]);
    if ($reqStmt->fetchColumn() > 0) {
        echo "<script>alert('You already have a pending request!'); window.location='dashboard.php';</script>";
        exit;
    }

    if ($payment_method === 'balance') {
        $pdo->beginTransaction();
        try {
            // Lock row and fetch fresh balance to prevent race condition (double-click bug)
            $balStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ? FOR UPDATE");
            $balStmt->execute([$current_user['id']]);
            $fresh_balance = (float)$balStmt->fetchColumn();

            if ($fresh_balance < $price) {
                $pdo->rollBack();
                echo "<script>alert('Insufficient balance. Please recharge.'); window.location='dashboard.php';</script>";
                exit;
            }

            // Deduct balance
            $new_balance = $fresh_balance - $price;
            $pdo->prepare("UPDATE subscribers SET balance = ?, package_id = ?, status = 'active' WHERE id = ?")
                ->execute([$new_balance, $package_id, $current_user['id']]);
                
            // Insert into ledger for balance deduction
            if ($price > 0) {
                $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'debit', ?, ?, ?)")
                    ->execute([$client_id, $current_user['username'], $price, $new_balance, "Auto-Renew: " . $package['name']]);
            }

            // Calculate Expiry
            // If current expiry is in the future, add days. Otherwise, add days from now.
            $current_exp = strtotime($current_user['expiry_date']);
            $now = time();
            if ($current_user['expiry_date'] && $current_exp > $now) {
                $new_exp = $current_exp + ($validity_days * 86400);
            } else {
                $new_exp = $now + ($validity_days * 86400);
            }
            
            $db_expiry = date('Y-m-d H:i:s', $new_exp);
            $rad_expiry = date('d M Y H:i:s', $new_exp);

            $pdo->prepare("UPDATE subscribers SET expiry_date = ? WHERE id = ?")
                ->execute([$db_expiry, $current_user['id']]);

            // Update FreeRADIUS
            $username = $current_user['username'];
            $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute IN ('Expiration', 'Auth-Type')")->execute([$username]);
            $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$username, $rad_expiry]);

            // Record request as approved
            $stmt = $pdo->prepare("INSERT INTO package_requests (subscriber_id, package_id, client_id, status, payment_method) VALUES (?, ?, ?, 'approved', 'balance')");
            $stmt->execute([$current_user['id'], $package_id, $client_id]);

            $pdo->commit();
            echo "<script>alert('Package activated successfully! Rs {$price} deducted.'); window.location='dashboard.php';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error processing balance payment.'); window.location='dashboard.php';</script>";
            exit;
        }

    } else {
        // Manual Verification Logic for Bank Transfer
        if (empty($payment_reference)) {
            echo "<script>alert('Transaction ID is required for online payments.'); window.history.back();</script>";
            exit;
        }

        // Insert pending request
        $stmt = $pdo->prepare("INSERT INTO package_requests (subscriber_id, package_id, client_id, status, payment_method, payment_reference) VALUES (?, ?, ?, 'pending', 'bank_transfer', ?)");
        $stmt->execute([$current_user['id'], $package_id, $client_id, $payment_reference]);
        
        echo "<script>alert('Request submitted! Operator will verify your transaction shortly.'); window.location='dashboard.php';</script>";
        exit;
    }
}
header("Location: dashboard.php");
exit;
