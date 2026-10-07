<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// 1. Find where metrics are calculated and add expired_online
if (strpos($c, '$expired_online_users') === false) {
    // Let's find $online_users definition
    $pattern = '/\$online_users\s*=\s*\$pdo->query\([^)]+\)->fetchColumn\(\);/s';
    
    // In operator/dashboard.php, it's actually:
    // $online_users = count($live_users); or something.
    // Let's do a more robust insertion.
}
?>
