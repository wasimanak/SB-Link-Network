<?php
$c = file_get_contents('recoveryman/dashboard.php');
$p = strpos($c, 'receive_payment');
// get the code around receive_payment
$start = max(0, $p - 500);
echo substr($c, $start, 3500);
?>
