<?php
require_once 'auth_check.php';

// Auto-migrate package_requests table if receipt_image column is missing
try {
    $cols = $pdo->query("SHOW COLUMNS FROM package_requests")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('receipt_image', $cols)) {
        $pdo->exec("ALTER TABLE package_requests ADD COLUMN receipt_image VARCHAR(255) DEFAULT NULL AFTER payment_reference");
    }
} catch (Exception $e) {}

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

    $dealer_id = (int)($current_user['dealer_id'] ?? 0);
    $wholesale_cost = 0;

    if ($dealer_id > 0) {
        // Dealer's customer: must be assigned in dealer_packages
        $dpStmt = $pdo->prepare("SELECT dealer_price, dealer_profit FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
        $dpStmt->execute([$dealer_id, $package_id]);
        $dp = $dpStmt->fetch(PDO::FETCH_ASSOC);
        if (!$dp) {
            echo "<script>alert('Invalid package selected for this dealer.'); window.location='dashboard.php';</script>";
            exit;
        }
        $price = ((float)$dp['dealer_price'] + (float)$dp['dealer_profit']) > 0 ? ((float)$dp['dealer_price'] + (float)$dp['dealer_profit']) : (float)$package['price'];
        $wholesale_cost = (float)$dp['dealer_price'];
    } else {
        // Direct admin customer: dealer-only packages cannot be selected
        if (!empty($package['package_target']) && $package['package_target'] === 'dealer') {
            echo "<script>alert('This package is reserved for dealers only.'); window.location='dashboard.php';</script>";
            exit;
        }
        $price = (float)$package['price'];
    }

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

            // If user belongs to a dealer, deduct wholesale price from dealer balance
            if ($dealer_id > 0 && $wholesale_cost > 0) {
                $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$wholesale_cost, $dealer_id]);
                $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")
                    ->execute([$dealer_id, "Customer Portal Self-Renewal for user {$current_user['username']} - Package: {$package['name']}. Wholesale cost deducted: Rs. {$wholesale_cost}"]);
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
        // Online Payment Logic (Bank Transfer / QR)
        // Send a pending request to the operator with mandatory receipt attachment
        if (empty($payment_reference)) {
            echo "<script>alert('Transaction Reference / TID is required.'); window.history.back();</script>";
            exit;
        }

        // Receipt attachment is mandatory for manual bank transfer verification
        if (!isset($_FILES['receipt_image']) || $_FILES['receipt_image']['error'] !== UPLOAD_ERR_OK) {
            echo "<script>alert('Payment receipt / screenshot attachment is mandatory! Please upload receipt to verify your transfer.'); window.history.back();</script>";
            exit;
        }

        $file = $_FILES['receipt_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

        if (!in_array($ext, $allowed)) {
            echo "<script>alert('Invalid file format. Allowed receipt formats: JPG, PNG, WEBP, PDF.'); window.history.back();</script>";
            exit;
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            echo "<script>alert('Receipt file is too large! Maximum allowed size is 5MB.'); window.history.back();</script>";
            exit;
        }

        $uploadDir = __DIR__ . '/../uploads/receipts/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $receipt_filename = 'receipt_pkg_' . $current_user['id'] . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $destPath = $uploadDir . $receipt_filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo "<script>alert('Server error: Failed to save uploaded receipt. Please check folder permissions.'); window.history.back();</script>";
            exit;
        }

        try {
            // Insert pending request with receipt_image
            $stmt = $pdo->prepare("INSERT INTO package_requests (subscriber_id, package_id, client_id, status, payment_method, payment_reference, receipt_image) VALUES (?, ?, ?, 'pending', 'bank_transfer', ?, ?)");
            $stmt->execute([$current_user['id'], $package_id, $client_id, $payment_reference, $receipt_filename]);
            
            echo "<script>alert('Payment receipt and request submitted successfully! Your package will be activated once the operator verifies the payment.'); window.location='dashboard.php';</script>";
            exit;
        } catch (Exception $e) {
            echo "<script>alert('Error submitting request: " . addslashes($e->getMessage()) . "'); window.location='dashboard.php';</script>";
            exit;
        }
    }
}
header("Location: dashboard.php");
exit;
