<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

$oldLogic = '$newStatus = ($dealer[\'status\'] ?? \'active\') === \'active\' ? \'disabled\' : \'active\';';
$newLogic = '$currStmt = $pdo->prepare("SELECT status FROM dealers WHERE id = ?");
    $currStmt->execute([$dealer_id]);
    $current_status = $currStmt->fetchColumn() ?: \'active\';
    $newStatus = $current_status === \'active\' ? \'disabled\' : \'active\';';

$c = str_replace($oldLogic, $newLogic, $c);
file_put_contents($f, $c);
echo "Fixed toggle logic to fetch from database.\n";

$oldBtn = '($dealer[\'status\'] ?? \'active\')';
$newBtn = '($dData[\'status\'] ?? \'active\')';
// Wait, what is the dealer variable named down in the UI?
// Let's check what the UI uses.
?>
