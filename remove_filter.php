<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// We will find the injected EXISTS clause and remove it.
$pattern = '/AND EXISTS \(SELECT 1 FROM radpostauth p2.*?\)/s';
$c = preg_replace($pattern, '', $c);

file_put_contents($f, $c);
echo "Removed the strict authentication filter.\n";
?>
