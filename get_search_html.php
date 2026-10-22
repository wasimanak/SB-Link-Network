<?php
$c = file_get_contents('recoveryman/dashboard.php');
$p = strpos($c, '<form');
echo substr($c, max(0, $p - 200), 500);
?>
