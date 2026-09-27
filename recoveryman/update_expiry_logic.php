<?php
$file = 'C:/xampp/htdocs/SB Link Network/recoveryman/dashboard.php';
$content = file_get_contents($file);

$old_block = <<<'PHP'
// Handle Receive Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_payment') {
    $sub_id = (int)$_POST['subscriber_id'];
    $sub_username = $_POST['subscriber_username'];
    $amount = (float)$_POST['amount'];
    $note = trim($_POST['note']);

    if ($amount > 0) {
        try {
            $pdo->beginTransaction();
            // Update subscriber balance
            $pdo->prepare("UPDATE subscribers SET balance = balance - ? WHERE id = ?")->execute([$amount, $sub_id]);
            
            // Get new balance
            $bStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $bStmt->execute([$sub_id]);
            $new_balance = $bStmt->fetchColumn();

            // Insert into user_ledger
            $desc = "Cash collected by RM: {$rm_name}. " . ($note ? " Note: $note" : "");
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $new_balance, $desc]);
            
            // Insert into activity log
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
                ->execute([$client_id, $rm_name, $sub_username, "Collected Payment Rs. $amount"]);

            $pdo->commit();
            echo "<script>alert('Payment collected successfully! New Balance: Rs. $new_balance'); window.location='dashboard.php?search_query=" . urlencode($_GET['search_query']??'') . "';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error processing payment.');</script>";
        }
    }
}
PHP;

$new_block = <<<'PHP'
// Handle Receive Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_payment') {
    $sub_id = (int)$_POST['subscriber_id'];
    $sub_username = $_POST['subscriber_username'];
    $amount = (float)$_POST['amount'];
    $note = trim($_POST['note']);

    if ($amount > 0) {
        try {
            $pdo->beginTransaction();
            
            // Fetch current expiry and package price
            $stmt = $pdo->prepare("SELECT s.expiry_date, p.price FROM subscribers s LEFT JOIN packages p ON s.package_id = p.id WHERE s.id = ?");
            $stmt->execute([$sub_id]);
            $subData = $stmt->fetch();
            
            $package_price = (float)($subData['price'] ?? 0);
            $days_to_add = 0;
            if ($package_price > 0) {
                // E.g. (1000 / 2000) * 30 = 15 days
                $days_to_add = round(($amount / $package_price) * 30);
            }
            
            $new_expiry_db = null;
            $new_expiry_rad = null;
            
            if ($days_to_add > 0) {
                $current_expiry = strtotime($subData['expiry_date']);
                $now = time();
                
                if ($current_expiry && $current_expiry > $now) {
                    // Unexpired: Extend from existing expiry date
                    $new_expiry_time = $current_expiry + ($days_to_add * 86400);
                } else {
                    // Expired or null: Extend from right NOW
                    $new_expiry_time = $now + ($days_to_add * 86400);
                }
                
                $new_expiry_db = date('Y-m-d H:i:s', $new_expiry_time);
                $new_expiry_rad = date('d M Y H:i:s', $new_expiry_time);
            }

            // Update subscriber balance AND expiry date
            if ($new_expiry_db) {
                $pdo->prepare("UPDATE subscribers SET balance = balance - ?, expiry_date = ?, status = 'active' WHERE id = ?")->execute([$amount, $new_expiry_db, $sub_id]);
                
                // Update FreeRADIUS radcheck
                $pdo->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Expiration'")->execute([$sub_username]);
                $pdo->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Expiration', ':=', ?)")->execute([$sub_username, $new_expiry_rad]);
                
                $activity_msg = "Collected Payment Rs. $amount. Expiry extended by $days_to_add days to " . date('d M', strtotime($new_expiry_db));
            } else {
                $pdo->prepare("UPDATE subscribers SET balance = balance - ? WHERE id = ?")->execute([$amount, $sub_id]);
                $activity_msg = "Collected Payment Rs. $amount";
            }
            
            // Get new balance
            $bStmt = $pdo->prepare("SELECT balance FROM subscribers WHERE id = ?");
            $bStmt->execute([$sub_id]);
            $new_balance = $bStmt->fetchColumn();

            // Insert into user_ledger
            $desc = "Cash collected by RM: {$rm_name}. " . ($note ? " Note: $note" : "");
            if ($days_to_add > 0) {
                $desc .= " (Added $days_to_add days)";
            }
            $pdo->prepare("INSERT INTO user_ledger (client_id, username, type, amount, balance_after, description) VALUES (?, ?, 'credit', ?, ?, ?)")
                ->execute([$client_id, $sub_username, $amount, $new_balance, $desc]);
            
            // Insert into activity log
            $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, by_role, against_to, against_role, activity) VALUES (?, ?, 'RecoveryMan', ?, 'User', ?)")
                ->execute([$client_id, $rm_name, $sub_username, $activity_msg]);

            $pdo->commit();
            
            $alert_msg = "Payment collected successfully!\\nNew Balance: Rs. $new_balance";
            if ($days_to_add > 0) {
                $alert_msg .= "\\nExpiry extended by $days_to_add days!";
            }
            echo "<script>alert('$alert_msg'); window.location='dashboard.php?search_query=" . urlencode($_GET['search_query']??'') . "';</script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo "<script>alert('Error processing payment.');</script>";
        }
    }
}
PHP;

$content = str_replace($old_block, $new_block, $content);

file_put_contents($file, $content);
echo "Updated payment logic to include automatic expiry extension.\n";
?>
