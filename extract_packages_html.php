<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
preg_match('/<div class="card-header.*?>\s*<h6>.*?Packages.*?<\/table>/is', $c, $m);
echo substr($m[0] ?? 'not found', 0, 2000);
?>
