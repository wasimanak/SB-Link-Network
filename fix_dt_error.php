<?php
$f = 'operator/statement.php';
$c = file_get_contents($f);

// Fix DataTables ordering index
$c = str_replace('"order": [[ 5, "desc" ]],', '"order": [[ 4, "desc" ]],', $c);

file_put_contents($f, $c);
echo "DataTables ordering index fixed in operator/statement.php.\n";
?>
