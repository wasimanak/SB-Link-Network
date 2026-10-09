<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);
preg_match('/\$total_users = .*?;/s', $c, $m);
if (isset($m[0])) {
    $start = strpos($c, $m[0]);
    echo substr($c, $start, 1000);
} else {
    echo "Not found";
}
?>
