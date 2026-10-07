<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// If it has auth_by_us, we need to modify the query to add WHERE s.status = 'active'
// Let's just use regex to reliably insert the WHERE clause before ORDER BY.
$pattern = '/GROUP BY username\s*\)\s*as latest ON r\.radacctid = latest\.max_id\s*ORDER BY/s';
$replacement = "GROUP BY username\n                                     ) as latest ON r.radacctid = latest.max_id\n                                     WHERE s.status = 'active'\n                                     ORDER BY";

if (strpos($c, "WHERE s.status = 'active'") === false) {
    $c = preg_replace($pattern, $replacement, $c);
}

// Modify the logic in the HTML for Auth Source
$oldHtml = '<?php if ($user[\'auth_by_us\'] > 0): ?>';
$newHtml = '<?php if ($user[\'auth_by_us\'] > 0 || $user[\'status\'] === \'active\'): ?>';
$c = str_replace($oldHtml, $newHtml, $c);

// Also need to make sure s.status is selected in the SQL query
if (strpos($c, 's.status') === false) {
    $c = str_replace('s.package_id, p.name', 's.package_id, s.status, p.name', $c);
}

file_put_contents($f, $c);
echo "Forcefully updated Auth logic to use subscriber status.\n";
?>
