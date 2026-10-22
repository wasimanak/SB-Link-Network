<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// We need to completely rebuild the WHERE clause line because it's currently corrupted.
// The corrupted line looks like: WHERE s.client_id = ?  - INTERVAL 2 HOUR AND p2.authdate <= FROM_UNIXTIME(r.acctstarttime) + INTERVAL 2 HOUR)" . (isset($_GET['filter'])

$lines = explode("\n", $c);
foreach ($lines as $k => $line) {
    if (strpos($line, 'WHERE s.client_id = ?') !== false) {
        // Replace this entire line with the robust Permanent Filter
        $lines[$k] = '          WHERE s.client_id = ? AND EXISTS (SELECT 1 FROM radpostauth p2 WHERE p2.username = r.username AND p2.reply = \'Access-Accept\' AND p2.authdate >= IF(r.acctstarttime REGEXP \'^[0-9]+$\', FROM_UNIXTIME(r.acctstarttime), r.acctstarttime) - INTERVAL 2 HOUR AND p2.authdate <= IF(r.acctstarttime REGEXP \'^[0-9]+$\', FROM_UNIXTIME(r.acctstarttime), r.acctstarttime) + INTERVAL 2 HOUR)" . (isset($_GET[\'filter\']) && $_GET[\'filter\'] === \'expired_online\' ? " AND s.expiry_date < CURDATE()" : "") . "';
    }
}

file_put_contents($f, implode("\n", $lines));
echo "Fixed corrupted SQL and applied permanent robust filter.\n";
?>
