<?php
$f = 'operator/live_sessions.php';
$c = file_get_contents($f);

// Find where the gibberish starts
$startStr = "<?php endif; ?>";
$endStr = "<script>";

$startPos = strpos($c, $startStr);
$endPos = strpos($c, $endStr, $startPos);

if ($startPos !== false && $endPos !== false) {
    $realStart = $startPos + strlen($startStr);
    
    // Read the safe raw text
    $replacement = "\n  " . file_get_contents('layout_raw.txt') . "\n</div>\n\n";
    
    // We want to replace everything between <?php endif; ?> and <script>
    $c = substr_replace($c, $replacement, $realStart, $endPos - $realStart);
    file_put_contents($f, $c);
    echo "Fixed layout from raw text.\n";
} else {
    echo "Bounds not found.\n";
}
?>
