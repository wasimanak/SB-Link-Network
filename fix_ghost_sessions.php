<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

$oldSql = '$sql = "SELECT r.*, s.package_id, p.name as package_name 
        FROM radacct r 
        JOIN subscribers s ON r.username = s.username 
        LEFT JOIN packages p ON s.package_id = p.id
        WHERE s.client_id = ? AND r.acctstoptime IS NULL 
        ORDER BY r.acctstarttime DESC";';
        
$newSql = '$sql = "SELECT r.*, s.package_id, p.name as package_name 
        FROM radacct r 
        JOIN subscribers s ON r.username = s.username 
        LEFT JOIN packages p ON s.package_id = p.id
        INNER JOIN (
            SELECT username, MAX(radacctid) as max_id 
            FROM radacct 
            WHERE acctstoptime IS NULL 
            GROUP BY username
        ) as latest ON r.radacctid = latest.max_id
        WHERE s.client_id = ?
        ORDER BY r.acctstarttime DESC";';

if (strpos($c, 'MAX(radacctid)') === false) {
    $c = str_replace($oldSql, $newSql, $c);
    file_put_contents($f, $c);
    echo "operator/live_sessions.php fixed to show unique users and hide ghost duplicates.\n";
} else {
    echo "Already fixed ghost sessions.\n";
}
?>
