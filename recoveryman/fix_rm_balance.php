<?php
$file = 'C:/xampp/htdocs/SB Link Network/recoveryman/dashboard.php';
$content = file_get_contents($file);

// Find the balance addition and change it to subtraction
$old_balance_logic = '$pdo->prepare("UPDATE subscribers SET balance = balance + ? WHERE id = ?")->execute([$amount, $sub_id]);';
$new_balance_logic = '$pdo->prepare("UPDATE subscribers SET balance = balance - ? WHERE id = ?")->execute([$amount, $sub_id]);';

$content = str_replace($old_balance_logic, $new_balance_logic, $content);

file_put_contents($file, $content);
echo "Updated balance deduction logic.\n";
?>
