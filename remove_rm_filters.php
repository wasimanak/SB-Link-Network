<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// 1. Remove 1 week filter HTML block
$c = preg_replace('/<div class="col-md col-6">\s*<a href="\?filter=expiring_1w".*?<\/div>\s*<\/a>\s*<\/div>/is', '', $c);

// 2. Remove 2 weeks filter HTML block
$c = preg_replace('/<div class="col-md col-12">\s*<a href="\?filter=expiring_2w".*?<\/div>\s*<\/a>\s*<\/div>/is', '', $c);

file_put_contents($f, $c);
echo "Successfully removed 1 Week and 2 Weeks filter buttons from recoveryman/dashboard.php.\n";
?>
