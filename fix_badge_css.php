<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// Fix the CSS classes for the badges so they are visible
$c = str_replace('badge badge-soft-success px-2 py-1 border border-success border-opacity-25', 'badge bg-success text-white px-2 py-1', $c);
$c = str_replace('badge badge-soft-secondary px-2 py-1 border border-secondary border-opacity-25', 'badge bg-secondary text-white px-2 py-1', $c);

// Relax the TIMESTAMPDIFF constraint slightly in case of minor delays, just check if ANY access-accept exists for this user in the last 2 hours before the session started
$oldSqlLine = 'AND ABS(TIMESTAMPDIFF(SECOND, p2.authdate, r.acctstarttime)) <= 300';
$newSqlLine = 'AND p2.authdate >= r.acctstarttime - INTERVAL 2 HOUR AND p2.authdate <= r.acctstarttime + INTERVAL 2 HOUR';
$c = str_replace($oldSqlLine, $newSqlLine, $c);

file_put_contents($f, $c);
echo "Fixed badge CSS and relaxed time constraints.\n";
?>
