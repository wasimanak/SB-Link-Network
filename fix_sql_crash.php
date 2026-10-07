<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// Fix the SQL syntax by using COUNT(*) and removing the active filter to see if it fixes the crash
$oldSql = 'SELECT r.*, s.full_name, s.package_id, s.status, p.name as package_name,
                                     (SELECT COUNT(id) FROM radpostauth rp WHERE rp.username = r.username AND rp.authdate >= r.acctstarttime - INTERVAL 24 HOUR LIMIT 1) as auth_by_us
                                     FROM radacct r 
                                     JOIN subscribers s ON r.username = s.username 
                                     LEFT JOIN packages p ON s.package_id = p.id
                                     INNER JOIN (
                                         SELECT username, MAX(radacctid) as max_id 
                                         FROM radacct 
                                         WHERE nasipaddress = ? AND acctstoptime IS NULL 
                                         GROUP BY username
                                     ) as latest ON r.radacctid = latest.max_id
                                     WHERE s.status = \'active\'
                                     ORDER BY r.acctstarttime DESC';

$newSql = 'SELECT r.*, s.full_name, s.package_id, s.status, p.name as package_name,
                                     (SELECT COUNT(*) FROM radpostauth rp WHERE rp.username = r.username AND rp.authdate >= r.acctstarttime - INTERVAL 24 HOUR LIMIT 1) as auth_by_us
                                     FROM radacct r 
                                     JOIN subscribers s ON r.username = s.username 
                                     LEFT JOIN packages p ON s.package_id = p.id
                                     INNER JOIN (
                                         SELECT username, MAX(radacctid) as max_id 
                                         FROM radacct 
                                         WHERE nasipaddress = ? AND acctstoptime IS NULL 
                                         GROUP BY username
                                     ) as latest ON r.radacctid = latest.max_id
                                     ORDER BY r.acctstarttime DESC';

$c = str_replace($oldSql, $newSql, $c);
file_put_contents($f, $c);
echo "Restored query with COUNT(*) and removed strict active filter to prevent crashes.\n";
?>
