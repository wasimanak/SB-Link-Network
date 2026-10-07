<?php
$f = 'operator/dealer_view.php';
$c = file_get_contents($f);

$jsFunction = '
<script>
function editPackage(pkgId, price, profit) {
    $(\'select[name="package_id"]\').val(pkgId).trigger(\'change\');
    $(\'input[name="dealer_price"]\').val(price);
    $(\'input[name="dealer_profit"]\').val(profit);
    $(\'#setNewPackageModal\').modal(\'show\');
}
</script>
';

// If it doesn't already exist
if (strpos($c, 'function editPackage(') === false) {
    $c = str_replace('<?php require_once \'footer.php\'; ?>', $jsFunction . "\n<?php require_once 'footer.php'; ?>", $c);
    file_put_contents($f, $c);
    echo "JS Function injected successfully.\n";
} else {
    echo "Function already exists!\n";
}
?>
