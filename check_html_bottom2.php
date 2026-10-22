<?php
$lines = explode("\n", file_get_contents('recoveryman/history.php'));
for($i=210; $i<=300; $i++) {
    if(isset($lines[$i])) echo "$i: " . $lines[$i] . "\n";
}
?>
