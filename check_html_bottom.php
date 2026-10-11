<?php
$lines = explode("\n", file_get_contents('recoveryman/history.php'));
for($i=180; $i<=250; $i++) {
    if(isset($lines[$i])) echo "$i: " . $lines[$i] . "\n";
}
?>
