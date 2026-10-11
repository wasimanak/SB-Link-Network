<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// Fix grid for the 3 filter buttons to look perfect on mobile (3 in a row)
$c = str_replace('<div class="col-md col-6">', '<div class="col-md-4 col-4">', $c);

// Wait, the Top Collection and Pending Cash use `<div class="col-6 mb-2">`. That's 50% width, which is fine (2 in a row).

// Just to be safe, I'll also ensure text scaling on mobile for these very small buttons
$c = str_replace('<div class="small fw-bold mb-0 text-uppercase', '<div class="fw-bold mb-0 text-uppercase" style="font-size:0.65rem;"', $c);
$c = str_replace('<div class="fs-4 fw-bold mb-0', '<div class="fw-bold mb-0" style="font-size:1.1rem;"', $c);

file_put_contents($f, $c);
echo "Fixed grid for mobile.\n";
?>
