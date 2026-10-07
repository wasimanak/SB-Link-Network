<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// 1. Remove the subquery
$badSql = '(SELECT COUNT(id) FROM radpostauth rp WHERE rp.username = r.username AND rp.reply = \'Access-Accept\' AND rp.authdate >= r.acctstarttime - INTERVAL 1 HOUR AND rp.authdate <= r.acctstarttime + INTERVAL 1 HOUR LIMIT 1) as auth_by_us';
$c = str_replace($badSql . ',', '', $c);

// 2. Remove the table header
$badTh = '<th>Auth Source</th>';
$c = str_replace($badTh, '', $c);

// 3. Remove the table data
$badTd = '<td>
                                    <?php if ($user[\'auth_by_us\'] > 0): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fa-solid fa-check-circle me-1"></i> Our RADIUS</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> Other/Local</span>
                                    <?php endif; ?>
                                </td>';
$c = str_replace($badTd, '', $c);

file_put_contents($f, $c);
echo "Reverted Auth Source column.\n";
?>
