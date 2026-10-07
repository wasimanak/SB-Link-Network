<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// 1. Add the Auth Source column header
$thTarget = '<th>Session Start</th>
                                
                                <th class="text-center">Action</th>';
$thReplacement = '<th>Session Start</th>
                                <th>Auth Source</th>
                                <th class="text-center">Action</th>';
$c = str_replace($thTarget, $thReplacement, $c);

// Also try alternative formatting just in case
$thTarget2 = '<th>Session Start</th>
                                <th class="text-center">Action</th>';
if (strpos($c, $thTarget2) !== false && strpos($c, $thReplacement) === false) {
    $c = str_replace($thTarget2, $thReplacement, $c);
}


// 2. Add the Auth Source column data
$tdTarget = "<td><?= date('d M Y h:i A', is_numeric(\$user['acctstarttime']) ? \$user['acctstarttime'] : strtotime(\$user['acctstarttime'])) ?></td>
                                  
                                  <td class=\"text-center\">";
$tdReplacement = "<td><?= date('d M Y h:i A', is_numeric(\$user['acctstarttime']) ? \$user['acctstarttime'] : strtotime(\$user['acctstarttime'])) ?></td>
                                  <td>
                                      <?php if (\$user['auth_by_us'] > 0 || \$user['status'] === 'active'): ?>
                                          <span class=\"badge bg-success bg-opacity-10 text-success border border-success\"><i class=\"fa-solid fa-check-circle me-1\"></i> Our RADIUS</span>
                                      <?php else: ?>
                                          <span class=\"badge bg-danger bg-opacity-10 text-danger border border-danger\"><i class=\"fa-solid fa-triangle-exclamation me-1\"></i> Other/Local</span>
                                      <?php endif; ?>
                                  </td>
                                  <td class=\"text-center\">";
$c = str_replace($tdTarget, $tdReplacement, $c);

// Alternative formatting
$tdTarget2 = "<td><?= date('d M Y h:i A', is_numeric(\$user['acctstarttime']) ? \$user['acctstarttime'] : strtotime(\$user['acctstarttime'])) ?></td>
                                <td class=\"text-center\">";
if (strpos($c, $tdTarget2) !== false && strpos($c, $tdReplacement) === false) {
    $c = str_replace($tdTarget2, $tdReplacement, $c);
}

file_put_contents($f, $c);
echo "Restored Auth Source HTML columns.\n";
?>
