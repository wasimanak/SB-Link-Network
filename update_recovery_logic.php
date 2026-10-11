<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Replace the UI text
$c = str_replace('<i class="fa-solid fa-hand-holding-dollar me-1"></i> Collect Payment', '<i class="fa-solid fa-rotate me-1"></i> Renew / Collect', $c);
$c = str_replace('<i class="fa-solid fa-hand-holding-dollar me-2"></i> User Dashboard & Collection', '<i class="fa-solid fa-rotate me-2"></i> Renew User Package', $c);
$c = str_replace('<i class="fa-solid fa-money-bill me-1"></i> Receive Payment', '<i class="fa-solid fa-rotate me-1"></i> Renew & Collect', $c);


// 2. Replace the PHP Logic
$start_marker = "// Handle Receive Payment";
$end_marker = "// Search Logic";

$pos_start = strpos($c, $start_marker);
$pos_end = strpos($c, $end_marker);

if ($pos_start !== false && $pos_end !== false) {
    $length = $pos_end - $pos_start;
    
    $new_logic = <<<'PHP'
// Handle Renew & Receive Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_payment') {
    $sub_id = (int)$_POST['subscriber_id'];
    $sub_username = $_POST['subscriber_username'];
    $amount = (float)$_POST['amount']; // Can be 0 if just renewing without collecting
    $new_package_id = isset($_POST['new_package_id']) && $_POST['new_package_id'] !== '' ? (int)$_POST['new_package_id'] : 0;
    $note = trim($_POST['note']);

    try {
        $pdo->beginTransaction();
        
        if ($new_package_id > 0) {
            $pdo->prepare("UPDATE subscribers SET package_id = ? WHERE id = ?")->execute([$new_package_id, $sub_id]);
        }
        
        // Fetch current expiry, package price, and balance
        $stmt = $pdo->prepare("SELECT s.expiry_date, s.balance, p.price, p.name as package_name FROM subscribers s LEFT JOIN packages p ON s.package_id = p.id WHERE s.id = ?");
        $stmt->execute([$sub_id]);
        $subData = $stmt->fetch();
        
        $package_price = (float)($subData['price'] ?? 0);
        $package_name = $subData['package_name'] ?? 'Unknown Package';
        $current_expiry = strtotime($subData['expiry_date']);
        $now = time();
        
        // 1. Extend Expiry by 1 Month (30 Days)
        if ($current_expiry && $current_expiry > $now) {
            $new_expiry_time = strtotime('+1 month', $current_expiry);
        } else {
            $new_expiry_time = strtotime('+1 month', $now);
        }
        
        $new_expiry_db = date('Y-m-d H:i:s', $new_expiry_time);
        $new_expiry_rad = date('d M Y H:i:s', $new_expiry_time);

        $pdo->prepare("UPDATE subscribers SET expiry_date = ?, status = 'active' WHERE id = ?")->execute([$new_expiry_db, $sub_id]);
        
        // Update FreeRADIUS radcheck
        $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Expiration'")->execute([$sub_username]);
        $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$sub_username, $new_expiry_rad]);

        // 2. Adjust Balance & Ledger
        // Step A: Charge the Package Price (Debit)
        if ($package_price > 0) {
            $pdo->prepare("UPDATE subscribers SET balance = balance - ? WHERE id = ?")->execute([$package_price, $sub_id]);
            
            $tmpBalStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $tmpBalStmt->execute([$sub_id]);
            $balAfterCharge = $tmpBalStmt->fetchColumn();
            
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, description, balance_after) VALUES (?, ?, 'debit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $package_price, "Package Renewal ($package_name)", $balAfterCharge]);
        }
        
        // Step B: Add the Collected Amount (Credit)
        if ($amount > 0) {
            $pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);
            
            $tmpBalStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $tmpBalStmt->execute([$sub_id]);
            $balAfterPayment = $tmpBalStmt->fetchColumn();
            
            $desc = "Cash collected by {$rm_name} RM" . ($note ? " - Note: $note" : "");
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $balAfterPayment, $desc]);
                
            // Update Recovery Man's Cash in Hand
            $pdo->prepare("UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?")->execute([$amount, $rm_id]);
        }

        // 3. Activity Log
        $activity_msg = "Renewed Package. Charged Rs. $package_price, Collected Rs. $amount. Expiry extended to " . date('d M Y', $new_expiry_time);
        $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
            ->execute([$client_id, $rm_name, $sub_username, $activity_msg]);
            
        $pdo->commit();
        
        $alert_msg = "Package Renewed Successfully!\\nCharged: Rs. $package_price\\nCollected: Rs. $amount\\nExpiry extended to " . date('d M Y', $new_expiry_time);
        echo "<script>alert('$alert_msg'); window.location='dashboard.php?search_query=" . urlencode($_GET['search_query']??'') . "';</script>";
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error processing renewal.'); window.history.back();</script>";
        exit;
    }
}

PHP;

    $c = substr_replace($c, $new_logic, $pos_start, $length);
    file_put_contents($f, $c);
    echo "Successfully updated Recoveryman Dashboard Logic.\n";
} else {
    echo "Could not find markers.\n";
}
?>
