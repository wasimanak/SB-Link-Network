<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

$oldJs = "$('#package_id').val(pkgId).trigger('change');
    $('input[name=\"dealer_price\"]').val(price);
    $('input[name=\"dealer_profit\"]').val(profit);
    $('#setPackageModal').modal('show');";

$newJs = "$('select[name=\"package_id\"]').val(pkgId).trigger('change');
    $('input[name=\"dealer_price\"]').val(price);
    $('input[name=\"dealer_profit\"]').val(profit);
    $('#setNewPackageModal').modal('show');";

if (strpos($c, "$('#package_id')") !== false) {
    $c = str_replace($oldJs, $newJs, $c);
    file_put_contents($f, $c);
    echo "operator/dealer_view.php JS patched for Edit Package.\n";
} else {
    echo "Old JS not found.\n";
}
?>
