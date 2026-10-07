<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
$postPos = strpos($c, '$_POST[\'action\'] === \'toggle_status\'');
$queryPos = strpos($c, 'SELECT * FROM dealers');
echo "Toggle POST Pos: $postPos\n";
echo "SELECT Query Pos: $queryPos\n";
?>
