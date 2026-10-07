<?php
$f = 'operator/dealers.php';
$c = file_get_contents($f);
if(preg_match('/<select id="add_routers_select".*?<\/select>/is', $c, $matches)) {
    echo $matches[0];
} else {
    echo "add_routers_select NOT FOUND!";
}
?>
