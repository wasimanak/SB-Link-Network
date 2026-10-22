<?php
$f = 'recoveryman/dashboard.php';
$c = file_get_contents($f);

// Remove the Recently Created block
$pattern = '/<\?php\s*\/\/\s*Fetch recently created users.*?<\?php endif; \?>/s';
$c = preg_replace($pattern, '', $c);

file_put_contents($f, $c);
echo "Removed block from dashboard.\n";
?>
