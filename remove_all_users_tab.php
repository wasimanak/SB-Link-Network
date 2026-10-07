<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// Remove the 'All Users' tab
$pattern = '/<a href="\?report=all#reportsSection" class="report-tab.*?All Users<\/a>\s*/is';
$c = preg_replace($pattern, '', $c);

file_put_contents($f, $c);
echo "Successfully removed 'All Users' tab from operator/dashboard.php.\n";
?>
