<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// 1. Add filter to SQL WHERE clause
$oldWhere = 'WHERE s.client_id = ?" . (isset($_GET[\'filter\'])';
$newWhere = 'WHERE s.client_id = ? AND EXISTS (SELECT 1 FROM radpostauth p2 WHERE p2.username = r.username AND p2.reply = \'Access-Accept\' AND p2.authdate >= r.acctstarttime - INTERVAL 2 HOUR AND p2.authdate <= r.acctstarttime + INTERVAL 2 HOUR)" . (isset($_GET[\'filter\'])';
$c = str_replace($oldWhere, $newWhere, $c);

// 2. Remove the Auth Source Badge block from HTML
$oldBadgeBlock = <<<HTML
                      <?php if(!empty(\$sess['auth_by_new'])): ?>
                          <div class="mt-1"><span class="badge bg-success text-white px-2 py-1" style="font-size:0.65rem;"><i class="fa-solid fa-check-circle me-1"></i>Auth: New RADIUS</span></div>
                      <?php else: ?>
                          <div class="mt-1"><span class="badge bg-secondary text-white px-2 py-1" style="font-size:0.65rem;"><i class="fa-solid fa-clock-rotate-left me-1"></i>Auth: Old RADIUS</span></div>
                      <?php endif; ?>
HTML;
$c = str_replace($oldBadgeBlock, "", $c);

file_put_contents($f, $c);
echo "Filtered Old RADIUS sessions and removed badges.\n";
?>
