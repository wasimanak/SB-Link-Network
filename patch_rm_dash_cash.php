<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Add schema upgrade for cash_in_hand
$schemaUpgrade = "
// Auto-upgrade recovery_men table for cash_in_hand
try {
    \$pdo->exec(\"ALTER TABLE `recovery_men` ADD COLUMN `cash_in_hand` DECIMAL(10,2) DEFAULT 0.00\");
} catch (PDOException \$e) {}
";

if (strpos($c, 'ADD COLUMN `cash_in_hand`') === false) {
    $c = preg_replace('/require_once \'..\/config\/db.php\';/', "require_once '../config/db.php';\n" . $schemaUpgrade, $c);
}

// 2. Inject the cash_in_hand update logic inside the payment collection transaction
$updateCash = "
            // Update Recovery Man's Cash in Hand
            \$pdo->prepare(\"UPDATE recovery_men SET cash_in_hand = cash_in_hand + ? WHERE id = ?\")->execute([\$amount, \$rm_id]);
";

if (strpos($c, 'cash_in_hand = cash_in_hand +') === false) {
    $c = str_replace('// Insert into activity log', $updateCash . "\n            // Insert into activity log", $c);
}

file_put_contents($f, $c);
echo "Updated recoveryman/dashboard.php to track cash_in_hand.\n";
?>
