<?php
$f = 'operator/header.php';
$c = file_get_contents($f);
$c = str_replace('<i class="fa-solid fa-headset menu-icon"></i> Support & Requests', '<i class="fa-solid fa-headset menu-icon"></i> <span class="menu-text">Support & Requests</span>', $c);
file_put_contents($f, $c);
echo "Fixed Support & Requests.\n";
?>
