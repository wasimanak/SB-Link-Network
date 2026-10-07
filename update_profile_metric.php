<?php
$f = 'operator/profile.php';
$c = file_get_contents($f);

// Change $total_advance to be the sum of all credits from user_ledger
$oldTotal = '$total_advance = $pdo->query("SELECT SUM(balance) FROM subscribers WHERE client_id = $client_id")->fetchColumn() ?: 0;';
$newTotal = '$total_advance = $pdo->query("SELECT SUM(amount) FROM user_ledger WHERE client_id = $client_id AND type = \'credit\'")->fetchColumn() ?: 0;';

if (strpos($c, 'type = \'credit\'') === false) {
    $c = str_replace($oldTotal, $newTotal, $c);
    
    // Also change the label from "Total Advance / Balance" to "Total Customer Recharge"
    $c = str_replace('Total Advance / Balance', 'Total Recharge (LifeTime)', $c);
    
    file_put_contents($f, $c);
    echo "operator/profile.php updated to show Total Recharge instead of Current Net Balance.\n";
} else {
    echo "Already updated profile.\n";
}
?>
