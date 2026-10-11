<?php
$lines = explode("\n", file_get_contents('operator/recovery_man.php'));
for($i=10; $i<=100; $i++) {
    if(isset($lines[$i])) echo "$i: " . $lines[$i] . "\n";
}
?>
