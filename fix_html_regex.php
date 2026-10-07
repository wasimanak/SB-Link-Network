<?php
$f = 'superadmin/live_routers.php';
$c = file_get_contents($f);

// Replace header
$c = preg_replace('/<th>Session Start<\/th>\s*(<th class="text-center">Action<\/th>)/', "<th>Session Start</th>\n                                <th>Auth Source</th>\n                                $1", $c);

// Replace td
$pattern = '/(<td><\?= date\(\'d M Y h:i A\', is_numeric\(\$user\[\'acctstarttime\'\]\) \? \$user\[\'acctstarttime\'\] : strtotime\(\$user\[\'acctstarttime\'\]\)\) \?><\/td>)\s*(<td class="text-center">)/s';
$replacement = "$1\n                                <td>\n                                    <?php if ((isset(\$user['auth_by_us']) && \$user['auth_by_us'] > 0) || (isset(\$user['status']) && \$user['status'] === 'active')): ?>\n                                        <span class=\"badge bg-success bg-opacity-10 text-success border border-success\"><i class=\"fa-solid fa-check-circle me-1\"></i> Our RADIUS</span>\n                                    <?php else: ?>\n                                        <span class=\"badge bg-danger bg-opacity-10 text-danger border border-danger\"><i class=\"fa-solid fa-triangle-exclamation me-1\"></i> Other/Local</span>\n                                    <?php endif; ?>\n                                </td>\n                                $2";
$c = preg_replace($pattern, $replacement, $c);

// Let's also ensure s.status is in the SQL query!
if (strpos($c, 's.status, p.name') === false) {
    $c = str_replace('s.package_id, p.name', 's.package_id, s.status, p.name', $c);
}

// Ensure auth_by_us is using COUNT(*) and not COUNT(id)
$c = str_replace('COUNT(id)', 'COUNT(*)', $c);

file_put_contents($f, $c);
echo "Regex update complete.\n";
?>
