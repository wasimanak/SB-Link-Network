<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);
$c = str_replace('<div class="value">0.00 <span class="pct">0.00%</span></div>', '<div class="value">0 <span class="pct">0.00%</span></div>', $c);
$c = str_replace('toFixed(2) : \'0.00\'', 'toFixed(0) : \'0\'', $c);
file_put_contents($f, $c);
echo "Fixed hardcoded decimals.";
?>
