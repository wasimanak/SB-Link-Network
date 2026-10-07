<?php
$f = 'superadmin/header.php';
$c = file_get_contents($f);

// Inject Live Routers link right after Global NAS/Routers
$target = '<a href="routers.php" class="<?= strpos($page, \'router\') !== false ? \'active\' : \'\' ?>"><i class="fa-solid fa-server menu-icon"></i> Global NAS/Routers</a>';

$newLink = $target . "\n" . '        <a href="live_routers.php" class="<?= $page === \'live_routers.php\' ? \'active\' : \'\' ?>"><i class="fa-solid fa-network-wired menu-icon"></i> Live Routers & Users</a>';

if (strpos($c, 'live_routers.php') === false) {
    $c = str_replace($target, $newLink, $c);
    file_put_contents($f, $c);
    echo "Added Live Routers link to Superadmin sidebar.\n";
} else {
    echo "Link already exists.\n";
}
?>
