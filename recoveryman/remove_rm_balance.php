<?php
$file = 'C:/xampp/htdocs/SB Link Network/recoveryman/dashboard.php';
$content = file_get_contents($file);

// 1. Replace the first UPDATE query (with expiry)
$old_update_1 = '$pdo->prepare("UPDATE subscribers SET balance = balance - ?, expiry_date = ?, status = \'active\' WHERE id = ?")->execute([$amount, $new_expiry_db, $sub_id]);';
$new_update_1 = '$pdo->prepare("UPDATE subscribers SET expiry_date = ?, status = \'active\' WHERE id = ?")->execute([$new_expiry_db, $sub_id]);';
$content = str_replace($old_update_1, $new_update_1, $content);

// 2. Replace the second UPDATE query (fallback without expiry)
$old_update_2 = '$pdo->prepare("UPDATE subscribers SET balance = balance - ? WHERE id = ?")->execute([$amount, $sub_id]);';
$new_update_2 = '// No balance deduction as requested by user';
$content = str_replace($old_update_2, $new_update_2, $content);

// 3. Remove "New Balance" from the success alert since it's irrelevant now
$old_alert = '$alert_msg = "Payment collected successfully!\\nNew Balance: Rs. $new_balance";';
$new_alert = '$alert_msg = "Payment collected successfully! (Balance Unchanged)";';
$content = str_replace($old_alert, $new_alert, $content);

file_put_contents($file, $content);
echo "Removed balance deduction logic.\n";
?>
