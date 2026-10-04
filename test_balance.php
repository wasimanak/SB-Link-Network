<?php
require 'config/db.php';

$client_id = 1; // test
$sub_id = 1; // test
$sub_user = 'testuser';
$amount = 100;
$finalBal = 200;

try {
    $pdo->prepare("INSERT INTO user_ledger (username, type, amount, description, balance_after) VALUES (?, 'credit', ?, 'Manual Balance Added', ?)")->execute([$sub_user, $amount, $finalBal]);
    echo "user_ledger success\n";
} catch (Exception $e) {
    echo "user_ledger error: " . $e->getMessage() . "\n";
}

try {
    $pdo->prepare("INSERT INTO activity_logs (client_id, by_user, against_to, against_role, activity) VALUES (?, 'Admin', ?, 'User', ?)")->execute([$client_id, $sub_user, "Added Balance: Rs. $amount"]);
    echo "activity_logs success\n";
} catch (Exception $e) {
    echo "activity_logs error: " . $e->getMessage() . "\n";
}
?>
