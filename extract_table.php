<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
preg_match('/<table.*?dealer_assigned_packages.*?<\/table>/is', $c, $m);
echo $m[0] ?? 'not found';
?>
