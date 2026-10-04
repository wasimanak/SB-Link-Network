<?php
$f = 'operator/line_man.php';
if (file_exists($f)) {
    $c = file_get_contents($f);
    
    // Replace incorrect 'line_men' with 'linemen' in the schema logic
    $c = str_replace('`line_men`', '`linemen`', $c);
    
    file_put_contents($f, $c);
    echo "Fixed table name in $f\n";
}
?>
