<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);
preg_match('/<div class="modal fade" id="addBalanceModal".*?<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/is', $c, $m);
echo substr($m[0] ?? 'not found', 0, 3000);
?>
