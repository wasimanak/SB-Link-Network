<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// Find <?php endif;
$startStr = "endif;";
$endStr = "<script>";

$startPos = strpos($c, $startStr);
$endPos = strpos($c, $endStr);

if ($startPos !== false && $endPos !== false) {
    // Add length of "endif;" and then the closing "? >"
    $realStart = $startPos + 6;
    $realStart = strpos($c, ">", $realStart) + 1;
    
    $replacement = "\n  " . file_get_contents('layout_raw.txt') . "\n</div>\n\n";
    
    $c = substr_replace($c, $replacement, $realStart, $endPos - $realStart);
    file_put_contents($f, $c);
    echo "Fixed completely without breaking tags.\n";
} else {
    echo "Bounds not found.\n";
}
