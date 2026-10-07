<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);
if(preg_match('/<form method="POST">.*?edit_profile.*?<\/form>/is', $c, $matches)) {
    echo $matches[0];
}
?>
