<?php
$f = 'operator/dealers.php';
$c = file_get_contents($f);
preg_match_all('/name="assigned_routers\[\]"/', $c, $matches);
echo "Occurrences of assigned_routers[] in dealers.php: " . count($matches[0]) . "\n";

$f2 = 'operator/dealer_view.php';
$c2 = file_get_contents($f2);
preg_match_all('/name="assigned_routers\[\]"/', $c2, $matches2);
echo "Occurrences of assigned_routers[] in dealer_view.php: " . count($matches2[0]) . "\n";
?>
