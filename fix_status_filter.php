<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// Change strict active filter to allow anything except 'disabled'
// We might have multiple variations depending on what previous scripts did.

// 1. Find and replace WHERE s.status = 'active'
$c = str_replace("WHERE s.status = 'active'", "WHERE s.status != 'disabled'", $c);

// 2. Just in case there are other variations
$c = str_replace('WHERE s.status = \'active\'', 'WHERE s.status != \'disabled\'', $c);

// Also fix the Auth Source HTML label logic to match this
$oldLabel = '<?php if ((isset($user[\'auth_by_us\']) && $user[\'auth_by_us\'] > 0) || (isset($user[\'status\']) && $user[\'status\'] === \'active\')): ?>';
$newLabel = '<?php if ((isset($user[\'auth_by_us\']) && $user[\'auth_by_us\'] > 0) || (isset($user[\'status\']) && $user[\'status\'] != \'disabled\')): ?>';
$c = str_replace($oldLabel, $newLabel, $c);

file_put_contents($f, $c);
echo "Changed active filter to != disabled.\n";
?>
