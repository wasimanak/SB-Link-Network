<?php
$lines = explode("\n", file_get_contents('recoveryman/history.php'));
for($i=70; $i<=150; $i++) {
    if(isset($lines[$i])) echo "$i: " . $lines[$i] . "\n";
}
?>
