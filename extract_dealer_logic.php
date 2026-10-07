<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
preg_match('/\$dealer_id\s*=\s*\$_GET.*?;(.*?)\$statsStmt/is', $c, $matches);
echo substr($matches[0] ?? "Not found", 0, 1000);
?>
