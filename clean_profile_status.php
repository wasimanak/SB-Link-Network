<?php
$f = 'operator/subscriber_view.php';
$c = file_get_contents($f);

// 1. Unified Logic Block
$logic = "
\$is_expired = false;
if (!empty(\$user['expiry_date']) && strtotime(\$user['expiry_date']) < time()) {
    \$is_expired = true;
}

\$profile_status = 'Offline';
\$status_color = 'secondary';
\$header_badge_class = 'offline';

if (strtolower(\$user['status']) === 'disabled') {
    \$profile_status = 'Disabled';
    \$status_color = 'danger';
    \$header_badge_class = 'disabled';
} elseif (\$is_expired) {
    \$profile_status = 'Expired';
    \$status_color = 'warning text-dark';
    \$header_badge_class = 'expired';
} elseif (\$online) {
    \$profile_status = 'Online';
    \$status_color = 'success';
    \$header_badge_class = '';
} else {
    \$profile_status = 'Offline';
    \$status_color = 'secondary';
    \$header_badge_class = 'offline';
}
?>";

if (strpos($c, '$is_expired = false;') === false) {
    $c = str_replace('<!DOCTYPE html>', $logic . "\n<!DOCTYPE html>", $c);
}

// 2. Profile Status Table Row
$find_row = '<tr><td class="fw-bold w-50">Profile Status</td><td><span class="badge bg-<?= $user[\'status\'] == \'active\' ? \'success\' : \'danger\' ?>"><?= ucfirst($user[\'status\']) ?></span></td></tr>';
$replace_row = '<tr><td class="fw-bold w-50">Profile Status</td><td><span class="badge bg-<?= $status_color ?>"><?= $profile_status ?></span></td></tr>';
$c = str_replace($find_row, $replace_row, $c);

// 3. Header Badge
$find_header = "                    <?php if(\$user['status'] === 'disabled'): ?>\n                        <span class=\"status-badge disabled\">Disabled</span>\n                    <?php else: ?>\n                        <span class=\"status-badge <?= \$online ? '' : 'offline' ?>\">\n                            <?= \$online ? 'Active' : 'Offline' ?>\n                        </span>\n                    <?php endif; ?>";
$replace_header = "                    <span class=\"status-badge <?= \$header_badge_class ?>\"><?= \$profile_status ?></span>";
$c = str_replace($find_header, $replace_header, $c);

// 4. Add CSS
$find_css = '.status-badge.disabled { background: rgba(239, 68, 68, 0.15); color: #ef4444; border-color: rgba(239, 68, 68, 0.2); }';
$replace_css = $find_css . "\n    .status-badge.expired { background: rgba(245, 158, 11, 0.15); color: #d97706; border-color: rgba(245, 158, 11, 0.2); }";

if(strpos($c, 'status-badge.expired') === false) {
    $c = str_replace($find_css, $replace_css, $c);
}

file_put_contents($f, $c);
echo "Clean script completed.\n";
?>
