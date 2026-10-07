<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
preg_match_all('/\$[a-zA-Z0-9_]+\s*=\s*\$pdo->prepare\("SELECT.*?dealers.*?"\);/is', $c, $m);
print_r($m[0]);
?>
