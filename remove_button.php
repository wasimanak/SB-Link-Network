<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);
$c = str_replace('<a href="#" class="quick-btn" data-bs-toggle="modal" data-bs-target="#addBalanceModal"><i class="fa-solid fa-coins"></i><span>User Balance</span></a>', '', $c);
file_put_contents($f, $c);
echo "Removed button.";
?>
