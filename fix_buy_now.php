<?php
$f = 'customer/dashboard.php';
$c = file_get_contents($f);

$oldCode = 'document.getElementById(\'qr_code_img\').src = qrUrl;';
$newCode = 'var qrElement = document.getElementById(\'qr_code_img\');
    if (qrElement) {
        qrElement.src = qrUrl;
    }';

if (strpos($c, 'qrElement.src = qrUrl') === false) {
    $c = str_replace($oldCode, $newCode, $c);
    file_put_contents($f, $c);
    echo "Fixed Buy Now modal javascript bug.\n";
} else {
    echo "Already fixed.\n";
}
?>
