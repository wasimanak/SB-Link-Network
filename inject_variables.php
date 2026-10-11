<?php
$f = 'operator/subscriber_view.php';
$c = file_get_contents($f);

// 1. The PHP Logic
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

// Insert the logic right before the first `?>`
if (strpos($c, '$is_expired = false;') === false) {
    // Find the very last `?>` before the HTML starts. 
    // In our file, we saw: $ledger_history = $ledgerStmt->fetchAll(); \n\n ?>
    $c = str_replace("\$ledger_history = \$ledgerStmt->fetchAll();\n\n?>", "\$ledger_history = \$ledgerStmt->fetchAll();\n" . $logic . "\n?>", $c);
    
    // Fallback if the exact spacing above fails
    if (strpos($c, '$is_expired = false;') === false) {
        $c = str_replace("\$ledger_history = \$ledgerStmt->fetchAll();\r\n\r\n?>", "\$ledger_history = \$ledgerStmt->fetchAll();\r\n" . $logic . "\r\n?>", $c);
    }
}

file_put_contents($f, $c);
echo "Final clean profile status variables injected.\n";
?>
