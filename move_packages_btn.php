<?php
// 1. Update header.php (remove from sidebar)
$f1 = 'operator/header.php';
$c1 = file_get_contents($f1);

$find1 = '<a href="packages.php" class="<?= $p===\'packages.php\' ? \'active\' : \'\' ?>">All Packages</a>';
$c1 = str_replace($find1, '', $c1);
file_put_contents($f1, $c1);

// 2. Update dashboard.php (add to top quick actions)
$f2 = 'operator/dashboard.php';
$c2 = file_get_contents($f2);

$find2 = <<<'HTML'
<div class="d-flex flex-wrap gap-3 mb-4">
    <a href="profile.php" class="quick-btn"><i class="fa-solid fa-user"></i><span>My Profile</span></a>
    <a href="#" class="quick-btn" data-bs-toggle="modal" data-bs-target="#addBalanceModal"><i class="fa-solid fa-money-bills"></i><span>Add Payment</span></a>
HTML;

$replace2 = <<<'HTML'
<div class="d-flex flex-wrap gap-3 mb-4">
    <a href="profile.php" class="quick-btn"><i class="fa-solid fa-user"></i><span>My Profile</span></a>
    <a href="packages.php" class="quick-btn"><i class="fa-solid fa-box-open text-primary"></i><span>All Packages</span></a>
    <a href="#" class="quick-btn" data-bs-toggle="modal" data-bs-target="#addBalanceModal"><i class="fa-solid fa-money-bills"></i><span>Add Payment</span></a>
HTML;

$c2 = str_replace($find2, $replace2, $c2);
file_put_contents($f2, $c2);

echo "Moved All Packages button.\n";
?>
