<?php
$lines = explode("\n", file_get_contents('recoveryman/dashboard.php'));
for($i=290; $i<=360; $i++) {
    if(isset($lines[$i])) echo "$i: " . $lines[$i] . "\n";
}
?>
