<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// 1. We will add the Auth Source column back, but make the query check for active status and loose radpostauth.
// First, extract the old query.
$oldSql = 'SELECT r.*, s.full_name, s.package_id, p.name as package_name 
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

$newSql = 'SELECT r.*, s.full_name, s.package_id, s.status, p.name as package_name,
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

if (strpos($c, 'auth_by_us') === false) {
    $c = str_replace($oldSql, $newSql, $c);

    // 2. Add column header
    $oldTh = '<th>Session Start</th>
                                <th class="text-center">Action</th>';
    $newTh = '<th>Session Start</th>
                                <th>Auth Source</th>
                                <th class="text-center">Action</th>';
    $c = str_replace($oldTh, $newTh, $c);

    // 3. Add column data
    $oldTd = '<td><?= date(\'d M Y h:i A\', is_numeric($user[\'acctstarttime\']) ? $user[\'acctstarttime\'] : strtotime($user[\'acctstarttime\'])) ?></td>
                                <td class="text-center">';
    $newTd = '<td><?= date(\'d M Y h:i A\', is_numeric($user[\'acctstarttime\']) ? $user[\'acctstarttime\'] : strtotime($user[\'acctstarttime\'])) ?></td>
                                <td>
                                    <?php if ($user[\'auth_by_us\'] > 0 || $user[\'status\'] === \'active\'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fa-solid fa-check-circle me-1"></i> Our RADIUS</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Other/Local</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">';
    $c = str_replace($oldTd, $newTd, $c);
    
    file_put_contents($f, $c);
    echo "Re-added Auth Source column and filtered by active status.\n";
} else {
    echo "Auth Source is already present.\n";
}
?>
