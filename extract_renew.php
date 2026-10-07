<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);
preg_match('/<div class="modal fade" id="renewUserModal".*?<\/form>/is', $c, $m);
echo substr($m[0] ?? 'not found', 0, 4000);
?>
