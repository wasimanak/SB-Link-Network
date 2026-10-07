<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);
preg_match_all('/class="([^"]*)"/', $c, $matches);
$classes = array_unique($matches[1]);
// print out some notable ones
foreach($classes as $cls) {
    if (strpos($cls, 'quick') !== false || strpos($cls, 'stat') !== false || strpos($cls, 'card') !== false) {
        echo $cls . "\n";
    }
}
?>
