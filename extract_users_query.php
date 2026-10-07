<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
preg_match('/\$dUsersStmt\s*=\s*\$pdo->prepare.*?\$dUsersStmt->execute/is', $c, $m);
echo $m[0] ?? "Not found";
?>
