<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

$lines = explode("\n", $c);
foreach ($lines as $k => $line) {
    if (strpos($line, 'WHERE s.client_id = ?') !== false) {
        $lines[$k] = '          WHERE s.client_id = ?" . (isset($_GET[\'filter\']) && $_GET[\'filter\'] === \'expired_online\' ? " AND s.expiry_date < CURDATE()" : "") . "';
    }
}

file_put_contents($f, implode("\n", $lines));
echo "Restored original unfiltered SQL query.\n";
?>
