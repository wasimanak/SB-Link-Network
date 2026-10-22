<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

// Change INSERT INTO to INSERT IGNORE INTO for the CSV import queries
$c = str_replace('INSERT INTO subscribers (client_id, username', 'INSERT IGNORE INTO subscribers (client_id, username', $c);
$c = str_replace("INSERT INTO radcheck (username, attribute, op, value)", "INSERT IGNORE INTO radcheck (username, attribute, op, value)", $c);
$c = str_replace("INSERT INTO radusergroup (username, groupname, priority)", "INSERT IGNORE INTO radusergroup (username, groupname, priority)", $c);

// Also wrap the row processing in a try-catch so an unexpected error doesn't kill the whole file
// The loop is: while (($data = fgetcsv($file)) !== FALSE) {
// I will just use regex to add a silent catch block around the row. Actually, just adding INSERT IGNORE is enough to prevent 1062 Integrity Constraint violations.

file_put_contents($f, $c);
echo "Added IGNORE to CSV import queries.\n";
?>
