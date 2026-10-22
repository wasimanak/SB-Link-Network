<?php
$f2 = 'operator/dashboard.php';
$c2 = file_get_contents($f2);

$find = '<a href="profile.php" class="quick-btn"><i class="fa-solid fa-user"></i><span>My Profile</span></a>';
$replace = '<a href="profile.php" class="quick-btn"><i class="fa-solid fa-user"></i><span>My Profile</span></a>' . "\n    " . '<a href="packages.php" class="quick-btn"><i class="fa-solid fa-box-open text-primary"></i><span>All Packages</span></a>';

$c2 = str_replace($find, $replace, $c2);
file_put_contents($f2, $c2);

echo "Fixed button injection.\n";
?>
