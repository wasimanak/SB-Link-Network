<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// We want to insert the "Recently Created" block right before the "<!-- Metrics (Mobile/Desktop) -->"
// Wait, I saw it before, let's inject after:
// `<div class="row">` that contains the search box.
// Actually, let's just use `str_replace` on `<!-- Search Box -->` block end.
// Let's print out lines 280-350 of dashboard.php
$lines = explode("\n", $c);
for ($i=280; $i<350; $i++) {
    if (isset($lines[$i])) {
        echo "$i: " . $lines[$i] . "\n";
    }
}
?>
