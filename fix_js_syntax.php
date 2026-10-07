<?php
$f = 'operator/subscriber_view.php';
$c = file_get_contents($f);

// Fix the JavaScript syntax error
$oldLine = 'const dataLimitGb = <?= $data_limit_gb ?>;';
$newLine = 'const dataLimitGb = <?= (float)($user[\'data_limit_gb\'] ?? 0) ?>;';
$c = str_replace($oldLine, $newLine, $c);

file_put_contents($f, $c);
echo "Fixed JS syntax error in operator/subscriber_view.php\n";
?>
