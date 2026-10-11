<?php
$c = file_get_contents('recoveryman/dashboard.php');
$p = strpos($c, 'collectModal');
echo substr($c, $p, 3000);
?>
