<?php
$c = file_get_contents('recoveryman/history.php');
// Let's print out lines containing "tab-pane" and "id="
$lines = explode("\n", $c);
foreach ($lines as $i => $l) {
    if (strpos($l, 'tab-pane') !== false) {
        echo "$i: " . trim($l) . "\n";
    }
}
?>
