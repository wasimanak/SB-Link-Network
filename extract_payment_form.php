<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);
preg_match('/<div class="tab-pane fade show active" id="payment".*?<\/form>/is', $c, $m);
echo substr($m[0] ?? 'not found', 0, 3000);
?>
