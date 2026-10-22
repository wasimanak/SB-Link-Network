<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

$oldSql = "AND p2.authdate >= r.acctstarttime - INTERVAL 2 HOUR AND p2.authdate <= r.acctstarttime + INTERVAL 2 HOUR";
$newSql = "AND p2.authdate >= FROM_UNIXTIME(r.acctstarttime) - INTERVAL 2 HOUR AND p2.authdate <= FROM_UNIXTIME(r.acctstarttime) + INTERVAL 2 HOUR";

$c = str_replace($oldSql, $newSql, $c);

file_put_contents($f, $c);
echo "Fixed UNIX timestamp comparison in SQL.\n";
?>
