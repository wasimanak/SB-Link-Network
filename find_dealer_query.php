<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
preg_match('/\$stmt\s*=\s*\$pdo->prepare.*?(dealer).*?;/is', $c, $m);
echo $m[0] ?? "not found";
?>
