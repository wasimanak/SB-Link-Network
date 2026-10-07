<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

$oldCode = "<td><?= date('d M Y h:i A', strtotime(\$user['acctstarttime'])) ?></td>";
$newCode = "<td><?= date('d M Y h:i A', is_numeric(\$user['acctstarttime']) ? \$user['acctstarttime'] : strtotime(\$user['acctstarttime'])) ?></td>";

$c = str_replace($oldCode, $newCode, $c);

file_put_contents($f, $c);
echo "Fixed date parsing logic in superadmin/live_routers.php\n";
?>
