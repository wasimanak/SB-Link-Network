<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
preg_match('/<form method="POST" class="m-0 p-0" onsubmit="return confirm.*?>/is', $c, $m);
echo $m[0] ?? 'not found';

preg_match('/\$([a-zA-Z0-9_]+)\s*=\s*\$stmt->fetch/is', $c, $m2);
echo "\nDealer variable: " . ($m2[1] ?? 'not found');
?>
