<?php
$f = 'operator/dashboard.php';
$c = file_get_contents($f);

$jsFunctions = '
<script>
function openPaymentModal(userId, username) {
    $(\'#single_payment_sub_id\').val(userId);
    $(\'#singlePaymentModalTitle\').html(\'<i class="fa-brands fa-paypal text-primary me-2"></i> Add Payment for \' + username);
    $(\'#single_payment_amount\').val(\'\');
    $(\'#singlePaymentModal\').modal(\'show\');
}

function openRenewModal(userId) {
    $(\'#renew_user_id\').val(userId).trigger(\'change\');
    $(\'#renewUserModal\').modal(\'show\');
}
</script>
';

if (strpos($c, 'function openPaymentModal') === false) {
    $c = str_replace('<?php require_once \'footer.php\'; ?>', $jsFunctions . "\n<?php require_once 'footer.php'; ?>", $c);
    file_put_contents($f, $c);
    echo "JS Functions injected successfully.\n";
} else {
    echo "Functions already exist.\n";
}
?>
