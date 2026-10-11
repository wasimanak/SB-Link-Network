<?php
$f = 'recoveryman/history.php';
$c = file_get_contents($f);

// 1. Fix User Collections Query
$old_user_ledger_q = <<<'PHP'
$userLedgerStmt = $pdo->prepare("
    SELECT * FROM user_ledger 
    WHERE client_id = ? AND description LIKE ? 
    ORDER BY created_at DESC 
    LIMIT 100
");
$userLedgerStmt->execute([$client_id, "Cash collected by {$rm_name} RM%"]);
PHP;

$new_user_ledger_q = <<<'PHP'
$userLedgerStmt = $pdo->prepare("
    SELECT * FROM user_ledger 
    WHERE client_id = ? AND (description LIKE ? OR description LIKE ?)
    ORDER BY created_at DESC 
    LIMIT 100
");
$userLedgerStmt->execute([$client_id, "Cash collected by {$rm_name}%", "Initial Payment Collected by RM: {$_SESSION['rm_username']}%"]);
PHP;

$c = str_replace($old_user_ledger_q, $new_user_ledger_q, $c);


// 2. Fix Activity Logs Query
$old_activity_q = <<<'PHP'
$activityStmt = $pdo->prepare("
    SELECT * FROM activity_logs 
    WHERE client_id = ? AND by_role = 'RecoveryMan' AND by_user = ? 
    ORDER BY created_at DESC 
    LIMIT 150
");
// Ensure we use the same format saved in dashboard ('RM: ' . $rm_name or similar)
// Wait, in dashboard.php I used: 'RM: ' . $_SESSION['rm_username'] for by_user? No, I used $rm_name.
// Let's check dashboard.php: by_user is $rm_name.
$activityStmt->execute([$client_id, $rm_name]);
PHP;

$new_activity_q = <<<'PHP'
$activityStmt = $pdo->prepare("
    SELECT * FROM activity_logs 
    WHERE client_id = ? AND by_role = 'RecoveryMan' AND (by_user = ? OR by_user = ?)
    ORDER BY created_at DESC 
    LIMIT 150
");
$activityStmt->execute([$client_id, $rm_name, 'RM: ' . $_SESSION['rm_username']]);
PHP;

$c = str_replace($old_activity_q, $new_activity_q, $c);

file_put_contents($f, $c);
echo "Fixed history queries!\n";
?>
