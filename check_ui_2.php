<?php
$lines = explode("\n", file_get_contents('recoveryman/dashboard.php'));
for($i=380; $i<=450; $i++) {
    if(isset($lines[$i])) echo "$i: " . $lines[$i] . "\n";
}
?>
