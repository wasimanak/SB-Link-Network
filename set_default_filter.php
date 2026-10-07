<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// Change default from 'all' to 'expired'
$c = str_replace(
    '$report_filter = $_GET[\'report\'] ?? \'all\';',
    '$report_filter = $_GET[\'report\'] ?? \'expired\';',
    $c
);

file_put_contents($f, $c);
echo "Set default report filter to 'expired'.\n";
?>
