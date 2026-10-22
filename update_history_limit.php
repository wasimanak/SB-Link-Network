<?php
$f1 = 'recoveryman/api_user_details.php';
$c1 = file_get_contents($f1);

// Remove the LIMIT 5
$c1 = str_replace('ORDER BY id DESC LIMIT 5', 'ORDER BY id DESC', $c1);
file_put_contents($f1, $c1);

$f2 = 'recoveryman/dashboard.php';
$c2 = file_get_contents($f2);

// Change text
$c2 = str_replace('Last 5 Transactions', 'Lifetime Transaction History', $c2);
file_put_contents($f2, $c2);

echo "Updated history limit and heading.\n";
?>
