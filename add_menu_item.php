<?php
$f = 'operator/header.php';
$c = file_get_contents($f);

// 1. Fix the "Online Users" link so it doesn't highlight when filter is expired_online
$oldOnline = '<a href="live_sessions.php" class="<?= $p===\'live_sessions.php\' ? \'active\' : \'\' ?>">Online Users</a>';
$newOnline = '<a href="live_sessions.php" class="<?= ($p===\'live_sessions.php\' && $filter!==\'expired_online\') ? \'active\' : \'\' ?>">Online Users</a>';

$c = str_replace($oldOnline, $newOnline, $c);

// 2. Inject the "Expired Online" link right after "Online Users"
$target = '<a href="live_sessions.php" class="<?= ($p===\'live_sessions.php\' && $filter!==\'expired_online\') ? \'active\' : \'\' ?>">Online Users</a>';
$newLink = $target . "\n            " . '<a href="live_sessions.php?filter=expired_online" class="<?= $filter===\'expired_online\' ? \'active\' : \'\' ?>">Expired Online</a>';

// Just in case oldOnline replacement failed because of spacing, try targeting the original string too
if (strpos($c, $target) !== false) {
    $c = str_replace($target, $newLink, $c);
} else {
    // If exact match failed, try inserting after Online Users
    $c = preg_replace('/<a href="live_sessions\.php" class="[^"]+">Online Users<\/a>/', '<a href="live_sessions.php" class="<?= ($p===\'live_sessions.php\' && $filter!==\'expired_online\') ? \'active\' : \'\' ?>">Online Users</a>' . "\n            " . '<a href="live_sessions.php?filter=expired_online" class="<?= $filter===\'expired_online\' ? \'active\' : \'\' ?>">Expired Online</a>', $c);
}

file_put_contents($f, $c);
echo "Added Expired Online to operator sidebar menu.\n";
?>
