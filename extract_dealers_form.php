<?php
$c = file_get_contents('operator/dealers.php');
if (preg_match('/<form method="POST".*?<\/form>/is', $c, $matches)) {
    echo $matches[0];
}
?>
