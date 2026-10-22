<?php
$f = 'operator/subscriber_view.php';
$c = file_get_contents($f);

$logic = "
// PROFILE STATUS LOGIC
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
";

// Use a safe string replacement
$find_target = "\$ledger_history = \$ledgerStmt->fetchAll();";
$replace_target = "\$ledger_history = \$ledgerStmt->fetchAll();\n" . $logic;

if (strpos($c, '$is_expired = false;') === false) {
    $c = str_replace($find_target, $replace_target, $c);
}

file_put_contents($f, $c);
echo "Logic injected successfully.\n";
