<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
preg_match('/<div class="modal fade".*?Set New Package.*?<\/form>\s*<\/div>\s*<\/div>\s*<\/div>/is', $c, $m);
echo $m[0] ?? 'not found';
?>
