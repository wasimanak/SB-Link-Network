<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);
preg_match('/\$expired = \$pdo->query.*?;\s*(.*?\n\s*)*?\$hotspot_users = .*?;/s', $c, $m);
if (isset($m[0])) {
    echo $m[0];
} else {
    echo "Not found";
}
?>
