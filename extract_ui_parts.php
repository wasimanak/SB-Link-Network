<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

preg_match('/<div class="d-flex gap-3 mb-4 overflow-auto pb-2".*?<\/div>.*?<\/div>/s', $c, $m1);
echo "QUICK BTNS:\n";
echo $m1[0] ?? "Not found";

preg_match('/<div class="stats-grid mb-4".*?<\/div>\s*<\/div>/s', $c, $m2);
echo "\n\nSTATS GRID:\n";
echo $m2[0] ?? "Not found";

preg_match('/<div class="card-ui p-4">.*?<\/div>\s*<\/div>\s*<\/div>/s', $c, $m3);
echo "\n\nTABLE/SEARCH:\n";
echo substr($m3[0] ?? "Not found", 0, 1000);
?>
