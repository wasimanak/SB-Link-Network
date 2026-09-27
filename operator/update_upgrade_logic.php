<?php
$files = [
    'C:/xampp/htdocs/SB Link Network/operator/subscribers.php',
    'C:/xampp/htdocs/SB Link Network/operator/dashboard.php'
];

$oldRenewLogic = <<<'PHP'
                    // Deduct balance if user belongs to a dealer
                    $dStmt = $pdo->prepare("SELECT dealer_id FROM subscribers WHERE id = ?");
                    $dStmt->execute([$id]);
                    $sub_dealer_id = $dStmt->fetchColumn();
                    
                    if ($sub_dealer_id) {
                        // Find price
                        $dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                        $dpStmt->execute([$sub_dealer_id, $package_id]);
                        $dp_price = $dpStmt->fetchColumn();
                        if ($dp_price === false) {
                            $dp_price = $pkg['price'] ?? 0;
                        }
                        
                        // Calculate days
                        $seconds = strtotime($expiry_date) - time();
                        if ($seconds > 0) {
                            $days = ceil($seconds / 86400); // total days
                            $price_per_day = $dp_price / 30; // standard 30 day month
                            $deduction = round($days * $price_per_day, 2);
                            
                            if ($deduction > 0) {
                                // Deduct from dealer
                                $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$deduction, $sub_dealer_id]);
                                
                                // Record in dealer_ledger if exists, or just dealer_notes
                                $note = "Renewed user $u for $days days. Deducted Rs. $deduction";
                                $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
                            }
                        }
                    }
PHP;

$newRenewLogic = <<<'PHP'
                    // Deduct balance if user belongs to a dealer (Handling Upgrades & Renewals)
                    $dStmt = $pdo->prepare("SELECT dealer_id, package_id as old_package_id, expiry_date as old_expiry FROM subscribers WHERE id = ?");
                    $dStmt->execute([$id]);
                    $subData = $dStmt->fetch(PDO::FETCH_ASSOC);
                    $sub_dealer_id = $subData['dealer_id'] ?? 0;
                    
                    if ($sub_dealer_id > 0) {
                        // 1. Calculate Old Package Remaining Value
                        $old_remaining_value = 0;
                        if (!empty($subData['old_expiry']) && strtotime($subData['old_expiry']) > time()) {
                            $old_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                            $old_dpStmt->execute([$sub_dealer_id, $subData['old_package_id']]);
                            $old_dp_price = $old_dpStmt->fetchColumn();
                            if ($old_dp_price === false) {
                                $opq = $pdo->prepare("SELECT price FROM packages WHERE id = ?");
                                $opq->execute([$subData['old_package_id']]);
                                $old_dp_price = $opq->fetchColumn() ?: 0;
                            }
                            $old_seconds = strtotime($subData['old_expiry']) - time();
                            $old_days = $old_seconds / 86400; // Exact fractional days
                            $old_price_per_day = $old_dp_price / 30;
                            $old_remaining_value = $old_days * $old_price_per_day;
                        }

                        // 2. Calculate New Package Total Value
                        $new_dpStmt = $pdo->prepare("SELECT dealer_price FROM dealer_packages WHERE dealer_id = ? AND package_id = ?");
                        $new_dpStmt->execute([$sub_dealer_id, $package_id]);
                        $new_dp_price = $new_dpStmt->fetchColumn();
                        if ($new_dp_price === false) {
                            $new_dp_price = $pkg['price'] ?? 0;
                        }
                        
                        $new_total_value = 0;
                        $new_seconds = strtotime($expiry_date) - time();
                        if ($new_seconds > 0) {
                            $new_days = $new_seconds / 86400; // Exact fractional days
                            $new_price_per_day = $new_dp_price / 30;
                            $new_total_value = $new_days * $new_price_per_day;
                        }

                        // 3. Net Deduction
                        $net_deduction = round($new_total_value - $old_remaining_value, 2);

                        if ($net_deduction > 0) {
                            // Deduct from dealer
                            $pdo->prepare("UPDATE dealers SET balance = balance - ? WHERE id = ?")->execute([$net_deduction, $sub_dealer_id]);
                            
                            $note = "Package Upgrade/Renew for user $u. Total Cost: Rs." . round($new_total_value, 2) . ", Old Credit: Rs." . round($old_remaining_value, 2) . ". Net Deducted: Rs. $net_deduction";
                            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
                        } else {
                            // Downgrade or unused credit covers it. No deduction.
                            $note = "Package Downgrade/Change for user $u. Total Cost: Rs." . round($new_total_value, 2) . " covered by Old Credit Rs." . round($old_remaining_value, 2) . ". No balance deducted.";
                            $pdo->prepare("INSERT INTO dealer_notes (dealer_id, note) VALUES (?, ?)")->execute([$sub_dealer_id, $note]);
                        }
                    }
PHP;

foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $content = str_replace($oldRenewLogic, $newRenewLogic, $content);
        file_put_contents($file, $content);
        echo "Updated upgrade logic in " . basename($file) . "\n";
    }
}
?>
