<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

$pattern = '/function editPackage.*?\{.*?\}/is';
$newFunc = 'function editPackage(pkgId, price, profit) {
    $(\'select[name="package_id"]\').val(pkgId).trigger(\'change\');
    $(\'input[name="dealer_price"]\').val(price);
    $(\'input[name="dealer_profit"]\').val(profit);
    $(\'#setNewPackageModal\').modal(\'show\');
}';

if (preg_match($pattern, $c)) {
    $c = preg_replace($pattern, $newFunc, $c);
    file_put_contents($f, $c);
    echo "JS Function replaced successfully.\n";
} else {
    echo "Function not found.\n";
}
?>
