<?php
$file = 'operator/dashboard.php';
$content = file_get_contents($file);

// Remove decimals from user stats
$content = preg_replace('/number_format\(\$([a-zA-Z0-9_]+), 2\)/', 'number_format($\1)', $content);

// Ensure percentages still have 2 decimals (the $pct function)
// It looks like: return number_format(($val / $total_users) * 100, 2) . "%";
// Which wasn't caught by the above regex because it's not a simple variable.

// Also, the user said "percentage k ilawa kuch bhi decimal me nhi hona chahiye"
// So things like $s['balance'], $total_balance_all, $rp['price'] should also have no decimals.
$content = preg_replace('/number_format\(\$s\[\'balance\'\],\s*2\)/', "number_format(\$s['balance'])", $content);
$content = preg_replace('/number_format\(\$total_balance_all,\s*2\)/', "number_format(\$total_balance_all)", $content);
$content = preg_replace('/number_format\(\$rp\[\'price\'\],\s*2\)/', "number_format(\$rp['price'])", $content);

// Also check for static "100.00%" which should be "100%" if they strictly don't want decimals there either, 
// but wait, they said "percentage k ilawa", meaning percentages CAN have decimals.

file_put_contents($file, $content);
echo "Fixed decimals.";
?>
