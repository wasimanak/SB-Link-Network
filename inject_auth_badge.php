<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// 1. Update SQL
$oldSql = '$sql = "SELECT r.*, s.package_id, p.name as package_name 
          FROM radacct r 
          JOIN subscribers s ON r.username = s.username 
          LEFT JOIN packages p ON s.package_id = p.id';

$newSql = '$sql = "SELECT r.*, s.package_id, p.name as package_name, 
          EXISTS (
              SELECT 1 FROM radpostauth p2 
              WHERE p2.username = r.username 
              AND p2.reply = \'Access-Accept\' 
              AND ABS(TIMESTAMPDIFF(SECOND, p2.authdate, r.acctstarttime)) <= 300
          ) as auth_by_new
          FROM radacct r 
          JOIN subscribers s ON r.username = s.username 
          LEFT JOIN packages p ON s.package_id = p.id';

if (strpos($c, 'auth_by_new') === false) {
    $c = str_replace($oldSql, $newSql, $c);
}

// 2. Update HTML
$oldHtml = '<div class="text-secondary font-monospace small"><i class="fa-solid fa-microchip text-muted me-1"></i><?= htmlspecialchars($sess[\'callingstationid\'] ?? \'N/A\') ?></div>';

$newHtml = $oldHtml . '
                      <?php if(!empty($sess[\'auth_by_new\'])): ?>
                          <div class="mt-1"><span class="badge badge-soft-success px-2 py-1 border border-success border-opacity-25" style="font-size:0.65rem;"><i class="fa-solid fa-check-circle me-1"></i>Auth: New RADIUS</span></div>
                      <?php else: ?>
                          <div class="mt-1"><span class="badge badge-soft-secondary px-2 py-1 border border-secondary border-opacity-25" style="font-size:0.65rem;"><i class="fa-solid fa-clock-rotate-left me-1"></i>Auth: Old RADIUS</span></div>
                      <?php endif; ?>';

if (strpos($c, 'Auth: New RADIUS') === false) {
    $c = str_replace($oldHtml, $newHtml, $c);
}

file_put_contents($f, $c);
echo "Added Auth Source badge to operator/live_sessions.php\n";
?>
