<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
if(preg_match('/if \(\$_SERVER\[\'REQUEST_METHOD\'\].*?edit_profile.*?\n(.*?)if \(\!empty\(\$_POST/is', $c, $matches)) {
    echo $matches[1];
}
?>
