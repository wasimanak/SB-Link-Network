<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
preg_match('/\$assignedPkgStmt\s*=\s*\$pdo->prepare.*?;/is', $c, $m);
echo $m[0] ?? 'not found';
?>
